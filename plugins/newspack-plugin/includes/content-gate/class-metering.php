<?php
/**
 * WooCommerce Content Gate Metering.
 *
 * @package Newspack
 */

namespace Newspack;

/**
 * WooCommerce Content Gate Metering class.
 */
class Metering {

	const METERING_META_KEY = 'np_content_metering';

	/**
	 * Article view activity to be handled by frontend metering.
	 *
	 * @var array|null
	 */
	private static $article_view = null;

	/**
	 * Cache of the user's metering status for posts.
	 *
	 * @var boolean[] Map of post IDs to booleans.
	 */
	private static $logged_in_metering_cache = [];

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_filter( 'newspack_content_gate_restrict_post', [ __CLASS__, 'restrict_post' ] );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_block_editor_assets' ], 11 ); // Render after gate layout editor.
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'wp_footer', [ __CLASS__, 'enqueue_scripts' ] );
		add_action( 'wp_footer', [ __CLASS__, 'render_frontend_metering_gate' ] );
		add_filter( 'newspack_reader_activity_article_view', [ __CLASS__, 'get_article_view' ], 20 );
	}

	/**
	 * Whether to restrict the post.
	 *
	 * @param bool $restrict Whether to restrict the post.
	 *
	 * @return bool
	 */
	public static function restrict_post( $restrict ) {
		if ( $restrict && self::is_metering() ) {
			return false;
		}
		return $restrict;
	}

	/**
	 * Block editor assets for metering settings.
	 */
	public static function enqueue_block_editor_assets() {
		if ( ! in_array( get_post_type(), Content_Gate::get_gate_post_types(), true ) ) {
			return;
		}
		$asset = require dirname( NEWSPACK_PLUGIN_FILE ) . '/dist/content-gate-editor-metering.asset.php';
		wp_enqueue_script( 'newspack-content-gate-editor-metering', Newspack::plugin_url() . '/dist/content-gate-editor-metering.js', $asset['dependencies'], $asset['version'], true );
	}

	/**
	 * Render the frontend metering gate.
	 */
	public static function render_frontend_metering_gate() {
		if ( ! \is_singular() || ! Content_Gate::is_post_restricted() || ! self::is_frontend_metering() ) {
			return;
		}
		Content_Gate::mark_gate_as_rendered();
		echo '<div style="display:none">' . Content_Gate::get_inline_gate_html() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Register gate meta.
	 */
	public static function register_meta() {
		$meta = [
			'metering'                  => [
				'type'    => 'boolean',
				'default' => false,
			],
			'metering_anonymous_count'  => [
				'type'    => 'integer',
				'default' => 0,
			],
			'metering_registered_count' => [
				'type'    => 'integer',
				'default' => 0,
			],
			'metering_period'           => [
				'type'    => 'string',
				'default' => 'week',
			],
		];
		foreach ( Content_Gate::get_gate_post_types() as $cpt ) {
			foreach ( $meta as $key => $config ) {
				\register_meta(
					'post',
					$key,
					[
						'object_subtype' => $cpt,
						'show_in_rest'   => true,
						'type'           => $config['type'],
						'default'        => $config['default'],
						'single'         => true,
					]
				);
			}
		}
	}

	/**
	 * Get metering settings for a gate.
	 *
	 * @param int $gate_id Gate ID.
	 *
	 * @return array Metering settings.
	 */
	public static function get_metering_settings( $gate_id ) {
		$anonymous_settings  = self::get_anonymous_settings( $gate_id );
		$registered_settings = self::get_registered_settings( $gate_id );
		return [
			'enabled'           => $anonymous_settings['enabled'] || $registered_settings['enabled'],
			'period'            => $anonymous_settings['period'], // Legacy property, equivalent to anonymous_period.
			'anonymous_count'   => $anonymous_settings['count'],
			'anonymous_period'  => $anonymous_settings['period'],
			'registered_count'  => $registered_settings['count'],
			'registered_period' => $registered_settings['period'],
		];
	}

	/**
	 * Get legacy metering settings for a gate.
	 *
	 * @param int $gate_id Gate ID.
	 *
	 * @return array Metering settings.
	 */
	protected static function get_legacy_metering_settings( $gate_id ) {
		return [
			'enabled'          => (bool) \get_post_meta( $gate_id, 'metering', true ),
			'anonymous_count'  => absint( \get_post_meta( $gate_id, 'metering_anonymous_count', true ) ),
			'registered_count' => absint( \get_post_meta( $gate_id, 'metering_registered_count', true ) ),
			'period'           => \get_post_meta( $gate_id, 'metering_period', true ),
		];
	}

	/**
	 * Get anonymous metering settings for a gate.
	 *
	 * @param int $gate_id Gate ID.
	 *
	 * @return array Anonymous metering settings.
	 */
	public static function get_anonymous_settings( $gate_id ) {
		if ( Memberships::is_active() ) {
			// Fetch from legacy metering settings.
			$metering = self::get_legacy_metering_settings( $gate_id );
			return [
				'enabled' => $metering['enabled'],
				'count'   => $metering['anonymous_count'],
				'period'  => $metering['period'],
			];
		}

		$registration = Content_Gate::get_registration_settings( $gate_id );
		return self::resolve_settings( $registration['active'], $registration['metering'], 'anonymous_count' );
	}

	/**
	 * Resolve an audience path's metering settings from an in-memory settings array.
	 *
	 * Gate layouts are generated from the settings being saved, before those settings
	 * reach the database, so the gate ID cannot be used to read them back. The layout
	 * copy still has to quote the allowance the reader will actually get, which is the
	 * shared one unless the path opted out.
	 *
	 * Access Control gates only. Every sibling resolver short-circuits to the legacy
	 * `metering` meta while Woo Memberships is active; this one takes a settings array
	 * rather than a gate, so it has no legacy meta to read and must not be used to
	 * describe a Memberships gate.
	 *
	 * @param array $path_settings One audience path's settings, as stored on the gate.
	 * @param bool  $is_logged_in  Whether to evaluate for a logged-in reader.
	 *
	 * @return array{enabled: bool, count: int, period: string} Metering settings.
	 */
	public static function resolve_path_settings( array $path_settings, bool $is_logged_in ): array {
		$metering = \wp_parse_args(
			$path_settings['metering'] ?? [],
			Content_Gate::get_default_metering_settings()
		);
		return self::resolve_settings(
			! empty( $path_settings['active'] ),
			$metering,
			Site_Meter::count_key_for_reader( $is_logged_in )
		);
	}

	/**
	 * Resolve one audience path's metering settings against its scope.
	 *
	 * Enablement always comes from the gate, so a hard wall and a metered gate can
	 * coexist against the same pool. The allowance comes from the site meter unless
	 * the path opts out.
	 *
	 * Gates saved before the scope setting existed read as shared, so until adoption
	 * has seeded the site meter the shared allowance is still only its defaults. Serving
	 * those would change a publisher's configured allowance the moment the plugin
	 * updates, so the gate's own values stand until the seed is written.
	 *
	 * @param bool   $active         Whether the audience path is active on the gate.
	 * @param array  $metering       The path's stored metering settings.
	 * @param string $site_count_key Which site meter count governs this reader.
	 *
	 * @return array{enabled: bool, count: int, period: string} Metering settings.
	 */
	private static function resolve_settings( bool $active, array $metering, string $site_count_key ): array {
		if ( ! $active ) {
			return [
				'enabled' => false,
				'count'   => 0,
				'period'  => 'month',
			];
		}
		$enabled = (bool) $metering['enabled'];
		if ( Site_Meter::SCOPE_SITE === Site_Meter::sanitize_scope( $metering['scope'] ?? null ) && Site_Meter::has_adopted() ) {
			$site = Site_Meter::get_settings();
			return [
				'enabled' => $enabled,
				'count'   => absint( $site[ $site_count_key ] ),
				'period'  => $site['period'],
			];
		}
		return [
			'enabled' => $enabled,
			'count'   => absint( $metering['count'] ),
			'period'  => $metering['period'],
		];
	}

	/**
	 * Get registered metering settings for a gate.
	 *
	 * @param int $gate_id Gate ID.
	 *
	 * @return array Registered metering settings.
	 */
	public static function get_registered_settings( $gate_id ) {
		if ( Memberships::is_active() ) {
			// Fetch from legacy metering settings.
			$metering = self::get_legacy_metering_settings( $gate_id );
			return [
				'enabled' => $metering['enabled'],
				'count'   => $metering['registered_count'],
				'period'  => $metering['period'],
			];
		}

		$custom_access = Content_Gate::get_custom_access_settings( $gate_id );
		return self::resolve_settings( $custom_access['active'], $custom_access['metering'], 'registered_count' );
	}

	/**
	 * Get the counter key for the reader the given settings govern.
	 *
	 * Counters keyed by gate gave a reader crossing sections a fresh allowance per
	 * gate. A shared scope collapses them onto one key so the allowance, and the
	 * countdown that reports it, hold across the whole site.
	 *
	 * Legacy Woo Memberships gates keep their per-gate key: they predate the scope
	 * setting and read both meters from the shared `metering` meta.
	 *
	 * Gates keep their own counter until adoption has seeded the shared allowance, for
	 * the same reason they keep their own count: sharing one counter while the gates
	 * still grant different allowances lets one gate's views exhaust another's.
	 *
	 * Only the shared key carries a blog ID on multisite. The gate ID does not, and
	 * deliberately: post IDs are per-site, so gate 412 on two sites of a network share
	 * one `np_content_metering_412` row, but that key is what per-gate counters have
	 * always been written under. Qualifying it now would abandon every reader's live
	 * counter mid-period, gating readers who had views left. The shared key is new, so
	 * it can be network-safe from the start without costing anyone their allowance.
	 *
	 * `$gate_id` is deliberately untyped. It arrives from `Content_Gate::get_gate_post_id()`,
	 * which returns `false` when no gate applies and is itself filterable, so a declared
	 * `int` would turn a third party returning null into a fatal on a gated article. The
	 * falsy guard below is the contract instead.
	 *
	 * @param int|false $gate_id      Gate ID, or false when no gate applies.
	 * @param bool      $is_logged_in Whether to evaluate for a logged-in reader.
	 *
	 * @return string Counter key: the shared key, or the gate ID.
	 */
	public static function get_meter_key( $gate_id, bool $is_logged_in ): string {
		// Falling through would hand back the shared key and report another gate's views.
		// '0' rather than a cast, so PHP and the frontend name the same empty counter.
		if ( ! $gate_id ) {
			return '0';
		}
		if ( Memberships::is_active() ) {
			return (string) $gate_id;
		}
		$settings = self::is_gated_by_registration( $gate_id, $is_logged_in )
			? Content_Gate::get_registration_settings( $gate_id )
			: Content_Gate::get_custom_access_settings( $gate_id );
		$scope = Site_Meter::sanitize_scope( $settings['metering']['scope'] ?? null );
		if ( Site_Meter::SCOPE_SITE === $scope && Site_Meter::has_adopted() ) {
			return Site_Meter::get_shared_meter_key();
		}
		return (string) $gate_id;
	}

	/**
	 * Whether the registration wall is the rule that gates the current reader.
	 *
	 * Follows the same registration-vs-`custom_access` split as the gate-layout
	 * selection in `Content_Restriction_Control::is_post_restricted()`, which restricts
	 * with the registration layout - and skips the `custom_access` rules entirely - for
	 * both anonymous visitors and signed-in readers who have not verified their email on
	 * a wall that requires verification.
	 *
	 * This assumes the reader is already known to be restricted; it is a settings
	 * selector, not a restriction check. In particular it does not re-check the
	 * anonymous `supports_anonymous` bypass (e.g. institutional access) that
	 * `is_post_restricted()` applies before ever restricting an anonymous visitor - a
	 * bypassed reader is not restricted, so no metering surface consults this for them.
	 *
	 * @param int  $gate_id            Gate ID.
	 * @param bool $is_logged_in       Whether to evaluate for a logged-in reader.
	 * @param bool $for_current_reader Whether the question is about the visitor making
	 *                                 this request, rather than about the gate.
	 *
	 * @return bool Whether the registration wall governs the reader.
	 */
	private static function is_gated_by_registration( $gate_id, $is_logged_in, bool $for_current_reader = true ) {
		$registration = Content_Gate::get_registration_settings( $gate_id );
		if ( ! $registration['active'] ) {
			return false;
		}
		if ( ! $is_logged_in ) {
			return true;
		}
		if ( ! $registration['require_verification'] ) {
			return false;
		}
		// Only the current session knows whether its reader has verified, so a question
		// about anyone else assumes the verified reader the paywall is written for.
		// Matching the session alone is not enough: for a signed-in unverified visitor
		// the two coincide, and a site-wide question would answer differently for them.
		if ( ! $for_current_reader || \is_user_logged_in() !== $is_logged_in ) {
			return false;
		}
		// Exempt non-reader users (admins, editors). This reuses the reader/verified
		// checks from `is_logged_in_metering_allowed()`'s verification bail; note that
		// bail keys off `Content_Gate::requires_account_verification()`, which reads
		// `require_verification` without the `active` guard applied above, so the two
		// only fully agree while the wall is active.
		$user = \wp_get_current_user();
		return Reader_Activation::is_user_reader( $user ) && ! Reader_Activation::is_reader_verified( $user );
	}

	/**
	 * Get the metering settings that govern the current reader.
	 *
	 * Beware the two senses of "registered" in this class: `get_anonymous_settings()`
	 * reads the `registration` meta (the registration wall), while
	 * `get_registered_settings()` reads the `custom_access` meta (the paywall).
	 *
	 * A reader gated by the registration wall is metered by the wall's own settings -
	 * including when its metering is deliberately turned off, which means no metered
	 * views at all. Only readers who are past (or not subject to) the wall fall through
	 * to the paywall, and with it the `custom_access` metering settings.
	 *
	 * Legacy Woo Memberships gates are exempt: they have no `registration` meta at all
	 * and read both meters from the shared `metering` meta, so they keep the plain
	 * anonymous/registered split.
	 *
	 * The path decides whether the reader is metered and, when the gate keeps its own
	 * allowance, how many views it grants. A shared allowance is instead chosen by the
	 * reader, so one signed-out reader draws on one pool site-wide rather than on
	 * whichever pool the gate they landed on happens to name.
	 *
	 * @param int  $gate_id            Gate ID.
	 * @param bool $is_logged_in       Whether to evaluate for a logged-in reader.
	 * @param bool $for_current_reader Whether the question is about the visitor making
	 *                                 this request. Pass false for questions about the
	 *                                 gate itself, which must answer the same for
	 *                                 everyone.
	 *
	 * @return array{enabled: bool, count: int, period: string} Metering settings.
	 */
	private static function get_effective_settings( $gate_id, $is_logged_in, bool $for_current_reader = true ) {
		if ( Memberships::is_active() ) {
			return $is_logged_in ? self::get_registered_settings( $gate_id ) : self::get_anonymous_settings( $gate_id );
		}
		$path = self::is_gated_by_registration( $gate_id, $is_logged_in, $for_current_reader )
			? Content_Gate::get_registration_settings( $gate_id )
			: Content_Gate::get_custom_access_settings( $gate_id );
		return self::resolve_settings( $path['active'], $path['metering'], Site_Meter::count_key_for_reader( $is_logged_in ) );
	}

	/**
	 * Update metering settings for a gate.
	 *
	 * @param int   $gate_id  Gate ID.
	 * @param array $settings Metering settings.
	 *
	 * @return void
	 */
	public static function update_metering_settings( $gate_id, $settings ) {
		\update_post_meta( $gate_id, 'metering', $settings['enabled'] );
		\update_post_meta( $gate_id, 'metering_anonymous_count', $settings['anonymous_count'] );
		\update_post_meta( $gate_id, 'metering_registered_count', $settings['registered_count'] );
		\update_post_meta( $gate_id, 'metering_period', $settings['period'] );
	}

	/**
	 * Enqueue frontend scripts and styles for gated content.
	 */
	public static function enqueue_scripts() {
		if ( ! Content_Gate::has_gate() ) {
			return;
		}
		if ( ! \is_singular() || ! Content_Gate::is_post_restricted() || ! self::is_frontend_metering() ) {
			return;
		}
		$gate_layout_id = Content_Gate::get_gate_layout_id();
		$gate_post_id   = Content_Gate::get_gate_post_id();
		$handle         = 'newspack-content-gate-metering';
		\wp_enqueue_script(
			$handle,
			Newspack::plugin_url() . '/dist/content-gate-metering.js',
			[],
			filemtime( dirname( NEWSPACK_PLUGIN_FILE ) . '/dist/content-gate-metering.js' ),
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);

		$settings = self::get_effective_settings( $gate_post_id, false );
		\wp_localize_script(
			$handle,
			'newspack_metering_settings',
			[
				'visible_paragraphs' => \get_post_meta( $gate_layout_id, 'visible_paragraphs', true ),
				'use_more_tag'       => \get_post_meta( $gate_layout_id, 'use_more_tag', true ),
				'count'              => $settings['count'],
				'period'             => $settings['period'],
				'gate_id'            => $gate_post_id,
				'meter_key'          => self::get_meter_key( $gate_post_id, false ),
				'post_id'            => get_the_ID(),
				'article_view'       => self::$article_view,
				'excerpt'            => apply_filters( 'newspack_gate_content', Content_Gate::get_restricted_post_excerpt( get_post() ) ),
				'other_settings'     => Content_Gate_Advanced_Settings::get_settings(),
			]
		);
	}

	/**
	 * Get the metering expiration time for the given date.
	 *
	 * @param string|null $period Metering period. Default is null, which will use the gate's metering period.
	 *
	 * @return int Timestamp of the expiration time.
	 */
	private static function get_expiration_time( $period = null ) {
		if ( ! $period ) {
			$settings = self::get_metering_settings( Content_Gate::get_gate_post_id() );
			$period = $settings['period'];
		}
		switch ( $period ) {
			case 'day':
				return strtotime( 'tomorrow' );
			case 'week':
				return strtotime( 'next monday' );
			case 'month':
				return mktime( 0, 0, 0, gmdate( 'n' ) + 1, 1 );
			default:
				return 0;
		}
	}

	/**
	 * Whether the given gate actually meters, i.e. it grants at least one free view.
	 *
	 * Metering switched on with 0 free views gates every reader on their first view, so
	 * it is indistinguishable from metering switched off and nothing downstream of
	 * metering has anything to count.
	 *
	 * Judged per reader rather than per audience path, matching how the allowance is
	 * resolved. A gate with no registration wall meters signed-out readers through its
	 * paywall against the signed-out allowance, which reading the two paths against
	 * their own counts would miss.
	 *
	 * @param int $gate_id Gate ID.
	 *
	 * @return bool
	 */
	public static function is_gate_metered( $gate_id ) {
		foreach ( [ false, true ] as $is_logged_in ) {
			// About the gate, not about whoever is asking: this drives site-wide surfaces.
			$settings = self::get_effective_settings( $gate_id, $is_logged_in, false );
			if ( $settings['enabled'] && 0 < $settings['count'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a gate offers metering to a reader class, without consuming a view.
	 *
	 * `is_logged_in_metering_allowed()` answers a different question — has this
	 * reader still got an allowance left — and records the view as a side effect.
	 * Analytics code must never call it; this is the read-only alternative.
	 *
	 * @param int       $gate_id      Gate post ID.
	 * @param bool|null $is_logged_in Reader class. Defaults to the current reader.
	 * @return bool
	 */
	public static function offers_metering( $gate_id, $is_logged_in = null ) {
		// A named reader class is a hypothetical; only the default means "this visitor".
		$for_current_reader = null === $is_logged_in;
		$is_logged_in       = $for_current_reader ? \is_user_logged_in() : (bool) $is_logged_in;
		$settings           = self::get_effective_settings( $gate_id, $is_logged_in, $for_current_reader );
		return ! empty( $settings['enabled'] ) && 0 < $settings['count'];
	}

	/**
	 * Whether the post has metering enabled.
	 *
	 * @param int|null $post_id Post ID. Default is the current post.
	 *
	 * @return bool
	 */
	public static function has_metering( $post_id = null ) {
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}
		return self::is_gate_metered( Content_Gate::get_gate_post_id( $post_id ) );
	}

	/**
	 * Whether to use the frontend metering strategy.
	 *
	 * @return bool
	 */
	public static function is_frontend_metering() {
		/**
		 * This filter documented in the `is_metering` method.
		 */
		$short_circuit = apply_filters( 'newspack_content_gate_metering_short_circuit', null );
		if ( null !== $short_circuit ) {
			return false;
		}

		// Frontend metering strategy should only be applied for anonymous readers.
		if ( \is_user_logged_in() ) {
			return false;
		}

		// Bail if not in a singular restricted post with available gate.
		if ( ! \is_singular() || ! Content_Gate::has_gate() || ! Content_Gate::is_post_restricted() ) {
			return false;
		}

		$gate_post_id         = Content_Gate::get_gate_post_id();
		$settings             = self::get_effective_settings( $gate_post_id, false );
		$is_frontend_metering = $settings['enabled'] && $settings['count'] > 0;

		/**
		 * Filters whether to use the frontend metering strategy.
		 *
		 * @param bool $is_frontend_metering Whether to use the frontend metering strategy.
		 */
		return apply_filters( 'newspack_content_gate_is_frontend_metering', $is_frontend_metering );
	}

	/**
	 * Whether to allow content rendering through metering for logged in users.
	 *
	 * @param int $post_id Optional post ID. Default is the current post.
	 *
	 * @return bool
	 */
	public static function is_logged_in_metering_allowed( $post_id = null ) {
		/**
		 * This filter documented in the `is_metering` method.
		 */
		$short_circuit = apply_filters( 'newspack_content_gate_metering_short_circuit', null );
		if ( null !== $short_circuit ) {
			return false;
		}

		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}

		// Metering back-end strategy is only for logged-in users.
		if ( ! \is_user_logged_in() ) {
			return false;
		}

		// Bail if the gate requires account verification and the reader is not verified.
		// Non-reader users (admins, editors) are exempt - they have full access through other means.
		$user = \wp_get_current_user();
		if ( Content_Gate::requires_account_verification() && Reader_Activation::is_user_reader( $user ) && ! Reader_Activation::is_reader_verified( $user ) ) {
			return false;
		}

		// Not in checkout modals.
		if ( method_exists( 'Newspack_Blocks\Modal_Checkout', 'is_modal_checkout' ) && \Newspack_Blocks\Modal_Checkout::is_modal_checkout() ) {
			return false;
		}

		$gate_post_id = Content_Gate::get_gate_post_id();
		// Read through the same helper as every reporting surface, so the enablement
		// check here can never advertise a different allowance than they display. For
		// every state that reaches this line the reader is either verified, a
		// non-reader, or on a gate without a verification requirement - the verification
		// bail above has already returned for the one state where the registration wall
		// would govern - so this is behavior-identical to a direct paid-settings read.
		$settings = self::get_effective_settings( $gate_post_id, true );

		// Bail if metering is not enabled.
		if ( ! $settings['enabled'] || $settings['count'] <= 0 ) {
			return false;
		}

		// Return cached value if available.
		if ( isset( self::$logged_in_metering_cache[ $post_id ] ) ) {
			return self::$logged_in_metering_cache[ $post_id ];
		}

		$user_meta_key = self::METERING_META_KEY . '_' . self::get_meter_key( $gate_post_id, true );

		$updated_user_data  = false;
		$user_metering_data = \get_user_meta( get_current_user_id(), $user_meta_key, true );
		if ( ! is_array( $user_metering_data ) ) {
			$user_metering_data = [];
		}

		$user_expiration = isset( $user_metering_data['expiration'] ) ? $user_metering_data['expiration'] : 0;

		$current_expiration = self::get_expiration_time( $settings['period'] );
		if ( $user_expiration !== $current_expiration ) {
			// Clear content if expired.
			if ( $user_expiration < $current_expiration ) {
				$user_metering_data['content'] = [];
			}
			// Reset expiration.
			$user_metering_data['expiration'] = $current_expiration;
			$updated_user_data                = true;
		}

		$limited          = count( $user_metering_data['content'] ) >= $settings['count'];
		$accessed_content = in_array( $post_id, $user_metering_data['content'], true );
		if ( ! $limited && ! $accessed_content ) {
			$user_metering_data['content'][] = $post_id;
			$updated_user_data               = true;
		}

		if ( $updated_user_data ) {
			\update_user_meta( get_current_user_id(), $user_meta_key, $user_metering_data );
		}

		// Allowed if the content has been accessed or the metering limit has not been reached.
		$allowed = $accessed_content || ! $limited;

		/**
		 * Filters whether to allow content rendering through metering for logged in user.
		 *
		 * @param bool $is_logged_in_metering_allowed Whether to allow content rendering through metering for logged in user
		 * @param int  $post_id                       Post ID.
		 */
		self::$logged_in_metering_cache[ $post_id ] = apply_filters( 'newspack_content_gate_is_logged_in_metering_allowed', $allowed, $post_id );

		return self::$logged_in_metering_cache[ $post_id ];
	}

	/**
	 * Whether the content should be allowed to render. If it's frontend metered,
	 * it will be handled by the frontend metering strategy.
	 *
	 * @return bool
	 */
	public static function is_metering() {
		/**
		 * Short-circuit the metering check. Anything other than null
		 * will prevent the metering logic from running.
		 *
		 * The `is_logged_in_metering_allowed` method also updates the user meta
		 * to track the content that's been allowed to access. This short-circuit
		 * prevents this from running if we want the entire metering feature to be
		 * skipped.
		 *
		 * @param mixed $short_circuit Short-circuit value. Default is null.
		 *
		 * @return mixed Short-circuit value.
		 */
		$short_circuit = apply_filters( 'newspack_content_gate_metering_short_circuit', null );
		if ( null !== $short_circuit ) {
			return false;
		}

		return self::is_frontend_metering() || self::is_logged_in_metering_allowed();
	}

	/**
	 * Store the article view activity push for use in the frontend metering
	 * strategy.
	 *
	 * @param array $activity Activity data.
	 *
	 * @return array
	 */
	public static function get_article_view( $activity ) {
		self::$article_view = $activity;
		return $activity;
	}

	/**
	 * Get the metering period for the current reader on a post.
	 *
	 * Resolves against the settings that govern the current reader, so the result is
	 * auth-state dependent (via `is_user_logged_in()`, and verification state for
	 * logged-in readers) - not a pure per-post value. Callers caching or comparing
	 * periods across readers should key on the reader as well as the post.
	 *
	 * @param int|null $post_id Post ID. Default is current post.
	 *
	 * @return string Metered period (day, week, month).
	 */
	public static function get_metering_period( $post_id = null ) {
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}
		$gate_post_id = Content_Gate::get_gate_post_id( $post_id );
		$settings     = self::get_effective_settings( $gate_post_id, \is_user_logged_in() );
		return $settings['period'];
	}

	/**
	 * Get number of metered views for the current user.
	 *
	 * @return int Number of metered views.
	 */
	public static function get_current_user_metered_views() {
		if ( ! is_user_logged_in() ) {
			return 0;
		}

		$gate_post_id  = Content_Gate::get_gate_post_id();
		$meta_key      = self::METERING_META_KEY . '_' . self::get_meter_key( $gate_post_id, true );
		$metering_data = \get_user_meta( get_current_user_id(), $meta_key, true );
		if ( ! is_array( $metering_data ) || ! isset( $metering_data['content'] ) ) {
			return 0;
		}
		return count( $metering_data['content'] );
	}

	/**
	 * Get total number of metered views for current post.
	 *
	 * @param boolean $is_logged_in Whether to check for logged-in or anonymous users. Default is false (anonymous).
	 *
	 * @return int|boolean Total number of metered views if metering is enabled, otherwise false.
	 */
	public static function get_total_metered_views( $is_logged_in = false ) {
		$gate_post_id = Content_Gate::get_gate_post_id();
		if ( ! $gate_post_id ) {
			return false;
		}
		$settings = self::get_effective_settings( $gate_post_id, $is_logged_in );
		if ( ! $settings['enabled'] ) {
			return false;
		}
		return $settings['count'];
	}
}
Metering::init();
