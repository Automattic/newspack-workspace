<?php
/**
 * The A/B Tests dashboard: its page, its stats route and the provider it reads from.
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tools → A/B Tests, behind the Campaigns A/B flag.
 */
final class Newspack_Popups_AB_Dashboard {
	/**
	 * Menu slug of the Tools page.
	 */
	const PAGE_SLUG = 'newspack-ab-tests';

	/**
	 * Script and style handle of the page's app.
	 */
	const SCRIPT_HANDLE = 'newspack-popups-ab-tests';

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
		add_action( 'admin_menu', [ __CLASS__, 'add_menu_page_item' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	/**
	 * Tools → A/B Tests.
	 */
	public static function add_menu_page_item() {
		if ( ! Newspack_Popups_AB_Tests::is_enabled() ) {
			return;
		}
		self::$page_hook = add_management_page(
			__( 'A/B Tests', 'newspack-popups' ),
			__( 'A/B Tests', 'newspack-popups' ),
			'manage_options',
			self::PAGE_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	/**
	 * The page's markup: a mount point the app fills.
	 */
	public static function render_page() {
		echo '<div class="wrap"><div id="newspack-popups-ab-tests-root"></div></div>';
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
		$dir = dirname( NEWSPACK_POPUPS_PLUGIN_FILE );
		\wp_register_script(
			self::SCRIPT_HANDLE,
			plugins_url( '../dist/abTests.js', __FILE__ ),
			[ 'wp-components', 'wp-api-fetch', 'wp-element', 'wp-i18n' ],
			filemtime( $dir . '/dist/abTests.js' ),
			true
		);
		wp_localize_script( self::SCRIPT_HANDLE, 'newspack_popups_ab_tests', self::get_boot_data() );
		\wp_enqueue_script( self::SCRIPT_HANDLE );
		\wp_register_style(
			self::SCRIPT_HANDLE,
			plugins_url( '../dist/abTests.css', __FILE__ ),
			null,
			filemtime( $dir . '/dist/abTests.css' )
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
		$provider  = self::get_provider();
		$available = $provider ? $provider->is_available() : null;
		$reason    = null;
		if ( null === $provider ) {
			$reason = __( 'No stats source is connected.', 'newspack-popups' );
		} elseif ( is_wp_error( $available ) ) {
			$reason = $available->get_error_message();
		}
		return [
			'tests'                 => Newspack_Popups_AB_Tests::get_tests(),
			'rest_base'             => 'newspack-popups/v1',
			'provider'              => [
				'available' => null !== $provider && true === $available,
				'slug'      => $provider ? $provider->get_slug() : null,
				'reason'    => $reason,
			],
			'thresholds'            => [
				'directional' => Newspack_Popups_AB_Stats::THRESHOLD_DIRECTIONAL,
				'reliable'    => Newspack_Popups_AB_Stats::THRESHOLD_RELIABLE,
				'min_readers' => Newspack_Popups_AB_Stats::MIN_READERS_PER_ARM,
			],
			'copy'                  => self::get_advisory_copy(),
			'labels'                => [
				'collecting'  => __( 'Collecting', 'newspack-popups' ),
				'directional' => __( 'Directional', 'newspack-popups' ),
				'reliable'    => __( 'Reliable', 'newspack-popups' ),
			],
			'editor_new_prompt_url' => admin_url( 'post-new.php?post_type=' . Newspack_Popups::NEWSPACK_POPUPS_CPT ),
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
			'high_anonymous' => __( "A large share of your readers aren't logged in. Test versions are assigned per browser, so these readers may see a different version across devices. When results are close, treat them with extra caution.", 'newspack-popups' ),
			'young_test'     => __( "This test doesn't have enough data yet to call a winner reliably. Keep it running until each version has been seen by more readers.", 'newspack-popups' ),
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

		$window   = self::get_window( $test );
		$provider = self::get_provider();
		$source   = [
			'state'      => 'collecting',
			'provider'   => $provider ? $provider->get_slug() : null,
			'reason'     => null,
			'fetched_at' => null,
			'cached'     => false,
			'unit'       => 'readers',
		];
		$counts   = [];

		if ( null === $provider ) {
			$source['state']  = 'unavailable';
			$source['reason'] = __( 'No stats source is connected.', 'newspack-popups' );
		} else {
			$available = $provider->is_available();
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
		$key = self::cache_key( $test, $provider->get_slug() );
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
		if ( ! is_array( $counts ) ) {
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
		return 'newspack_popups_ab_stats_' . md5(
			wp_json_encode(
				[
					$test['test_id'],
					$slug,
					$test['goal'],
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
		 * provider, and the dashboard shows every test as still collecting.
		 *
		 * @param Newspack_Popups_AB_Stats_Provider|null $provider The provider; null when none is registered.
		 */
		$provider = apply_filters( 'newspack_popups_ab_stats_provider', null );
		return $provider instanceof Newspack_Popups_AB_Stats_Provider ? $provider : null;
	}
}

Newspack_Popups_AB_Dashboard::init();
