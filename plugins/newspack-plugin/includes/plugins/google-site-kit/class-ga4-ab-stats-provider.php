<?php
/**
 * A/B test counts from GA4, through Site Kit.
 *
 * Newspack Campaigns asks one stats provider for raw per-variant counts and
 * computes rates and confidence itself. This provider answers from the GA4
 * Data API, using the events Newspack already sends and the custom
 * dimensions it already registers, so a site needs nothing beyond a Site Kit
 * Analytics connection.
 *
 * @package Newspack
 */

namespace Newspack;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The GA4 stats provider for Campaigns A/B tests.
 *
 * Requests and responses are plain arrays in the Data API's JSON shape. The
 * runner, injected or Site Kit's, is the only place that touches Site Kit's
 * client classes, which keeps the request builders testable without Site Kit
 * installed and lets a test hand in canned responses.
 */
final class GA4_AB_Stats_Provider implements \Newspack_Popups_AB_Stats_Provider {

	const SLUG                  = 'ga4-site-kit';
	const ROW_LIMIT             = 100;
	const LOGGER_HEADER         = 'NEWSPACK-GA4-AB-STATS';

	/**
	 * Site Kit's Analytics settings option. Read by name because this class
	 * must answer whether Site Kit is connected when Site Kit's classes are
	 * not loaded.
	 */
	const SITE_KIT_SETTINGS_OPTION = 'googlesitekit_analytics-4_settings';

	const METRIC_READERS = 'totalUsers';
	const METRIC_EVENTS  = 'eventCount';

	/**
	 * The dimensions the requests use. All are GA4 custom dimensions that
	 * `GA4_Custom_Dimensions` registers when the feature that sends them is
	 * on; the parameter names are the ones the tracking code sends. A report
	 * on a dimension the property lacks fails with the API's own message.
	 */
	const DIM_PROMPT       = 'customEvent:newspack_popup_id';
	const DIM_LOGGED_IN    = 'customEvent:logged_in';
	const DIM_ACTION       = 'customEvent:action';
	const DIM_ACTION_TYPE  = 'customEvent:action_type';
	const DIM_PRODUCT_TYPE = 'customEvent:product_type';

	/**
	 * `logged_in` is sent as the strings `yes` and `no`, see
	 * `GoogleSiteKit::get_custom_event_parameters()`.
	 */
	const LOGGED_IN_VALUE = 'yes';

	const EVENT_PROMPT     = 'np_prompt_interaction';
	const EVENT_REGISTERED = 'np_reader_registered';
	const EVENT_NEWSLETTER = 'np_newsletter_subscribed';
	const EVENT_CHECKOUT   = 'np_modal_checkout_interaction';

	/**
	 * Runs one report: `( string $property_id, array $request )` returning the
	 * response as an array, or a WP_Error. Null means Site Kit's runner.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $runner;

	/**
	 * The Site Kit module bound for the current get_counts() call, so one
	 * test's reports share a client and token reads instead of rebuilding
	 * Site Kit's authentication graph per report.
	 *
	 * @var GoogleSiteKitAnalyticsData|null
	 */
	private ?GoogleSiteKitAnalyticsData $module = null;

	/**
	 * Constructor.
	 *
	 * @param callable|null $runner Report runner; tests inject one.
	 */
	public function __construct( ?callable $runner = null ) {
		$this->runner = $runner ? \Closure::fromCallable( $runner ) : null;
	}

	/**
	 * Provider slug, as the dashboard reports it.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return self::SLUG;
	}

	/**
	 * Whether Site Kit can run reports on this site, without calling out.
	 *
	 * @return true|WP_Error
	 */
	public function is_available(): bool|WP_Error {
		if ( null !== $this->runner ) {
			return true;
		}
		if ( ! defined( 'GOOGLESITEKIT_PLUGIN_MAIN_FILE' ) ) {
			return new WP_Error( 'newspack_ga4_ab_stats', __( 'Site Kit is not installed.', 'newspack' ) );
		}
		if ( ! self::get_property_id() || ! $this->has_credentials() ) {
			return new WP_Error( 'newspack_ga4_ab_stats', __( 'Connect Google Analytics through Site Kit.', 'newspack' ) );
		}
		return true;
	}

	/**
	 * Per-variant readers and conversions for one test and window.
	 *
	 * Readers are distinct users who saw one of the test's prompts. The
	 * logged-in share comes from a second readers report split on the
	 * `logged_in` dimension. Conversions are distinct users who completed the
	 * goal's event on one of the prompts; a goal that maps to two events sums
	 * two reports, so a reader who completed both is counted twice.
	 *
	 * GA4 withholds rows from a user-count report when they could identify
	 * someone, which on a small site can empty a whole report, and a
	 * conversions report is the smallest. When any report comes back flagged
	 * as subject to thresholding, the whole set is rerun counting events
	 * instead and the result says so in `unit`; the split runs in both modes,
	 * so the logged-in share is never a placeholder. At most eight reports
	 * per test. The flag means rows may have been removed, not that they
	 * were, so a flagged event-count pass keeps what came back and the note
	 * says it may be short; an error here would hide complete counts on a
	 * property that flags every report.
	 *
	 * @param array  $test  Test, with `variants` keyed by letter, each carrying a prompt `id`.
	 * @param string $goal  Conversion goal.
	 * @param string $start Window start, Y-m-d.
	 * @param string $end   Window end, Y-m-d.
	 * @return array|WP_Error
	 */
	public function get_counts( array $test, string $goal, string $start, string $end ): array|WP_Error {
		$property_id = self::get_property_id();
		if ( ! $property_id ) {
			return new WP_Error( 'newspack_ga4_ab_stats', __( 'Connect Google Analytics through Site Kit.', 'newspack' ) );
		}

		$letters  = [];
		$variants = [];
		foreach ( $test['variants'] ?? [] as $letter => $variant ) {
			$variants[ $letter ] = [
				'readers'           => 0,
				'conversions'       => 0,
				'logged_in_readers' => 0,
			];
			if ( ! empty( $variant['id'] ) ) {
				$letters[ (string) $variant['id'] ] = $letter;
			}
		}
		$result = [
			'unit'     => 'readers',
			'note'     => __( 'Google Analytics updates these counts once a day.', 'newspack' ),
			'variants' => $variants,
		];
		if ( empty( $letters ) ) {
			return $result;
		}

		$this->module = null;
		$counted      = $this->count( $property_id, $letters, $variants, $goal, $start, $end, self::METRIC_READERS, true );
		if ( null === $counted ) {
			$result['unit'] = 'events';
			$counted        = $this->count( $property_id, $letters, $variants, $goal, $start, $end, self::METRIC_EVENTS, false );
		}
		if ( is_wp_error( $counted ) ) {
			return $counted;
		}
		if ( $counted['flagged'] ) {
			$result['note'] = __( 'Google Analytics updates these counts once a day, and may have left out some low-traffic counts for this test.', 'newspack' );
		}
		$result['variants'] = $counted['variants'];
		return $result;
	}

	/**
	 * One counting pass in one metric: readers, the logged-in split, then the
	 * goal's conversion reports.
	 *
	 * @param string $property_id GA4 property ID.
	 * @param array  $letters     Prompt ID => variant letter.
	 * @param array  $variants    Zero-filled counts per letter.
	 * @param string $goal        Conversion goal.
	 * @param string $start       Window start, Y-m-d.
	 * @param string $end         Window end, Y-m-d.
	 * @param string $metric      METRIC_READERS or METRIC_EVENTS.
	 * @param bool   $stop        Stop at the first report flagged as thresholded and return null.
	 * @return array|WP_Error|null `variants` (counts per letter) and `flagged` (any report was subject to thresholding).
	 */
	private function count( string $property_id, array $letters, array $variants, string $goal, string $start, string $end, string $metric, bool $stop ): array|WP_Error|null {
		$flagged    = false;
		$prompt_ids = array_keys( $letters );
		$requests   = [
			'readers'           => [ self::build_readers_request( $prompt_ids, $start, $end, $metric, false ) ],
			'logged_in_readers' => [ self::build_readers_request( $prompt_ids, $start, $end, $metric, true ) ],
			'conversions'       => self::build_conversion_requests( $goal, $prompt_ids, $start, $end, $metric ),
		];
		foreach ( $requests as $field => $set ) {
			foreach ( $set as $request ) {
				$response = $this->run( $property_id, $request );
				if ( is_wp_error( $response ) ) {
					return $response;
				}
				if ( self::is_thresholded( $response ) ) {
					if ( $stop ) {
						return null;
					}
					$flagged = true;
				}
				$split = 'logged_in_readers' === $field;
				foreach ( self::parse_rows( $response, $split ) as $prompt_id => $row ) {
					if ( isset( $letters[ $prompt_id ] ) ) {
						$variants[ $letters[ $prompt_id ] ][ $field ] += $split ? $row['logged_in'] : $row['count'];
					}
				}
			}
		}
		return [
			'variants' => $variants,
			'flagged'  => $flagged,
		];
	}

	/**
	 * The readers report: distinct users who saw one of the prompts, per prompt.
	 *
	 * @param array  $prompt_ids      Prompt post IDs.
	 * @param string $start           Window start, Y-m-d.
	 * @param string $end             Window end, Y-m-d.
	 * @param string $metric          METRIC_READERS or METRIC_EVENTS.
	 * @param bool   $split_logged_in Add the `logged_in` dimension.
	 * @return array
	 */
	public static function build_readers_request( array $prompt_ids, string $start, string $end, string $metric, bool $split_logged_in ): array {
		$dimensions = [ self::DIM_PROMPT ];
		if ( $split_logged_in ) {
			$dimensions[] = self::DIM_LOGGED_IN;
		}
		return self::build_request(
			$dimensions,
			$metric,
			$start,
			$end,
			[
				self::exact( 'eventName', self::EVENT_PROMPT ),
				self::exact( self::DIM_ACTION, 'seen' ),
				self::in_list( self::DIM_PROMPT, $prompt_ids ),
			]
		);
	}

	/**
	 * The conversion reports for a goal: distinct users who completed the
	 * goal's event on one of the prompts, per prompt.
	 *
	 * A form submission completes as one of three events depending on the
	 * form, so it takes two reports: the registration and newsletter events
	 * share a report, and the checkout form has its own.
	 *
	 * @param string $goal       Conversion goal.
	 * @param array  $prompt_ids Prompt post IDs.
	 * @param string $start      Window start, Y-m-d.
	 * @param string $end        Window end, Y-m-d.
	 * @param string $metric     METRIC_READERS or METRIC_EVENTS.
	 * @return array[] Zero or more requests.
	 */
	public static function build_conversion_requests( string $goal, array $prompt_ids, string $start, string $end, string $metric ): array {
		$checkout_success = [
			self::exact( 'eventName', self::EVENT_CHECKOUT ),
			self::exact( self::DIM_ACTION, 'form_submission_success' ),
		];
		switch ( $goal ) {
			case 'link_click':
				$clauses = [
					[
						self::exact( 'eventName', self::EVENT_PROMPT ),
						self::exact( self::DIM_ACTION, 'clicked' ),
					],
				];
				break;
			case 'form_submission':
				$clauses = [
					[ self::in_list( 'eventName', [ self::EVENT_REGISTERED, self::EVENT_NEWSLETTER ] ) ],
					$checkout_success,
				];
				break;
			case 'donation':
				$clauses = [ array_merge( $checkout_success, [ self::exact( self::DIM_ACTION_TYPE, 'donation' ) ] ) ];
				break;
			case 'subscription':
				$clauses = [
					array_merge(
						$checkout_success,
						[
							self::exact( self::DIM_ACTION_TYPE, 'checkout_button' ),
							self::exact( self::DIM_PRODUCT_TYPE, 'subscription' ),
						]
					),
				];
				break;
			default:
				return [];
		}
		$requests = [];
		foreach ( $clauses as $filters ) {
			$filters[]  = self::in_list( self::DIM_PROMPT, $prompt_ids );
			$requests[] = self::build_request( [ self::DIM_PROMPT ], $metric, $start, $end, $filters );
		}
		return $requests;
	}

	/**
	 * Per-prompt totals from a report response.
	 *
	 * @param array $response        The report response as an array.
	 * @param bool  $split_logged_in Whether the rows carry the `logged_in` dimension second.
	 * @return array `[ '<prompt_id>' => [ 'count' => int, 'logged_in' => int ] ]`.
	 */
	public static function parse_rows( array $response, bool $split_logged_in = false ): array {
		$parsed = [];
		foreach ( $response['rows'] ?? [] as $row ) {
			$dimensions = $row['dimensionValues'] ?? [];
			$prompt_id  = (string) ( $dimensions[0]['value'] ?? '' );
			if ( '' === $prompt_id ) {
				continue;
			}
			$count = (int) ( $row['metricValues'][0]['value'] ?? 0 );
			if ( ! isset( $parsed[ $prompt_id ] ) ) {
				$parsed[ $prompt_id ] = [
					'count'     => 0,
					'logged_in' => 0,
				];
			}
			$parsed[ $prompt_id ]['count'] += $count;
			if ( $split_logged_in && self::LOGGED_IN_VALUE === ( $dimensions[1]['value'] ?? '' ) ) {
				$parsed[ $prompt_id ]['logged_in'] += $count;
			}
		}
		return $parsed;
	}

	/**
	 * Whether GA4 withheld rows from a response to protect small user counts.
	 *
	 * @param array $response The report response as an array.
	 * @return bool
	 */
	public static function is_thresholded( array $response ): bool {
		return ! empty( $response['metadata']['subjectToThresholding'] );
	}

	/**
	 * The connected GA4 property ID from Site Kit's settings, or an empty string.
	 *
	 * @return string
	 */
	public static function get_property_id(): string {
		$settings = get_option( self::SITE_KIT_SETTINGS_OPTION, [] );
		return is_array( $settings ) && ! empty( $settings['propertyID'] ) ? (string) $settings['propertyID'] : '';
	}

	/**
	 * Run one report through the injected runner, or Site Kit.
	 *
	 * @param string $property_id GA4 property ID.
	 * @param array  $request     Report request.
	 * @return array|WP_Error
	 */
	private function run( string $property_id, array $request ): array|WP_Error {
		$response = $this->runner ? ( $this->runner )( $property_id, $request ) : $this->run_through_site_kit( $property_id, $request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return is_array( $response ) ? $response : [];
	}

	/**
	 * Whether any token can run a report, by reporting_user_id()'s rule.
	 * Reads stored state only.
	 *
	 * @return bool
	 */
	private function has_credentials(): bool {
		if ( ! GoogleSiteKit::is_active() ) {
			return false;
		}
		try {
			return self::reporting_user_id() >= 0;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Site Kit's runner: the report runs with the Analytics owner's token, so
	 * every administrator reads the same numbers from the account that
	 * connected the property, and with the current user's own token only
	 * when no owner holds one.
	 *
	 * Lending the owner's token is limited to administrators here rather than
	 * left to the callers' own checks: an administrator can already read the
	 * stored token, and the report is read-only and scoped to the connected
	 * property. The module is bound to the owner through Site Kit's own
	 * user-options seam rather than by switching the request's current user,
	 * so nothing else in the request runs as them.
	 *
	 * Preferring the owner is deliberate, and it has one cost: an owner who
	 * has lost access to the property breaks the page for every
	 * administrator until Site Kit's Analytics owner changes. A stale token
	 * is not that case; Site Kit refreshes it inside the report and drops it
	 * when Google rejects the refresh token, so the next view falls back to
	 * the viewer's own.
	 * The provider assumes a logged-in administrator's request; with no user
	 * set, as in cron, no token is lent and the error below is the answer.
	 *
	 * @param string $property_id GA4 property ID.
	 * @param array  $request     Report request.
	 * @return array|WP_Error
	 */
	private function run_through_site_kit( string $property_id, array $request ): array|WP_Error {
		if ( ! GoogleSiteKit::is_active() ) {
			return new WP_Error( 'newspack_ga4_ab_stats', __( 'Site Kit is not installed.', 'newspack' ) );
		}
		try {
			if ( null === $this->module ) {
				$user_id = self::reporting_user_id();
				if ( $user_id < 0 ) {
					return new WP_Error( 'newspack_ga4_ab_stats', __( 'Connect Google Analytics through Site Kit.', 'newspack' ) );
				}
				$this->module = self::site_kit_module( $user_id );
			}
			return $this->module->run_report( $property_id, $request );
		} catch ( \Throwable $e ) {
			Logger::error( 'GA4 report failed: ' . $e->getMessage(), self::LOGGER_HEADER );
			$reason = self::reason_from( $e );
			return new WP_Error( 'newspack_ga4_ab_stats', '' !== $reason ? $reason : __( 'Google Analytics did not answer.', 'newspack' ) );
		}
	}

	/**
	 * The sentence a failed report leaves for the page.
	 *
	 * Site Kit's Google client throws the raw HTTP error body as the exception
	 * message, pretty-printed JSON whose first line is a brace, so the
	 * `error.message` inside it is what a site administrator can act on. Any
	 * other exception keeps its first line.
	 *
	 * @param \Throwable $e The exception.
	 * @return string Empty when the message carries no sentence.
	 */
	public static function reason_from( \Throwable $e ): string {
		$message = trim( $e->getMessage() );
		if ( '' === $message ) {
			return '';
		}
		$decoded = json_decode( $message, true );
		if ( is_array( $decoded ) ) {
			$reason = $decoded['error']['message'] ?? '';
			return is_string( $reason ) ? trim( $reason ) : '';
		}
		return trim( strtok( $message, "\n" ) );
	}

	/**
	 * Whose token a report runs with: the Analytics owner's when one is
	 * recorded, holds a token, and the current user is the owner or an
	 * administrator; else the current user's own (0 is Site Kit's name for
	 * the current user); else nobody's.
	 *
	 * @return int User ID, 0 for the current user, -1 when no token can be used.
	 */
	private static function reporting_user_id(): int {
		return self::choose_reporting_user( self::owner_id(), self::has_token( ... ) );
	}

	/**
	 * The rule behind reporting_user_id(), with its two reads passed in so
	 * the lending gate can be tested without Site Kit.
	 *
	 * @param int      $owner_id  The Analytics owner, zero when none is recorded.
	 * @param callable $has_token Whether a user ID (zero for the current user) holds a token.
	 * @return int User ID, 0 for the current user, -1 when no token can be used.
	 */
	public static function choose_reporting_user( int $owner_id, callable $has_token ): int {
		if ( $owner_id > 0 && $has_token( $owner_id ) && ( $owner_id === get_current_user_id() || current_user_can( 'manage_options' ) ) ) {
			return $owner_id;
		}
		return $has_token( 0 ) ? 0 : -1;
	}

	/**
	 * The Analytics module's owner, as Site Kit records it.
	 *
	 * @return int Zero when none is recorded.
	 */
	private static function owner_id(): int {
		$settings = get_option( self::SITE_KIT_SETTINGS_OPTION, [] );
		return is_array( $settings ) && ! empty( $settings['ownerID'] ) ? (int) $settings['ownerID'] : 0;
	}

	/**
	 * Whether a user holds a Site Kit token, read from their user options.
	 *
	 * @param int $user_id User ID; zero for the current user.
	 * @return bool
	 */
	private static function has_token( int $user_id ): bool {
		$context = new \Google\Site_Kit\Context( GOOGLESITEKIT_PLUGIN_MAIN_FILE );
		$token   = new \Google\Site_Kit\Core\Authentication\Token( new \Google\Site_Kit\Core\Storage\User_Options( $context, $user_id ) );
		return (bool) $token->has();
	}

	/**
	 * A Site Kit module whose client authenticates as one user.
	 *
	 * @param int $user_id User ID; zero for the current user.
	 * @return GoogleSiteKitAnalyticsData
	 */
	private static function site_kit_module( int $user_id ): GoogleSiteKitAnalyticsData {
		require_once NEWSPACK_ABSPATH . 'includes/plugins/google-site-kit/class-googlesitekitanalyticsdata.php';
		$context = new \Google\Site_Kit\Context( GOOGLESITEKIT_PLUGIN_MAIN_FILE );
		return new GoogleSiteKitAnalyticsData( $context, null, new \Google\Site_Kit\Core\Storage\User_Options( $context, $user_id ) );
	}

	/**
	 * A report request in the Data API's JSON shape.
	 *
	 * @param string[] $dimensions Dimension names.
	 * @param string   $metric     Metric name.
	 * @param string   $start      Window start, Y-m-d.
	 * @param string   $end        Window end, Y-m-d.
	 * @param array[]  $filters    Filter expressions, ANDed.
	 * @return array
	 */
	private static function build_request( array $dimensions, string $metric, string $start, string $end, array $filters ): array {
		return [
			'dimensions'      => array_map( fn( $name ) => [ 'name' => $name ], $dimensions ),
			'metrics'         => [ [ 'name' => $metric ] ],
			'dateRanges'      => [
				[
					'startDate' => $start,
					'endDate'   => $end,
				],
			],
			'dimensionFilter' => [ 'andGroup' => [ 'expressions' => $filters ] ],
			'limit'           => self::ROW_LIMIT,
		];
	}

	/**
	 * An exact-match filter expression.
	 *
	 * @param string $field Dimension name.
	 * @param string $value Value.
	 * @return array
	 */
	private static function exact( string $field, string $value ): array {
		return [
			'filter' => [
				'fieldName'    => $field,
				'stringFilter' => [
					'matchType' => 'EXACT',
					'value'     => $value,
				],
			],
		];
	}

	/**
	 * An in-list filter expression. Values are sent as strings, as GA4 stores them.
	 *
	 * @param string $field  Dimension name.
	 * @param array  $values Values.
	 * @return array
	 */
	private static function in_list( string $field, array $values ): array {
		return [
			'filter' => [
				'fieldName'    => $field,
				'inListFilter' => [ 'values' => array_values( array_map( 'strval', $values ) ) ],
			],
		];
	}
}
