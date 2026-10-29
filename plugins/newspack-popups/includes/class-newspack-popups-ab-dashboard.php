<?php
/**
 * The A/B Tests dashboard: its page, its stats route and the provider it reads from.
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Audience → A/B Tests (Tools → A/B Tests without the Audience menu), behind
 * the Campaigns A/B flag.
 */
final class Newspack_Popups_AB_Dashboard {
	/**
	 * Menu slug of the page.
	 */
	const PAGE_SLUG = 'newspack-ab-tests';

	/**
	 * Script and style handle of the page's app.
	 */
	const SCRIPT_HANDLE = 'newspack-popups-ab-tests';

	/**
	 * Parent menu of the Campaigns screens, which newspack-plugin registers.
	 */
	const AUDIENCE_MENU_SLUG = 'newspack-audience';

	/**
	 * Menu slug of the Campaigns screen, which the page sits under.
	 */
	const CAMPAIGNS_MENU_SLUG = 'newspack-audience-campaigns';

	/**
	 * Body class that lets the page fill the admin canvas.
	 */
	const BODY_CLASS = 'newspack-popups-ab-tests-screen';

	/**
	 * Hook suffix WordPress gave the page, once registered.
	 *
	 * @var string|false
	 */
	private static $page_hook = false;

	/**
	 * Hook the page in. Both callbacks check the flag themselves, at the time
	 * the menu and the scripts are built, so a flag set after plugins load
	 * still counts.
	 */
	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu_page_item' ], 20 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_filter( 'admin_body_class', [ __CLASS__, 'add_body_class' ] );
		foreach ( [ 'admin_notices', 'all_admin_notices', 'network_admin_notices' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'remove_admin_notices' ], -PHP_INT_MAX );
		}
	}

	/**
	 * Whether the current admin request is for this page. The hook suffix is
	 * compared rather than the screen ID, which sanitize_key() mangles when the
	 * parent menu's title is not Latin.
	 *
	 * @return bool
	 */
	private static function is_page_screen() {
		return self::$page_hook && ( $GLOBALS['hook_suffix'] ?? '' ) === self::$page_hook;
	}

	/**
	 * Other plugins' notices would render above the page header, as on the
	 * other Newspack screens that drop them.
	 */
	public static function remove_admin_notices() {
		if ( self::is_page_screen() ) {
			remove_all_actions( current_action() );
		}
	}

	/**
	 * Audience → A/B Tests, beside Campaigns. Without newspack-plugin there is
	 * no Audience menu, so the page falls back to Tools.
	 */
	public static function add_menu_page_item() {
		if ( ! Newspack_Popups_AB_Tests::is_enabled() ) {
			return;
		}
		$parent          = self::get_parent_slug();
		self::$page_hook = add_submenu_page(
			$parent,
			__( 'A/B Tests', 'newspack-popups' ),
			__( 'A/B Tests', 'newspack-popups' ),
			'manage_options',
			self::PAGE_SLUG,
			[ __CLASS__, 'render_page' ]
		);
		if ( self::AUDIENCE_MENU_SLUG === $parent ) {
			self::move_menu_item_below_campaigns();
		}
	}

	/**
	 * Put the page's Audience submenu item directly under Campaigns, which
	 * newspack-plugin registers earlier on the same hook.
	 */
	private static function move_menu_item_below_campaigns() {
		$items     = array_values( $GLOBALS['submenu'][ self::AUDIENCE_MENU_SLUG ] ?? [] );
		$own       = array_search( self::PAGE_SLUG, array_column( $items, 2 ), true );
		$campaigns = array_search( self::CAMPAIGNS_MENU_SLUG, array_column( $items, 2 ), true );
		if ( false === $own || false === $campaigns ) {
			return;
		}
		$item = array_splice( $items, $own, 1 );
		if ( $own < $campaigns ) {
			--$campaigns;
		}
		array_splice( $items, $campaigns + 1, 0, $item );
		$GLOBALS['submenu'][ self::AUDIENCE_MENU_SLUG ] = $items; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * The menu the page sits under.
	 *
	 * @return string
	 */
	public static function get_parent_slug() {
		return empty( $GLOBALS['admin_page_hooks'][ self::AUDIENCE_MENU_SLUG ] ) ? 'tools.php' : self::AUDIENCE_MENU_SLUG;
	}

	/**
	 * URL of the page, for links from the prompt editor.
	 *
	 * @return string
	 */
	public static function get_page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Mark the page's body so its styles can drop the admin's content padding.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public static function add_body_class( $classes ) {
		if ( self::is_page_screen() ) {
			$classes .= ' ' . self::BODY_CLASS;
		}
		return $classes;
	}

	/**
	 * The page's markup: a mount point the app fills.
	 */
	public static function render_page() {
		echo '<div id="newspack-popups-ab-tests-root"></div>';
	}

	/**
	 * Load the app on its own page only.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue( $hook_suffix ) {
		if ( ! self::$page_hook || $hook_suffix !== self::$page_hook || ! Newspack_Popups_AB_Tests::is_enabled() ) {
			return;
		}
		$dir        = dirname( NEWSPACK_POPUPS_PLUGIN_FILE ) . '/dist/';
		$asset_path = $dir . 'abTests.asset.php';
		$paths      = [ $asset_path, $dir . 'abTests.js', $dir . 'abTests.css' ];
		if ( array_filter( $paths, fn( $path ) => ! file_exists( $path ) ) ) {
			// A checkout that has not been built: the page renders its root and
			// nothing mounts, which is better than a fatal on the page.
			return;
		}
		$asset = require $asset_path;
		\wp_register_script(
			self::SCRIPT_HANDLE,
			plugins_url( '../dist/abTests.js', __FILE__ ),
			$asset['dependencies'],
			$asset['version'],
			true
		);
		// An inline script keeps the booleans in the boot data; wp_localize_script()
		// would hand the page "1" and "" instead.
		\wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'var newspack_popups_ab_tests = ' . wp_json_encode( self::get_boot_data(), JSON_HEX_TAG | JSON_HEX_AMP ) . ';',
			'before'
		);
		\wp_set_script_translations( self::SCRIPT_HANDLE, 'newspack-popups', dirname( NEWSPACK_POPUPS_PLUGIN_FILE ) . '/languages' );
		\wp_enqueue_script( self::SCRIPT_HANDLE );
		\wp_register_style(
			self::SCRIPT_HANDLE,
			plugins_url( '../dist/abTests.css', __FILE__ ),
			[ 'wp-components' ],
			$asset['version']
		);
		\wp_style_add_data( self::SCRIPT_HANDLE, 'rtl', 'replace' );
		\wp_enqueue_style( self::SCRIPT_HANDLE );
	}

	/**
	 * What the app needs before its first request.
	 *
	 * @return array
	 */
	public static function get_boot_data() {
		$provider      = self::get_provider();
		$available     = self::availability( $provider );
		$in_audience   = self::AUDIENCE_MENU_SLUG === self::get_parent_slug();
		$campaigns_url = $in_audience
			? admin_url( 'admin.php?page=' . self::CAMPAIGNS_MENU_SLUG . '#/campaigns' )
			: admin_url( 'edit.php?post_type=' . Newspack_Popups::NEWSPACK_POPUPS_CPT );
		return [
			'tests'            => Newspack_Popups_AB_Tests::get_tests(),
			'rest_base'        => 'newspack-popups/v1',
			'provider'         => [
				'available' => true === $available,
				'slug'      => self::provider_slug( $provider ),
				'reason'    => is_wp_error( $available ) ? $available->get_error_message() : null,
			],
			'thresholds'       => [
				'directional' => Newspack_Popups_AB_Stats::THRESHOLD_DIRECTIONAL,
				'reliable'    => Newspack_Popups_AB_Stats::THRESHOLD_RELIABLE,
				'min_readers' => Newspack_Popups_AB_Stats::MIN_READERS_PER_ARM,
			],
			'copy'             => self::get_advisory_copy(),
			'labels'           => [
				'collecting'  => __( 'Too early', 'newspack-popups' ),
				'directional' => __( 'Early signal', 'newspack-popups' ),
				'reliable'    => __( 'Reliable', 'newspack-popups' ),
			],
			'in_audience_menu' => $in_audience,
			'campaigns_url'    => $campaigns_url,
		];
	}

	/**
	 * How long a provider's counts are reused before it is asked again.
	 */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Days a test runs before its results stop being flagged as early.
	 */
	const YOUNG_TEST_DAYS = 14;

	/**
	 * Share of logged-out readers at which per-browser assignment is flagged.
	 */
	const HIGH_ANONYMOUS_SHARE = 0.8;

	/**
	 * Advisory copy, keyed by flag.
	 *
	 * @return string[]
	 */
	public static function get_advisory_copy() {
		return [
			'high_anonymous' => __( "Most readers in this test aren't logged in, so some may see both versions on different devices. Treat close results with caution.", 'newspack-popups' ),
			'young_test'     => __( 'Too early to call a winner. Keep the test running until more readers have seen each version.', 'newspack-popups' ),
		];
	}

	/**
	 * Stats for one test: the provider's counts, with rates, lift, confidence
	 * and the flags the page shows beside them.
	 *
	 * @param string $test_id Test ID.
	 * @param bool   $refresh Ask the provider again even when counts are cached.
	 * @return array|WP_Error The response array, or a 404 error for an unknown test.
	 */
	public static function get_stats( $test_id, $refresh = false ) {
		$test_id = Newspack_Popups_AB_Tests::sanitize_test_id( $test_id );
		$test    = null;
		foreach ( Newspack_Popups_AB_Tests::get_tests() as $candidate ) {
			if ( $candidate['test_id'] === $test_id ) {
				$test = $candidate;
				break;
			}
		}
		if ( null === $test ) {
			return new WP_Error( 'newspack_popups_ab_test_not_found', __( 'No A/B test has that ID.', 'newspack-popups' ), [ 'status' => 404 ] );
		}

		$window    = self::get_window( $test );
		$provider  = self::get_provider();
		$available = self::availability( $provider );
		$source    = [
			'state'      => 'collecting',
			'provider'   => self::provider_slug( $provider ),
			'reason'     => null,
			'fetched_at' => null,
			'cached'     => false,
			'unit'       => 'readers',
		];
		$counts    = [];

		if ( is_wp_error( $available ) ) {
			$source['state']  = 'unavailable';
			$source['reason'] = $available->get_error_message();
		} elseif ( null !== $window ) {
			$result = self::fetch_counts( $provider, $test, $window, (bool) $refresh );
			if ( is_wp_error( $result ) ) {
				$source['state']  = 'unavailable';
				$source['reason'] = $result->get_error_message();
			} else {
				$counts               = $result['counts'];
				$source['fetched_at'] = $result['fetched_at'];
				$source['cached']     = $result['cached'];
				$source['unit']       = in_array( $counts['unit'] ?? '', [ 'readers', 'events' ], true ) ? $counts['unit'] : 'readers';
			}
		}

		$variants = self::merge_counts( $test['variants'], $counts['variants'] ?? [] );
		if ( 'collecting' === $source['state'] && array_sum( array_column( $variants, 'readers' ) ) > 0 ) {
			$source['state'] = 'ok';
		}

		return [
			'test_id'     => $test['test_id'],
			'goal'        => $test['goal'],
			'source'      => $source,
			'window'      => $window,
			'variants'    => $variants,
			'comparisons' => self::compare( $variants ),
			'flags'       => self::compute_flags( $test, $variants ),
		];
	}

	/**
	 * Whether the provider can answer, as true or a WP_Error that says why not.
	 *
	 * The provider is third-party code running inside the page's enqueue
	 * hook and the stats route, so a false, an unexpected value or an
	 * exception all become an "unavailable" the page can show, never a fatal.
	 *
	 * @param Newspack_Popups_AB_Stats_Provider|null $provider Provider, or null when none is registered.
	 * @return true|WP_Error
	 */
	private static function availability( $provider ) {
		if ( null === $provider ) {
			return new WP_Error( 'newspack_popups_ab_stats_provider', __( 'Results will show here once a stats source is connected.', 'newspack-popups' ) );
		}
		try {
			$available = $provider->is_available();
		} catch ( \Throwable $e ) {
			$available = new WP_Error( 'newspack_popups_ab_stats_provider', $e->getMessage() );
		}
		if ( true === $available || is_wp_error( $available ) ) {
			return $available;
		}
		return new WP_Error( 'newspack_popups_ab_stats_provider', __( 'The stats source is not available on this site.', 'newspack-popups' ) );
	}

	/**
	 * The provider's slug, or null when there is no provider or it fails to say.
	 *
	 * @param Newspack_Popups_AB_Stats_Provider|null $provider Provider.
	 * @return string|null
	 */
	private static function provider_slug( $provider ) {
		if ( null === $provider ) {
			return null;
		}
		try {
			return $provider->get_slug();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * The reporting window: the day the split began through today, UTC.
	 *
	 * @param array $test One entry of Newspack_Popups_AB_Tests::get_tests().
	 * @return array|null `[ 'start', 'end' ]` as Y-m-d, or null until both arms are published.
	 */
	public static function get_window( $test ) {
		if ( empty( $test['started_at'] ) ) {
			return null;
		}
		return [
			'start' => gmdate( 'Y-m-d', strtotime( $test['started_at'] ) ),
			'end'   => gmdate( 'Y-m-d' ),
		];
	}

	/**
	 * The provider's counts for a test, from the cache when they are fresh.
	 *
	 * The cache key covers the goal and the window as well as the test, so a
	 * goal changed in the editor is never answered with the old goal's
	 * conversions. Errors are returned as they come and never cached.
	 *
	 * @param Newspack_Popups_AB_Stats_Provider $provider Provider.
	 * @param array                             $test     Test.
	 * @param array                             $window   Window.
	 * @param bool                              $refresh  Skip the cached copy.
	 * @return array|WP_Error `[ 'counts', 'fetched_at', 'cached' ]`.
	 */
	private static function fetch_counts( $provider, $test, $window, $refresh ) {
		$key = self::cache_key( $test, (string) self::provider_slug( $provider ) );
		if ( ! $refresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) && isset( $cached['counts'], $cached['fetched_at'] ) ) {
				return [
					'counts'     => $cached['counts'],
					'fetched_at' => $cached['fetched_at'],
					'cached'     => true,
				];
			}
		}
		try {
			$counts = $provider->get_counts( $test, $test['goal'], $window['start'], $window['end'] );
		} catch ( \Throwable $e ) {
			$counts = new WP_Error( 'newspack_popups_ab_stats_provider', $e->getMessage() );
		}
		if ( is_wp_error( $counts ) ) {
			return $counts;
		}
		// The interface lets a source leave individual letters out of `variants`,
		// never the key itself; an answer without it would otherwise be cached for
		// an hour and read as "collecting" with every arm at zero.
		if ( ! is_array( $counts ) || ! is_array( $counts['variants'] ?? null ) ) {
			return new WP_Error( 'newspack_popups_ab_stats_provider', __( 'The stats source returned an unexpected answer.', 'newspack-popups' ) );
		}
		$fetched_at = gmdate( 'c' );
		set_transient(
			$key,
			[
				'counts'     => $counts,
				'fetched_at' => $fetched_at,
			],
			self::CACHE_TTL
		);
		return [
			'counts'     => $counts,
			'fetched_at' => $fetched_at,
			'cached'     => false,
		];
	}

	/**
	 * Transient key for a test's counts from one provider.
	 *
	 * @param array  $test Test.
	 * @param string $slug Provider slug.
	 * @return string
	 */
	private static function cache_key( $test, $slug ) {
		$window = self::get_window( $test );
		// The arms are part of the key: swapping which prompt carries a letter
		// changes what the provider counts, and must not be served the old
		// prompt's numbers for the rest of the hour.
		$arms = array_map( fn( $variant ) => (int) $variant['id'], $test['variants'] );
		ksort( $arms );
		return 'newspack_popups_ab_stats_' . md5(
			wp_json_encode(
				[
					$test['test_id'],
					$slug,
					$test['goal'],
					$arms,
					$window['start'] ?? '',
					$window['end'] ?? '',
				]
			)
		);
	}

	/**
	 * Discovery's variants with the provider's counts laid over them.
	 *
	 * Discovery decides which variants exist: one the provider knows nothing
	 * about is zero-filled, and one it reports that is not in the test is
	 * dropped, so stale analytics never add an arm to the page.
	 *
	 * @param array $discovered Variants from Newspack_Popups_AB_Tests::get_tests().
	 * @param array $reported   Variants from the provider.
	 * @return array Per letter: `readers`, `conversions`, `logged_in_readers`, `rate`, `lift`.
	 */
	private static function merge_counts( $discovered, $reported ) {
		$variants  = [];
		$raw_rates = [];
		foreach ( array_keys( $discovered ) as $letter ) {
			$row                 = is_array( $reported[ $letter ] ?? null ) ? $reported[ $letter ] : [];
			$readers             = max( 0, (int) ( $row['readers'] ?? 0 ) );
			$conversions         = max( 0, (int) ( $row['conversions'] ?? 0 ) );
			$variants[ $letter ] = [
				'readers'           => $readers,
				'conversions'       => $conversions,
				'logged_in_readers' => min( $readers, max( 0, (int) ( $row['logged_in_readers'] ?? 0 ) ) ),
				'rate'              => Newspack_Popups_AB_Stats::rate( $readers, $conversions ),
				'lift'              => null,
			];
			// Lift is taken from the unrounded rate: two arms that both display
			// as 0.01% can still differ by a third.
			$raw_rates[ $letter ] = $readers > 0 ? 100 * $conversions / $readers : null;
		}
		$control_rate = $raw_rates['a'] ?? null;
		foreach ( $variants as $letter => &$variant ) {
			if ( 'a' !== $letter ) {
				$variant['lift'] = Newspack_Popups_AB_Stats::lift( $control_rate, $raw_rates[ $letter ] );
			}
		}
		unset( $variant );
		return $variants;
	}

	/**
	 * Each challenger against the control.
	 *
	 * @param array $variants Merged variants.
	 * @return array Per challenger letter: `confidence`, `label`. Empty without a control.
	 */
	private static function compare( $variants ) {
		if ( ! isset( $variants['a'] ) ) {
			return [];
		}
		$control     = $variants['a'];
		$comparisons = [];
		foreach ( $variants as $letter => $variant ) {
			if ( 'a' === $letter ) {
				continue;
			}
			$confidence             = Newspack_Popups_AB_Stats::confidence( $control['readers'], $control['conversions'], $variant['readers'], $variant['conversions'] );
			$comparisons[ $letter ] = [
				'confidence' => $confidence,
				'label'      => Newspack_Popups_AB_Stats::label( $confidence ),
			];
		}
		return $comparisons;
	}

	/**
	 * The cautions the page shows beside a result.
	 *
	 * @param array $test     Test.
	 * @param array $variants Merged variants.
	 * @return array `young_test`, `high_anonymous`, `days_running`, `anonymous_share`.
	 */
	private static function compute_flags( $test, $variants ) {
		$days_running = null;
		if ( ! empty( $test['started_at'] ) ) {
			$days_running = (int) floor( ( time() - strtotime( $test['started_at'] ) ) / DAY_IN_SECONDS );
		}
		$readers         = array_sum( array_column( $variants, 'readers' ) );
		$logged_in       = array_sum( array_column( $variants, 'logged_in_readers' ) );
		$anonymous_share = $readers > 0 ? round( 1 - $logged_in / $readers, 2 ) : null;
		return [
			'young_test'      => null === $days_running || $days_running < self::YOUNG_TEST_DAYS,
			'high_anonymous'  => null !== $anonymous_share && $anonymous_share >= self::HIGH_ANONYMOUS_SHARE,
			'days_running'    => $days_running,
			'anonymous_share' => $anonymous_share,
		];
	}

	/**
	 * The provider supplying per-variant counts on this site, if any.
	 *
	 * @return Newspack_Popups_AB_Stats_Provider|null
	 */
	public static function get_provider() {
		/**
		 * Supplies the stats provider the A/B Tests dashboard reads from.
		 *
		 * One provider per site. Return an object implementing
		 * Newspack_Popups_AB_Stats_Provider; anything else counts as no
		 * provider, and the dashboard reports its stats source as unavailable.
		 *
		 * @param Newspack_Popups_AB_Stats_Provider|null $provider The provider; null when none is registered.
		 */
		$provider = apply_filters( 'newspack_popups_ab_stats_provider', null );
		return $provider instanceof Newspack_Popups_AB_Stats_Provider ? $provider : null;
	}
}

Newspack_Popups_AB_Dashboard::init();
