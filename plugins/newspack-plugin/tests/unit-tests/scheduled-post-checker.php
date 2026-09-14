<?php
/**
 * Tests the scheduled-post-checker safety net's post-type coverage.
 *
 * @package Newspack
 */

/**
 * Test the scheduled-post checker.
 */
class Scheduled_Post_Checker_Test extends WP_UnitTestCase {

	/**
	 * CPT slugs registered during a test, unregistered on tear down.
	 *
	 * @var string[]
	 */
	private $registered_cpts = [];

	/**
	 * Filters added during a test, as [ hook, callback ] pairs, removed on tear
	 * down. Keeping the callback is what makes an anonymous one removable.
	 *
	 * @var array[]
	 */
	private $added_filters = [];

	/**
	 * Undo anything a test registered so it can't leak into the next one — test
	 * order isn't guaranteed.
	 */
	public function tear_down() {
		foreach ( $this->registered_cpts as $cpt ) {
			if ( post_type_exists( $cpt ) ) {
				unregister_post_type( $cpt );
			}
		}
		foreach ( $this->added_filters as [ $hook, $callback ] ) {
			remove_filter( $hook, $callback );
		}
		$this->registered_cpts = [];
		$this->added_filters   = [];
		parent::tear_down();
	}

	/**
	 * Add a filter that tear_down() will remove — even an anonymous callback,
	 * which remove_filter() otherwise can't target once its reference is lost.
	 *
	 * @param string   $hook          Filter hook.
	 * @param callable $callback      Callback.
	 * @param int      $accepted_args Number of args the callback accepts.
	 */
	private function add_cleanup_filter( $hook, $callback, $accepted_args = 1 ) {
		add_filter( $hook, $callback, 10, $accepted_args );
		$this->added_filters[] = [ $hook, $callback ];
	}

	/**
	 * Register a non-public CPT for the duration of a test.
	 *
	 * @param string $slug CPT slug (max 20 chars, per register_post_type()).
	 * @param array  $args Registration args, merged over a non-public default.
	 */
	private function register_test_cpt( $slug, $args = [] ) {
		register_post_type(
			$slug,
			array_merge(
				[
					'public'   => false,
					'supports' => [ 'title' ],
				],
				$args
			)
		);
		$this->registered_cpts[] = $slug;
	}

	/**
	 * Create a post in the exact state a missed cron slot leaves: `future` status
	 * with a publish time already in the past.
	 *
	 * Written straight to the DB because both wp_insert_post() and wp_update_post()
	 * coerce a `future` post with a past date to `publish`, so the stuck state
	 * can't be produced through the normal API.
	 *
	 * @param string $post_type Post type.
	 * @param array  $columns   Extra post columns to write alongside the status.
	 *                          Goes through $wpdb too, so JSON payloads reach the
	 *                          column unslashed and unfiltered.
	 * @return int Post ID.
	 */
	private function create_overdue_future_post( $post_type, $columns = [] ) {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => $post_type,
				'post_status' => 'draft',
			]
		);

		global $wpdb;
		$past = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->posts,
			array_merge(
				[
					'post_status'   => 'future',
					'post_date'     => $past,
					'post_date_gmt' => $past,
				],
				$columns
			),
			[ 'ID' => $post_id ]
		);
		clean_post_cache( $post_id );

		$this->assertSame( 'future', get_post_status( $post_id ), 'Precondition: scheduled (future) with a past slot.' );
		return $post_id;
	}

	/**
	 * The rescued-type list covers the known non-public editorial CPTs and the
	 * search-visible types, and is filterable.
	 */
	public function test_post_type_list_covers_hidden_editorial_cpts() {
		// Simulate the popups and sponsors plugins being active.
		$this->register_test_cpt( 'newspack_popups_cpt' );
		$this->register_test_cpt( 'newspack_spnsrs_cpt' );

		$post_types = \Newspack\Scheduled_Post_Checker\nspc_get_post_types();

		$this->assertContains( 'newspack_popups_cpt', $post_types, 'Campaign prompts are covered.' );
		$this->assertContains( 'newspack_spnsrs_cpt', $post_types, 'Sponsors are covered.' );
		$this->assertContains( 'customize_changeset', $post_types, 'Scheduled Customizer changes are covered.' );
		$this->assertContains( 'post', $post_types, 'Search-visible types are still covered.' );

		// An unrelated non-public CPT is not swept in by default.
		$this->register_test_cpt( 'nspc_unrelated' );
		$this->assertNotContains( 'nspc_unrelated', \Newspack\Scheduled_Post_Checker\nspc_get_post_types(), 'Unlisted hidden types are not rescued by default.' );

		// ...but the filter lets a plugin opt one in.
		$this->add_cleanup_filter(
			'newspack_scheduled_post_checker_post_types',
			function ( $types ) {
				$types[] = 'nspc_unrelated';
				return $types;
			}
		);
		$this->assertContains( 'nspc_unrelated', \Newspack\Scheduled_Post_Checker\nspc_get_post_types(), 'The filter can register a schedulable type.' );
	}

	/**
	 * A scheduled post in a non-public CPT that missed its slot is republished.
	 * The bug was that `post_type => 'any'` never saw it.
	 */
	public function test_rescues_missed_schedule_in_hidden_cpt() {
		$this->register_test_cpt( 'nspc_hidden' );
		$this->add_cleanup_filter(
			'newspack_scheduled_post_checker_post_types',
			function ( $types ) {
				$types[] = 'nspc_hidden';
				return $types;
			}
		);

		$hidden_id = $this->create_overdue_future_post( 'nspc_hidden' );
		$public_id = $this->create_overdue_future_post( 'post' );

		// The old `post_type => 'any'` query never sees the hidden post — the bug.
		$seen_by_any = get_posts(
			[
				'post_status' => 'future',
				'post_type'   => 'any',
				'fields'      => 'ids',
			]
		);
		$this->assertNotContains( $hidden_id, $seen_by_any, 'A non-public CPT is invisible to post_type => any.' );

		\Newspack\Scheduled_Post_Checker\nspc_run_check();

		$this->assertSame( 'publish', get_post_status( $hidden_id ), 'The missed pop-up/sponsor-style post is rescued.' );
		$this->assertSame( 'publish', get_post_status( $public_id ), 'Search-visible posts are still rescued.' );
	}

	/**
	 * A non-public CPT nobody opted in stays scheduled — the fix is scoped, not a
	 * blanket "publish every future post regardless of type".
	 */
	public function test_does_not_rescue_unlisted_hidden_cpt() {
		$this->register_test_cpt( 'nspc_untracked' );
		$post_id = $this->create_overdue_future_post( 'nspc_untracked' );

		\Newspack\Scheduled_Post_Checker\nspc_run_check();

		$this->assertSame( 'future', get_post_status( $post_id ), 'An unlisted hidden type is left alone.' );
	}

	/**
	 * A scheduled Customizer change that missed its slot goes live, and its values
	 * actually land: publishing the changeset hands off to core's
	 * _wp_customize_publish_changeset(), which applies the stored settings.
	 *
	 * Theme mods the changeset never carried are untouched, which is what keeps a
	 * late rescue from reverting settings written outside the Customizer — the
	 * wizards' set_theme_mod() calls, for instance.
	 */
	public function test_rescues_missed_customizer_changeset() {
		$scheduled_mod = 'nspc_scheduled_mod';
		$other_mod     = 'nspc_other_mod';

		/*
		 * Register the setting the changeset carries. Hooking add_dynamic_settings(),
		 * which _wp_customize_publish_changeset() always calls, rather than
		 * customize_register, which it fires only if nothing else in the suite
		 * already did.
		 */
		$this->add_cleanup_filter(
			'customize_dynamic_setting_args',
			function ( $args, $id ) use ( $scheduled_mod ) {
				return $id === $scheduled_mod ? [ 'type' => 'theme_mod' ] : $args;
			},
			2
		);

		set_theme_mod( $scheduled_mod, 'before' );
		set_theme_mod( $other_mod, 'written outside the Customizer' );

		// Changeset values are namespaced per stylesheet, and only entries matching
		// the active theme are applied.
		$changeset_id = $this->create_overdue_future_post(
			'customize_changeset',
			[
				'post_name'    => wp_generate_uuid4(),
				'post_content' => wp_json_encode(
					[
						get_stylesheet() . '::' . $scheduled_mod => [
							'value' => 'after',
							'type'  => 'theme_mod',
						],
					]
				),
			]
		);

		\Newspack\Scheduled_Post_Checker\nspc_run_check();

		$this->assertSame( 'after', get_theme_mod( $scheduled_mod ), 'The scheduled Customizer change is applied.' );
		$this->assertSame( 'written outside the Customizer', get_theme_mod( $other_mod ), 'A theme mod the changeset never carried is left alone.' );

		/*
		 * Core trashes a published changeset — or deletes it outright where
		 * EMPTY_TRASH_DAYS is 0 — because the CPT has no revisions support to keep
		 * it around. Either way it is no longer stuck.
		 */
		$this->assertNotSame( 'future', get_post_status( $changeset_id ), 'The changeset does not stay scheduled.' );
	}

	/**
	 * More than five missed posts are all rescued in a single run — the default
	 * get_posts() limit of 5 used to strand the rest.
	 */
	public function test_rescues_more_than_five_missed_posts() {
		$ids = [];
		for ( $i = 0; $i < 6; $i++ ) {
			$ids[] = $this->create_overdue_future_post( 'post' );
		}

		\Newspack\Scheduled_Post_Checker\nspc_run_check();

		foreach ( $ids as $id ) {
			$this->assertSame( 'publish', get_post_status( $id ), 'Every missed post is rescued, not just the first five.' );
		}
	}
}
