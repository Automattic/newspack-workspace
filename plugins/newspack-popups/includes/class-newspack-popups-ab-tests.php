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

	// Not the whole key: bucket_meta_key() puts the site's table prefix in front.
	const USER_META_BUCKET_PREFIX = 'newspack_popups_ab_bucket_';

	/**
	 * Private taxonomy holding one term per test: the slug is the Test ID, the
	 * name is what publishers see. Prompts still join a test through
	 * META_TEST_ID, so a test with no term is valid and shows its ID as its name.
	 */
	const TAXONOMY = 'newspack_popups_ab_test';

	/**
	 * Term meta on an ended test's term: what was kept and when, and the
	 * versions as they were, since ending takes the prompts out of the test.
	 */
	const TERM_META_ENDED = 'newspack_popups_ab_ended';

	/**
	 * Term meta on a test's term: when it started, ISO 8601 UTC, written once
	 * when a control and a challenger are first both published.
	 */
	const TERM_META_STARTED = 'newspack_popups_ab_started';

	/**
	 * Term meta on an ended test's term: the final results, written once.
	 */
	const TERM_META_FINAL_COUNTS = 'newspack_popups_ab_final_counts';

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
	 * Test IDs that a meta write in progress is removing, by meta ID.
	 *
	 * @var string[]
	 */
	private static $leaving_test_ids = [];

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'init', [ __CLASS__, 'register_taxonomy' ] );
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

		// A status hook rather than the A/B routes: the Campaigns wizard, batch
		// publish, wp-admin and cron all publish prompts without going through
		// them. The meta hooks cover a prompt that joins a test while already
		// live, which an editor save does after the status change.
		add_action( 'transition_post_status', [ __CLASS__, 'record_start_on_publish' ], 10, 3 );
		foreach ( [ 'added_post_meta', 'updated_post_meta' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'record_start_for_meta' ], 10, 3 );
		}

		// Meta hooks rather than post ones, so one rule covers every way a
		// prompt leaves a test: End Test clearing its Test ID, an editor clearing
		// or changing it, and deleting the prompt, whose meta goes with it.
		// Not gated on the flag: they only remove the feature's own reader meta,
		// and a site that turned the flag off still wants it gone.
		add_action( 'update_post_meta', [ __CLASS__, 'note_leaving_test_id' ], 10, 3 );
		add_action( 'delete_post_meta', [ __CLASS__, 'note_leaving_test_id' ], 10, 3 );
		add_action( 'updated_post_meta', [ __CLASS__, 'drop_buckets_of_empty_tests' ], 10, 3 );
		add_action( 'deleted_post_meta', [ __CLASS__, 'drop_buckets_of_empty_tests' ], 10, 3 );
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
	 * Remember the Test ID a meta write is about to remove or replace. It is read
	 * here because by the after-hooks the row has been replaced or removed.
	 *
	 * @param int|int[] $meta_ids  Meta ID, or IDs for a delete.
	 * @param int       $object_id Post ID, or 0 for a delete across all posts.
	 * @param string    $meta_key  Meta key.
	 */
	public static function note_leaving_test_id( $meta_ids, $object_id, $meta_key ) {
		if ( self::META_TEST_ID !== $meta_key ) {
			return;
		}
		foreach ( (array) $meta_ids as $meta_id ) {
			$meta = get_metadata_by_mid( 'post', $meta_id );
			if ( $meta && '' !== (string) $meta->meta_value ) {
				self::$leaving_test_ids[ (int) $meta_id ] = (string) $meta->meta_value;
			}
		}
	}

	/**
	 * Drop a test's buckets once no prompt carries its ID. Trashed prompts still
	 * count, since restoring one brings the test back.
	 *
	 * A reader request already in progress can write one bucket back after this
	 * runs. Nothing reads it unless a later test takes the same ID, which a named
	 * test's term prevents.
	 *
	 * @param int|int[] $meta_ids  Meta ID, or IDs for a delete.
	 * @param int       $object_id Post ID, or 0 for a delete across all posts.
	 * @param string    $meta_key  Meta key.
	 */
	public static function drop_buckets_of_empty_tests( $meta_ids, $object_id, $meta_key ) {
		if ( self::META_TEST_ID !== $meta_key ) {
			return;
		}
		$test_ids = [];
		foreach ( (array) $meta_ids as $meta_id ) {
			if ( isset( self::$leaving_test_ids[ (int) $meta_id ] ) ) {
				$test_ids[] = self::$leaving_test_ids[ (int) $meta_id ];
				unset( self::$leaving_test_ids[ (int) $meta_id ] );
			}
		}
		foreach ( array_unique( $test_ids ) as $test_id ) {
			if ( empty( self::get_test_prompt_ids( $test_id ) ) ) {
				self::delete_buckets( $test_id );
			}
		}
	}

	/**
	 * Remove every reader's stored bucket for a test.
	 *
	 * One DELETE, but core then clears each affected reader's meta cache, so the
	 * cost grows with the number of logged-in readers the test saw.
	 *
	 * @param string $test_id Test ID.
	 */
	private static function delete_buckets( $test_id ) {
		if ( '' === $test_id ) {
			return;
		}
		delete_metadata( 'user', 0, self::bucket_meta_key( $test_id ), '', true );
	}

	/**
	 * The user meta key that holds a reader's bucket for a test on this site.
	 *
	 * User meta is shared by every site in a network, so the key carries this
	 * site's table prefix, as core's per-site user options do. Two sites can then
	 * use the same Test ID, and ending one test leaves the other's readers alone.
	 * Raw user meta rather than get_user_option(), which falls back to the
	 * unprefixed key that every site shares.
	 *
	 * @param string $test_id Test ID.
	 * @return string Meta key.
	 */
	public static function bucket_meta_key( $test_id ) {
		global $wpdb;
		return $wpdb->get_blog_prefix() . self::USER_META_BUCKET_PREFIX . $test_id;
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
	 * Record a test's start when one of its prompts goes live.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public static function record_start_on_publish( $new_status, $old_status, $post ) {
		if ( 'publish' === $new_status && 'publish' !== $old_status && Newspack_Popups::NEWSPACK_POPUPS_CPT === $post->post_type ) {
			self::maybe_record_start( $post->ID );
		}
	}

	/**
	 * Record a test's start when a live prompt is given a Test ID or a version.
	 *
	 * @param int    $meta_id   Meta ID.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 */
	public static function record_start_for_meta( $meta_id, $object_id, $meta_key ) {
		if ( ( self::META_TEST_ID === $meta_key || self::META_VARIANT === $meta_key ) && 'publish' === get_post_status( $object_id ) && Newspack_Popups::NEWSPACK_POPUPS_CPT === get_post_type( $object_id ) ) {
			self::maybe_record_start( $object_id );
		}
	}

	/**
	 * Store the moment a prompt's test started, if this is that moment: the
	 * first time a control and a challenger are both published. Once recorded,
	 * pausing and republishing a version keeps it, since the results count from
	 * it; a test with no record yet, such as one already running before starts
	 * were recorded, gets one at its next publish. Nothing is recorded with the
	 * flag off, when readers aren't split, or for a test that has ended.
	 *
	 * @param int $post_id A published prompt's ID.
	 */
	private static function maybe_record_start( $post_id ) {
		if ( ! self::is_enabled() ) {
			return;
		}
		$test_id = trim( (string) get_post_meta( $post_id, self::META_TEST_ID, true ) );
		if ( '' === $test_id || ! in_array( get_post_meta( $post_id, self::META_VARIANT, true ), self::VALID_VARIANTS, true ) ) {
			return;
		}
		$existing = get_term_by( 'slug', $test_id, self::TAXONOMY );
		if ( $existing && '' !== (string) get_term_meta( $existing->term_id, self::TERM_META_STARTED, true ) ) {
			return;
		}
		$live = get_posts(
			[
				'post_type'      => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_status'    => 'publish',
				'posts_per_page' => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging -- Prompts in one test.
				'fields'         => 'ids',
				'meta_key'       => self::META_TEST_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The Test ID lives in meta.
				'meta_value'     => $test_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The Test ID lives in meta.
			]
		);
		$letters = array_map( fn( $id ) => (string) get_post_meta( $id, self::META_VARIANT, true ), $live );
		if ( ! in_array( 'a', $letters, true ) || empty( array_intersect( array_diff( self::VALID_VARIANTS, [ 'a' ] ), $letters ) ) ) {
			return;
		}
		if ( null !== self::get_ended_record( $test_id ) ) {
			return;
		}
		$term = self::ensure_term( $test_id, $test_id );
		if ( is_wp_error( $term ) ) {
			return;
		}
		// Unique, which is a read before the insert rather than a lock: two
		// requests at once can each add a row, and reads return the first.
		add_term_meta( $term->term_id, self::TERM_META_STARTED, gmdate( 'Y-m-d\TH:i:s\Z' ), true );
	}

	/**
	 * Recorded starts by Test ID, for the tests that have one.
	 *
	 * @param string[] $test_ids Test IDs.
	 * @return array<string,string> Test ID => ISO 8601 UTC.
	 */
	private static function get_recorded_starts( array $test_ids ) {
		if ( empty( $test_ids ) ) {
			return [];
		}
		$terms  = get_terms(
			[
				'taxonomy'   => self::TAXONOMY,
				'slug'       => $test_ids,
				'hide_empty' => false,
			]
		);
		$starts = [];
		foreach ( is_array( $terms ) ? $terms : [] as $term ) {
			$started = get_term_meta( $term->term_id, self::TERM_META_STARTED, true );
			if ( is_string( $started ) && '' !== $started ) {
				$starts[ (string) $term->slug ] = $started;
			}
		}
		return $starts;
	}

	/**
	 * Register the taxonomy that holds test names. Registered whether or not the
	 * feature flag is on, like the meta: the terms outlive the flag either way, but
	 * core can only read them back while the taxonomy is registered.
	 */
	public static function register_taxonomy() {
		register_taxonomy(
			self::TAXONOMY,
			[],
			[
				'public'             => false,
				'show_ui'            => false,
				'show_in_menu'       => false,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => false,
				'show_tagcloud'      => false,
				'show_in_quick_edit' => false,
				'show_admin_column'  => false,
				'hierarchical'       => false,
				'rewrite'            => false,
				'query_var'          => false,
			]
		);
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
					'sanitize_callback' => [ __CLASS__, 'sanitize_test_id' ],
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
		$stored    = function ( $meta_key ) use ( $post_id ) {
			return $post_id ? (string) get_post_meta( $post_id, $meta_key, true ) : '';
		};
		$effective = function ( $meta_key ) use ( $meta, $stored ) {
			if ( ! array_key_exists( $meta_key, $meta ) ) {
				return $stored( $meta_key );
			}
			return is_scalar( $meta[ $meta_key ] ) ? (string) $meta[ $meta_key ] : '';
		};
		$changes   = function ( $meta_key ) use ( $effective, $stored ) {
			return $effective( $meta_key ) !== $stored( $meta_key );
		};
		// The ID the save would store, so a value the sanitizer empties reads as
		// clearing the test rather than as a new one.
		$test_id = self::sanitize_test_id( $effective( self::META_TEST_ID ) );
		// The rule refuses the save that creates a violation, not every later save
		// of a prompt already in one. A tested prompt can reach a manual placement
		// past this guard (the Campaigns wizard writes placement directly, and a
		// save while the flag is off is not checked), and the editor sends the
		// whole meta object on every save, so checking the stored state too would
		// refuse a frequency edit with an error about a field the editor may have
		// disabled for that user. The editor's notice asks for the fix instead.
		// An ID sent back as stored is unchanged here as it is for the write below,
		// even where the current sanitizer would spell it differently.
		// Only while the flag is on: with it off a stored test has no effect, and
		// the panel that could clear the Test ID is not registered.
		if (
			self::is_enabled()
			&& ! $is_autosave
			&& ( ( $changes( self::META_TEST_ID ) && $test_id !== $stored( self::META_TEST_ID ) ) || $changes( 'placement' ) || $changes( 'manual_only' ) )
			&& '' !== $test_id
			&& ! self::is_eligible_placement( $effective( 'placement' ), rest_sanitize_boolean( $effective( 'manual_only' ) ) )
		) {
			return new WP_Error(
				'newspack_popups_ab_ineligible_placement',
				self::is_eligible_placement( $effective( 'placement' ) )
					? __( 'A/B tests only work on prompts that Newspack places for you. Turn off Manual only.', 'newspack-popups' )
					: __( 'A/B tests only work on prompts that Newspack places for you. Choose another placement.', 'newspack-popups' ),
				[ 'status' => 400 ]
			);
		}

		// A Test ID sent back unchanged is left out of the write. Core re-sanitizes
		// a submitted value before comparing it with the stored one, so an ID stored
		// under an earlier, looser sanitizer would otherwise be rewritten on one
		// variant by any save and split its test; only a deliberate edit normalizes it.
		if ( array_key_exists( self::META_TEST_ID, $meta ) && is_scalar( $meta[ self::META_TEST_ID ] ) && ! $changes( self::META_TEST_ID ) ) {
			unset( $meta[ self::META_TEST_ID ] );
			$request->set_param( 'meta', $meta );
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
			$stored_value = $post_id ? get_post_meta( $post_id, $meta_key, true ) : ( $registered[ $meta_key ]['default'] ?? '' );
			// A null asks core to delete the key, which resets it to its registered default.
			$value = $meta[ $meta_key ] ?? ( $registered[ $meta_key ]['default'] ?? '' );
			if ( ! $is_autosave && ( ! is_scalar( $value ) || (string) $value !== (string) $stored_value ) ) {
				return new WP_Error(
					'rest_cannot_update',
					__( "You can't change this prompt's test settings. If someone else just edited it, reload and try again.", 'newspack-popups' ),
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
			'results_url'           => class_exists( 'Newspack_Popups_AB_Dashboard' ) ? Newspack_Popups_AB_Dashboard::get_page_url() : '',
			'test_name'             => $post_id ? self::get_test_name( (string) get_post_meta( (int) $post_id, self::META_TEST_ID, true ) ) : '',
		];
	}

	/**
	 * The prompts carrying a Test ID, for the editor's list of the other prompts in
	 * the test. Drafts and pending prompts are included, since an author sets up the
	 * challenger before publishing it; trashed ones are not. Private prompts follow
	 * the Prompts list's rule: another author's are listed only to a user with
	 * read_private_posts, which is why the statuses are spelled out. WP_Query
	 * applies 'readable' to an explicit list and ignores it for 'any'.
	 *
	 * @param string $test_id Test ID.
	 * @return array[] Each `[ 'id', 'title', 'variant', 'goal', 'control_share', 'status', 'status_label', 'edit_link' ]`; `edit_link` is null when the current user can't edit that prompt. `goal` and `control_share` are each prompt's stored values; only the control's take effect.
	 */
	public static function get_test_prompts( $test_id ) {
		$prompts = [];
		foreach ( self::test_id_candidates( $test_id ) as $candidate ) {
			$prompts = self::query_test_prompts( $candidate );
			if ( $prompts ) {
				break;
			}
		}
		$list = [];
		foreach ( $prompts as $prompt ) {
			$status = get_post_status_object( $prompt->post_status );
			$share  = get_post_meta( $prompt->ID, self::META_CONTROL_SHARE, true );
			$list[] = [
				'id'            => (int) $prompt->ID,
				'title'         => $prompt->post_title,
				'variant'       => (string) get_post_meta( $prompt->ID, self::META_VARIANT, true ),
				'goal'          => self::sanitize_goal( get_post_meta( $prompt->ID, self::META_GOAL, true ) ),
				'control_share' => $share ? self::sanitize_control_share( $share ) : self::DEFAULT_CONTROL_SHARE,
				'status'        => $prompt->post_status,
				'status_label'  => $status ? $status->label : $prompt->post_status,
				'edit_link'     => get_edit_post_link( $prompt->ID, 'raw' ),
			];
		}
		return $list;
	}

	/**
	 * The prompts whose stored Test ID is exactly this one.
	 *
	 * @param string $test_id Test ID as stored.
	 * @return WP_Post[]
	 */
	private static function query_test_prompts( $test_id ) {
		return get_posts(
			[
				'post_type'      => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_status'    => [ 'publish', 'future', 'draft', 'pending', 'private' ],
				'perm'           => 'readable',
				// Same reasoning as get_tests_config(): a bound would silently drop
				// prompts from the list.
				'posts_per_page' => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging -- Prompts CPT; config-scale.
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_key'       => self::META_TEST_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $test_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
	}

	/**
	 * Every test on the site, for the A/B Tests dashboard.
	 *
	 * Tests are discovered from prompts carrying a Test ID, in every non-trashed
	 * status, so a test still being set up (one arm published, the other a draft)
	 * is listed with its state rather than hidden until it is valid. A prompt
	 * whose variant key is outside VALID_VARIANTS is skipped: the schema will not
	 * save one, so one can only come from a direct meta write.
	 *
	 * @return array[] Ordered by Test ID. Each `[ 'test_id', 'name', 'goal', 'goal_label',
	 *                 'control_share', 'started_at', 'variants', 'duplicates', 'prompt_count' ]`. `control_share`
	 *                 comes from the control prompt, or DEFAULT_CONTROL_SHARE without
	 *                 one. `started_at` is when the split began, ISO 8601 UTC, or
	 *                 null while a control and a challenger aren't both published.
	 *                 It is the start recorded when the split began (a resume
	 *                 with a new challenger records a new one); a test with no
	 *                 record falls back to the later of the control's publish
	 *                 date and the earliest challenger's. Readers see the
	 *                 control alone before the split, so an earlier date would
	 *                 count traffic the test never split. `variants` maps the
	 *                 letter to `[ 'id', 'title', 'status', 'status_label',
	 *                 'edit_link', 'eligible' ]`,
	 *                 where `eligible` is false for a manual or custom placement
	 *                 and for an overlay set to Manual only.
	 *                 `duplicates` lists the other prompts carrying a letter, as
	 *                 `letter` plus those fields. The arm shown is the first
	 *                 published prompt by ID, or the first by ID when none is
	 *                 published; this page reports that one, but readers still
	 *                 see every published prompt with the letter. `prompt_count`
	 *                 counts every untrashed prompt with the Test ID, those with no
	 *                 version included, since a start refuses a test that has any.
	 */
	public static function get_tests() {
		$prompts = get_posts(
			[
				'post_type'      => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_status'    => [ 'publish', 'future', 'draft', 'pending', 'private' ],
				'perm'           => 'readable',
				'posts_per_page' => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging -- Prompts CPT; config-scale.
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The Test ID lives in meta; every prompt in a test carries it.
					[
						'key'     => self::META_TEST_ID,
						'value'   => '',
						'compare' => '!=',
					],
				],
			]
		);
		$grouped       = [];
		$prompt_counts = [];
		foreach ( $prompts as $prompt ) {
			// Keyed by the ID as stored, as get_tests_config() keys the test readers
			// see: a legacy footer_test and footer-test are two tests, not one.
			$test_id = trim( (string) get_post_meta( $prompt->ID, self::META_TEST_ID, true ) );
			$variant = (string) get_post_meta( $prompt->ID, self::META_VARIANT, true );
			if ( '' === $test_id ) {
				continue;
			}
			$prompt_counts[ $test_id ] = ( $prompt_counts[ $test_id ] ?? 0 ) + 1;
			if ( ! in_array( $variant, self::VALID_VARIANTS, true ) ) {
				continue;
			}
			$grouped[ $test_id ][ $variant ][] = $prompt;
		}
		ksort( $grouped );

		$labels = self::get_goal_labels();
		$names  = self::get_test_names( array_map( 'strval', array_keys( $grouped ) ) );
		$starts = self::get_recorded_starts( array_map( 'strval', array_keys( $grouped ) ) );
		$tests  = [];
		foreach ( $grouped as $test_id => $letters ) {
			ksort( $letters );
			$test_id = (string) $test_id; // PHP stores an integer-like key as an int; get_stats() compares the ID strictly.
			$test    = [
				'test_id'       => $test_id,
				'name'          => $names[ $test_id ] ?? $test_id,
				'goal'          => '',
				'goal_label'    => '',
				'control_share' => self::DEFAULT_CONTROL_SHARE,
				'started_at'    => null,
				'variants'      => [],
				'duplicates'    => [],
				'prompt_count'  => $prompt_counts[ $test_id ],
			];
			$published_at = [];
			$live         = [];
			$control_goal = null;
			foreach ( $letters as $variant => $candidates ) {
				// Two prompts on one letter: this page reports the first published
				// one, or the first by ID when none is, and lists the rest so an
				// editor can split them. Readers still see every published prompt.
				$kept = null;
				foreach ( $candidates as $candidate ) {
					if ( 'publish' === $candidate->post_status ) {
						$kept = $candidate;
						break;
					}
				}
				$kept = $kept ?? $candidates[0];
				foreach ( $candidates as $candidate ) {
					if ( $candidate !== $kept ) {
						$test['duplicates'][] = [ 'letter' => $variant ] + self::describe_prompt( $candidate );
					}
				}
				$test['variants'][ $variant ] = self::describe_prompt( $kept );

				if ( 'a' === $variant ) {
					$share = get_post_meta( $kept->ID, self::META_CONTROL_SHARE, true );
					if ( '' !== $share && null !== $share ) {
						$test['control_share'] = self::sanitize_control_share( $share );
					}
				}

				// The control's goal is the test's, an empty one included, since the
				// editor sets the goal on version A only. A challenger's stored goal
				// counts only while the test has no control.
				$goal = self::sanitize_goal( get_post_meta( $kept->ID, self::META_GOAL, true ) );
				if ( 'a' === $variant ) {
					$control_goal = $goal;
				} elseif ( '' !== $goal && '' === $test['goal'] ) {
					$test['goal'] = $goal;
				}

				if ( 'publish' === $kept->post_status ) {
					$live[] = $variant;
					if ( '0000-00-00 00:00:00' !== $kept->post_date_gmt ) {
						$published_at[ $variant ] = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $kept->post_date_gmt . ' UTC' ) );
					}
				}
			}
			if ( null !== $control_goal ) {
				$test['goal'] = $control_goal;
			}
			$test['goal_label'] = $labels[ $test['goal'] ] ?? '';
			// A start counts only while a control and a challenger are both live, so a
			// paused test reads as not started. Liveness comes from the status, not the
			// dates: wp_publish_post(), which the Campaigns wizard uses, leaves a
			// published draft's GMT date empty.
			$splitting          = in_array( 'a', $live, true ) && count( $live ) > 1;
			$test['started_at'] = $splitting ? ( $starts[ $test_id ] ?? self::get_started_at( $published_at ) ) : null;
			$test['status']     = 'active';
			$tests[]            = $test;
		}

		// An ended test's prompts no longer carry its ID, so it is listed from its
		// record. A prompt given the ID again by hand doesn't revive it, here or for
		// readers: get_tests_config() leaves ended IDs out too.
		$ended     = self::get_ended_tests( $labels );
		$ended_ids = array_column( $ended, 'test_id' );
		$tests     = array_filter( $tests, fn( $test ) => ! in_array( $test['test_id'], $ended_ids, true ) );

		return array_merge( array_values( $tests ), $ended );
	}

	/**
	 * A test's term, created with its current name for a test made before names
	 * existed.
	 *
	 * @param string $test_id Test ID.
	 * @param string $name    Name to give a new term.
	 * @return WP_Term|WP_Error
	 */
	private static function ensure_term( $test_id, $name ) {
		$term = get_term_by( 'slug', $test_id, self::TAXONOMY );
		if ( $term ) {
			return $term;
		}
		$inserted = self::insert_term( '' === trim( (string) $name ) ? $test_id : $name, $test_id );
		if ( ! is_wp_error( $inserted ) && $inserted['created'] && get_term( $inserted['term_id'], self::TAXONOMY )->slug !== $test_id ) {
			// A concurrent request made this test's term first, and core gave
			// this one a suffixed slug.
			wp_delete_term( $inserted['term_id'], self::TAXONOMY );
		}
		$term = get_term_by( 'slug', $test_id, self::TAXONOMY );
		return $term ? $term : new WP_Error( 'newspack_popups_ab_ended', __( 'Could not end the test. Try again.', 'newspack-popups' ), [ 'status' => 500 ] );
	}

	/**
	 * Every untrashed prompt, with what a start from outside the editor needs to
	 * know about it: the A/B Tests tab's prompt picker lists the ones that can
	 * start a test, and the prompt menu in Campaigns offers to start one, view
	 * the prompt's test, or says why it can't.
	 *
	 * @return array[] Ordered by ID. Each is `[ 'id', 'title', 'status', 'test_id',
	 *                 'ineligible_reason', 'suggested_goal' ]`. `test_id` is the ID
	 *                 as stored, or '' for a prompt in no test, whatever the
	 *                 test's state: a start refuses a prompt that has one, except
	 *                 to resume a test with no other version, which that test's
	 *                 card offers. `ineligible_reason` is the message a start would
	 *                 refuse the placement with, or ''.
	 */
	public static function get_prompt_start_states() {
		$prompts = get_posts(
			[
				'post_type'      => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_status'    => [ 'publish', 'future', 'draft', 'pending', 'private' ],
				'perm'           => 'readable',
				'posts_per_page' => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging -- Prompts CPT; config-scale.
				'orderby'        => 'ID',
				'order'          => 'ASC',
			]
		);
		return array_map(
			static function ( $prompt ) {
				return [
					'id'                => (int) $prompt->ID,
					'title'             => $prompt->post_title,
					'status'            => $prompt->post_status,
					'test_id'           => trim( (string) get_post_meta( $prompt->ID, self::META_TEST_ID, true ) ),
					'ineligible_reason' => self::get_ineligible_reason(
						get_post_meta( $prompt->ID, 'placement', true ),
						rest_sanitize_boolean( get_post_meta( $prompt->ID, 'manual_only', true ) )
					),
					'suggested_goal'    => self::suggest_goal( $prompt->post_content ),
				];
			},
			$prompts
		);
	}

	/**
	 * The goal to suggest for a new test, from the blocks in a prompt.
	 *
	 * Mirrors suggestGoal() in src/editor/ab-utils.js, which the editor runs on
	 * the blocks it has loaded; keep the order in step. The most specific goal
	 * wins, so GOAL_BLOCKS can't be read in order: it lists the Checkout Button
	 * under form submissions too.
	 *
	 * @param string $content Prompt content.
	 * @return string Goal key, or '' when nothing in the prompt names a goal.
	 */
	public static function suggest_goal( $content ) {
		$content = (string) $content;
		$names   = [];
		$collect = static function ( $blocks ) use ( &$collect, &$names ) {
			foreach ( $blocks as $block ) {
				if ( ! empty( $block['blockName'] ) ) {
					$names[] = $block['blockName'];
				}
				if ( ! empty( $block['innerBlocks'] ) ) {
					$collect( $block['innerBlocks'] );
				}
			}
		};
		$collect( parse_blocks( $content ) );
		$has = static function ( $name ) use ( $names ) {
			return in_array( $name, $names, true );
		};
		if ( $has( 'newspack-blocks/checkout-button' ) ) {
			return 'subscription';
		}
		if ( $has( 'newspack-blocks/donate' ) ) {
			return 'donation';
		}
		if ( $has( 'newspack/reader-registration' ) || $has( 'newspack-newsletters/subscribe' ) ) {
			return 'form_submission';
		}
		if ( $has( 'core/button' ) || preg_match( '/<a\s[^>]*href=/i', $content ) ) {
			return 'link_click';
		}
		return '';
	}

	/**
	 * Display names for a set of tests, in one query.
	 *
	 * @param string[] $test_ids Test IDs.
	 * @return array<string,string> Test ID => name, only for tests that have a term.
	 */
	private static function get_test_names( array $test_ids ) {
		if ( empty( $test_ids ) ) {
			return [];
		}
		$terms = get_terms(
			[
				'taxonomy'               => self::TAXONOMY,
				'slug'                   => $test_ids,
				'hide_empty'             => false,
				'update_term_meta_cache' => false,
			]
		);
		$names = [];
		foreach ( is_array( $terms ) ? $terms : [] as $term ) {
			$names[ (string) $term->slug ] = self::decode_term_name( $term->name );
		}
		return $names;
	}

	/**
	 * A test's display name: its term's name, or the Test ID for a test that was
	 * never named, which covers every test made before names existed.
	 *
	 * @param string $test_id Test ID.
	 * @return string
	 */
	public static function get_test_name( $test_id ) {
		$test_id = self::resolve_test_id( $test_id );
		if ( '' === $test_id ) {
			return '';
		}
		return self::get_test_names( [ $test_id ] )[ $test_id ] ?? $test_id;
	}

	/**
	 * Start a test record with a new, unique Test ID.
	 *
	 * The ID is the name's slug plus a random suffix, so it stays readable in GA4
	 * reports, where it is the `ab_test_id` value, and never changes after a
	 * rename. A name that leaves no slug, such as one in a non-Latin script,
	 * gets `test` as its base. The ID is also what readers are bucketed on, which
	 * is why nothing here ever rewrites one.
	 *
	 * @param string $name Display name.
	 * @return string|WP_Error The new Test ID.
	 */
	public static function create_test( $name ) {
		$name = self::sanitize_test_name( $name );
		if ( is_wp_error( $name ) ) {
			return $name;
		}
		// GA4 caps an event parameter value at 100 characters; 40 leaves room for
		// the suffix and for whatever a report prefixes to the ID.
		$base = trim( substr( self::sanitize_test_id( $name ), 0, 40 ), '-' );
		$base = '' === $base ? 'test' : $base;
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$test_id = $base . '-' . bin2hex( random_bytes( 2 ) );
			if ( self::is_test_id_in_use( $test_id ) ) {
				continue;
			}
			$term = self::insert_term( $name, $test_id );
			if ( is_wp_error( $term ) ) {
				if ( 'term_exists' === $term->get_error_code() ) {
					continue;
				}
				return $term;
			}
			// Core handed back a term it already had: another create drew the same
			// ID after the check above, and that test owns the term.
			if ( ! $term['created'] ) {
				continue;
			}
			// Another request took the ID after the check above, so core stored
			// this term under a suffixed slug that no test would ever read.
			if ( get_term( $term['term_id'], self::TAXONOMY )->slug !== $test_id ) {
				wp_delete_term( $term['term_id'], self::TAXONOMY );
				continue;
			}
			return $test_id;
		}
		return new WP_Error( 'newspack_popups_ab_test_id', __( 'Could not create the test. Try again.', 'newspack-popups' ), [ 'status' => 500 ] );
	}

	/**
	 * Clean a display name, refusing one that is empty or longer than a term name
	 * can hold.
	 *
	 * @param mixed $name Raw name.
	 * @return string|WP_Error
	 */
	private static function sanitize_test_name( $name ) {
		$name = is_scalar( $name ) ? trim( sanitize_text_field( (string) $name ) ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'newspack_popups_ab_test_name', __( 'Enter a test name.', 'newspack-popups' ), [ 'status' => 400 ] );
		}
		// Measured as core will store it: its filters turn `&` into `&amp;`, and a
		// name over the column's 200 characters is not cut short but dropped,
		// silently on update. The filters expect slashed input, as the term APIs
		// pass it; unslashed, they strip a lone backslash and add one before
		// each quote.
		if ( mb_strlen( wp_unslash( sanitize_term_field( 'name', wp_slash( $name ), 0, self::TAXONOMY, 'db' ) ), 'UTF-8' ) > 200 ) {
			return new WP_Error( 'newspack_popups_ab_test_name', __( 'Use a shorter test name.', 'newspack-popups' ), [ 'status' => 400 ] );
		}
		return $name;
	}

	/**
	 * Insert a test's term, noting whether this call created it.
	 *
	 * When two inserts race for one slug, core can delete the newer row and
	 * return the older term, which may be another test's. It returns before the
	 * create hooks fire, so only a term this call made is safe to delete.
	 *
	 * @param string $name    Clean display name.
	 * @param string $test_id Test ID to use as the slug.
	 * @return array|WP_Error Core's result, plus `created`.
	 */
	private static function insert_term( $name, $test_id ) {
		$created = 0;
		$listen  = function ( $term_id ) use ( &$created ) {
			$created = (int) $term_id;
		};
		add_action( 'created_' . self::TAXONOMY, $listen );
		$term = wp_insert_term( wp_slash( $name ), self::TAXONOMY, [ 'slug' => $test_id ] );
		remove_action( 'created_' . self::TAXONOMY, $listen );
		if ( ! is_wp_error( $term ) ) {
			$term['created'] = (int) $term['term_id'] === $created;
		}
		return $term;
	}

	/**
	 * A term name with core's HTML encoding undone, so `&` and a lone `<` or `>`
	 * read as entered. Entity text typed into a name is decoded too.
	 *
	 * @param string $name Stored name.
	 * @return string
	 */
	private static function decode_term_name( $name ) {
		return wp_specialchars_decode( (string) $name, ENT_QUOTES );
	}

	/**
	 * The spellings a given Test ID could be stored under, in the order to try
	 * them. The ID as stored comes first: the save guard keeps a legacy ID with
	 * underscores, and the front end keys tests by the stored value, so
	 * footer_test and footer-test are different tests. Only input that isn't
	 * already in stored form, such as typed text, falls back to the spelling a
	 * save would store.
	 *
	 * @param mixed $test_id Test ID as given.
	 * @return string[] Empty for input with no usable ID.
	 */
	private static function test_id_candidates( $test_id ) {
		if ( ! is_scalar( $test_id ) ) {
			return [];
		}
		$stored_form = sanitize_title( (string) $test_id );
		$candidates  = [ $stored_form ];
		if ( (string) $test_id !== $stored_form ) {
			$candidates[] = self::sanitize_test_id( $test_id );
		}
		return array_values( array_filter( array_unique( $candidates ), 'strlen' ) );
	}

	/**
	 * The Test ID a caller means, resolved the way the page finds a test, so a
	 * rename lands on the test the page listed.
	 *
	 * @param mixed $test_id Test ID as given.
	 * @return string The first candidate a prompt carries, else the last candidate,
	 *                or an empty string for input with no usable ID.
	 */
	public static function resolve_test_id( $test_id ) {
		$candidates = self::test_id_candidates( $test_id );
		foreach ( $candidates as $candidate ) {
			if ( self::get_test_prompt_ids( $candidate ) ) {
				return $candidate;
			}
		}
		return $candidates ? $candidates[ count( $candidates ) - 1 ] : '';
	}

	/**
	 * Change a test's display name. The Test ID, and so every reader's bucket and
	 * every recorded result, stays the same.
	 *
	 * A test made before names existed has no term yet; renaming it creates one
	 * with the existing ID as its slug.
	 *
	 * @param string $test_id Test ID.
	 * @param string $name    New display name.
	 * @return string|WP_Error The stored name.
	 */
	public static function rename_test( $test_id, $name ) {
		$test_id = self::resolve_test_id( $test_id );
		$name    = self::sanitize_test_name( $name );
		if ( is_wp_error( $name ) ) {
			return $name;
		}
		$term = '' === $test_id ? false : get_term_by( 'slug', $test_id, self::TAXONOMY );
		if ( ! $term ) {
			if ( '' === $test_id || empty( self::get_test_prompt_ids( $test_id ) ) ) {
				return new WP_Error( 'newspack_popups_ab_test_not_found', __( 'This test no longer exists.', 'newspack-popups' ), [ 'status' => 404 ] );
			}
			$inserted = self::insert_term( $name, $test_id );
			if ( is_wp_error( $inserted ) && 'term_exists' !== $inserted->get_error_code() ) {
				return $inserted;
			}
			if ( ! is_wp_error( $inserted ) ) {
				$term = get_term( $inserted['term_id'], self::TAXONOMY );
				if ( $term->slug === $test_id ) {
					return self::decode_term_name( $term->name );
				}
				// A concurrent rename created this test's term after the lookup
				// above, and core gave this one a suffixed slug. Drop it if this
				// call made it, and rename the real one.
				if ( $inserted['created'] ) {
					wp_delete_term( $term->term_id, self::TAXONOMY );
				}
			}
			$term = get_term_by( 'slug', $test_id, self::TAXONOMY );
			if ( ! $term ) {
				return new WP_Error( 'newspack_popups_ab_test_name', __( 'Could not rename the test. Try again.', 'newspack-popups' ), [ 'status' => 500 ] );
			}
		}
		$result = wp_update_term( $term->term_id, self::TAXONOMY, [ 'name' => wp_slash( $name ) ] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::decode_term_name( get_term( $term->term_id, self::TAXONOMY )->name );
	}

	/**
	 * Whether a user may rename a test: only someone who may change the A/B
	 * settings of every prompt in it, since the name shows on all of them.
	 *
	 * @param int    $user_id User ID.
	 * @param string $test_id Test ID.
	 * @return bool False for a test with no prompts.
	 */
	public static function can_rename_test( $user_id, $test_id ) {
		$prompt_ids = self::get_member_prompt_ids( self::resolve_test_id( $test_id ) );
		foreach ( $prompt_ids as $prompt_id ) {
			if ( ! self::can_manage_tests( $user_id, $prompt_id ) ) {
				return false;
			}
		}
		return ! empty( $prompt_ids );
	}

	/**
	 * The prompts a test is made of: those carrying its ID and, for an ended test,
	 * the versions its record names. Both, because anyone who can edit a prompt
	 * can give it an ended test's ID, and that alone mustn't make the test theirs.
	 *
	 * @param string $test_id Test ID.
	 * @return int[]
	 */
	private static function get_member_prompt_ids( $test_id ) {
		$prompt_ids = self::get_test_prompt_ids( $test_id );
		$record     = '' === $test_id ? null : self::get_ended_record( $test_id );
		if ( $record ) {
			foreach ( $record['versions'] as $version ) {
				$prompt_id = (int) ( $version['id'] ?? 0 );
				if ( $prompt_id > 0 && get_post( $prompt_id ) ) {
					$prompt_ids[] = $prompt_id;
				}
			}
		}
		return array_values( array_unique( $prompt_ids ) );
	}

	/**
	 * Whether a user may end a test: the same rule as renaming, since ending
	 * changes every prompt in it.
	 *
	 * @param int    $user_id User ID.
	 * @param string $test_id Test ID.
	 * @return bool
	 */
	public static function can_end_test( $user_id, $test_id ) {
		return self::can_rename_test( $user_id, $test_id );
	}

	/**
	 * A test's ended record, or null for a test that hasn't ended.
	 *
	 * @param string $test_id Test ID.
	 * @return array|null `[ 'ended_at', 'kept', 'started_at', 'goal', 'control_share', 'versions' ]`;
	 *                    `versions` maps each letter to `[ 'id', 'title' ]` as they were at the end.
	 */
	public static function get_ended_record( $test_id ) {
		$term = get_term_by( 'slug', (string) $test_id, self::TAXONOMY );
		if ( ! $term ) {
			return null;
		}
		$record = get_term_meta( $term->term_id, self::TERM_META_ENDED, true );
		return is_array( $record ) && ! empty( $record['ended_at'] ) && is_array( $record['versions'] ?? null ) ? $record : null;
	}

	/**
	 * The claim on a test's earliest ended record, read from the database: the
	 * object cache can hold a copy read before this request's insert.
	 *
	 * @param int $term_id Term ID.
	 * @return string|null
	 */
	private static function get_first_ended_claim( $term_id ) {
		global $wpdb;
		$value  = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1",
				$term_id,
				self::TERM_META_ENDED
			)
		);
		$record = maybe_unserialize( $value );
		return is_array( $record ) ? ( $record['claim'] ?? null ) : null;
	}

	/**
	 * End a running test, keeping one version.
	 *
	 * The order matters, because nothing here is a transaction. The record goes
	 * first. Core's unique write is a read before the insert, not a lock, so two
	 * ends at once can both insert one; each request then reads the records back
	 * and only the earliest goes on, before either has drafted anything. Then
	 * every other prompt in the test goes to draft, which stops the split; a
	 * draft that fails puts back the ones already drafted and removes the record,
	 * so the test is still running and can be ended again. Only after every draft
	 * do the prompts leave the test, so a fatal in between leaves a test that has
	 * ended but still lists its prompts, never one that has silently lost its
	 * record. A fatal partway through the drafts can still leave a version
	 * published; it shows to every reader until it is drafted by hand. Every
	 * prompt carrying the ID leaves it, trashed ones too, so restoring one can't
	 * restart the test. The term stays, holding the ID against reuse and the
	 * record for the page.
	 *
	 * The kept version's status is left as it is: ending a test never publishes
	 * anything.
	 *
	 * @param string $test_id Test ID.
	 * @param string $keep    Letter of the version to keep.
	 * @return array|WP_Error The ended test, as get_tests() lists it.
	 */
	public static function end_test( $test_id, $keep ) {
		$test_id = self::resolve_test_id( $test_id );
		$test    = null;
		foreach ( self::get_tests() as $row ) {
			if ( $test_id === $row['test_id'] ) {
				$test = $row;
				break;
			}
		}
		if ( null === $test ) {
			return new WP_Error( 'newspack_popups_ab_test_not_found', __( 'This test no longer exists.', 'newspack-popups' ), [ 'status' => 404 ] );
		}
		if ( 'ended' === $test['status'] ) {
			return self::already_ended_error();
		}
		if ( ! is_string( $keep ) || ! isset( $test['variants'][ $keep ] ) ) {
			return new WP_Error( 'newspack_popups_ab_keep', __( 'Choose a version this test has.', 'newspack-popups' ), [ 'status' => 400 ] );
		}
		$running = isset( $test['variants']['a'] ) && count( $test['variants'] ) > 1;
		foreach ( $test['variants'] as $variant ) {
			$running = $running && 'publish' === $variant['status'];
		}
		if ( ! $running ) {
			return new WP_Error( 'newspack_popups_ab_not_running', __( 'Only a running test can be ended.', 'newspack-popups' ), [ 'status' => 409 ] );
		}

		$term = self::ensure_term( $test_id, $test['name'] );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		$record = [
			'ended_at'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'kept'          => $keep,
			'started_at'    => $test['started_at'],
			'goal'          => $test['goal'],
			'control_share' => $test['control_share'],
			'versions'      => array_map(
				fn( $variant ) => [
					'id'    => (int) $variant['id'],
					'title' => (string) $variant['title'],
				],
				$test['variants']
			),
		];
		// The claim tells this request's record from one written at the same moment.
		$record['claim'] = wp_generate_uuid4();
		// Slashed, because the meta functions unslash: a backslash in a title
		// would otherwise be lost, and the deletes below must match what was stored.
		$stored = wp_slash( $record );
		if ( ! add_term_meta( $term->term_id, self::TERM_META_ENDED, $stored, true ) ) {
			return self::already_ended_error();
		}
		if ( self::get_first_ended_claim( $term->term_id ) !== $record['claim'] ) {
			delete_term_meta( $term->term_id, self::TERM_META_ENDED, $stored );
			// A reader request may have seen this record and recorded that the
			// site has no tests running.
			self::invalidate_has_tests();
			return self::already_ended_error();
		}

		$kept_id    = (int) $test['variants'][ $keep ]['id'];
		$prompt_ids = self::get_test_prompt_ids( $test_id );
		$drafted    = [];
		foreach ( $prompt_ids as $prompt_id ) {
			$status = get_post_status( $prompt_id );
			if ( $kept_id === $prompt_id || in_array( $status, [ 'draft', 'trash' ], true ) ) {
				continue;
			}
			$result = wp_update_post(
				[
					'ID'          => $prompt_id,
					'post_status' => 'draft',
				],
				true
			);
			if ( is_wp_error( $result ) || ! $result ) {
				foreach ( $drafted as $drafted_id => $drafted_status ) {
					wp_update_post(
						[
							'ID'          => $drafted_id,
							'post_status' => $drafted_status,
						]
					);
				}
				delete_term_meta( $term->term_id, self::TERM_META_ENDED, $stored );
				// A reader request that saw the record may have recorded that the
				// site has no tests running; the test is running again.
				self::invalidate_has_tests();
				return is_wp_error( $result ) ? $result : new WP_Error( 'newspack_popups_ab_end_failed', __( 'Could not end the test. Try again.', 'newspack-popups' ), [ 'status' => 500 ] );
			}
			$drafted[ $prompt_id ] = $status;
		}
		// Clearing the last prompt's Test ID also drops every reader's bucket,
		// so it stays after the drafts: a test that failed to end keeps
		// splitting readers as before.
		foreach ( $prompt_ids as $prompt_id ) {
			foreach ( self::META_KEYS as $meta_key ) {
				delete_post_meta( $prompt_id, $meta_key );
			}
		}
		self::invalidate_has_tests();

		foreach ( self::get_tests() as $row ) {
			if ( $test_id === $row['test_id'] ) {
				return $row;
			}
		}
		return new WP_Error( 'newspack_popups_ab_test_not_found', __( 'This test no longer exists.', 'newspack-popups' ), [ 'status' => 404 ] );
	}

	/**
	 * The refusal for a test that has already ended.
	 *
	 * @return WP_Error
	 */
	private static function already_ended_error() {
		return new WP_Error( 'newspack_popups_ab_ended', __( 'This test has already ended.', 'newspack-popups' ), [ 'status' => 409 ] );
	}

	/**
	 * An ended test's final results, or null until they have been taken.
	 *
	 * @param string $test_id Test ID.
	 * @return array|null `[ 'counts', 'fetched_at' ]`.
	 */
	public static function get_final_counts( $test_id ) {
		$term = get_term_by( 'slug', (string) $test_id, self::TAXONOMY );
		if ( ! $term ) {
			return null;
		}
		$final = get_term_meta( $term->term_id, self::TERM_META_FINAL_COUNTS, true );
		return is_array( $final ) && isset( $final['counts'], $final['fetched_at'] ) ? $final : null;
	}

	/**
	 * Keep an ended test's final results. The first ones stored stay, so two
	 * reads that both fetch can't leave the page showing different numbers.
	 *
	 * @param string $test_id    Test ID.
	 * @param array  $counts     The provider's counts.
	 * @param string $fetched_at When they were fetched.
	 */
	public static function store_final_counts( $test_id, $counts, $fetched_at ) {
		$term = get_term_by( 'slug', (string) $test_id, self::TAXONOMY );
		if ( $term && self::get_ended_record( $test_id ) ) {
			add_term_meta(
				$term->term_id,
				self::TERM_META_FINAL_COUNTS,
				[
					'counts'     => $counts,
					'fetched_at' => $fetched_at,
				],
				true
			);
		}
	}

	/**
	 * Every ended test, as get_tests() lists them.
	 *
	 * @param array $labels Goal labels.
	 * @return array[] Ordered by Test ID.
	 */
	private static function get_ended_tests( $labels ) {
		$terms = get_terms(
			[
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'slug',
				'meta_key'   => self::TERM_META_ENDED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			]
		);
		$terms = is_array( $terms ) ? $terms : [];
		// Ended tests are kept for good, so load every version's post and meta in
		// one go rather than a few queries per version on each list.
		$version_ids = [];
		foreach ( $terms as $term ) {
			$record = get_term_meta( $term->term_id, self::TERM_META_ENDED, true );
			foreach ( is_array( $record['versions'] ?? null ) ? $record['versions'] : [] as $version ) {
				$version_ids[] = (int) ( $version['id'] ?? 0 );
			}
		}
		$version_ids = array_filter( array_unique( $version_ids ) );
		if ( $version_ids ) {
			_prime_post_caches( $version_ids, false, true );
		}
		$tests = [];
		foreach ( $terms as $term ) {
			$record = get_term_meta( $term->term_id, self::TERM_META_ENDED, true );
			if ( ! is_array( $record ) || empty( $record['ended_at'] ) || ! is_array( $record['versions'] ?? null ) ) {
				continue;
			}
			$variants = [];
			foreach ( $record['versions'] as $letter => $version ) {
				$prompt = get_post( (int) ( $version['id'] ?? 0 ) );
				if ( $prompt && Newspack_Popups::NEWSPACK_POPUPS_CPT === $prompt->post_type ) {
					$row = self::describe_prompt( $prompt );
				} else {
					$row = [
						'id'           => (int) ( $version['id'] ?? 0 ),
						'status'       => '',
						'status_label' => '',
						'edit_link'    => null,
						'eligible'     => true,
					];
				}
				// The title as it was when the test ended, so the card reports
				// what readers saw, whatever the prompt is called now.
				$row['title']        = (string) ( $version['title'] ?? '' );
				$variants[ $letter ] = $row;
			}
			ksort( $variants );
			$goal    = self::sanitize_goal( $record['goal'] ?? '' );
			$tests[] = [
				'test_id'       => (string) $term->slug,
				'name'          => self::decode_term_name( $term->name ),
				'goal'          => $goal,
				'goal_label'    => $labels[ $goal ] ?? '',
				'control_share' => self::sanitize_control_share( $record['control_share'] ?? self::DEFAULT_CONTROL_SHARE ),
				'started_at'    => $record['started_at'] ?? null,
				'variants'      => $variants,
				'duplicates'    => [],
				'prompt_count'  => count( $variants ),
				'status'        => 'ended',
				'ended_at'      => (string) $record['ended_at'],
				'kept'          => (string) $record['kept'],
			];
		}
		return $tests;
	}

	/**
	 * Start a test on a prompt: it becomes version A, and a draft copy of it
	 * becomes version B with the same placement and other settings.
	 *
	 * A prompt that is already version A of a test with no other version, such
	 * as one whose B was trashed, resumes that test: it keeps its ID, takes the
	 * new name, goal and split, and gets a new B. Its start is cleared, and
	 * publishing the new B records a new one.
	 *
	 * A new test claims A before B is made, so a second start on the same prompt
	 * fails instead of making a second test, and a failure after that removes the
	 * new test. A resume makes no claim and undoes nothing on failure: two resumes
	 * at once can each make a B, which the dashboard then flags.
	 *
	 * The goal and split are stored on A only, because A's are the test's. The
	 * meta is written directly, past guard_rest_ab_meta(), which is why the
	 * placement rule is checked here.
	 *
	 * @param int    $prompt_id     Prompt to test.
	 * @param string $name          Display name.
	 * @param string $goal          Goal key.
	 * @param int    $control_share Percent of readers who see A, 20 to 80.
	 * @return array|WP_Error `[ 'test_id', 'name', 'version_b' => [ 'id', 'edit_link' ] ]`;
	 *                        `edit_link` is null when the current user can't edit B.
	 */
	public static function start_test( $prompt_id, $name, $goal, $control_share ) {
		$prompt = get_post( $prompt_id );
		if ( ! $prompt || Newspack_Popups::NEWSPACK_POPUPS_CPT !== $prompt->post_type || 'trash' === $prompt->post_status ) {
			return new WP_Error( 'newspack_popups_ab_prompt_not_found', __( 'This prompt no longer exists.', 'newspack-popups' ), [ 'status' => 404 ] );
		}
		$ineligible = self::get_ineligible_reason( get_post_meta( $prompt->ID, 'placement', true ), rest_sanitize_boolean( get_post_meta( $prompt->ID, 'manual_only', true ) ) );
		if ( '' !== $ineligible ) {
			return new WP_Error( 'newspack_popups_ab_ineligible_placement', $ineligible, [ 'status' => 400 ] );
		}
		$goal = self::sanitize_goal( $goal );
		if ( '' === $goal ) {
			return new WP_Error( 'newspack_popups_ab_test_goal', __( 'Choose a goal.', 'newspack-popups' ), [ 'status' => 400 ] );
		}
		// The ID as stored, not re-sanitized: B has to carry exactly A's value, or
		// an ID stored under an earlier sanitizer would split the test in two.
		$existing = (string) get_post_meta( $prompt->ID, self::META_TEST_ID, true );
		$resuming = '' !== $existing;
		if ( $resuming ) {
			if ( 'a' !== get_post_meta( $prompt->ID, self::META_VARIANT, true ) || self::has_other_versions( $existing, $prompt->ID ) ) {
				return self::already_in_test_error();
			}
			// A new name renames the whole test, trashed versions included, so it
			// takes the same permission as the rename route. The stored name is
			// sanitized too before comparing: it reads back decoded, so a kept name
			// with a lone `<` would otherwise look changed.
			$clean_name = self::sanitize_test_name( $name );
			if ( is_wp_error( $clean_name ) ) {
				return $clean_name;
			}
			$current_name = self::sanitize_test_name( self::get_test_name( $existing ) );
			if ( $current_name !== $clean_name && ! self::can_rename_test( get_current_user_id(), $existing ) ) {
				return new WP_Error( 'newspack_popups_ab_rename_forbidden', __( 'You can’t rename this test.', 'newspack-popups' ), [ 'status' => 403 ] );
			}
			$renamed = self::rename_test( $existing, $name );
			if ( is_wp_error( $renamed ) ) {
				return $renamed;
			}
			$test_id = $existing;
		} else {
			$test_id = self::claim_new_test( $prompt->ID, $name );
			if ( is_wp_error( $test_id ) ) {
				return $test_id;
			}
		}

		/* translators: %s: title of the prompt being tested, which is version A. */
		$version_b = Newspack_Popups::duplicate_popup( $prompt->ID, sprintf( __( '%s (B)', 'newspack-popups' ), $prompt->post_title ) );
		if ( ! $version_b || is_wp_error( $version_b ) ) {
			// A resumed test was there before this call, so only a new one is undone.
			if ( ! $resuming ) {
				delete_post_meta( $prompt->ID, self::META_TEST_ID, $test_id );
				self::delete_test_term( $test_id );
			}
			return new WP_Error( 'newspack_popups_ab_version_b', __( 'Could not create version B. Try again.', 'newspack-popups' ), [ 'status' => 500 ] );
		}
		if ( $resuming ) {
			// Readers saw A alone while the test had no B, so the new B's split
			// is a new start; publishing it records one.
			$term = get_term_by( 'slug', $test_id, self::TAXONOMY );
			if ( $term ) {
				delete_term_meta( $term->term_id, self::TERM_META_STARTED );
			}
		}
		// The registered sanitizer would rewrite a legacy ID (`footer_test` to
		// `footer-test`) and put B in a different test, so this copy skips it.
		$keep_stored = static function () use ( $test_id ) {
			return $test_id;
		};
		add_filter( 'sanitize_post_meta_' . self::META_TEST_ID . '_for_' . Newspack_Popups::NEWSPACK_POPUPS_CPT, $keep_stored, PHP_INT_MAX );
		update_post_meta( $version_b, self::META_TEST_ID, $test_id );
		remove_filter( 'sanitize_post_meta_' . self::META_TEST_ID . '_for_' . Newspack_Popups::NEWSPACK_POPUPS_CPT, $keep_stored, PHP_INT_MAX );
		update_post_meta( $version_b, self::META_VARIANT, 'b' );
		update_post_meta( $prompt->ID, self::META_VARIANT, 'a' );
		update_post_meta( $prompt->ID, self::META_GOAL, $goal );
		update_post_meta( $prompt->ID, self::META_CONTROL_SHARE, self::sanitize_control_share( $control_share ) );

		return [
			'test_id'   => $test_id,
			'name'      => self::get_test_name( $test_id ),
			'version_b' => [
				'id'        => (int) $version_b,
				'edit_link' => get_edit_post_link( $version_b, 'raw' ),
			],
		];
	}

	/**
	 * Create a test and claim a prompt for it as version A.
	 *
	 * @param int    $prompt_id Prompt ID.
	 * @param string $name      Display name.
	 * @return string|WP_Error Test ID.
	 */
	private static function claim_new_test( $prompt_id, $name ) {
		$test_id = self::create_test( $name );
		if ( is_wp_error( $test_id ) ) {
			return $test_id;
		}
		// The claim: add_post_meta() with $unique refuses to add a second row, so
		// of two starts that both found the prompt in no test, usually only one gets A.
		// Its own check is a read before the insert, not a lock, so two starts in
		// the same instant can still both succeed; the result is a second B whose
		// test has no A, which the dashboard shows as Incomplete. A row left empty
		// by clearing a test would refuse every start, so it goes first, by row:
		// delete_post_meta() with an empty value deletes every row of the key,
		// which would take another start's claim with it.
		self::delete_empty_test_id_rows( $prompt_id );
		if ( ! add_post_meta( $prompt_id, self::META_TEST_ID, $test_id, true ) ) {
			self::delete_test_term( $test_id );
			return self::already_in_test_error();
		}
		return $test_id;
	}

	/**
	 * Delete a prompt's empty Test ID rows, leaving any with a value.
	 *
	 * @param int $prompt_id Prompt ID.
	 */
	private static function delete_empty_test_id_rows( $prompt_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must read the rows as stored, past the meta cache, to find their IDs.
		$meta_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_id FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = %s AND meta_value = ''",
				$prompt_id,
				self::META_TEST_ID
			)
		);
		foreach ( $meta_ids as $meta_id ) {
			delete_metadata_by_mid( 'post', (int) $meta_id );
		}
	}

	/**
	 * Whether a test has a version other than this prompt, trashed ones aside.
	 *
	 * @param string $test_id   Test ID.
	 * @param int    $prompt_id Prompt to leave out.
	 * @return bool
	 */
	private static function has_other_versions( $test_id, $prompt_id ) {
		return ! empty(
			get_posts(
				[
					'post_type'      => Newspack_Popups::NEWSPACK_POPUPS_CPT,
					'post_status'    => [ 'publish', 'future', 'draft', 'pending', 'private' ],
					'fields'         => 'ids',
					'posts_per_page' => 1,
					'post__not_in'   => [ (int) $prompt_id ], // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- One ID; config-scale CPT.
					'meta_key'       => self::META_TEST_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $test_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				]
			)
		);
	}

	/**
	 * The error for a start on a prompt that is already in a test.
	 *
	 * @return WP_Error
	 */
	private static function already_in_test_error() {
		return new WP_Error( 'newspack_popups_ab_already_in_test', __( 'This prompt is already in an A/B test.', 'newspack-popups' ), [ 'status' => 409 ] );
	}

	/**
	 * Delete the name term of a test this request created and is abandoning.
	 *
	 * @param string $test_id Test ID.
	 */
	private static function delete_test_term( $test_id ) {
		$term = get_term_by( 'slug', $test_id, self::TAXONOMY );
		if ( $term ) {
			wp_delete_term( $term->term_id, self::TAXONOMY );
		}
	}

	/**
	 * IDs of every prompt carrying a Test ID, trashed ones included, so a trashed
	 * prompt still holds its ID against reuse.
	 *
	 * @param string $test_id Sanitized Test ID.
	 * @return int[]
	 */
	private static function get_test_prompt_ids( $test_id ) {
		if ( '' === $test_id ) {
			return [];
		}
		return array_map(
			'intval',
			get_posts(
				[
					'post_type'      => Newspack_Popups::NEWSPACK_POPUPS_CPT,
					'post_status'    => [ 'publish', 'future', 'draft', 'pending', 'private', 'trash' ],
					'fields'         => 'ids',
					'posts_per_page' => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging -- Prompts CPT; config-scale.
					'meta_key'       => self::META_TEST_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $test_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				]
			)
		);
	}

	/**
	 * Whether a Test ID already belongs to a test, named or not.
	 *
	 * @param string $test_id Test ID.
	 * @return bool
	 */
	private static function is_test_id_in_use( $test_id ) {
		return (bool) get_term_by( 'slug', $test_id, self::TAXONOMY ) || ! empty( self::get_test_prompt_ids( $test_id ) );
	}

	/**
	 * What the page shows for one prompt.
	 *
	 * @param WP_Post $prompt Prompt.
	 * @return array `id`, `title`, `status`, `status_label`, `edit_link`, `eligible`.
	 */
	private static function describe_prompt( $prompt ) {
		$status = get_post_status_object( $prompt->post_status );
		return [
			'id'           => (int) $prompt->ID,
			'title'        => $prompt->post_title,
			'status'       => $prompt->post_status,
			'status_label' => $status ? $status->label : $prompt->post_status,
			'edit_link'    => get_edit_post_link( $prompt->ID, 'raw' ),
			'eligible'     => self::is_eligible_placement(
				get_post_meta( $prompt->ID, 'placement', true ),
				rest_sanitize_boolean( get_post_meta( $prompt->ID, 'manual_only', true ) )
			),
		];
	}

	/**
	 * When a test's split began: the moment both a control and a challenger
	 * were published. Before that, readers see the control alone.
	 *
	 * @param string[] $published_at Publish dates keyed by variant letter, ISO 8601 UTC.
	 * @return string|null The later of the control's date and the earliest
	 *                     challenger's, or null until both exist.
	 */
	private static function get_started_at( $published_at ) {
		if ( empty( $published_at['a'] ) ) {
			return null;
		}
		$challengers = $published_at;
		unset( $challengers['a'] );
		if ( empty( $challengers ) ) {
			return null;
		}
		return max( $published_at['a'], min( $challengers ) );
	}

	/**
	 * Placements a tested prompt can't have. Manual and custom placements render
	 * wherever a block or shortcode puts them, so there is no slot to split
	 * between variants. An overlay set to Manual only is in the same position;
	 * is_eligible_placement() covers it, since it is a flag rather than a value.
	 *
	 * @return string[] Placement values.
	 */
	public static function get_ineligible_placements() {
		return array_merge( [ 'manual' ], Newspack_Popups_Custom_Placements::get_custom_placement_values() );
	}

	/**
	 * Whether a prompt with this placement can be part of a test. Follows
	 * Newspack_Popups_Model::is_manual_only(), which treats an overlay set to
	 * Manual only like the manual placement.
	 *
	 * @param string $placement   Placement value.
	 * @param bool   $manual_only The prompt's Manual only setting.
	 * @return bool
	 */
	public static function is_eligible_placement( $placement, $manual_only = false ) {
		$placement = (string) $placement;
		if ( in_array( $placement, self::get_ineligible_placements(), true ) ) {
			return false;
		}
		return ! ( $manual_only && in_array( $placement, Newspack_Popups_Model::get_overlay_placements(), true ) );
	}

	/**
	 * Why a prompt with this placement can't be part of a test.
	 *
	 * @param string $placement   Placement value.
	 * @param bool   $manual_only The prompt's Manual only setting.
	 * @return string The message, or '' when the placement can be tested.
	 */
	public static function get_ineligible_reason( $placement, $manual_only = false ) {
		if ( self::is_eligible_placement( $placement, $manual_only ) ) {
			return '';
		}
		return self::is_eligible_placement( $placement )
			? __( 'Overlays set to Manual only can’t be A/B tested.', 'newspack-popups' )
			: __( 'Prompts on manual or custom placements can’t be A/B tested.', 'newspack-popups' );
	}

	/**
	 * Sanitize a Test ID to a slug of lowercase letters, digits and hyphens.
	 *
	 * Not sanitize_title() directly, for two reasons. register_meta() passes the
	 * meta key as the second argument, which sanitize_title() reads as the fallback
	 * title, so an empty value would be stored as the key name instead of clearing
	 * the test. And sanitize_title() keeps underscores and percent-encodes what it
	 * can't transliterate, which the editor's slug can't produce. A legacy ID
	 * stored by sanitize_title() stays as it is; get_test_prompts() finds one with
	 * underscores, and a percent-encoded one can't be looked up.
	 *
	 * @param mixed $value Raw value.
	 * @return string Slug, or empty string.
	 */
	public static function sanitize_test_id( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$slug = preg_replace( '/%[0-9a-f]{2}/', '', sanitize_title( (string) $value ) );
		$slug = preg_replace( '/[^a-z0-9-]+/', '-', $slug );
		return trim( preg_replace( '/-+/', '-', $slug ), '-' );
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

		// An ended test stays ended: prompts given its ID again by hand read as
		// untested, as the A/B tab lists them, instead of splitting readers.
		if ( ! empty( $config ) ) {
			$ended = get_terms(
				[
					'taxonomy'   => self::TAXONOMY,
					'hide_empty' => false,
					'slug'       => array_map( 'strval', array_keys( $config ) ),
					'fields'     => 'slugs',
					'meta_key'   => self::TERM_META_ENDED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				]
			);
			foreach ( is_array( $ended ) ? $ended : [] as $slug ) {
				unset( $config[ $slug ] );
			}
		}

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
	 * construction (generated cookie IDs; Test IDs from sanitize_test_id(), or
	 * from sanitize_title() for legacy ones, which percent-encodes the rest). If either
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
	 * A stored variant stays stored while no published prompt holds it: a variant
	 * can be drafted while the test keeps running, and overwriting the value would
	 * strand its readers on another arm for good. They see a computed published arm
	 * until a published prompt holds that letter again, which may never happen,
	 * since nothing tells a draft from a retired arm. That fallback is not
	 * persisted, so a second device or a control-share edit can move it.
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
			$meta_key = self::bucket_meta_key( $test_id );
			$stored   = get_user_meta( $user_id, $meta_key, true );
			$bucket   = $stored;
			if ( ! in_array( $bucket, $config['variants'], true ) ) {
				$reader_key = $client_id ? $client_id : (string) $user_id;
				$bucket     = self::compute_bucket( $reader_key, $test_id, $config );
				if ( ! in_array( $stored, self::VALID_VARIANTS, true ) ) {
					update_user_meta( $user_id, $meta_key, $bucket );
				}
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
