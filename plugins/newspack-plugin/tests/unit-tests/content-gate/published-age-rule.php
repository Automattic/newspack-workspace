<?php
/**
 * Tests for the published-age content rule (NPPD-2435).
 *
 * @package Newspack
 */

use Newspack\Content_Gate;
use Newspack\Content_Gate_API;
use Newspack\Content_Restriction_Control;
use Newspack\Content_Rules;

/**
 * A gate can apply to posts by how long ago they were published, and a post can set its own time window.
 *
 * @group content-gate
 */
class Test_Published_Age_Rule extends WP_UnitTestCase {

	/**
	 * Delete the gates a test created and forget which gates matched which post.
	 */
	public function tear_down() {
		foreach ( Content_Gate::get_gates() as $gate ) {
			wp_delete_post( $gate['id'], true );
		}
		$this->reset_post_gates_cache();
		foreach ( array_column( (array) Content_Restriction_Control::get_available_post_types(), 'value' ) as $subtype ) {
			unregister_meta_key( 'post', Content_Restriction_Control::IS_EXEMPT_META_KEY, $subtype );
			unregister_meta_key( 'post', Content_Rules::AGE_OVERRIDE_META_KEY, $subtype );
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Clear the request-scoped get_post_gates() memo.
	 */
	private function reset_post_gates_cache() {
		$post_gates_map_property = new ReflectionProperty( Content_Restriction_Control::class, 'post_gates_map' );
		$post_gates_map_property->setAccessible( true );
		$post_gates_map_property->setValue( null, [] );
	}

	/**
	 * Publish a regwall gate on posts that also carries a published-age rule.
	 *
	 * @param string $comparison     'more_than' or 'less_than'.
	 * @param int    $duration_value Number of units.
	 * @param string $duration_unit  'days', 'months' or 'years'.
	 */
	private function create_age_gate( $comparison, $duration_value, $duration_unit = 'days' ) {
		$gate_id = Content_Gate::create_gate( [ 'title' => 'Archive Gate' ] );
		Content_Gate::update_gate_settings(
			$gate_id,
			[
				'title'         => 'Archive Gate',
				'status'        => 'publish',
				'priority'      => 1,
				'content_rules' => [
					[
						'slug'  => 'post_types',
						'value' => [ 'post' ],
					],
					[
						'slug'  => Content_Rules::PUBLISHED_AGE_RULE,
						'value' => [
							'comparison'     => $comparison,
							'duration_value' => $duration_value,
							'duration_unit'  => $duration_unit,
						],
					],
				],
				'registration'  => [
					'active'               => true,
					'metering'             => [
						'enabled' => false,
						'count'   => 0,
						'period'  => 'month',
					],
					'require_verification' => false,
				],
				'custom_access' => [
					'active'       => false,
					'metering'     => [
						'enabled' => false,
						'count'   => 0,
						'period'  => 'month',
					],
					'access_rules' => [],
				],
			]
		);
		$this->reset_post_gates_cache();
	}

	/**
	 * Create a published post of the given age.
	 *
	 * @param string $age A relative time, such as '10 days'.
	 *
	 * @return int Post ID.
	 */
	private function create_post_published_ago( $age ) {
		$published = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $age ) );
		return self::factory()->post->create(
			[
				'post_date'     => get_date_from_gmt( $published ),
				'post_date_gmt' => $published,
			]
		);
	}

	/**
	 * Whether any gate applies to the post.
	 *
	 * @param int $post_id Post ID.
	 */
	private function is_gated( $post_id ) {
		return ! empty( Content_Restriction_Control::get_post_gates( $post_id ) );
	}

	/**
	 * "Free for 7 days, then paid": the gate covers a post once it is older than the line.
	 */
	public function test_more_than_gates_posts_older_than_the_line() {
		$this->create_age_gate( 'more_than', 7 );
		$this->assertTrue( $this->is_gated( $this->create_post_published_ago( '10 days' ) ), 'A 10-day-old post is past the 7-day line.' );
		$this->assertFalse( $this->is_gated( $this->create_post_published_ago( '3 days' ) ), 'A 3-day-old post is still free.' );
	}

	/**
	 * "Paid for the first month, then free": the reverse direction.
	 */
	public function test_less_than_gates_posts_newer_than_the_line() {
		$this->create_age_gate( 'less_than', 1, 'months' );
		$this->assertTrue( $this->is_gated( $this->create_post_published_ago( '10 days' ) ), 'A 10-day-old post is within its first month.' );
		$this->assertFalse( $this->is_gated( $this->create_post_published_ago( '40 days' ) ), 'A 40-day-old post has opened up.' );
	}

	/**
	 * Months follow the calendar, as the editor's mirror counts them: one month after Jan 31 is Mar 3.
	 */
	public function test_months_follow_the_calendar() {
		$post_id   = self::factory()->post->create(
			[
				'post_date'     => '2026-01-31 12:00:00',
				'post_date_gmt' => '2026-01-31 12:00:00',
			]
		);
		$one_month = [
			'comparison'     => 'more_than',
			'duration_value' => 1,
			'duration_unit'  => 'months',
		];
		$utc       = new DateTimeZone( 'UTC' );

		$this->assertFalse( Content_Rules::post_matches_published_age( $one_month, $post_id, new DateTimeImmutable( '2026-03-02 12:00:00', $utc ) ) );
		$this->assertTrue( Content_Rules::post_matches_published_age( $one_month, $post_id, new DateTimeImmutable( '2026-03-03 12:00:00', $utc ) ) );
	}

	/**
	 * A post's own time window replaces the gate's duration, and keeps the gate's direction: under
	 * "more than 30 days", a 7-day window gates a 10-day-old post, and a 60-day one frees a 40-day-old post.
	 */
	public function test_post_time_window_wins_over_the_gate_duration() {
		$this->create_age_gate( 'more_than', 30 );
		$short_window_id = $this->create_post_published_ago( '10 days' );
		$long_window_id  = $this->create_post_published_ago( '40 days' );
		$set_window      = function ( $post_id, $days ) {
			update_post_meta(
				$post_id,
				Content_Rules::AGE_OVERRIDE_META_KEY,
				[
					'duration_value' => $days,
					'duration_unit'  => 'days',
				]
			);
		};
		$set_window( $short_window_id, 7 );
		$set_window( $long_window_id, 60 );

		$this->assertTrue( $this->is_gated( $short_window_id ), 'Free for 7 days, the 10-day-old post is gated.' );
		$this->assertFalse( $this->is_gated( $long_window_id ), 'Free for 60 days, the 40-day-old post is still open.' );
	}

	/**
	 * A post not yet published has a zero age, whatever date the editor has typed in.
	 */
	public function test_unpublished_post_counts_as_new() {
		$this->create_age_gate( 'more_than', 7 );
		$draft_id = self::factory()->post->create(
			[
				'post_status'   => 'draft',
				'post_date'     => '2020-01-01 00:00:00',
				'post_date_gmt' => '2020-01-01 00:00:00',
			]
		);
		$this->assertFalse( $this->is_gated( $draft_id ) );
	}

	/**
	 * The save keeps a usable age, and refuses one it cannot read: dropping the rule instead would
	 * widen an "all posts AND older than 2 years" gate to every post.
	 */
	public function test_save_sanitizes_the_age_value() {
		$sanitized = Content_Gate_API::sanitize_content_rule(
			[
				'slug'      => Content_Rules::PUBLISHED_AGE_RULE,
				'value'     => [
					'comparison'     => 'more_than',
					'duration_value' => '2',
					'duration_unit'  => 'years',
				],
				'exclusion' => true,
			]
		);
		$this->assertSame(
			[
				'slug'  => Content_Rules::PUBLISHED_AGE_RULE,
				'value' => [
					'comparison'     => 'more_than',
					'duration_value' => 2,
					'duration_unit'  => 'years',
				],
			],
			$sanitized,
			'The count is cast to an integer, and the exclusion flag is dropped: the comparison sets the direction.'
		);

		foreach ( [ [ 0, 'days' ], [ '7.5', 'days' ], [ 7, 'weeks' ] ] as [ $duration_value, $duration_unit ] ) {
			$unreadable_age_rule = [
				'slug'  => Content_Rules::PUBLISHED_AGE_RULE,
				'value' => [
					'comparison'     => 'more_than',
					'duration_value' => $duration_value,
					'duration_unit'  => $duration_unit,
				],
			];
			$this->assertWPError(
				Content_Gate_API::sanitize_rules( [ $unreadable_age_rule ], 'content' ),
				"An age of {$duration_value} {$duration_unit} fails the save."
			);
		}
	}

	/**
	 * A gate save carrying an unreadable age rule fails, with one exception: the gates list turns a
	 * gate off by posting the stored gate back, and a stored rule that can't be read must not trap
	 * the gate in the published state.
	 */
	public function test_gate_save_refuses_unreadable_age_rule_unless_switching_off() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$unreadable_rules = [
			[
				'slug'  => Content_Rules::PUBLISHED_AGE_RULE,
				'value' => [
					'comparison'     => 'more_than',
					'duration_value' => 0,
					'duration_unit'  => 'days',
				],
			],
		];
		// Written past the sanitizer, as a CLI script or a migration could.
		$gate_id = Content_Gate::create_gate( [ 'title' => 'Damaged Gate' ] );
		Content_Rules::update_gate_content_rules( $gate_id, $unreadable_rules );
		$request = new WP_REST_Request( 'POST', '/newspack/v1/wizard/newspack-audience-access-control/gates/' . $gate_id );
		$request->set_url_params( [ 'id' => $gate_id ] );

		$this->assertWPError(
			Content_Gate_API::sanitize_gate(
				[
					'content_rules' => $unreadable_rules,
					'status'        => 'publish',
				],
				$request
			),
			'Publishing with the rule fails.'
		);
		$edited_rules = $unreadable_rules;
		$edited_rules[0]['value']['comparison'] = 'less_than';
		$this->assertWPError(
			Content_Gate_API::sanitize_gate(
				[
					'content_rules' => $edited_rules,
					'status'        => 'draft',
				],
				$request
			),
			'A draft save that changes the rules fails too, rather than dropping the edit.'
		);

		$switched_off = Content_Gate_API::sanitize_gate(
			[
				'content_rules' => $unreadable_rules,
				'status'        => 'draft',
			],
			$request
		);
		$this->assertNotWPError( $switched_off );
		$this->assertArrayNotHasKey( 'content_rules', $switched_off, 'Switching off leaves the stored rules as they are.' );
	}

	/**
	 * An import can leave a published post's GMT date zeroed; its age then comes from the local date,
	 * as the editor reads it, rather than counting as brand new.
	 */
	public function test_published_post_with_zeroed_gmt_date_ages_from_local_date() {
		$this->create_age_gate( 'more_than', 7 );
		$imported_post_id = $this->create_post_published_ago( '10 days' );
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'post_date_gmt' => '0000-00-00 00:00:00' ], [ 'ID' => $imported_post_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $imported_post_id );

		$this->assertTrue( $this->is_gated( $imported_post_id ) );
	}

	/**
	 * Only a user who can exempt a post can set its time window: a contributor's save drops the key.
	 */
	public function test_contributor_cannot_set_post_time_window() {
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
		Content_Restriction_Control::register_meta();
		$contributor_id = self::factory()->user->create( [ 'role' => 'contributor' ] );
		$post_id        = self::factory()->post->create(
			[
				'post_author' => $contributor_id,
				'post_status' => 'draft',
			]
		);
		wp_set_current_user( $contributor_id );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_body_params(
			[
				'title' => 'Edited',
				'meta'  => [
					Content_Rules::AGE_OVERRIDE_META_KEY => [
						'duration_value' => 30,
						'duration_unit'  => 'days',
					],
				],
			]
		);
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), 'The rest of the save goes through.' );
		$this->assertEmpty( get_post_meta( $post_id, Content_Rules::AGE_OVERRIDE_META_KEY, true ) );
	}
}
