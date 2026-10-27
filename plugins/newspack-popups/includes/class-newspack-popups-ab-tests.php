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

	const META_TEST_ID       = 'newspack_popups_ab_test_id';
	const META_VARIANT       = 'newspack_popups_ab_variant';
	const META_GOAL          = 'newspack_popups_ab_test_goal';
	const META_CONTROL_SHARE = 'newspack_popups_ab_control_share';

	const META_KEYS = [ self::META_TEST_ID, self::META_VARIANT, self::META_GOAL, self::META_CONTROL_SHARE ];

	const USER_META_BUCKET_PREFIX = 'newspack_popups_ab_bucket_';

	/**
	 * Autoloaded flag recording whether any valid test exists.
	 *
	 * Three states, and the distinction matters: '1' and absent both fall through to
	 * the discovery query, only '0' short-circuits. Invalidation therefore deletes
	 * the option rather than writing '0', so a missed invalidation degrades to an
	 * extra query rather than to tests silently not running.
	 */
	const OPTION_HAS_TESTS = 'newspack_popups_ab_has_tests';

	const VALID_VARIANTS = [ 'a', 'b', 'c', 'd' ];

	const VALID_GOALS = [ 'form_submission', 'link_click', 'donation', 'subscription' ];

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
		add_filter( 'rest_pre_insert_' . Newspack_Popups::NEWSPACK_POPUPS_CPT, [ __CLASS__, 'guard_rest_ab_meta' ], 10, 2 );

		// Anything that can add or remove a test invalidates the flag. Scoped to the
		// prompts CPT and to the test-id meta key: a news site saves posts constantly,
		// and invalidating on those would reinstate the per-request query this exists
		// to avoid. Meta hooks are covered separately because update_post_meta() can
		// set a test id without firing save_post.
		add_action( 'save_post_' . Newspack_Popups::NEWSPACK_POPUPS_CPT, [ __CLASS__, 'invalidate_has_tests' ] );
		foreach ( [ 'deleted_post', 'trashed_post', 'untrashed_post' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'invalidate_has_tests_for_post' ] );
		}
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'invalidate_has_tests_for_meta' ], 10, 3 );
		}
	}

	/**
	 * Whether A/B testing is enabled on this site.
	 *
	 * The flag gates what reads A/B meta, never its registration. With it off,
	 * stored tests have no effect anywhere, while REST writes keep their sanitizers
	 * and schema checks, and a site can turn the flag off and on again without
	 * losing its test setup.
	 *
	 * Not cached, unlike the sibling flags: the filter has to stay live so one
	 * PHPUnit run can cover both states.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		/**
		 * Enables A/B testing for Campaigns prompts: running two versions of a
		 * prompt against each other and comparing how they convert.
		 *
		 * @constant NEWSPACK_CAMPAIGNS_AB_TESTING
		 * @type     bool
		 * @default  A/B testing disabled
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_CAMPAIGNS_AB_TESTING', true );
		 */
		$enabled = defined( 'NEWSPACK_CAMPAIGNS_AB_TESTING' ) && NEWSPACK_CAMPAIGNS_AB_TESTING;

		/**
		 * Filters whether A/B testing is enabled.
		 *
		 * @param bool $enabled Whether the NEWSPACK_CAMPAIGNS_AB_TESTING constant is set.
		 */
		return (bool) apply_filters( 'newspack_popups_ab_testing_enabled', $enabled );
	}

	/**
	 * Drop the has-tests flag.
	 */
	public static function invalidate_has_tests() {
		delete_option( self::OPTION_HAS_TESTS );
		self::$tests_config = null;
	}

	/**
	 * Drop the flag when a prompt is deleted, trashed or restored.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function invalidate_has_tests_for_post( $post_id ) {
		if ( Newspack_Popups::NEWSPACK_POPUPS_CPT === get_post_type( $post_id ) ) {
			self::invalidate_has_tests();
		}
	}

	/**
	 * Drop the flag when a prompt's test-id meta changes.
	 *
	 * @param int    $meta_id   Meta ID.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 */
	public static function invalidate_has_tests_for_meta( $meta_id, $object_id, $meta_key ) {
		if ( self::META_TEST_ID === $meta_key ) {
			self::invalidate_has_tests_for_post( $object_id );
		}
	}

	/**
	 * Register A/B test meta fields on the prompts CPT.
	 */
	public static function register_meta() {
		$base = [
			'object_subtype' => Newspack_Popups::NEWSPACK_POPUPS_CPT,
			'show_in_rest'   => true,
			'single'         => true,
			'auth_callback'  => [ __CLASS__, 'can_write_meta' ],
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
					// The enum makes an invalid REST write fail with a 400 the editor
					// can surface, instead of the sanitize callback silently coercing
					// it to '' (which would detach the prompt from its test on a 200).
					// The empty string stays valid: it is the "not part of a test" state.
					'show_in_rest'      => [
						'schema' => [
							'type' => 'string',
							'enum' => array_merge( [ '' ], self::VALID_VARIANTS ),
						],
					],
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
					'sanitize_callback' => [ __CLASS__, 'sanitize_goal' ],
					// Same reason as the variant enum: an unknown goal fails the REST
					// write with a 400 the editor can show, instead of being coerced to
					// '' (no goal) on a 200. The empty string stays valid.
					'show_in_rest'      => [
						'schema' => [
							'type' => 'string',
							'enum' => array_merge( [ '' ], self::VALID_GOALS ),
						],
					],
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
					// The explicit default keeps REST reads of an unset share at the
					// same 50 the display path assumes. Without it, the schema default
					// 0 would round-trip through an editor and clamp to 20 — silently
					// changing the live split and re-bucketing anonymous readers
					// mid-test (see get_tests_config()).
					'default'           => self::DEFAULT_CONTROL_SHARE,
					'show_in_rest'      => [
						'schema' => [
							'type'    => 'integer',
							'minimum' => 20,
							'maximum' => 80,
							'default' => self::DEFAULT_CONTROL_SHARE,
						],
					],
				]
			)
		);
	}

	/**
	 * Whether a user may change a prompt's A/B test settings.
	 *
	 * By default, anyone who can edit the prompt, the same rule as for the rest of
	 * its settings. Saves through the prompt's REST endpoint check here, both in the
	 * meta auth callback and in the save guard, so a narrower capability can
	 * replace the default in one place.
	 *
	 * @param int $user_id User ID.
	 * @param int $post_id Prompt post ID, or 0 for a prompt not yet created.
	 * @return bool
	 */
	public static function can_manage_tests( $user_id, $post_id ) {
		if ( $post_id ) {
			$allowed = user_can( $user_id, 'edit_post', $post_id );
		} else {
			$post_type = get_post_type_object( Newspack_Popups::NEWSPACK_POPUPS_CPT );
			$allowed   = $post_type && user_can( $user_id, $post_type->cap->edit_posts );
		}

		/**
		 * Filters whether a user may change a prompt's A/B test settings.
		 *
		 * A REST create asks twice: once with post ID 0 before the prompt exists,
		 * then with its new ID when the meta is written. Give the same answer both
		 * times, or the prompt is created and its A/B settings are refused.
		 *
		 * @param bool $allowed Whether the user may change them.
		 * @param int  $user_id User ID.
		 * @param int  $post_id Prompt post ID, or 0 for a prompt not yet created.
		 */
		return (bool) apply_filters( 'newspack_popups_ab_can_manage_tests', $allowed, $user_id, $post_id );
	}

	/**
	 * Meta auth callback for A/B keys.
	 *
	 * @param bool   $allowed   Core's default for the key; ignored.
	 * @param string $meta_key  Meta key.
	 * @param int    $object_id Prompt post ID.
	 * @param int    $user_id   User ID.
	 * @return bool
	 */
	public static function can_write_meta( $allowed, $meta_key, $object_id, $user_id ) {
		return self::can_manage_tests( (int) $user_id, (int) $object_id );
	}

	/**
	 * Hold A/B meta to can_manage_tests() on prompt saves without breaking other saves.
	 *
	 * The block editor sends a prompt's whole meta object on every save, A/B keys
	 * included, and core runs the write check on any key with no stored row. So for
	 * a user who may edit the prompt but not its test, unchanged A/B values are
	 * dropped from the request before core sees them, and a changed one refuses the
	 * request here, before the post or any other meta is written.
	 *
	 * Autosaves are the exception: they never store A/B meta, and core's autosave
	 * route ignores an error from this filter and would record an empty autosave,
	 * so there the A/B keys are dropped whatever their value.
	 *
	 * @param stdClass|WP_Error $prepared_post Post prepared for the database.
	 * @param WP_REST_Request   $request       Request.
	 * @return stdClass|WP_Error
	 */
	public static function guard_rest_ab_meta( $prepared_post, $request ) {
		$meta = $request->get_param( 'meta' );
		if ( is_wp_error( $prepared_post ) || ! is_array( $meta ) ) {
			return $prepared_post;
		}

		$post_id     = isset( $prepared_post->ID ) ? (int) $prepared_post->ID : 0;
		$is_autosave = str_ends_with( $request->get_route(), '/autosaves' );

		// A validity rule, not a permission, so it runs before the permission branch
		// and applies to administrators too. It reads the values the save would
		// leave in place: a key the request omits keeps its stored value. A
		// non-scalar reads as empty here; the meta schema refuses it on its own.
		$effective = function ( $meta_key ) use ( $meta, $post_id ) {
			$value = array_key_exists( $meta_key, $meta ) ? $meta[ $meta_key ] : ( $post_id ? get_post_meta( $post_id, $meta_key, true ) : '' );
			return is_scalar( $value ) ? (string) $value : '';
		};
		if ( ! $is_autosave && '' !== $effective( self::META_TEST_ID ) && ! self::is_eligible_placement( $effective( 'placement' ) ) ) {
			return new WP_Error(
				'newspack_popups_ab_ineligible_placement',
				__( "Prompts with a manual or custom placement can't be part of an A/B test. Clear the Test ID or change the placement.", 'newspack-popups' ),
				[ 'status' => 400 ]
			);
		}

		if ( self::can_manage_tests( get_current_user_id(), $post_id ) ) {
			return $prepared_post;
		}

		$registered = get_registered_meta_keys( 'post', Newspack_Popups::NEWSPACK_POPUPS_CPT );
		foreach ( self::META_KEYS as $meta_key ) {
			if ( ! array_key_exists( $meta_key, $meta ) ) {
				continue;
			}
			// get_post_meta() applies the registered default to an unset key, which
			// is also what the editor read and is sending back.
			$stored = $post_id ? get_post_meta( $post_id, $meta_key, true ) : ( $registered[ $meta_key ]['default'] ?? '' );
			// A null asks core to delete the key, which resets it to its registered default.
			$value = $meta[ $meta_key ] ?? ( $registered[ $meta_key ]['default'] ?? '' );
			if ( ! $is_autosave && ( ! is_scalar( $value ) || (string) $value !== (string) $stored ) ) {
				return new WP_Error(
					'rest_cannot_update',
					__( "You don't have permission to change this prompt's A/B test settings. If someone changed its test while you were editing, reload the editor and try again.", 'newspack-popups' ),
					[
						'status' => rest_authorization_required_code(),
						'key'    => $meta_key,
					]
				);
			}
			unset( $meta[ $meta_key ] );
		}
		$request->set_param( 'meta', $meta );

		return $prepared_post;
	}

	/**
	 * What the prompt editor needs to show the A/B test panel: whether the
	 * feature is on, whether this user may change the fields, and the vocabulary
	 * the fields offer. Variants stop at B because the editor supports two-arm
	 * tests; the server keeps accepting c and d so stored ones are not lost.
	 *
	 * @param int $post_id Prompt post ID, or 0 for a prompt not yet created.
	 * @return array
	 */
	public static function get_editor_data( $post_id ) {
		return [
			'enabled'               => self::is_enabled(),
			'can_manage_tests'      => self::can_manage_tests( get_current_user_id(), (int) $post_id ),
			'goals'                 => self::get_goal_labels(),
			'ineligible_placements' => self::get_ineligible_placements(),
			'variants'              => [
				'a' => __( 'A (control)', 'newspack-popups' ),
				'b' => __( 'B (challenger)', 'newspack-popups' ),
			],
		];
	}

	/**
	 * The prompts carrying a Test ID, for the editor's list of the other prompts in
	 * the test. Drafts and pending prompts are included, since an author sets up the
	 * challenger before publishing it; trashed ones are not.
	 *
	 * @param string $test_id Test ID.
	 * @return array[] Each `[ 'id', 'title', 'variant', 'status', 'edit_link' ]`; `edit_link` is null when the current user can't edit that prompt.
	 */
	public static function get_test_prompts( $test_id ) {
		$test_id = sanitize_key( $test_id );
		if ( '' === $test_id ) {
			return [];
		}
		$prompts = get_posts(
			[
				'post_type'      => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_status'    => 'any',
				// Same reasoning as get_tests_config(): a bound would silently drop
				// prompts from the list.
				'posts_per_page' => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging -- Prompts CPT; config-scale.
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_key'       => self::META_TEST_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $test_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
		$list    = [];
		foreach ( $prompts as $prompt ) {
			if ( in_array( $prompt->post_status, [ 'trash', 'auto-draft' ], true ) ) {
				continue;
			}
			$list[] = [
				'id'        => (int) $prompt->ID,
				'title'     => $prompt->post_title,
				'variant'   => (string) get_post_meta( $prompt->ID, self::META_VARIANT, true ),
				'status'    => $prompt->post_status,
				'edit_link' => get_edit_post_link( $prompt->ID, 'raw' ),
			];
		}
		return $list;
	}

	/**
	 * Placements a tested prompt can't have. Manual and custom placements render
	 * wherever a block or shortcode puts them, so there is no slot to split
	 * between variants.
	 *
	 * @return string[] Placement values.
	 */
	public static function get_ineligible_placements() {
		return array_merge( [ 'manual' ], Newspack_Popups_Custom_Placements::get_custom_placement_values() );
	}

	/**
	 * Whether a prompt with this placement can be part of a test.
	 *
	 * @param string $placement Placement value.
	 * @return bool
	 */
	public static function is_eligible_placement( $placement ) {
		return ! in_array( (string) $placement, self::get_ineligible_placements(), true );
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
	 * Sanitize a conversion goal.
	 *
	 * @param string $value Raw value.
	 * @return string Valid goal key, or empty string.
	 */
	public static function sanitize_goal( $value ) {
		return in_array( $value, self::VALID_GOALS, true ) ? $value : '';
	}

	/**
	 * The conversion goals a test can measure, keyed by stored value.
	 *
	 * @return array Goal key => label.
	 */
	public static function get_goal_labels() {
		return [
			'form_submission' => __( 'Form submission', 'newspack-popups' ),
			'link_click'      => __( 'Link or button click', 'newspack-popups' ),
			'donation'        => __( 'Donation', 'newspack-popups' ),
			'subscription'    => __( 'Subscription', 'newspack-popups' ),
		];
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
	 * @param int  $popup_id Prompt post ID.
	 * @param bool $validate If true, only return fields when the prompt's test is
	 *                       valid (published control + at least one published
	 *                       challenger) — an invalid test must not present itself
	 *                       as a live experiment in markup or analytics params.
	 * @return array|null Array with test_id and variant, or null if not part of a test
	 *                    or A/B testing is disabled.
	 */
	public static function get_popup_ab_fields( $popup_id, $validate = false ) {
		if ( ! self::is_enabled() ) {
			return null;
		}
		$test_id = get_post_meta( $popup_id, self::META_TEST_ID, true );
		$variant = get_post_meta( $popup_id, self::META_VARIANT, true );
		if ( ! $test_id || ! in_array( $variant, self::VALID_VARIANTS, true ) ) {
			return null;
		}
		if ( $validate && ! isset( self::get_tests_config()[ $test_id ] ) ) {
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
		// Ahead of the memo and the has-tests flag, and writing neither: a '0'
		// recorded while the feature is off would outlive the flag and keep live
		// tests from running once it is turned back on.
		if ( ! self::is_enabled() ) {
			return [];
		}

		if ( null !== self::$tests_config ) {
			return self::$tests_config;
		}

		// Every non-AMP front-end request reaches this, on every site. WP's query cache
		// absorbs the repeat cost only until the next post save, which on a news site
		// is continuous -- so without this a site with zero tests pays a postmeta-join
		// query more or less per request, forever.
		if ( '0' === get_option( self::OPTION_HAS_TESTS, '' ) ) {
			self::$tests_config = [];
			return self::$tests_config;
		}

		$prompt_ids = get_posts(
			[
				'post_type'      => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_status'    => 'publish',
				// The prompts CPT is inherently small (tens of posts) and this is
				// further narrowed by the meta filter; a bound here would silently
				// truncate the config and drop whole tests (fail-open, uncounted).
				'posts_per_page' => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging -- Prompts CPT; config-scale.
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

		// 'fields' => 'ids' skips meta-cache priming, so without this each
		// get_post_meta() below would issue its own query — an N+1 on the
		// always-enqueued front-end path. One batched load instead.
		if ( ! empty( $prompt_ids ) ) {
			update_meta_cache( 'post', $prompt_ids );
		}

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
					// Clamp at read time too: direct meta writes (importers, CLI)
					// bypass the sanitize callback, and an out-of-range share would
					// skew the client-side range math.
					$config[ $test_id ]['control_share'] = self::sanitize_control_share( $control_share );
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

		// update_option() is a no-op when the stored value already matches, so this
		// does not write on every request.
		update_option( self::OPTION_HAS_TESTS, empty( $config ) ? '0' : '1', true );

		self::$tests_config = $config;
		return $config;
	}

	/**
	 * The djb2 (xor variant) string hash, bit-for-bit identical to the client-side
	 * hash in src/view/utils/ab.js. Server and client MUST use the same hash on
	 * the same identity (the client ID cookie) or a reader can land in different
	 * arms anonymous vs. logged-in. Parity is pinned by tests on both sides.
	 *
	 * ASCII-ONLY PRECONDITION: this hashes UTF-8 *bytes* (strlen/ord) while the
	 * client hashes UTF-16 *code units* (charCodeAt) — the two agree only while
	 * every hashed input (client ID, test ID) is ASCII. That holds today by
	 * construction (generated cookie IDs; sanitize_title output). If either
	 * input's charset ever widens, normalize both implementations together.
	 *
	 * @param string $str String to hash.
	 * @return int Unsigned 32-bit hash.
	 */
	public static function hash_djb2( $str ) {
		$hash = 5381;
		$len  = strlen( $str );
		for ( $i = 0; $i < $len; $i++ ) {
			$hash = ( ( ( $hash << 5 ) + $hash ) ^ ord( $str[ $i ] ) ) & 0xFFFFFFFF;
		}
		return $hash;
	}

	/**
	 * Compute a stable bucket for a reader key using weighted ranges.
	 *
	 * @param string $reader_key Stable reader identifier (client ID cookie, or user ID as fallback).
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

		$hash       = self::hash_djb2( $reader_key . '|' . $test_id );
		$normalized = $hash / 4294967295;

		foreach ( $ranges as $range ) {
			if ( $normalized <= $range[1] ) {
				return $range[0];
			}
		}
		return end( $ranges )[0];
	}

	/**
	 * Get the reader's client ID from the Reader Activation cookie, if present.
	 *
	 * @return string Client ID, or empty string.
	 */
	public static function get_client_id() {
		$cookie_name = defined( 'NEWSPACK_CLIENT_ID_COOKIE_NAME' ) ? NEWSPACK_CLIENT_ID_COOKIE_NAME : 'newspack-cid'; // phpcs:ignore phpcsSniffs.Constants.ConstantDocblock.Missing -- Internal constant owned by newspack-plugin, not publisher-configurable.
		return isset( $_COOKIE[ $cookie_name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) ) : ''; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}

	/**
	 * Get (computing and persisting on first encounter) the current logged-in
	 * user's buckets for the given tests.
	 *
	 * First assignment prefers the client ID cookie as the hash key — the same
	 * identity and hash the anonymous client-side assignment uses — so a reader
	 * who registers mid-test stays in the arm they were already seeing. The user
	 * ID is the fallback key when no client ID is available. The persisted value
	 * wins thereafter for as long as it names a published variant, so mid-test
	 * control-share edits never re-bucket a reader, and assignment is stable across
	 * devices once logged in.
	 *
	 * The exception is a stored variant that leaves the published set: the guard
	 * below recomputes and overwrites it. In a two-arm test that cannot happen --
	 * unpublishing the only challenger ends the test -- but with three or more arms,
	 * briefly drafting one variant permanently reassigns every reader holding it, and
	 * republishing does not bring them back. Tracked separately; the fix is to keep
	 * any stored VALID_VARIANTS value and fall back only for display.
	 *
	 * @param array $tests_config Config from get_tests_config().
	 * @return array Buckets keyed by test ID.
	 */
	public static function get_logged_in_buckets( $tests_config ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return [];
		}

		$client_id = self::get_client_id();
		$buckets   = [];
		foreach ( $tests_config as $test_id => $config ) {
			$meta_key = self::USER_META_BUCKET_PREFIX . $test_id;
			$bucket   = get_user_meta( $user_id, $meta_key, true );
			if ( ! in_array( $bucket, $config['variants'], true ) ) {
				$reader_key = $client_id ? $client_id : (string) $user_id;
				$bucket     = self::compute_bucket( $reader_key, $test_id, $config );
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
		delete_option( self::OPTION_HAS_TESTS );
	}
}

Newspack_Popups_AB_Tests::init();
