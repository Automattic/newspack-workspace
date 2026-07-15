<?php
/**
 * Newspack Popups A/B Tests
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * A/B testing for prompts: meta registration, test configuration, and
 * reader bucket assignment.
 *
 * Two prompts (or more, post-v1) sharing a `newspack_ab_test_id` form a test.
 * Variant selection happens client-side in the view engine (src/view/utils/ab.js)
 * using the config this class localizes; logged-in readers get a server-computed
 * bucket persisted in user meta so assignment is stable across devices.
 */
final class Newspack_Popups_AB_Tests {

	const META_TEST_ID       = 'newspack_ab_test_id';
	const META_VARIANT       = 'newspack_ab_variant';
	const META_GOAL          = 'newspack_ab_test_goal';
	const META_CONTROL_SHARE = 'newspack_ab_control_share';

	const USER_META_BUCKET_PREFIX = 'np_ab_bucket_';

	const VALID_VARIANTS = [ 'a', 'b', 'c', 'd' ];

	const DEFAULT_CONTROL_SHARE = 50;

	/**
	 * Memoized tests config.
	 *
	 * @var array|null
	 */
	private static $tests_config = null;

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
	}

	/**
	 * Register A/B test meta fields on the prompts CPT.
	 */
	public static function register_meta() {
		$base = [
			'object_subtype' => Newspack_Popups::NEWSPACK_POPUPS_CPT,
			'show_in_rest'   => true,
			'single'         => true,
			'auth_callback'  => '__return_true',
		];

		\register_meta(
			'post',
			self::META_TEST_ID,
			array_merge(
				$base,
				[
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_title',
				]
			)
		);

		\register_meta(
			'post',
			self::META_VARIANT,
			array_merge(
				$base,
				[
					'type'              => 'string',
					'sanitize_callback' => [ __CLASS__, 'sanitize_variant' ],
				]
			)
		);

		\register_meta(
			'post',
			self::META_GOAL,
			array_merge(
				$base,
				[
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				]
			)
		);

		\register_meta(
			'post',
			self::META_CONTROL_SHARE,
			array_merge(
				$base,
				[
					'type'              => 'integer',
					'sanitize_callback' => [ __CLASS__, 'sanitize_control_share' ],
				]
			)
		);
	}

	/**
	 * Sanitize a variant key.
	 *
	 * @param string $value Raw value.
	 * @return string Valid variant key, or empty string.
	 */
	public static function sanitize_variant( $value ) {
		return in_array( $value, self::VALID_VARIANTS, true ) ? $value : '';
	}

	/**
	 * Sanitize a control share percentage, clamped to 20–80.
	 *
	 * @param int $value Raw value.
	 * @return int Clamped value.
	 */
	public static function sanitize_control_share( $value ) {
		return max( 20, min( 80, absint( $value ) ) );
	}

	/**
	 * Get the A/B fields for a single prompt.
	 *
	 * @param int $popup_id Prompt post ID.
	 * @return array|null Array with test_id and variant, or null if not part of a test.
	 */
	public static function get_popup_ab_fields( $popup_id ) {
		$test_id = get_post_meta( $popup_id, self::META_TEST_ID, true );
		$variant = get_post_meta( $popup_id, self::META_VARIANT, true );
		if ( ! $test_id || ! in_array( $variant, self::VALID_VARIANTS, true ) ) {
			return null;
		}
		return [
			'test_id' => $test_id,
			'variant' => $variant,
		];
	}

	/**
	 * Build the config for all valid A/B tests: tests with a published control
	 * and at least one published challenger.
	 *
	 * The full variant set is derived from published prompts regardless of which
	 * prompts render on a given page, so client-side hash ranges stay stable.
	 *
	 * @return array Config keyed by test ID: [ 'variants' => [ 'a', 'b' ], 'control_share' => 60 ].
	 */
	public static function get_tests_config() {
		if ( null !== self::$tests_config ) {
			return self::$tests_config;
		}

		$prompt_ids = get_posts(
			[
				'post_type'      => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => self::META_TEST_ID,
						'compare' => '!=',
						'value'   => '',
					],
				],
			]
		);

		$config = [];
		foreach ( $prompt_ids as $prompt_id ) {
			$fields = self::get_popup_ab_fields( $prompt_id );
			if ( ! $fields ) {
				continue;
			}
			$test_id = $fields['test_id'];
			$variant = $fields['variant'];
			if ( ! isset( $config[ $test_id ] ) ) {
				$config[ $test_id ] = [
					'variants'      => [],
					'control_share' => self::DEFAULT_CONTROL_SHARE,
				];
			}
			if ( ! in_array( $variant, $config[ $test_id ]['variants'], true ) ) {
				$config[ $test_id ]['variants'][] = $variant;
			}
			if ( 'a' === $variant ) {
				$control_share = get_post_meta( $prompt_id, self::META_CONTROL_SHARE, true );
				if ( $control_share ) {
					$config[ $test_id ]['control_share'] = absint( $control_share );
				}
			}
		}

		// Only tests with a control and at least one challenger are valid.
		$config = array_filter(
			$config,
			function ( $test ) {
				return in_array( 'a', $test['variants'], true ) && count( $test['variants'] ) >= 2;
			}
		);

		// Normalize variant order for stable client-side range math.
		foreach ( $config as $test_id => $test ) {
			sort( $config[ $test_id ]['variants'] );
		}

		self::$tests_config = $config;
		return $config;
	}

	/**
	 * Compute a stable bucket for a reader key using weighted ranges.
	 *
	 * Uses crc32, matching the POC's server-side assignment, so buckets computed
	 * before the core integration carry over for logged-in readers.
	 *
	 * @param string $reader_key Stable reader identifier (e.g. user ID).
	 * @param string $test_id    Test ID.
	 * @param array  $config     Test config with variants and control_share.
	 * @return string Variant key.
	 */
	public static function compute_bucket( $reader_key, $test_id, $config ) {
		$variants    = $config['variants'];
		$challengers = array_values(
			array_filter(
				$variants,
				function ( $variant ) {
					return 'a' !== $variant;
				}
			)
		);

		if ( empty( $challengers ) ) {
			return 'a';
		}

		$control_share    = ( $config['control_share'] ?? self::DEFAULT_CONTROL_SHARE ) / 100;
		$challenger_share = ( 1 - $control_share ) / count( $challengers );

		$ranges = [ [ 'a', $control_share ] ];
		$cursor = $control_share;
		foreach ( $challengers as $variant ) {
			$cursor  += $challenger_share;
			$ranges[] = [ $variant, $cursor ];
		}

		$hash       = abs( crc32( $reader_key . '|' . $test_id ) );
		$normalized = $hash / 4294967295;

		foreach ( $ranges as $range ) {
			if ( $normalized <= $range[1] ) {
				return $range[0];
			}
		}
		return end( $ranges )[0];
	}

	/**
	 * Get (computing and persisting on first encounter) the current logged-in
	 * user's buckets for the given tests.
	 *
	 * The persisted value always wins, so mid-test control-share edits never
	 * re-bucket a reader who has already been assigned.
	 *
	 * @param array $tests_config Config from get_tests_config().
	 * @return array Buckets keyed by test ID.
	 */
	public static function get_logged_in_buckets( $tests_config ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return [];
		}

		$buckets = [];
		foreach ( $tests_config as $test_id => $config ) {
			$meta_key = self::USER_META_BUCKET_PREFIX . $test_id;
			$bucket   = get_user_meta( $user_id, $meta_key, true );
			if ( ! in_array( $bucket, $config['variants'], true ) ) {
				$bucket = self::compute_bucket( (string) $user_id, $test_id, $config );
				update_user_meta( $user_id, $meta_key, $bucket );
			}
			$buckets[ $test_id ] = $bucket;
		}
		return $buckets;
	}

	/**
	 * Reset the memoized config (for tests).
	 */
	public static function reset_config_cache() {
		self::$tests_config = null;
	}
}

Newspack_Popups_AB_Tests::init();
