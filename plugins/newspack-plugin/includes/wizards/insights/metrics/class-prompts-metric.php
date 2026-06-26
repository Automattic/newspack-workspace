<?php
/**
 * Newspack Insights — Prompts Metric orchestrator (NPPD-1607, Phase 2).
 *
 * Dispatches catalog queries to the Newspack Manager BigQuery proxy and shapes
 * each result for the React layer. Every method reports an explicit `state`:
 *   - 'error'     — the proxy/query failed (carries `error_code` + `error_message`)
 *   - 'empty'     — the query succeeded but returned no rows (collections only)
 *   - 'populated' — the query returned usable data
 *
 * This replaces the Phase 1 `pending: true` flag, which collapsed proxy errors,
 * legitimately-empty results, and malformed responses into one ambiguous
 * "No data yet" state and masked real failures. Scalar scorecards use
 * 'error' | 'populated' only ('empty' has no meaning for a single value — an
 * absent value renders as a non-computable zero).
 *
 * Mirrors {@see Gates_Metric} (Tab 4) one-for-one. Tab 5 carries four conversion
 * intents (registration, newsletter signup, donation, subscription) versus
 * Gates' two, so it has more scorecards and a three-table performance
 * breakdown — but the per-metric envelope is identical.
 *
 * Phase 2 wiring is now complete in this class: 10 scalar metrics (Task 3.1),
 * 8 paid conversion + revenue metrics (Task 3.2) — rates and revenue are
 * hub-computed in BigQuery and returned directly by the proxy, and 5 collection
 * metrics — conversion funnel, exposures distribution, and three performance
 * breakdown tables (Task 3.3). The performance-by-prompt table augments each
 * row with per-popup donation and subscription conversion counts sourced from
 * order meta via {@see Donors_Metric} and {@see Subscribers_Metric}; that
 * augmentation degrades gracefully (engagement columns still render with zeros
 * in the conversion columns) when WooCommerce is not active.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

use DateTimeInterface;
use Newspack\Insights\BigQuery_Proxy_Client;
use Newspack\Insights\Donors_Metric;
use Newspack\Insights\Subscribers_Metric;

/**
 * Tab 5 metric orchestrator.
 *
 * @phpstan-type ScalarMetric array{
 *   state: string,
 *   value: int|float,
 *   computable: bool,
 *   denominator: int|null,
 *   placeholder_type: string,
 *   error_code?: string,
 *   error_message?: string,
 * }
 */
final class Prompts_Metric {

	/**
	 * Cache key prefix. Bumped when the response shape changes so
	 * cached payloads from a prior shape don't break a deploy.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'newspack_insights_tab5_v2:';

	/**
	 * Display labels for each `action_type` (intent) value. Spec §7.2 prescribes
	 * title-cased labels with `newsletters_subscription` rendered as the more
	 * publisher-friendly "Newsletter signup" (the auto-ucwords result —
	 * "Newsletters Subscription" — is awkward). Centralized so the per-intent
	 * table and any future intent display agree.
	 *
	 * @var array<string, string>
	 */
	private const INTENT_LABELS = [
		'donation'                 => 'Donation',
		'registration'             => 'Registration',
		'newsletters_subscription' => 'Newsletter signup',
		// NPPD-1758: checkout-button prompts emit `action_type = 'checkout'` (NPPD-1755).
		// Reader-facing framing 'Subscription' (Katie's call), consistent with the tab's
		// Subscription Conversion Rate card. Unmapped intents fall back to a frontend
		// humanizer, so this map only needs the friendly overrides.
		'checkout'                 => 'Subscription',
	];

	/**
	 * Conversion blocks scanned by the per-intent capability gate (NPPD-1720),
	 * keyed by capability. A prompt is "capable" of an intent when any active
	 * prompt contains the matching block. Mirrors the three blocks newspack-popups
	 * watches in `Newspack_Popups_Data_Api::get_popup_metadata()` plus
	 * `newspack-blocks/checkout-button`, which that map omits — without it,
	 * checkout-button membership prompts would read as informational.
	 *
	 * @var array<string, string>
	 */
	private const WATCHED_BLOCKS = [
		'registration' => 'newspack/reader-registration',
		'donation'     => 'newspack-blocks/donate',
		'newsletter'   => 'newspack-newsletters/subscribe',
		'checkout'     => 'newspack-blocks/checkout-button',
	];

	/**
	 * Prompt CPT slug (newspack-popups' `Newspack_Popups::NEWSPACK_POPUPS_CPT`).
	 * Hardcoded so the capability scan can enumerate published prompts via core
	 * `get_posts()` without depending on the popups plugin class being loadable,
	 * and without the write side effect `retrieve_popups()` carries.
	 *
	 * @var string
	 */
	private const POPUPS_CPT = 'newspack_popups_cpt';

	/**
	 * Maps each conversion-tied scalar metric to the capability that gates it
	 * (NPPD-1720). `form_submission_rate` is capable when any *form-bearing* block
	 * is present ('form_bearing' = registration / donation / newsletter signup);
	 * checkout-button is a click-through to a checkout page, not an inline form, so
	 * it's excluded here to match the form-bearing nudge copy. Every other metric
	 * gates on its specific block. Non-conversion metrics (exposure, CTR, dismissal,
	 * the funnel, the tables) are absent here — they carry no `has_capability` flag
	 * and always render as long as there are prompts at all.
	 *
	 * @var array<string, string>
	 */
	private const METRIC_CAPABILITY = [
		'form_submission_rate'                       => 'form_bearing',
		'registration_conversion_direct'             => 'registration',
		'registration_conversion_influenced_7d'      => 'registration',
		'newsletter_signup_conversion_direct'        => 'newsletter',
		'newsletter_signup_conversion_influenced_7d' => 'newsletter',
		'donation_conversion_direct'                 => 'donation',
		'donation_conversion_influenced_14d'         => 'donation',
		'subscription_conversion_direct'             => 'checkout',
		'subscription_conversion_influenced_14d'     => 'checkout',
		'donation_revenue_direct'                    => 'donation',
		'donation_revenue_influenced_14d'            => 'donation',
		'subscription_revenue_direct'                => 'checkout',
		'subscription_revenue_influenced_14d'        => 'checkout',
	];

	/**
	 * Data-source classification per metric key (NPPD-1745). Declares, explicitly,
	 * whether each card depends on the hub (BigQuery proxy), on local Woo order
	 * data, or both:
	 *
	 *  - 'hub'    — fully hub-backed; errors when the proxy is down.
	 *  - 'local'  — fully local (Woo order meta); survives a hub outage.
	 *  - 'hybrid' — local numerator + hub denominator (or vice-versa); a hub
	 *               failure makes it genuinely uncomputable, so it counts as
	 *               hub-backed for the tab-error banner.
	 *
	 * Read by {@see \Newspack\Insights\Prompts_REST_Controller::is_window_all_error()}
	 * so the "whole tab failed" banner fires when **all hub-backed metrics** error,
	 * even though a surviving local card still renders — instead of being silently
	 * suppressed by that one local card.
	 *
	 * MIGRATION CHECKLIST: when a card moves from hub to order-meta sourcing, update
	 * its entry here (→ 'local' or 'hybrid'). Keys mirror {@see Prompts_REST_Controller::build_window()}.
	 *
	 * @var array<string, string>
	 */
	public const METRIC_SOURCES = [
		'total_prompt_impressions'                   => 'hub',
		'unique_readers_reached'                     => 'hub',
		'avg_prompts_per_reader'                     => 'hub',
		'click_through_rate'                         => 'hub',
		'form_submission_rate'                       => 'hub',
		'dismissal_rate'                             => 'hub',
		'registration_conversion_direct'             => 'hub',
		'registration_conversion_influenced_7d'      => 'hub',
		'newsletter_signup_conversion_direct'        => 'hub',
		'newsletter_signup_conversion_influenced_7d' => 'hub',
		'donation_conversion_direct'                 => 'hybrid', // Local order-meta numerator + hub donation-impressions denominator.
		'donation_conversion_influenced_14d'         => 'hub',    // NPPD-1822: fully BQ-internal (rate, conversion_denominator, influenced_revenue); no local Woo component.
		'subscription_conversion_direct'             => 'hybrid', // NPPD-1746: local order-meta (popup) numerator + hub per-popup-impressions denominator.
		'subscription_conversion_influenced_14d'     => 'hub',    // NPPD-1822: fully BQ-internal (rate, conversion_denominator, influenced_revenue); no local Woo component.
		'donation_revenue_direct'                    => 'local',   // Pure Woo order meta; survives a hub outage.
		'donation_revenue_influenced_14d'            => 'hub',    // NPPD-1822: fully BQ-internal (influenced_revenue); no local Woo component.
		'subscription_revenue_direct'                => 'local',   // NPPD-1746: pure Woo order meta (popup surface); survives a hub outage.
		'subscription_revenue_influenced_14d'        => 'hub',    // NPPD-1822: fully BQ-internal (influenced_revenue); no local Woo component.
		'conversion_funnel'                          => 'hub',
		'exposures_distribution'                     => 'hub',
		'performance_by_prompt'                      => 'hub',
		'performance_by_intent'                      => 'hub',
		'performance_by_placement'                   => 'hub',
	];

	/**
	 * Proxy client used to dispatch catalog queries to the hub.
	 *
	 * @var BigQuery_Proxy_Client
	 */
	private BigQuery_Proxy_Client $proxy;

	/**
	 * Donors_Metric collaborator (NPPD-1685). Owns the WC-native donation
	 * storage + caching used to source the DIRECT prompt-donation metrics from
	 * order meta. Lazily built on first direct-donation call
	 * ({@see self::donors_metric()}) so the storage detector + classifier don't
	 * run for requests that never touch a direct-donation card.
	 *
	 * @var Donors_Metric|null
	 */
	private ?Donors_Metric $donors_metric;

	/**
	 * Subscribers_Metric collaborator (NPPD-1746). Owns the WC-native subscription
	 * storage + caching used to source the DIRECT prompt-subscription metrics
	 * (popup surface) from order meta — the subscription counterpart to
	 * {@see self::$donors_metric}. Lazily built on first direct-subscription
	 * call ({@see self::subscribers_metric()}).
	 *
	 * @var Subscribers_Metric|null
	 */
	private ?Subscribers_Metric $subscribers_metric;

	/**
	 * Per-request memo for `prompts_performance_by_prompt` hub rows, keyed by
	 * `Ymd|Ymd` window (NPPD-1745). The direct donation-rate denominator
	 * ({@see self::fetch_donation_prompt_impressions()}) and the per-prompt table
	 * ({@see self::get_performance_by_prompt()}) both read this query for the same
	 * window in one request; memoizing avoids the duplicate hub round-trip.
	 *
	 * @var array<string, array|\WP_Error>
	 */
	private array $performance_by_prompt_cache = [];

	/**
	 * Per-request memoization for the prompt-capability scan (NPPD-1720). Null
	 * until the first `compute_prompt_capabilities()` call; thereafter the four
	 * capability booleans are reused across both windows and all 13 gated metrics
	 * so the active-prompt enumeration runs at most once per request.
	 *
	 * @var array<string, bool>|null
	 */
	private ?array $prompt_capabilities = null;

	/**
	 * Active-prompt provider (test seam, NPPD-1720). When null, prompt content is
	 * read from published prompt CPT posts via `get_posts()`. Tests inject a
	 * callable returning canned `post_content` strings so the detector can be
	 * exercised without loading newspack-popups. Mirrors the proxy / Woo-resolver DI.
	 *
	 * @var callable|null
	 */
	private $prompt_provider;

	/**
	 * Constructor. Optionally inject collaborators (used in tests).
	 *
	 * @param BigQuery_Proxy_Client|null $proxy              Injected proxy client, or null to lazy-resolve.
	 * @param callable|null              $prompt_provider    Injected active-prompt provider (test seam),
	 *                                                       or null to read published prompt content via get_posts().
	 * @param Donors_Metric|null         $donors_metric      Injected donors collaborator (NPPD-1685), or null to lazy-create.
	 * @param Subscribers_Metric|null    $subscribers_metric Injected subscribers collaborator (NPPD-1746), or null to lazy-create.
	 */
	public function __construct(
		?BigQuery_Proxy_Client $proxy = null,
		?callable $prompt_provider = null,
		?Donors_Metric $donors_metric = null,
		?Subscribers_Metric $subscribers_metric = null
	) {
		$this->proxy              = $proxy ?? new BigQuery_Proxy_Client();
		$this->prompt_provider    = $prompt_provider;
		$this->donors_metric      = $donors_metric;
		$this->subscribers_metric = $subscribers_metric;
	}

	/**
	 * Lazily resolve the Donors_Metric collaborator (NPPD-1685). Built on first
	 * use so requests that never hit a direct-donation card don't pay for the
	 * storage detector + donation-product classifier.
	 *
	 * @return Donors_Metric
	 */
	private function donors_metric(): Donors_Metric {
		if ( null === $this->donors_metric ) {
			$this->donors_metric = new Donors_Metric();
		}
		return $this->donors_metric;
	}

	/**
	 * Lazily resolve the Subscribers_Metric collaborator (NPPD-1746). Built on first
	 * use so requests that never hit a direct-subscription card don't pay for the
	 * storage detector + donation-product classifier.
	 *
	 * @return Subscribers_Metric
	 */
	private function subscribers_metric(): Subscribers_Metric {
		if ( null === $this->subscribers_metric ) {
			$this->subscribers_metric = new Subscribers_Metric();
		}
		return $this->subscribers_metric;
	}

	/**
	 * Whether WooCommerce is active. The direct donation/subscription metrics read
	 * local Woo orders, so they no-op to an empty state on non-WC publishers
	 * (NPPD-1685). Filterable so tests can exercise both the WC-active and non-WC
	 * paths without toggling a global class (the class is `final`, so it can't be
	 * doubled). Public because the REST controller reads it to scope the tab-error
	 * banner: on a non-WC publisher a hybrid card short-circuits before reaching the
	 * hub, so it must not count as a hub-backed survivor (NPPD-1745).
	 *
	 * @return bool
	 */
	public function woocommerce_active(): bool {
		/**
		 * Filters whether Insights treats WooCommerce as active for the direct
		 * order-meta donation/subscription metrics.
		 *
		 * @param bool $active Whether the WooCommerce class is loaded.
		 */
		return (bool) apply_filters( 'newspack_insights_woocommerce_active', class_exists( 'WooCommerce' ) );
	}

	/**
	 * Return the canned fixture payload for the Prompts tab.
	 *
	 * Returned by the REST controller when NEWSPACK_INSIGHTS_FIXTURE_MODE is on.
	 * The variant selects a render path: 'populated' (default), 'empty', 'error',
	 * 'not_capable' (NPPD-1720), 'not_computable' (NPPD-1704).
	 *
	 * @param string $variant One of 'populated', 'empty', 'error', 'not_capable',
	 *                        'not_computable'.
	 * @param bool   $compare Whether comparison was requested; when false the
	 *                        `previous` window is null (no period-over-period deltas).
	 * @return array Full { tab_error, current, previous } response shape.
	 */
	public static function get_fixture( string $variant = 'populated', bool $compare = false ): array {
		$build = require NEWSPACK_ABSPATH . 'includes/wizards/insights/fixtures/prompts-fixture.php';
		return $build( $variant, $compare );
	}

	/**
	 * Error payload for a scalar scorecard metric. Carries the proxy's error
	 * code + message so the UI can render an error treatment (without exposing
	 * internals to the reader) instead of a misleading zero.
	 *
	 * @param string    $placeholder_type One of 'count', 'rate', 'currency', 'decimal'.
	 * @param \WP_Error $error            The originating proxy error.
	 * @return array
	 */
	private function error_scalar( string $placeholder_type, \WP_Error $error ): array {
		return [
			'state'            => 'error',
			'value'            => 'decimal' === $placeholder_type ? 0.0 : 0,
			'computable'       => false,
			'denominator'      => null,
			'placeholder_type' => $placeholder_type,
			'error_code'       => $error->get_error_code(),
			'error_message'    => $error->get_error_message(),
		];
	}

	/**
	 * Populated payload for a scalar scorecard metric. A successful query that
	 * yields no usable value is still 'populated' — it renders as a
	 * non-computable zero ('empty' has no meaning for a single scalar).
	 *
	 * @param int|float $value            Metric value.
	 * @param bool      $computable       Whether the value is a real computed figure.
	 * @param int|null  $denominator      Optional denominator.
	 * @param string    $placeholder_type One of 'count', 'rate', 'currency', 'decimal'.
	 * @param bool      $data_missing     Whether underlying data is absent (default false).
	 * @return array
	 */
	private function populated_scalar( $value, bool $computable, ?int $denominator, string $placeholder_type, bool $data_missing = false ): array {
		return [
			'state'            => 'populated',
			'value'            => $value,
			'computable'       => $computable,
			'denominator'      => $denominator,
			'placeholder_type' => $placeholder_type,
			'data_missing'     => $data_missing,
		];
	}

	/**
	 * Error payload for a collection metric (funnel / distribution / table).
	 *
	 * @param string    $rows_key Key holding the (empty) collection: 'stages'|'buckets'|'rows'.
	 * @param \WP_Error $error    The originating proxy error.
	 * @return array
	 */
	private function error_collection( string $rows_key, \WP_Error $error ): array {
		return [
			'state'         => 'error',
			'error_code'    => $error->get_error_code(),
			'error_message' => $error->get_error_message(),
			$rows_key       => [],
		];
	}

	/**
	 * Error payload for a collection whose query succeeded but returned an
	 * unexpected (non-array) shape — a data-quality bug, not an empty window.
	 *
	 * @param string $rows_key Key holding the (empty) collection: 'stages'|'buckets'|'rows'.
	 * @return array
	 */
	private function malformed_collection( string $rows_key ): array {
		return $this->error_collection(
			$rows_key,
			new \WP_Error( 'bigquery_proxy_malformed_rows', __( 'The query returned an unexpected shape.', 'newspack-plugin' ) )
		);
	}

	/**
	 * Detect which conversion intents the publisher's active prompts can drive
	 * (NPPD-1720). Enumerates published prompts and tests each for the four
	 * watched conversion blocks; an intent is "capable" once any prompt carries
	 * its block. Result is memoized per request.
	 *
	 * Uses raw block presence via `has_block()` rather than newspack-popups'
	 * `action_type`, which collapses a multi-block prompt to 'undefined' (see the
	 * `@TODO: How to handle prompts with more than one block?` in
	 * `class-newspack-popups-data-api.php`); a prompt with both a donate and a
	 * subscribe block must register as capable of both.
	 *
	 * "Active" = `post_status = 'publish'`. No `frequency` filter is applied: an
	 * audit of real publishers found no published prompts left in `frequency =
	 * 'never'` test mode, so it would add complexity without changing results.
	 *
	 * Fails open: if newspack-popups isn't active the prompt CPT isn't registered
	 * and the prompts can't be inspected, so every intent is assumed capable rather
	 * than falsely gating every conversion card on a misconfiguration.
	 *
	 * @return array<string, bool> Capability keyed by 'registration'|'donation'|'newsletter'|'checkout'.
	 */
	private function compute_prompt_capabilities(): array {
		if ( null !== $this->prompt_capabilities ) {
			return $this->prompt_capabilities;
		}

		// Fail open when prompts can't be inspected (popups inactive → CPT absent,
		// and no injected provider): assume every intent is possible rather than
		// falsely gating every conversion card on a misconfiguration. Keyed on the
		// CPT registration rather than an empty query so an inactive plugin reads as
		// "can't tell" (all-capable), not "no conversions possible" (all-gated).
		if ( null === $this->prompt_provider && ! post_type_exists( self::POPUPS_CPT ) ) {
			$this->prompt_capabilities = array_fill_keys( array_keys( self::WATCHED_BLOCKS ), true );
			return $this->prompt_capabilities;
		}

		$capabilities = array_fill_keys( array_keys( self::WATCHED_BLOCKS ), false );
		// Blocks still to look for; entries drop out as soon as one is found so
		// later prompts skip the already-satisfied checks.
		$remaining = self::WATCHED_BLOCKS;

		foreach ( $this->get_active_prompt_contents() as $content ) {
			if ( empty( $remaining ) ) {
				break; // Every capability satisfied — stop scanning prompts.
			}
			if ( '' === $content ) {
				continue;
			}
			foreach ( $remaining as $capability => $block_name ) {
				if ( \has_block( $block_name, $content ) ) {
					$capabilities[ $capability ] = true;
					unset( $remaining[ $capability ] );
				}
			}
		}

		$this->prompt_capabilities = $capabilities;
		return $capabilities;
	}

	/**
	 * Raw block markup of every active (published) prompt.
	 *
	 * Reads via a plain `get_posts()` on the prompt CPT + `get_post_field()` rather
	 * than `Newspack_Popups_Model::retrieve_popups()` on purpose: that method routes
	 * through `deprecate_test_never_manual()`, which writes post meta and can demote
	 * legacy `never`/`test` prompts to draft — a write side effect we must not
	 * trigger from a read-only analytics scan (and which would fire on a cached GET).
	 * The direct query also drops retrieve_popups()'s 1000-prompt cap and its unused
	 * taxonomy hydration.
	 *
	 * Tests inject a provider that returns canned content strings (newspack-popups
	 * isn't loaded in the unit suite).
	 *
	 * @return string[] `post_content` for each published prompt.
	 */
	private function get_active_prompt_contents(): array {
		if ( null !== $this->prompt_provider ) {
			return ( $this->prompt_provider )();
		}

		$ids = get_posts(
			[
				'post_type'        => self::POPUPS_CPT,
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			]
		);

		return array_map(
			static function ( $id ) {
				return (string) get_post_field( 'post_content', $id );
			},
			$ids
		);
	}

	/**
	 * Per-metric capability flags for the Prompts envelope (NPPD-1720). Maps each
	 * of the 13 conversion-tied scalar keys to a boolean the REST controller
	 * stamps onto the metric. `form_submission_rate` is capable when any form-bearing
	 * block is present; every other metric gates on its specific block.
	 *
	 * @return array<string, bool> Metric key => has_capability.
	 */
	public function get_capability_flags(): array {
		$capabilities = $this->compute_prompt_capabilities();
		// Form-bearing = blocks that submit an inline form (registration / donation /
		// newsletter signup). Checkout-button is excluded — it's a click-through to a
		// checkout page, not a form — matching the form-bearing nudge copy.
		$form_bearing = $capabilities['registration'] || $capabilities['donation'] || $capabilities['newsletter'];

		$flags = [];
		foreach ( self::METRIC_CAPABILITY as $metric_key => $capability ) {
			$flags[ $metric_key ] = 'form_bearing' === $capability
				? $form_bearing
				: ( $capabilities[ $capability ] ?? false );
		}
		return $flags;
	}

	/**
	 * Run a scalar catalog query and extract a single value from the first row.
	 *
	 * A proxy WP_Error becomes state 'error'. A successful query with no usable
	 * value (empty rows, missing key, non-numeric, or count drift) becomes a
	 * 'populated' non-computable zero.
	 *
	 * @param string            $query_name        Catalog `query_name`.
	 * @param string            $row_key           Column to extract from the first row.
	 * @param string            $placeholder_type  'count' | 'rate' | 'currency' | 'decimal'.
	 * @param DateTimeInterface $start             Window start.
	 * @param DateTimeInterface $end               Window end.
	 * @return array
	 */
	private function compute_metric_from_proxy(
		string $query_name,
		string $row_key,
		string $placeholder_type,
		DateTimeInterface $start,
		DateTimeInterface $end
	): array {
		$rows = $this->proxy->query( $query_name, $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_scalar( $placeholder_type, $rows );
		}
		$zero = 'decimal' === $placeholder_type ? 0.0 : 0;
		if ( empty( $rows ) || ! is_array( $rows[0] ) || ! array_key_exists( $row_key, $rows[0] ) ) {
			// Query succeeded with no usable value → non-computable zero.
			return $this->populated_scalar( $zero, false, null, $placeholder_type );
		}
		$value = $rows[0][ $row_key ];
		// SAFE_DIVIDE returns NULL when the denominator is zero — a legitimate
		// "no eligible events to compute a rate" case, not a schema regression.
		// Same handling as the missing-key branch above: non-computable zero.
		if ( null === $value ) {
			return $this->populated_scalar( $zero, false, null, $placeholder_type );
		}
		// Non-numeric, or (for counts) a non-integer value, signals catalog/schema
		// drift — malformed data, not an empty window. Surface it as an error so a
		// real data-quality regression isn't masked as a benign zero.
		if ( ! is_numeric( $value ) || ( 'count' === $placeholder_type && (float) $value !== (float) (int) $value ) ) {
			return $this->error_scalar(
				$placeholder_type,
				new \WP_Error( 'bigquery_proxy_malformed_value', __( 'The query returned a non-numeric value.', 'newspack-plugin' ) )
			);
		}
		return $this->populated_scalar( 'count' === $placeholder_type ? (int) $value : (float) $value, true, null, $placeholder_type );
	}

	/**
	 * Fetch the hub-internal influenced rate from the proxy and shape it into a
	 * scalar envelope. The hub returns ONE row with the pre-computed rate,
	 * integer denominator, and revenue — no Woo join required on the consumer.
	 *
	 * Ported from {@see Gates_Metric::compute_influenced_rate_from_proxy()}, adapted
	 * for Prompts' `populated_scalar` arg order where `data_missing` is the 5th param
	 * and there is no `numerator`.
	 *
	 * @param string            $query_name       Catalog query name.
	 * @param string            $rate_key         Column holding the pre-computed rate.
	 * @param string            $denominator_key  Column holding the integer denominator.
	 * @param DateTimeInterface $start            Window start.
	 * @param DateTimeInterface $end              Window end.
	 * @return array
	 */
	private function compute_influenced_rate_from_proxy( string $query_name, string $rate_key, string $denominator_key, DateTimeInterface $start, DateTimeInterface $end ): array {
		$rows = $this->proxy->query( $query_name, $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_scalar( 'rate', $rows );
		}
		if ( empty( $rows ) ) {
			return $this->populated_scalar( 0.0, false, null, 'rate' );
		}
		if ( ! is_array( $rows[0] ) || ! array_key_exists( $rate_key, $rows[0] ) || ! array_key_exists( $denominator_key, $rows[0] ) ) {
			return $this->populated_scalar( 0.0, false, null, 'rate', true );
		}
		$denominator = $rows[0][ $denominator_key ];
		if ( ! is_numeric( $denominator ) || (float) $denominator !== (float) (int) $denominator ) {
			return $this->error_scalar( 'rate', new \WP_Error( 'bigquery_proxy_malformed_value', __( 'The query returned a non-integer denominator.', 'newspack-plugin' ) ) );
		}
		$denominator = (int) $denominator;
		$rate        = $rows[0][ $rate_key ];
		if ( null === $rate ) {
			return $this->populated_scalar( 0.0, false, $denominator, 'rate' );
		}
		if ( ! is_numeric( $rate ) ) {
			return $this->error_scalar( 'rate', new \WP_Error( 'bigquery_proxy_malformed_value', __( 'The query returned a non-numeric value.', 'newspack-plugin' ) ) );
		}
		return $this->populated_scalar( (float) $rate, $denominator > 0, $denominator, 'rate' );
	}

	/**
	 * Fetch the hub-internal influenced revenue from the proxy and shape it into a
	 * scalar envelope. The hub returns ONE row with the pre-computed revenue
	 * alongside the rate — no Woo join required on the consumer.
	 *
	 * @param string            $query_name  Catalog query name.
	 * @param string            $revenue_key Column holding the pre-computed revenue.
	 * @param DateTimeInterface $start       Window start.
	 * @param DateTimeInterface $end         Window end.
	 * @return array
	 */
	private function compute_influenced_revenue_from_proxy( string $query_name, string $revenue_key, DateTimeInterface $start, DateTimeInterface $end ): array {
		$rows = $this->proxy->query( $query_name, $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_scalar( 'currency', $rows );
		}
		if ( empty( $rows ) ) {
			return $this->populated_scalar( 0.0, false, null, 'currency' );
		}
		if ( ! is_array( $rows[0] ) || ! array_key_exists( $revenue_key, $rows[0] ) ) {
			return $this->populated_scalar( 0.0, false, null, 'currency', true );
		}
		$revenue = $rows[0][ $revenue_key ];
		if ( null === $revenue ) {
			return $this->populated_scalar( 0.0, false, null, 'currency' );
		}
		if ( ! is_numeric( $revenue ) ) {
			return $this->error_scalar( 'currency', new \WP_Error( 'bigquery_proxy_malformed_value', __( 'The query returned a non-numeric revenue.', 'newspack-plugin' ) ) );
		}
		return $this->populated_scalar( (float) $revenue, true, null, 'currency' );
	}

	// --- Section 1: Prompt exposure -------------------------------------

	/**
	 * Total prompt impressions in window.
	 *
	 * Dispatches `prompts_total_impressions`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_total_prompt_impressions( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_total_impressions', 'prompt_impressions', 'count', $start, $end );
	}

	/**
	 * Unique readers who saw at least one prompt.
	 *
	 * Dispatches `prompts_unique_viewers`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_unique_readers_reached( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_unique_viewers', 'unique_prompt_viewers', 'count', $start, $end );
	}

	/**
	 * Average prompt exposures per reader.
	 *
	 * Dispatches `prompts_avg_prompts_per_reader`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_avg_prompts_per_reader( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_avg_prompts_per_reader', 'avg_prompts_per_reader', 'decimal', $start, $end );
	}

	// --- Section 2: Prompt engagement -----------------------------------

	/**
	 * Click-through rate (clicks ÷ impressions).
	 *
	 * Dispatches `prompts_click_through_rate`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_click_through_rate( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_click_through_rate', 'click_through_rate', 'rate', $start, $end );
	}

	/**
	 * Form submission rate (submissions ÷ form-bearing impressions).
	 *
	 * Dispatches `prompts_form_submission_rate`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_form_submission_rate( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_form_submission_rate', 'form_submission_rate', 'rate', $start, $end );
	}

	/**
	 * Dismissal rate (explicit dismissals ÷ impressions).
	 *
	 * Dispatches `prompts_dismissal_rate`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_dismissal_rate( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_dismissal_rate', 'dismissal_rate', 'rate', $start, $end );
	}

	// --- Section 3: Free reader conversion ------------------------------

	/**
	 * Registration conversion rate, direct attribution.
	 *
	 * Dispatches `prompts_registration_conversion_direct`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_registration_conversion_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_registration_conversion_direct', 'registration_conversion_direct', 'rate', $start, $end );
	}

	/**
	 * Registration conversion rate, influenced (7-day lookback).
	 *
	 * Dispatches `prompts_registration_conversion_influenced_7d`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_registration_conversion_influenced_7d( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_registration_conversion_influenced_7d', 'registration_conversion_influenced', 'rate', $start, $end );
	}

	/**
	 * Newsletter signup conversion rate, direct attribution.
	 *
	 * Dispatches `prompts_newsletter_signup_conversion_direct`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_newsletter_signup_conversion_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_newsletter_signup_conversion_direct', 'newsletter_signup_conversion_direct', 'rate', $start, $end );
	}

	/**
	 * Newsletter signup conversion rate, influenced (7-day lookback).
	 *
	 * Dispatches `prompts_newsletter_signup_conversion_influenced_7d`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_newsletter_signup_conversion_influenced_7d( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_newsletter_signup_conversion_influenced_7d', 'newsletter_signup_conversion_influenced', 'rate', $start, $end );
	}

	// --- Section 4: Paid reader conversion ------------------------------
	//
	// Each rate dispatches the BQ "attempt" query for the intent + direction;
	// the hub returns hub-computed rates and revenue directly from BigQuery.
	// The BQ-side `action_type` filter on the hub already scopes attempts to
	// the right product intent (donation vs subscription).
	//
	// v1 simplification: we use attempts as the denominator (not impressions).
	// The spec acknowledges this as a known trade-off (see formulas-doc
	// Section 4). The follow-up is a separate impression-count query; not
	// blocking for this task.

	/**
	 * Donation conversion rate, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_donation_conversion_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		// NPPD-1685/1745: rate = prompt-attributed donation conversions (Woo order
		// meta, anonymous-inclusive) ÷ donation-intent prompt impressions (hub,
		// anonymous-inclusive — `prompts_performance_by_prompt`). Numerator is local,
		// denominator is hub, so this card is 'hybrid' (see METRIC_SOURCES): a hub
		// impressions failure makes the rate genuinely uncomputable and counts toward
		// the tab-error banner.
		if ( ! $this->woocommerce_active() ) {
			// Non-WC publisher (third-party donations): no local conversions to count.
			// Empty state, not a fake 0% (NPPD-1737/1738 Option A scoping).
			return $this->populated_scalar( 0.0, false, 0, 'rate' );
		}

		$impressions = $this->fetch_donation_prompt_impressions( $start, $end );
		if ( is_wp_error( $impressions ) ) {
			return $this->error_scalar( 'rate', $impressions );
		}

		// NPPD-1817: restrict the numerator to donation-CAPABLE popups (per-popup
		// `donation_impressions` > 0) so it shares the capable-impressions denominator's
		// population and reconciles with the per-prompt table, which credits a donation
		// conversion only to a capable row. A converting-but-not-capable popup is excluded
		// here; its conversion still counts in the count + revenue cards. When the hub
		// hasn't shipped `donation_impressions`, the map is null and we keep counting all
		// attributed conversions, pairing with the intent-sum fallback denominator.
		$capability_impressions = $this->fetch_capability_impressions_by_popup( 'donation_impressions', $start, $end );
		if ( is_wp_error( $capability_impressions ) ) {
			return $this->error_scalar( 'rate', $capability_impressions );
		}
		// `null` (column absent) → count all attributed conversions (pre-column behavior,
		// paired with the intent-sum fallback denominator); an array → restrict to donation-
		// capable popups.
		$by_popup    = $this->donors_metric()->get_prompt_attributed_donation_conversions( $start, $end );
		$conversions = 0;
		foreach ( $by_popup as $popup_id => $row ) {
			if ( ! is_array( $capability_impressions ) || (int) ( $capability_impressions[ (string) $popup_id ] ?? 0 ) > 0 ) {
				$conversions += (int) $row['conversions'];
			}
		}

		// Shared coherence guard + em-dash semantics (also drives the per-prompt
		// donation_conversion_rate, so the two rates can't diverge). null = not
		// computable (no impressions, or the >100% cross-surface case); a float
		// (incl. a genuine 0.0) = a real rate.
		$rate = $this->rate_value( $conversions, $impressions );
		return null === $rate
			? $this->populated_scalar( 0.0, false, $impressions, 'rate' )
			: $this->populated_scalar( $rate, true, $impressions, 'rate' );
	}

	/**
	 * Coherence-guarded conversion-rate value, shared by the direct donation rate
	 * (NPPD-1745) and the direct subscription rate (NPPD-1746) so a tab-level card
	 * and its per-prompt table row route through one helper. The numerator is always
	 * an order-meta (local, anonymous-inclusive) conversion count and the denominator
	 * an anonymous-inclusive hub impressions count for the SAME population (the
	 * donation-intent prompts, or — per-popup keyed for subscriptions — the popups
	 * that converted). The tab card and its per-row table value still differ by design
	 * (aggregate vs per-prompt inputs), but neither can drift to a different RULE —
	 * e.g. one rendering a >100% rate the other suppresses — which is the divergence
	 * that matters.
	 *
	 * Returns the rate as a float — a genuine 0.0 when there are impressions but no
	 * conversions ("viewed but no conversion") — or null when not computable:
	 *  - no impressions → no denominator (em-dash); or
	 *  - conversions > impressions → a cross-surface incoherence (order-meta
	 *    numerator vs hub impressions) that must not render as a >100% rate.
	 *
	 * KNOWN LIMITATION (NPPD-1745 #2): the numerator is complete + server-side, but the
	 * hub impressions denominator is consent/adblock-undercounted — different
	 * populations, so the denominator can legitimately be the smaller of the two. The
	 * guard then nulls the rate to an em-dash, which is the honest render (a fabricated
	 * >100% would be worse), but it means the headline rate is fragile by construction
	 * when impressions run low. Same denominator-fidelity issue as the gate
	 * `checkout_impressions` case; a more complete impressions source is a tracked
	 * follow-up.
	 *
	 * @param int $conversions Prompt-attributed conversions (order meta).
	 * @param int $impressions Matched-population prompt impressions (hub).
	 * @return float|null
	 */
	private function rate_value( int $conversions, int $impressions ): ?float {
		if ( $impressions <= 0 ) {
			return null;
		}
		if ( $conversions > $impressions ) {
			return null;
		}
		return (float) $conversions / $impressions;
	}

	/**
	 * Total donation-intent prompt impressions in the window, summed from the hub's
	 * `prompts_performance_by_prompt` per-popup rows (NPPD-1745). Anonymous-inclusive
	 * (impressions are `np_prompt_interaction(seen)` counts, no identity filter), so
	 * reusing it as the conversion-rate denominator does not reintroduce the
	 * anonymous-at-attempt bias the order-meta numerator escapes.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return int|\WP_Error Total donation-intent impressions, or the proxy error.
	 */
	private function fetch_donation_prompt_impressions( DateTimeInterface $start, DateTimeInterface $end ) {
		$rows = $this->fetch_performance_by_prompt_rows( $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		if ( ! is_array( $rows ) ) {
			// A successful-but-malformed hub response (not an array of rows) is a data
			// fault, not "0 impressions". The sibling per-prompt table surfaces it as
			// malformed_collection; mirror that here so the hybrid rate card errors
			// (counting toward the tab-error banner) instead of silently rendering an
			// em-dash off a fabricated 0 denominator (NPPD-1745 review #3).
			return new \WP_Error( 'bigquery_proxy_malformed_rows', __( 'The query returned an unexpected shape.', 'newspack-plugin' ) );
		}
		$impressions = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( isset( $row['donation_impressions'] ) ) {
				// NPPD-1756/1757: per-popup donation-CAPABLE impressions — seen events where
				// the prompt carried a donate block (`prompt_has_donation`) — summed across
				// all popups. Prefer this when the hub exposes it: it's the population matched
				// to the order-meta donation numerator, and it catches a multi-block prompt
				// that has a donate block but a different primary intent.
				$impressions += (int) $row['donation_impressions'];
			} elseif ( 'donation' === (string) ( $row['intent'] ?? '' ) ) {
				// Fallback until the hub ships `donation_impressions`: donation-INTENT
				// impressions. Misses the multi-block-donate-prompt case above (where
				// `action_type` collapses to 'undefined'); the capability column closes it.
				$impressions += (int) ( $row['impressions'] ?? 0 );
			}
		}
		return $impressions;
	}

	/**
	 * Total subscription-capable (checkout) prompt impressions in the window, summed
	 * from the hub's `prompts_performance_by_prompt` per-popup `checkout_impressions`
	 * column (NPPD-1759) — seen events on prompts carrying a checkout-button block.
	 * The subscription analog of {@see self::fetch_donation_prompt_impressions()},
	 * anonymous-inclusive, so reusing it as the conversion-rate denominator does not
	 * reintroduce the anonymous-at-attempt bias the order-meta numerator escapes.
	 *
	 * Returns `null` (not 0) when the hub hasn't shipped the column yet, so the caller
	 * falls back to today's per-popup-keyed denominator. (The donation sibling has no
	 * such tri-state because its fallback is an inline intent sum; subscription's
	 * fallback is a different per-popup-keyed computation, so it's signalled here.)
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return int|null|\WP_Error Total checkout-capable impressions, null if the hub
	 *                            column is absent, or the proxy error.
	 */
	private function fetch_subscription_prompt_impressions( DateTimeInterface $start, DateTimeInterface $end ) {
		$rows = $this->fetch_performance_by_prompt_rows( $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		if ( ! is_array( $rows ) ) {
			// Malformed hub response (not an array of rows): surface it so the rate
			// errors instead of silently dividing by a fabricated 0 denominator
			// (mirrors fetch_donation_prompt_impressions, NPPD-1745 #3).
			return new \WP_Error( 'bigquery_proxy_malformed_rows', __( 'The query returned an unexpected shape.', 'newspack-plugin' ) );
		}
		$has_column  = false;
		$impressions = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( isset( $row['checkout_impressions'] ) ) {
				// NPPD-1759: per-popup checkout-CAPABLE impressions — seen events where the
				// prompt carried a checkout-button block (`prompt_has_checkout`) — summed
				// across all popups. The population matched to the order-meta subscription
				// numerator, and it catches a multi-block prompt whose primary intent
				// collapsed to 'undefined'.
				$has_column   = true;
				$impressions += (int) $row['checkout_impressions'];
			}
		}
		return $has_column ? $impressions : null;
	}

	/**
	 * Per-popup capability-impressions map for a capability column
	 * (`donation_impressions` / `checkout_impressions`) on the hub's
	 * `prompts_performance_by_prompt` rows, keyed by popup id (string).
	 *
	 * Used to capability-RESTRICT the Direct conversion-rate numerators (NPPD-1817):
	 * a popup is capability-capable when its column value is > 0, so the rate's
	 * numerator (conversions) describes the same population as its capable-impressions
	 * denominator — and reconciles with the per-prompt table, which already credits a
	 * conversion only to a capable row. Without this, a converting-but-not-capable
	 * popup inflates the scalar numerator over a denominator that excludes its
	 * impressions (the NPPD-1746 scalar/table divergence class).
	 *
	 * Returns `null` when NO row carries the column (the hub hasn't shipped it yet), so
	 * the caller keeps its pre-column numerator behavior. The map includes every row
	 * that carries the column; the capable subset is the entries with value > 0.
	 *
	 * @param string            $column Capability column name.
	 * @param DateTimeInterface $start  Window start.
	 * @param DateTimeInterface $end    Window end.
	 * @return array<string, int>|null|\WP_Error Map, null if the column is absent, or the proxy error.
	 */
	private function fetch_capability_impressions_by_popup( string $column, DateTimeInterface $start, DateTimeInterface $end ) {
		$rows = $this->fetch_performance_by_prompt_rows( $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		if ( ! is_array( $rows ) ) {
			return new \WP_Error( 'bigquery_proxy_malformed_rows', __( 'The query returned an unexpected shape.', 'newspack-plugin' ) );
		}
		$map = null;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row[ $column ] ) ) {
				continue;
			}
			if ( null === $map ) {
				$map = [];
			}
			$popup_id = (string) ( $row['popup_id'] ?? '' );
			if ( '' === $popup_id ) {
				continue;
			}
			$map[ $popup_id ] = (int) $row[ $column ];
		}
		return $map;
	}

	/**
	 * Per-popup impressions map from the hub's `prompts_performance_by_prompt` rows
	 * (NPPD-1746), keyed by popup id (string). Used as the per-popup-keyed
	 * denominator source for the direct subscription rate: subscription-intent
	 * popups share `action_type=registration` with pure-registration popups, so the
	 * subscription rate restricts its denominator to the impressions of the SAME
	 * popups that converted (see {@see self::get_subscription_conversion_direct()})
	 * rather than summing a tab-level intent bucket. Impressions are anonymous-
	 * inclusive `np_prompt_interaction(seen)` counts.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array<string, int>|\WP_Error popup_id => impressions, or the proxy error.
	 */
	private function fetch_prompt_impressions_by_popup( DateTimeInterface $start, DateTimeInterface $end ) {
		$rows = $this->fetch_performance_by_prompt_rows( $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		if ( ! is_array( $rows ) ) {
			// Malformed hub response (not an array of rows): surface it so the
			// subscription rate errors instead of silently dividing by a fabricated 0
			// denominator (NPPD-1745 #3, mirrored proactively to the subscription rate).
			return new \WP_Error( 'bigquery_proxy_malformed_rows', __( 'The query returned an unexpected shape.', 'newspack-plugin' ) );
		}
		$map = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$popup_id = (string) ( $row['popup_id'] ?? '' );
			if ( '' === $popup_id || '0' === $popup_id ) {
				continue;
			}
			$map[ $popup_id ] = (int) ( $row['impressions'] ?? 0 );
		}
		return $map;
	}

	/**
	 * Fetch (memoized per window) the `prompts_performance_by_prompt` hub rows, so
	 * the rate-denominator and the per-prompt table share one round-trip per request
	 * (NPPD-1745).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array|\WP_Error Rows, or the proxy error.
	 */
	private function fetch_performance_by_prompt_rows( DateTimeInterface $start, DateTimeInterface $end ) {
		$utc       = new \DateTimeZone( 'UTC' );
		$cache_key = \DateTimeImmutable::createFromInterface( $start )->setTimezone( $utc )->format( 'Ymd' )
			. '|'
			. \DateTimeImmutable::createFromInterface( $end )->setTimezone( $utc )->format( 'Ymd' );
		if ( ! array_key_exists( $cache_key, $this->performance_by_prompt_cache ) ) {
			$this->performance_by_prompt_cache[ $cache_key ] = $this->proxy->query( 'prompts_performance_by_prompt', $start, $end );
		}
		return $this->performance_by_prompt_cache[ $cache_key ];
	}

	/**
	 * Donation conversion rate, influenced (14-day lookback), hub-internal (NPPD-1822).
	 *
	 * The hub builder returns ONE row with the pre-computed rate, integer denominator,
	 * and influenced revenue for `prompts_donation_conversion_influenced_14d`. The rate
	 * is fully BQ-internal — no Woo join required on the consumer.
	 *
	 * @see self::compute_influenced_rate_from_proxy()
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_donation_conversion_influenced_14d( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_influenced_rate_from_proxy( 'prompts_donation_conversion_influenced_14d', 'donation_conversion_influenced_rate', 'conversion_denominator', $start, $end );
	}

	/**
	 * Subscription conversion rate, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_subscription_conversion_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		// NPPD-1759: rate = popup-attributed subscription conversions (Woo order meta,
		// anonymous-inclusive) ÷ checkout-CAPABLE prompt impressions (hub
		// `checkout_impressions` = seen events on prompts carrying a checkout-button
		// block). This is the subscription analog of the donation rate-direct and the
		// gates paywall rate: a tab-level capability denominator, NOT per-popup keyed.
		// The per-popup keying was the NPPD-1746 interim workaround — without a
		// checkout-capability signal, subscription-intent popups shared
		// `action_type=registration` with pure registration popups, so the denominator
		// had to be restricted to the converting popups to keep both sides describing
		// the same population. The capability column makes that unnecessary: it already
		// isolates the checkout-capable impression subset. Forward-compatible — prefer
		// the column, fall back to today's per-popup keying until the hub ships it.
		// Numerator local, denominator hub → 'hybrid' (see METRIC_SOURCES): a hub
		// impressions failure makes the rate genuinely uncomputable and counts toward
		// the tab-error banner. Same coherence guard as the donation rate-direct.
		if ( ! $this->woocommerce_active() ) {
			// Non-WC publisher: no local conversions to count. Empty state, not a fake
			// 0% (NPPD-1737 Option A scoping).
			return $this->populated_scalar( 0.0, false, 0, 'rate' );
		}

		$by_popup             = $this->subscribers_metric()->get_attributed_subscription_conversions( $start, $end )['by_popup'];
		$checkout_impressions = $this->fetch_subscription_prompt_impressions( $start, $end );
		if ( is_wp_error( $checkout_impressions ) ) {
			return $this->error_scalar( 'rate', $checkout_impressions );
		}

		if ( null !== $checkout_impressions ) {
			// Capability denominator (preferred): checkout-capable conversions ÷ total
			// checkout-capable impressions. NPPD-1817: the numerator is restricted to
			// checkout-CAPABLE popups (per-popup `checkout_impressions` > 0) so it shares
			// the denominator's population and reconciles with the per-prompt table, which
			// credits a subscription conversion only to a capable row. A converting-but-
			// not-capable popup is excluded here; its conversion still counts in the
			// count + revenue cards (those sum all attributed conversions).
			$capability_impressions = $this->fetch_capability_impressions_by_popup( 'checkout_impressions', $start, $end );
			if ( is_wp_error( $capability_impressions ) ) {
				return $this->error_scalar( 'rate', $capability_impressions );
			}
			$conversions = 0;
			foreach ( $by_popup as $popup_id => $row ) {
				if ( ! is_array( $capability_impressions ) || (int) ( $capability_impressions[ (string) $popup_id ] ?? 0 ) > 0 ) {
					$conversions += (int) $row['conversions'];
				}
			}
			$impressions = $checkout_impressions;
		} else {
			// Forward-compat fallback (hub hasn't shipped `checkout_impressions` yet):
			// today's per-popup-keyed denominator — restrict to the impressions of the
			// popups that actually converted (NPPD-1746 interim).
			$impressions_by_popup = $this->fetch_prompt_impressions_by_popup( $start, $end );
			if ( is_wp_error( $impressions_by_popup ) ) {
				return $this->error_scalar( 'rate', $impressions_by_popup );
			}
			$conversions = 0;
			$impressions = 0;
			foreach ( $by_popup as $popup_id => $row ) {
				$conversions += (int) $row['conversions'];
				$impressions += (int) ( $impressions_by_popup[ (string) $popup_id ] ?? 0 );
			}
		}

		$rate = $this->rate_value( $conversions, $impressions );
		return null === $rate
			? $this->populated_scalar( 0.0, false, $impressions, 'rate' )
			: $this->populated_scalar( $rate, true, $impressions, 'rate' );
	}

	/**
	 * Subscription conversion rate, influenced (14-day lookback), hub-internal (NPPD-1822).
	 *
	 * Subscription sibling of {@see self::get_donation_conversion_influenced_14d()}.
	 * The hub builder returns ONE row with the pre-computed rate, integer denominator,
	 * and influenced revenue for `prompts_subscription_conversion_influenced_14d`.
	 * The rate is fully BQ-internal — no Woo join required on the consumer.
	 *
	 * @see self::compute_influenced_rate_from_proxy()
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_subscription_conversion_influenced_14d( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_influenced_rate_from_proxy( 'prompts_subscription_conversion_influenced_14d', 'subscription_conversion_influenced_rate', 'conversion_denominator', $start, $end );
	}

	// --- Section 5: Revenue from prompts --------------------------------
	//
	// Each revenue method dispatches the underlying CONVERSION query name (not
	// the byte-identical hub `*_revenue_*` alias) so the per-window cache is
	// shared with the matching rate method — both can be computed from one
	// proxy round-trip when they appear in the same response.

	/**
	 * Total donation revenue from prompts, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_donation_revenue_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		// NPPD-1685: source DIRECT donation revenue from order meta
		// (`_newspack_popup_id` on initial donation orders) instead of the GA4
		// attempt → customer_id join, which silently dropped anonymous-at-attempt
		// donors (~100% non-joinable). Order meta carries a real customer_id +
		// total and is anonymous-inclusive. Influenced (14d) revenue still uses the
		// join — see get_donation_revenue_influenced_14d().
		if ( ! $this->woocommerce_active() ) {
			// Non-WC publisher (e.g. third-party donation platform): no orders to
			// read. Empty state, not a real $0 (NPPD-1737/1738 Option A scoping).
			return $this->populated_scalar( 0.0, false, 0, 'currency' );
		}

		$by_popup    = $this->donors_metric()->get_prompt_attributed_donation_conversions( $start, $end );
		$revenue     = 0.0;
		$conversions = 0;
		foreach ( $by_popup as $row ) {
			$revenue     += (float) $row['revenue'];
			$conversions += (int) $row['conversions'];
		}

		// Computable when there was prompt-attributed donation activity in the
		// window. With the order-meta source there are no GA4 "attempt" rows to gate
		// on, so the gate is now `conversions > 0` rather than "≥1 prompt viewed".
		// NOTE (NPPD-1745): this card is intentionally local / hub-resilient, so its
		// em-dash is conversions-based ("no prompt-attributed donations"), NOT
		// impressions-based. The —/0% impressions distinction lives only on the
		// sibling rate card (which is hybrid). Revenue does not fetch impressions by
		// design, so the two cards' em-dash semantics differ on purpose — do not
		// "fix" this to match the rate card without making revenue hub-dependent.
		return $this->populated_scalar( $revenue, $conversions > 0, $conversions, 'currency' );
	}

	/**
	 * Total donation revenue from prompts, influenced (14-day lookback), hub-internal.
	 *
	 * Reads `influenced_revenue` from the same ONE-row response as the rate
	 * ({@see self::get_donation_conversion_influenced_14d()}). The revenue is
	 * fully BQ-internal — no Woo join required on the consumer.
	 *
	 * @see self::compute_influenced_revenue_from_proxy()
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_donation_revenue_influenced_14d( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_influenced_revenue_from_proxy( 'prompts_donation_conversion_influenced_14d', 'influenced_revenue', $start, $end );
	}

	/**
	 * Total subscription revenue from prompts, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_subscription_revenue_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		// NPPD-1746: source DIRECT subscription revenue from order meta (popup
		// surface — `_newspack_popup_id` on initial subscription orders) instead of
		// the GA4 attempt → customer_id join, which silently dropped anonymous-at-
		// attempt subscribers. Mirrors donation revenue-direct (NPPD-1685/1745).
		// Influenced (14d) revenue still uses the join — see
		// get_subscription_revenue_influenced_14d().
		if ( ! $this->woocommerce_active() ) {
			// Non-WC publisher: no orders to read. Empty state, not a real $0
			// (NPPD-1737 Option A scoping).
			return $this->populated_scalar( 0.0, false, 0, 'currency' );
		}

		$by_popup    = $this->subscribers_metric()->get_attributed_subscription_conversions( $start, $end )['by_popup'];
		$revenue     = 0.0;
		$conversions = 0;
		foreach ( $by_popup as $row ) {
			$revenue     += (float) $row['revenue'];
			$conversions += (int) $row['conversions'];
		}

		// Local / hub-resilient, like donation revenue-direct: the em-dash is
		// conversions-based ("no popup-attributed subscriptions"), NOT impressions-
		// based — revenue does not fetch impressions by design, so its em-dash
		// semantics differ on purpose from the sibling (hybrid) rate card. Do not
		// "fix" this to match the rate card without making revenue hub-dependent.
		return $this->populated_scalar( $revenue, $conversions > 0, $conversions, 'currency' );
	}

	/**
	 * Total subscription revenue from prompts, influenced (14-day lookback), hub-internal.
	 *
	 * Reads `influenced_revenue` from the same ONE-row response as the rate
	 * ({@see self::get_subscription_conversion_influenced_14d()}). The revenue is
	 * fully BQ-internal — no Woo join required on the consumer.
	 *
	 * @see self::compute_influenced_revenue_from_proxy()
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_subscription_revenue_influenced_14d( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_influenced_revenue_from_proxy( 'prompts_subscription_conversion_influenced_14d', 'influenced_revenue', $start, $end );
	}

	// --- Section 6: How readers convert ---------------------------------

	/**
	 * Conversion funnel — three stages (impression → engagement → conversion).
	 *
	 * Dispatches `prompts_funnel` and normalizes the single-row response into a
	 * stage list with each stage's count and proportion-of-top-stage.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{
	 *   state: string,
	 *   stages: array<int, array{label: string, count: int, pct_of_top: float}>,
	 *   error_code?: string,
	 *   error_message?: string
	 * }
	 */
	public function get_conversion_funnel( DateTimeInterface $start, DateTimeInterface $end ): array {
		$rows = $this->proxy->query( 'prompts_funnel', $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_collection( 'stages', $rows );
		}
		if ( ! is_array( $rows ) || ( ! empty( $rows ) && ! is_array( $rows[0] ) ) ) {
			// Successful response in an unexpected shape — a data-quality bug,
			// not a legitimately empty window. Surface it as an error.
			return $this->malformed_collection( 'stages' );
		}
		if ( empty( $rows ) ) {
			return [
				'state'  => 'empty',
				'stages' => [],
			];
		}
		$row   = $rows[0];
		$step1 = (int) ( $row['step_1_impression'] ?? 0 );
		$step2 = (int) ( $row['step_2_engagement'] ?? 0 );
		$step3 = (int) ( $row['step_3_conversion'] ?? 0 );
		$top   = $step1 > 0 ? $step1 : 1; // Avoid div-by-zero in pct_of_top.
		return [
			'state'  => 'populated',
			'stages' => [
				[
					'label'      => __( 'Impression', 'newspack-plugin' ),
					'count'      => $step1,
					'pct_of_top' => 1.0,
				],
				[
					'label'      => __( 'Engagement', 'newspack-plugin' ),
					'count'      => $step2,
					'pct_of_top' => $step2 / $top,
				],
				[
					'label'      => __( 'Conversion', 'newspack-plugin' ),
					'count'      => $step3,
					'pct_of_top' => $step3 / $top,
				],
			],
		];
	}

	/**
	 * Exposures-before-conversion distribution buckets.
	 *
	 * Dispatches `prompts_exposures_before_conversion`. The hub emits one row
	 * per non-zero bucket; we always render the full ordered set so missing
	 * buckets show as zero rather than disappearing.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{
	 *   state: string,
	 *   buckets: array<int, array{label: string, count: int, pct: float}>,
	 *   error_code?: string,
	 *   error_message?: string
	 * }
	 */
	public function get_exposures_distribution( DateTimeInterface $start, DateTimeInterface $end ): array {
		$rows = $this->proxy->query( 'prompts_exposures_before_conversion', $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_collection( 'buckets', $rows );
		}
		if ( ! is_array( $rows ) ) {
			return $this->malformed_collection( 'buckets' );
		}

		$by_bucket = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				// Hub returned a row in an unexpected shape — a data-quality bug,
				// not a legitimately empty bucket. Surface as malformed so the
				// regression isn't masked as missing buckets.
				return $this->malformed_collection( 'buckets' );
			}
			if ( isset( $row['bucket'] ) ) {
				$by_bucket[ $row['bucket'] ] = [
					'count' => (int) ( $row['converters_in_bucket'] ?? 0 ),
					'pct'   => (float) ( $row['pct_of_converters'] ?? 0.0 ),
				];
			}
		}

		if ( empty( $by_bucket ) ) {
			return [
				'state'   => 'empty',
				'buckets' => [],
			];
		}

		$ordered_keys   = [ '1', '2', '3-5', '6+' ];
		$ordered_labels = [
			'1'   => __( '1 exposure', 'newspack-plugin' ),
			'2'   => __( '2 exposures', 'newspack-plugin' ),
			'3-5' => __( '3–5 exposures', 'newspack-plugin' ),
			'6+'  => __( '6+ exposures', 'newspack-plugin' ),
		];
		$buckets        = [];
		foreach ( $ordered_keys as $key ) {
			$buckets[] = [
				'label' => $ordered_labels[ $key ],
				'count' => $by_bucket[ $key ]['count'] ?? 0,
				'pct'   => $by_bucket[ $key ]['pct'] ?? 0.0,
			];
		}
		return [
			'state'   => 'populated',
			'buckets' => $buckets,
		];
	}

	// --- Section 7: Performance breakdown -------------------------------

	/**
	 * Per-prompt breakdown, augmented with per-popup Woo-completed donation and
	 * subscription counts (intent-scoped: donation_conversions are non-zero
	 * only for `intent === 'donation'` rows; subscription_conversions only for
	 * `intent === 'registration'` rows — subscription-intent prompts share the
	 * registration `action_type` in the data model, see spec §"Subscription-
	 * intent vs registration-intent prompts").
	 *
	 * The Woo augmentation degrades gracefully: if either conversion-attempt
	 * query fails, the matching column renders as 0 conversions / null rate
	 * (React renders null as the em-dash) rather than blanking the whole table.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{state: string, rows: array, error_code?: string, error_message?: string}
	 */
	public function get_performance_by_prompt( DateTimeInterface $start, DateTimeInterface $end ): array {
		$rows = $this->fetch_performance_by_prompt_rows( $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_collection( 'rows', $rows );
		}
		if ( ! is_array( $rows ) ) {
			return $this->malformed_collection( 'rows' );
		}
		if ( empty( $rows ) ) {
			return [
				'state' => 'empty',
				'rows'  => [],
			];
		}

		// Per-popup conversion augmentation. Both donations (NPPD-1685/1745) and
		// subscriptions (NPPD-1746) now come from order meta — anonymous-inclusive,
		// keyed by popup id, no GA4 join. Subscriptions read the popup surface of the
		// two-key reader (`by_popup`); gate-attributed subscriptions live on the Gates
		// tab, not here. Both are gated on WooCommerce being active (the storage
		// readers hit local Woo tables), so both maps are empty on a non-WC publisher.
		$wc               = $this->woocommerce_active();
		$donation_map     = $wc ? $this->donors_metric()->get_prompt_attributed_donation_conversions( $start, $end ) : [];
		$subscription_map = $wc ? $this->subscribers_metric()->get_attributed_subscription_conversions( $start, $end )['by_popup'] : [];

		$mapped = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				// Hub returned a row in an unexpected shape — a data-quality bug,
				// not a legitimately empty table. Surface as malformed so the
				// regression isn't masked as missing prompts.
				return $this->malformed_collection( 'rows' );
			}
			$popup_id    = (int) ( $row['popup_id'] ?? 0 );
			$intent      = (string) ( $row['intent'] ?? '' );
			$impressions = (int) ( $row['impressions'] ?? 0 );

			// Subscription is CAPABILITY-gated (NPPD-1759), symmetric with the donation
			// column below and the gates paywall column: a prompt is subscription-capable
			// when its per-popup `checkout_impressions` (seen events carrying a checkout-
			// button block, NPPD-1755 emission / NPPD-1759 hub column) is non-zero. This
			// catches an "Undefined"-intent
			// "50% off" promo that drives subscriptions — the order carries the popup id
			// regardless of `action_type`, which collapses to 'undefined' for a multi-block
			// prompt. Until the hub exposes the column, fall back to today's behavior — map
			// membership (a popup that actually converted). The rate denominator is those
			// checkout-capable impressions, else total impressions.
			$checkout_impressions     = (int) ( $row['checkout_impressions'] ?? $impressions );
			$subscription_capable     = isset( $row['checkout_impressions'] )
				? $checkout_impressions > 0
				: isset( $subscription_map[ $popup_id ] );
			$subscription_conversions = $subscription_capable ? (int) ( $subscription_map[ $popup_id ]['conversions'] ?? 0 ) : 0;

			// Donation is CAPABILITY-gated: a prompt is donation-capable when its per-popup
			// `donation_impressions` (seen events carrying a donate block) is non-zero — or,
			// until the hub exposes that column (NPPD-1756), when `action_type` is 'donation'
			// (the legacy single-block signal). The capability column additionally catches a
			// multi-block donate prompt whose primary intent collapsed to 'undefined'. The
			// rate denominator is those donation-capable impressions, else total impressions.
			$donation_impressions = (int) ( $row['donation_impressions'] ?? $impressions );
			$donation_capable     = isset( $row['donation_impressions'] )
				? $donation_impressions > 0
				: 'donation' === $intent;
			$donation_conversions = $donation_capable ? (int) ( $donation_map[ $popup_id ]['conversions'] ?? 0 ) : 0;

			// Both rates share the coherence guard + em-dash semantics via rate_value() (null =
			// not computable: no impressions, or a >100% cross-surface ratio; a float incl. 0.0
			// = a real rate, e.g. a donation-capable prompt that converted nobody). null = N/A
			// for a prompt the metric doesn't apply to, and on non-WC.
			$mapped[] = [
				'popup_id'                     => $popup_id,
				'prompt_title'                 => (string) ( $row['prompt_title'] ?? '' ),
				'intent'                       => $intent,
				// Friendly display label (NPPD-1758), single source of truth shared with
				// the per-intent table. Null when the intent has no friendly override, so
				// the frontend falls back to its humanizer (preserving title-casing).
				'intent_label'                 => self::INTENT_LABELS[ $intent ] ?? null,
				'placement'                    => (string) ( $row['placement'] ?? '' ),
				'impressions'                  => $impressions,
				'unique_viewers'               => (int) ( $row['unique_viewers'] ?? 0 ),
				'ctr'                          => isset( $row['ctr'] ) && null !== $row['ctr'] ? (float) $row['ctr'] : null,
				'form_submission_rate'         => isset( $row['form_submission_rate'] ) && null !== $row['form_submission_rate'] ? (float) $row['form_submission_rate'] : null,
				'dismissal_rate'               => isset( $row['dismissal_rate'] ) && null !== $row['dismissal_rate'] ? (float) $row['dismissal_rate'] : null,
				'registrations'                => (int) ( $row['registrations'] ?? 0 ),
				'newsletter_signups'           => (int) ( $row['newsletter_signups'] ?? 0 ),
				'donation_conversions'         => $donation_conversions,
				'donation_conversion_rate'     => ( $wc && $donation_capable ) ? $this->rate_value( $donation_conversions, $donation_impressions ) : null,
				'subscription_conversions'     => $subscription_conversions,
				'subscription_conversion_rate' => ( $wc && $subscription_capable ) ? $this->rate_value( $subscription_conversions, $checkout_impressions ) : null,
			];
		}

		return [
			'state' => 'populated',
			'rows'  => $mapped,
		];
	}

	/**
	 * Per-intent breakdown (donation / registration / newsletter signup).
	 *
	 * Pure BQ → row mapping; no Woo or WP enrichment. Friendly labels come from
	 * {@see self::INTENT_LABELS} as `intent_label`; an unknown `intent` yields a
	 * null label (the raw `intent` is still returned alongside it, so a hub-side
	 * catalog change isn't silently swallowed — the frontend humanizes it).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{state: string, rows: array, error_code?: string, error_message?: string}
	 */
	public function get_performance_by_intent( DateTimeInterface $start, DateTimeInterface $end ): array {
		$rows = $this->proxy->query( 'prompts_performance_by_intent', $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_collection( 'rows', $rows );
		}
		if ( ! is_array( $rows ) ) {
			return $this->malformed_collection( 'rows' );
		}
		if ( empty( $rows ) ) {
			return [
				'state' => 'empty',
				'rows'  => [],
			];
		}

		$mapped = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				// Hub returned a row in an unexpected shape — surface as malformed.
				return $this->malformed_collection( 'rows' );
			}
			$intent   = (string) ( $row['intent'] ?? '' );
			$mapped[] = [
				'intent'               => $intent,
				// Null when no friendly override exists, so the frontend humanizer fills
				// in (preserving title-casing); mapped intents use the label (NPPD-1758).
				'intent_label'         => self::INTENT_LABELS[ $intent ] ?? null,
				'impressions'          => (int) ( $row['impressions'] ?? 0 ),
				'unique_viewers'       => (int) ( $row['unique_viewers'] ?? 0 ),
				'ctr'                  => isset( $row['ctr'] ) && null !== $row['ctr'] ? (float) $row['ctr'] : null,
				'form_submission_rate' => isset( $row['form_submission_rate'] ) && null !== $row['form_submission_rate'] ? (float) $row['form_submission_rate'] : null,
				'dismissal_rate'       => isset( $row['dismissal_rate'] ) && null !== $row['dismissal_rate'] ? (float) $row['dismissal_rate'] : null,
			];
		}

		return [
			'state' => 'populated',
			'rows'  => $mapped,
		];
	}

	/**
	 * Per-placement breakdown (overlay / inline / above-header / etc.).
	 *
	 * Pure BQ → row mapping. Placement labels are humanized inline
	 * (`above-header` → `Above header`); the raw value is also carried through
	 * for any UI that needs to filter on it. This table intentionally has no
	 * `form_submission_rate` column per spec §"Performance by Prompt Placement".
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{state: string, rows: array, error_code?: string, error_message?: string}
	 */
	public function get_performance_by_placement( DateTimeInterface $start, DateTimeInterface $end ): array {
		$rows = $this->proxy->query( 'prompts_performance_by_placement', $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_collection( 'rows', $rows );
		}
		if ( ! is_array( $rows ) ) {
			return $this->malformed_collection( 'rows' );
		}
		if ( empty( $rows ) ) {
			return [
				'state' => 'empty',
				'rows'  => [],
			];
		}

		$mapped = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				// Hub returned a row in an unexpected shape — surface as malformed.
				return $this->malformed_collection( 'rows' );
			}
			$placement = (string) ( $row['placement'] ?? '' );
			$mapped[]  = [
				'placement'       => $placement,
				'placement_label' => '' === $placement ? '' : ucfirst( str_replace( '-', ' ', $placement ) ),
				'impressions'     => (int) ( $row['impressions'] ?? 0 ),
				'unique_viewers'  => (int) ( $row['unique_viewers'] ?? 0 ),
				'ctr'             => isset( $row['ctr'] ) && null !== $row['ctr'] ? (float) $row['ctr'] : null,
				'dismissal_rate'  => isset( $row['dismissal_rate'] ) && null !== $row['dismissal_rate'] ? (float) $row['dismissal_rate'] : null,
			];
		}

		return [
			'state' => 'populated',
			'rows'  => $mapped,
		];
	}
}
