<?php
/**
 * Class Test Newsletters List REST
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Admin\Newsletters_List_REST;

/**
 * Tests the REST surface that the Newsletters list DataView consumes.
 *
 * The shape returned by `get_status_for_post` consolidates `post_status`,
 * `is_newsletter_sent()`, and the scheduled-send signal into a single
 * `{ kind, sent_at, scheduled_at }` payload so the React side never has
 * to re-derive sent/scheduled state.
 */
class Newsletters_List_REST_Test extends WP_UnitTestCase {
	/**
	 * Helper: make a newsletter post with optional overrides and meta.
	 *
	 * @param array $args Post args; meta supplied via `meta_input`.
	 * @return int Post ID.
	 */
	private function make_newsletter( $args = [] ) {
		return self::factory()->post->create(
			array_merge(
				[
					'post_type'   => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
					'post_status' => 'draft',
					'post_title'  => 'Test newsletter',
				],
				$args
			)
		);
	}

	/**
	 * Run a WP_Query against the newsletters CPT so any installed
	 * one-shot `posts_where` callbacks actually fire.
	 *
	 * @param array $args Extra query args layered on top of the defaults.
	 * @return \WP_Query
	 */
	private function run_newsletter_query( $args = [] ) {
		return new WP_Query(
			array_merge(
				[
					'post_type'      => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				],
				$args
			)
		);
	}

	/**
	 * Count callbacks currently registered on `posts_where`.
	 *
	 * @return int
	 */
	private function count_posts_where_callbacks() {
		if ( empty( $GLOBALS['wp_filter']['posts_where'] ) ) {
			return 0;
		}
		$total = 0;
		foreach ( $GLOBALS['wp_filter']['posts_where']->callbacks as $callbacks ) {
			$total += count( $callbacks );
		}
		return $total;
	}

	/**
	 * A draft newsletter has kind=draft and no timestamps.
	 */
	public function test_draft_post_reports_draft_kind() {
		$post_id = $this->make_newsletter( [ 'post_status' => 'draft' ] );

		$status = Newsletters_List_REST::get_status_for_post( get_post( $post_id ) );

		$this->assertSame( 'draft', $status['kind'] );
		$this->assertNull( $status['sent_at'] );
		$this->assertNull( $status['scheduled_at'] );
	}

	/**
	 * A published newsletter is treated as sent — `is_newsletter_sent()` will
	 * back-fill `newsletter_sent` meta from the publish date if missing, so
	 * `sent_at` should be populated even without an explicit set call.
	 */
	public function test_published_post_reports_sent_kind() {
		$post_id = $this->make_newsletter(
			[
				'post_status' => 'publish',
				'post_date'   => '2026-04-20 10:00:00',
			]
		);

		$status = Newsletters_List_REST::get_status_for_post( get_post( $post_id ) );

		$this->assertSame( 'sent', $status['kind'] );
		$this->assertIsInt( $status['sent_at'] );
		$this->assertGreaterThan( 0, $status['sent_at'] );
		$this->assertNull( $status['scheduled_at'] );
	}

	/**
	 * A private newsletter (i.e. published but `is_public` was false) is also sent.
	 */
	public function test_private_post_reports_sent_kind() {
		$post_id = $this->make_newsletter(
			[
				'post_status' => 'private',
				'post_date'   => '2026-04-20 10:00:00',
			]
		);

		$status = Newsletters_List_REST::get_status_for_post( get_post( $post_id ) );

		$this->assertSame( 'sent', $status['kind'] );
		$this->assertIsInt( $status['sent_at'] );
	}

	/**
	 * A WP-scheduled post (`post_status=future`) reports kind=scheduled with
	 * `scheduled_at` derived from post_date_gmt.
	 */
	public function test_future_post_reports_scheduled_kind() {
		$future_date = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$post_id     = $this->make_newsletter(
			[
				'post_status' => 'future',
				'post_date'   => $future_date,
			]
		);

		$status = Newsletters_List_REST::get_status_for_post( get_post( $post_id ) );

		$this->assertSame( 'scheduled', $status['kind'] );
		$this->assertNull( $status['sent_at'] );
		$this->assertIsInt( $status['scheduled_at'] );
		$this->assertGreaterThan( time(), $status['scheduled_at'] );
	}

	/**
	 * The `sending_scheduled` meta flag (set when an ESP send is queued)
	 * also marks the newsletter as scheduled even if post_status is still
	 * draft — used during the brief window between scheduling and dispatch.
	 */
	public function test_sending_scheduled_meta_reports_scheduled_kind() {
		$post_id = $this->make_newsletter(
			[
				'post_status' => 'draft',
				'meta_input'  => [ 'sending_scheduled' => true ],
			]
		);

		$status = Newsletters_List_REST::get_status_for_post( get_post( $post_id ) );

		$this->assertSame( 'scheduled', $status['kind'] );
	}

	/**
	 * A trashed newsletter reports kind=trash regardless of any sent state.
	 */
	public function test_trashed_post_reports_trash_kind() {
		$post_id = $this->make_newsletter(
			[
				'post_status' => 'publish',
				'post_date'   => '2026-04-20 10:00:00',
			]
		);
		wp_trash_post( $post_id );

		$status = Newsletters_List_REST::get_status_for_post( get_post( $post_id ) );

		$this->assertSame( 'trash', $status['kind'] );
	}

	/**
	 * Stale `newsletter_sent` meta on a draft must not flip the row to
	 * "sent" — `is_newsletter_sent` only accepts the meta when it equals
	 * the publish timestamp, and a draft has no publish timestamp.
	 */
	public function test_draft_with_stale_newsletter_sent_meta_still_reports_draft() {
		$post_id = $this->make_newsletter(
			[
				'post_status' => 'draft',
				'meta_input'  => [ 'newsletter_sent' => 1700000000 ],
			]
		);

		$status = Newsletters_List_REST::get_status_for_post( get_post( $post_id ) );

		$this->assertSame( 'draft', $status['kind'] );
		$this->assertNull( $status['sent_at'] );
	}

	/**
	 * Same guard applies to a future-scheduled row carrying stale meta —
	 * the row should still report as scheduled, not sent.
	 */
	public function test_scheduled_with_stale_newsletter_sent_meta_still_reports_scheduled() {
		$future_date = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$post_id     = $this->make_newsletter(
			[
				'post_status' => 'future',
				'post_date'   => $future_date,
				'meta_input'  => [ 'newsletter_sent' => 1700000000 ],
			]
		);

		$status = Newsletters_List_REST::get_status_for_post( get_post( $post_id ) );

		$this->assertSame( 'scheduled', $status['kind'] );
		$this->assertNull( $status['sent_at'] );
	}

	/**
	 * When a publish row carries `newsletter_sent` meta that doesn't match
	 * the publish timestamp (drift between meta and post_date), prefer the
	 * publish timestamp over the stale meta value — `is_newsletter_sent`
	 * would have rewritten the meta in this case; we just ignore it.
	 */
	public function test_publish_with_mismatched_newsletter_sent_meta_uses_publish_date() {
		$post_id = $this->make_newsletter(
			[
				'post_status' => 'publish',
				'post_date'   => '2026-04-20 10:00:00',
				'meta_input'  => [ 'newsletter_sent' => 1700000000 ],
			]
		);

		$status = Newsletters_List_REST::get_status_for_post( get_post( $post_id ) );

		$publish_timestamp = get_post_datetime( $post_id, 'date', 'gmt' )->getTimestamp();

		$this->assertSame( 'sent', $status['kind'] );
		$this->assertSame( $publish_timestamp, $status['sent_at'] );
		$this->assertNotSame( 1700000000, $status['sent_at'] );
	}

	/**
	 * When the meta exactly matches the publish timestamp, return that
	 * value (it's what the upstream cache would return after back-fill).
	 */
	public function test_publish_with_matching_newsletter_sent_meta_returns_meta_value() {
		$post_id           = $this->make_newsletter(
			[
				'post_status' => 'publish',
				'post_date'   => '2026-04-20 10:00:00',
			]
		);
		$publish_timestamp = get_post_datetime( $post_id, 'date', 'gmt' )->getTimestamp();
		update_post_meta( $post_id, 'newsletter_sent', $publish_timestamp );

		$status = Newsletters_List_REST::get_status_for_post( get_post( $post_id ) );

		$this->assertSame( 'sent', $status['kind'] );
		$this->assertSame( $publish_timestamp, $status['sent_at'] );
	}

	/**
	 * `get_status_for_post` must not write to post_meta when computing the
	 * sent state. The vanilla `Newspack_Newsletters::is_newsletter_sent`
	 * back-fills `newsletter_sent` for any published post missing it; on
	 * a list REST GET that turns into N writes per page. The non-mutating
	 * `compute_sent_at` path mirrors the logic without the back-fill.
	 */
	public function test_get_status_does_not_back_fill_newsletter_sent_meta() {
		$post_id = $this->make_newsletter(
			[
				'post_status' => 'publish',
				'post_date'   => '2026-04-20 10:00:00',
			]
		);

		// Sanity: created post has no `newsletter_sent` meta yet.
		$this->assertSame( '', get_post_meta( $post_id, 'newsletter_sent', true ) );

		$status = Newsletters_List_REST::get_status_for_post( get_post( $post_id ) );

		$this->assertSame( 'sent', $status['kind'] );
		$this->assertIsInt( $status['sent_at'] );

		// Crucially: no meta back-fill happened during the read.
		$this->assertSame(
			'',
			get_post_meta( $post_id, 'newsletter_sent', true ),
			'get_status_for_post must be read-only — meta should not be back-filled.'
		);
	}

	/**
	 * The `newspack_newsletters_status` REST field is registered on the
	 * newsletters CPT so it surfaces on `/wp/v2/newspack_nl_cpt` responses.
	 */
	public function test_rest_field_is_registered_on_newsletters_cpt() {
		// Force REST init so register_rest_field callbacks have fired.
		do_action( 'rest_api_init' );

		global $wp_rest_additional_fields;

		$cpt    = Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
		$fields = isset( $wp_rest_additional_fields[ $cpt ] ) ? $wp_rest_additional_fields[ $cpt ] : [];

		$this->assertArrayHasKey( 'newspack_newsletters_status', $fields );
		$this->assertIsCallable( $fields['newspack_newsletters_status']['get_callback'] );
	}

	/**
	 * Helper: build a REST request with the given query params.
	 *
	 * @param array $params Query params keyed by name.
	 * @return WP_REST_Request
	 */
	private function rest_request( $params ) {
		$request = new WP_REST_Request( 'GET', '/wp/v2/' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * The `newspack_newsletters_is_public=1` query arg adds a meta_query
	 * clause matching only newsletters with `is_public` set to truthy.
	 */
	public function test_filter_rest_query_adds_is_public_clause_when_truthy() {
		$args = Newsletters_List_REST::filter_rest_query(
			[],
			$this->rest_request( [ Newsletters_List_REST::IS_PUBLIC_QUERY_PARAM => '1' ] )
		);

		$this->assertNotEmpty( $args['meta_query'] );
		$clause = $args['meta_query'][0];
		$this->assertSame( 'is_public', $clause['key'] );
		$this->assertSame( '1', $clause['value'] );
		$this->assertSame( '=', $clause['compare'] );
	}

	/**
	 * The `newspack_newsletters_is_public=0` arg matches newsletters where
	 * the meta is missing OR set to anything other than truthy — the same
	 * "not public" semantics as the column renderer.
	 */
	public function test_filter_rest_query_matches_missing_meta_when_falsy() {
		$args = Newsletters_List_REST::filter_rest_query(
			[],
			$this->rest_request( [ Newsletters_List_REST::IS_PUBLIC_QUERY_PARAM => '0' ] )
		);

		$this->assertNotEmpty( $args['meta_query'] );
		$clause = $args['meta_query'][0];
		$this->assertSame( 'OR', $clause['relation'] );
		$this->assertSame( 'is_public', $clause[0]['key'] );
		$this->assertSame( 'NOT EXISTS', $clause[0]['compare'] );
	}

	/**
	 * Without the query param the filter returns args untouched, so the
	 * REST request behaves like a normal CPT query.
	 */
	public function test_filter_rest_query_passes_through_when_param_absent() {
		$original = [ 'post_status' => 'publish' ];
		$args     = Newsletters_List_REST::filter_rest_query(
			$original,
			$this->rest_request( [] )
		);
		$this->assertSame( $original, $args );
	}

	/**
	 * Out-of-whitelist values (anything other than `'1'`/`'0'`/booleans)
	 * are ignored rather than coerced — guards against unexpected values
	 * silently flipping the filter to the "not public" branch.
	 */
	public function test_filter_rest_query_ignores_values_outside_whitelist() {
		$original = [ 'post_status' => 'publish' ];

		foreach ( [ '2', 'yes', 'no', 'true', 'foo', '' ] as $junk ) {
			$args = Newsletters_List_REST::filter_rest_query(
				$original,
				$this->rest_request( [ Newsletters_List_REST::IS_PUBLIC_QUERY_PARAM => $junk ] )
			);
			$this->assertSame(
				$original,
				$args,
				sprintf( 'Value %s should pass through unchanged.', wp_json_encode( $junk ) )
			);
		}
	}

	/**
	 * Boolean true/false are accepted as equivalents to `'1'`/`'0'`.
	 */
	public function test_filter_rest_query_accepts_boolean_values() {
		$true_args = Newsletters_List_REST::filter_rest_query(
			[],
			$this->rest_request( [ Newsletters_List_REST::IS_PUBLIC_QUERY_PARAM => true ] )
		);
		$this->assertSame( '=', $true_args['meta_query'][0]['compare'] );

		$false_args = Newsletters_List_REST::filter_rest_query(
			[],
			$this->rest_request( [ Newsletters_List_REST::IS_PUBLIC_QUERY_PARAM => false ] )
		);
		$this->assertSame( 'OR', $false_args['meta_query'][0]['relation'] );
	}

	/**
	 * Empty selection is a true pass-through — no `posts_where` installed.
	 */
	public function test_align_status_filter_with_scheduled_meta_passes_through_when_selection_empty() {
		$cases = [
			[],
			[ 'status' => '' ],
			[ 'status' => [] ],
			[ 'status' => [ '' ] ],
		];

		foreach ( $cases as $params ) {
			$original     = [ 'post_status' => 'something_specific' ];
			$where_before = $GLOBALS['wp_filter']['posts_where'] ?? null;

			$args = Newsletters_List_REST::align_status_filter_with_scheduled_meta(
				$original,
				$this->rest_request( $params )
			);

			$this->assertSame( $original, $args, 'Should pass through for params: ' . wp_json_encode( $params ) );
			$this->assertSame(
				$where_before,
				$GLOBALS['wp_filter']['posts_where'] ?? null,
				'Empty selection must not install a posts_where filter for params: ' . wp_json_encode( $params )
			);
		}
	}

	/**
	 * Non-empty selection without `future` must install the exclusion
	 * `posts_where`, otherwise in-flight scheduled rows leak in.
	 */
	public function test_align_status_filter_with_scheduled_meta_installs_inverse_where_when_future_absent() {
		$cases = [
			[ 'status' => 'publish' ],
			[ 'status' => 'publish,private' ],
			[ 'status' => [ 'publish', 'private' ] ],
			[ 'status' => 'draft,pending,auto-draft' ],
			[ 'status' => 'trash' ],
		];

		foreach ( $cases as $params ) {
			$before = $this->count_posts_where_callbacks();

			$args = Newsletters_List_REST::align_status_filter_with_scheduled_meta(
				[],
				$this->rest_request( $params )
			);

			$this->assertSame(
				$before + 1,
				$this->count_posts_where_callbacks(),
				'A posts_where callback should be installed for params: ' . wp_json_encode( $params )
			);

			// Drain only the closure we just installed by firing posts_where with a
			// WP_Query carrying the matching token — preserves any unrelated callbacks.
			$drain = new WP_Query();
			$drain->set( '_newspack_nl_bucket_token', $args['_newspack_nl_bucket_token'] );
			apply_filters( 'posts_where', '', $drain );

			$this->assertSame(
				$before,
				$this->count_posts_where_callbacks(),
				'Token-matching drain should self-remove the closure for params: ' . wp_json_encode( $params )
			);
		}
	}

	/**
	 * Shutdown drains an orphan closure when the owning query never ran.
	 */
	public function test_install_bucket_filter_drains_on_shutdown_if_query_never_runs() {
		$before = $this->count_posts_where_callbacks();

		// Snapshot existing priority-0 shutdown subscribers so we only fire
		// the callback this install adds — calling do_action('shutdown') or
		// every p0 callback would run unrelated hooks and trip PHPUnit's
		// output-buffer hygiene check.
		$pre_shutdown = isset( $GLOBALS['wp_filter']['shutdown'] ) && isset( $GLOBALS['wp_filter']['shutdown']->callbacks[0] )
			? array_keys( $GLOBALS['wp_filter']['shutdown']->callbacks[0] )
			: [];

		Newsletters_List_REST::align_status_filter_with_scheduled_meta(
			[],
			$this->rest_request( [ 'status' => 'publish' ] )
		);

		$this->assertSame( $before + 1, $this->count_posts_where_callbacks() );

		$post_shutdown = isset( $GLOBALS['wp_filter']['shutdown']->callbacks[0] )
			? $GLOBALS['wp_filter']['shutdown']->callbacks[0]
			: [];
		foreach ( $post_shutdown as $key => $registered ) {
			if ( ! in_array( $key, $pre_shutdown, true ) ) {
				call_user_func( $registered['function'] );
			}
		}

		$this->assertSame( $before, $this->count_posts_where_callbacks(), 'Shutdown drain should remove the orphan closure.' );
	}

	/**
	 * Draft selection widens `post_status` to include publish/private so
	 * scheduling_error fallthrough rows are reachable. Other selections
	 * don't need widening.
	 */
	public function test_align_status_filter_widens_post_status_when_draft_selected() {
		$args = Newsletters_List_REST::align_status_filter_with_scheduled_meta(
			[],
			$this->rest_request( [ 'status' => [ 'draft', 'pending', 'auto-draft' ] ] )
		);

		$this->assertContains( 'publish', $args['post_status'] );
		$this->assertContains( 'private', $args['post_status'] );
		$this->assertContains( 'draft', $args['post_status'] );

		// Sent-only doesn't need widening.
		$args = Newsletters_List_REST::align_status_filter_with_scheduled_meta(
			[ 'post_status' => 'preserved' ],
			$this->rest_request( [ 'status' => [ 'publish', 'private' ] ] )
		);
		$this->assertSame( 'preserved', $args['post_status'] );
	}

	/**
	 * Sent filter excludes publish rows carrying `sending_scheduled` or
	 * `scheduling_error` meta — neither renders as Sent.
	 */
	public function test_sent_filter_excludes_inflight_scheduled_rows() {
		$published    = $this->make_newsletter(
			[
				'post_status' => 'publish',
				'post_date'   => '2026-04-20 10:00:00',
			]
		);
		$pending_send = $this->make_newsletter(
			[
				'post_status' => 'publish',
				'post_date'   => '2026-04-20 10:00:00',
				'meta_input'  => [ 'sending_scheduled' => true ],
			]
		);
		$failed_send = $this->make_newsletter(
			[
				'post_status' => 'publish',
				'post_date'   => '2026-04-20 10:00:00',
				'meta_input'  => [ 'scheduling_error' => 'send failed' ],
			]
		);
		$plain_draft = $this->make_newsletter( [ 'post_status' => 'draft' ] );

		$args = Newsletters_List_REST::align_status_filter_with_scheduled_meta(
			[],
			$this->rest_request( [ 'status' => [ 'publish', 'private' ] ] )
		);

		$query = $this->run_newsletter_query( array_merge( $args, [ 'post_status' => [ 'publish', 'private' ] ] ) );

		$this->assertContains( $published, $query->posts, 'plain published row surfaces' );
		$this->assertNotContains( $pending_send, $query->posts, 'in-flight scheduled publish row is excluded' );
		$this->assertNotContains( $failed_send, $query->posts, 'publish row with scheduling_error is excluded' );
		$this->assertNotContains( $plain_draft, $query->posts, 'plain draft is excluded by status filter' );
	}

	/**
	 * Draft filter mirrors the renderer's Draft kind: keeps draft-family
	 * rows AND publish/private rows with `scheduling_error` (which fall
	 * through to Draft), while excluding any row with `sending_scheduled`.
	 */
	public function test_draft_filter_matches_renderer_draft_kind() {
		$plain_draft  = $this->make_newsletter( [ 'post_status' => 'draft' ] );
		$pending_send = $this->make_newsletter(
			[
				'post_status' => 'draft',
				'meta_input'  => [ 'sending_scheduled' => true ],
			]
		);
		$errored_draft = $this->make_newsletter(
			[
				'post_status' => 'draft',
				'meta_input'  => [ 'scheduling_error' => 'send failed' ],
			]
		);
		$errored_publish = $this->make_newsletter(
			[
				'post_status' => 'publish',
				'post_date'   => '2026-04-20 10:00:00',
				'meta_input'  => [ 'scheduling_error' => 'send failed' ],
			]
		);
		// Should NOT match: plain publish renders as Sent, not Draft.
		$plain_publish = $this->make_newsletter(
			[
				'post_status' => 'publish',
				'post_date'   => '2026-04-20 10:00:00',
			]
		);

		$args = Newsletters_List_REST::align_status_filter_with_scheduled_meta(
			[],
			$this->rest_request( [ 'status' => [ 'draft', 'pending', 'auto-draft' ] ] )
		);

		// `align_status_filter_with_scheduled_meta` widens `post_status`
		// to include publish/private when Draft is wanted, so let the
		// returned args drive the query.
		$query = $this->run_newsletter_query( $args );

		$this->assertContains( $plain_draft, $query->posts, 'plain draft surfaces' );
		$this->assertNotContains( $pending_send, $query->posts, 'in-flight scheduled draft is excluded' );
		$this->assertContains( $errored_draft, $query->posts, 'draft with scheduling_error surfaces' );
		$this->assertContains( $errored_publish, $query->posts, 'publish row with scheduling_error surfaces — renders as Draft' );
		$this->assertNotContains( $plain_publish, $query->posts, 'plain publish stays out — it renders as Sent, not Draft' );
	}

	/**
	 * Mixed Sent + Draft selection keeps `scheduling_error` rows
	 * (whether on draft or publish), since the user's selection covers
	 * the Draft kind they fall through to.
	 */
	public function test_mixed_sent_and_draft_filter_keeps_scheduling_error_rows() {
		$plain_publish = $this->make_newsletter(
			[
				'post_status' => 'publish',
				'post_date'   => '2026-04-20 10:00:00',
			]
		);
		$errored_publish = $this->make_newsletter(
			[
				'post_status' => 'publish',
				'post_date'   => '2026-04-20 10:00:00',
				'meta_input'  => [ 'scheduling_error' => 'send failed' ],
			]
		);
		$errored_draft = $this->make_newsletter(
			[
				'post_status' => 'draft',
				'meta_input'  => [ 'scheduling_error' => 'send failed' ],
			]
		);
		$pending_send = $this->make_newsletter(
			[
				'post_status' => 'publish',
				'post_date'   => '2026-04-20 10:00:00',
				'meta_input'  => [ 'sending_scheduled' => true ],
			]
		);

		$args = Newsletters_List_REST::align_status_filter_with_scheduled_meta(
			[],
			$this->rest_request( [ 'status' => [ 'publish', 'private', 'draft', 'pending', 'auto-draft' ] ] )
		);

		$query = $this->run_newsletter_query(
			array_merge( $args, [ 'post_status' => [ 'publish', 'private', 'draft', 'pending', 'auto-draft' ] ] )
		);

		$this->assertContains( $plain_publish, $query->posts, 'plain published row surfaces' );
		$this->assertContains( $errored_publish, $query->posts, 'publish row with scheduling_error stays — renders as Draft' );
		$this->assertContains( $errored_draft, $query->posts, 'draft with scheduling_error stays — renders as Draft' );
		$this->assertNotContains( $pending_send, $query->posts, 'in-flight scheduled row stays out — renders as Scheduled' );
	}

	/**
	 * Scheduled+Sent must exclude publish rows with `scheduling_error`
	 * (they render as Draft, not Sent or Scheduled), and Scheduled+Draft
	 * must include them. Both cases previously bypassed the kind logic
	 * because `future` triggered a separate raw-status branch.
	 */
	public function test_scheduled_plus_sent_or_draft_aligns_with_renderer_for_scheduling_error_rows() {
		$future_post = $this->make_newsletter(
			[
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			]
		);
		$pending_send = $this->make_newsletter(
			[
				'post_status' => 'draft',
				'meta_input'  => [ 'sending_scheduled' => true ],
			]
		);
		$plain_publish = $this->make_newsletter(
			[
				'post_status' => 'publish',
				'post_date'   => '2026-04-20 10:00:00',
			]
		);
		$errored_publish = $this->make_newsletter(
			[
				'post_status' => 'publish',
				'post_date'   => '2026-04-20 10:00:00',
				'meta_input'  => [ 'scheduling_error' => 'send failed' ],
			]
		);

		// Scheduled+Sent: errored_publish renders as Draft → out.
		$args  = Newsletters_List_REST::align_status_filter_with_scheduled_meta(
			[],
			$this->rest_request( [ 'status' => [ 'future', 'publish', 'private' ] ] )
		);
		$query = $this->run_newsletter_query( $args );
		$this->assertContains( $future_post, $query->posts, 'Scheduled+Sent: future post surfaces' );
		$this->assertContains( $pending_send, $query->posts, 'Scheduled+Sent: sending_scheduled draft surfaces' );
		$this->assertContains( $plain_publish, $query->posts, 'Scheduled+Sent: plain publish surfaces' );
		$this->assertNotContains( $errored_publish, $query->posts, 'Scheduled+Sent: publish with scheduling_error is excluded (renders as Draft)' );

		// Scheduled+Draft: errored_publish renders as Draft → in.
		$args  = Newsletters_List_REST::align_status_filter_with_scheduled_meta(
			[],
			$this->rest_request( [ 'status' => [ 'future', 'draft', 'pending', 'auto-draft' ] ] )
		);
		$query = $this->run_newsletter_query( $args );
		$this->assertContains( $future_post, $query->posts, 'Scheduled+Draft: future post surfaces' );
		$this->assertContains( $pending_send, $query->posts, 'Scheduled+Draft: sending_scheduled draft surfaces' );
		$this->assertContains( $errored_publish, $query->posts, 'Scheduled+Draft: publish with scheduling_error surfaces (renders as Draft)' );
		$this->assertNotContains( $plain_publish, $query->posts, 'Scheduled+Draft: plain publish is excluded (renders as Sent)' );
	}

	/**
	 * Trash filter still includes trashed rows with leftover
	 * `sending_scheduled` meta — they render as Trash, not Scheduled.
	 */
	public function test_trash_filter_includes_trashed_rows_with_sending_scheduled_meta() {
		$trashed = $this->make_newsletter(
			[
				'post_status' => 'publish',
				'post_date'   => '2026-04-20 10:00:00',
				'meta_input'  => [ 'sending_scheduled' => true ],
			]
		);
		wp_trash_post( $trashed );

		$args = Newsletters_List_REST::align_status_filter_with_scheduled_meta(
			[],
			$this->rest_request( [ 'status' => 'trash' ] )
		);

		$query = $this->run_newsletter_query( array_merge( $args, [ 'post_status' => [ 'trash' ] ] ) );

		$this->assertContains( $trashed, $query->posts, 'trashed row with sending_scheduled meta still surfaces under Trash' );
	}

	/**
	 * When the request includes `future` (alone or mixed), widen
	 * `post_status` to cover both the user's explicit picks AND the
	 * statuses where a `sending_scheduled` row might live. Trash is
	 * only included when the user actually asked for it — otherwise
	 * the user expects scheduled-only and trash should stay excluded.
	 */
	public function test_expand_scheduled_filter_widens_post_status_when_future_is_selected() {
		// Sole-`future` selection: trash NOT included.
		foreach ( [ 'future', [ 'future' ] ] as $value ) {
			$args = Newsletters_List_REST::align_status_filter_with_scheduled_meta(
				[],
				$this->rest_request( [ 'status' => $value ] )
			);
			$this->assertContains( 'future', $args['post_status'] );
			$this->assertContains( 'draft', $args['post_status'] );
			$this->assertNotContains( 'trash', $args['post_status'] );
		}

		// Future + other published statuses: still no trash.
		foreach ( [ 'future,publish,private', [ 'future', 'publish', 'private' ] ] as $value ) {
			$args = Newsletters_List_REST::align_status_filter_with_scheduled_meta(
				[],
				$this->rest_request( [ 'status' => $value ] )
			);
			$this->assertContains( 'publish', $args['post_status'] );
			$this->assertNotContains( 'trash', $args['post_status'] );
		}

		// Future + trash: trash MUST be in the widened set, otherwise
		// WP_Query filters it out before `posts_where` can preserve it.
		foreach ( [ 'future,trash', [ 'future', 'trash' ] ] as $value ) {
			$args = Newsletters_List_REST::align_status_filter_with_scheduled_meta(
				[],
				$this->rest_request( [ 'status' => $value ] )
			);
			$this->assertContains( 'future', $args['post_status'] );
			$this->assertContains( 'trash', $args['post_status'] );
			$this->assertContains( 'draft', $args['post_status'] );
		}
	}

	/**
	 * End-to-end regression: a draft post with `sending_scheduled` meta
	 * renders as "Scheduled" in the column; the Scheduled filter should
	 * surface it alongside actual `future` posts. We assert this by
	 * running an actual `WP_Query` through the filtered args + the
	 * `posts_where` callback that `expand_scheduled_filter` installs.
	 */
	public function test_scheduled_filter_includes_sending_scheduled_meta_drafts() {
		$future_post = $this->make_newsletter(
			[
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			]
		);
		$pending_send = $this->make_newsletter(
			[
				'post_status' => 'draft',
				'meta_input'  => [ 'sending_scheduled' => true ],
			]
		);
		// Should NOT match: plain draft, no sending_scheduled meta.
		$plain_draft = $this->make_newsletter( [ 'post_status' => 'draft' ] );
		// Should NOT match: trashed row, even if it had sending_scheduled meta.
		$trashed = $this->make_newsletter(
			[
				'post_status' => 'trash',
				'meta_input'  => [ 'sending_scheduled' => true ],
			]
		);

		$args = Newsletters_List_REST::align_status_filter_with_scheduled_meta(
			[],
			$this->rest_request( [ 'status' => 'future' ] )
		);

		// `expand_scheduled_filter` registered a one-shot `posts_where`.
		// Run a real WP_Query so the callback fires.
		$query = new WP_Query(
			array_merge(
				$args,
				[
					'post_type'      => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				]
			)
		);

		$this->assertContains( $future_post, $query->posts );
		$this->assertContains( $pending_send, $query->posts );
		$this->assertNotContains( $plain_draft, $query->posts );
		$this->assertNotContains( $trashed, $query->posts );
	}

	/**
	 * Mixed selection regression: when the user combines `future` with
	 * `publish` / `private`, the published rows must still come through
	 * AND the in-flight scheduled rows must surface alongside.
	 */
	public function test_scheduled_filter_in_mixed_selection_keeps_other_statuses_too() {
		$future_post  = $this->make_newsletter(
			[
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			]
		);
		$pending_send = $this->make_newsletter(
			[
				'post_status' => 'draft',
				'meta_input'  => [ 'sending_scheduled' => true ],
			]
		);
		$published    = $this->make_newsletter(
			[
				'post_status' => 'publish',
				'post_date'   => '2026-04-20 10:00:00',
			]
		);
		// Should NOT match: not future, no sending_scheduled meta, and
		// the user didn't request `draft` — so a plain draft is filtered
		// out by the OR clause.
		$plain_draft = $this->make_newsletter( [ 'post_status' => 'draft' ] );

		$args = Newsletters_List_REST::align_status_filter_with_scheduled_meta(
			[],
			$this->rest_request( [ 'status' => [ 'future', 'publish', 'private' ] ] )
		);

		$query = new WP_Query(
			array_merge(
				$args,
				[
					'post_type'      => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				]
			)
		);

		$this->assertContains( $future_post, $query->posts, 'future post still matches' );
		$this->assertContains( $published, $query->posts, 'published post is preserved' );
		$this->assertContains( $pending_send, $query->posts, 'in-flight scheduled row surfaces' );
		$this->assertNotContains( $plain_draft, $query->posts, 'plain draft is excluded' );
	}

	/**
	 * Mixed Scheduled + Trash regression: when the user picks both, the
	 * widened `post_status` set must include `trash` so `posts_where`'s
	 * `IN (selection)` clause can keep trashed rows. Without this, the
	 * fixed widened set silently drops trashed rows even though they
	 * were explicitly requested.
	 */
	public function test_scheduled_filter_in_mixed_selection_with_trash_keeps_trashed_rows() {
		$future_post = $this->make_newsletter(
			[
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			]
		);
		$pending_send = $this->make_newsletter(
			[
				'post_status' => 'draft',
				'meta_input'  => [ 'sending_scheduled' => true ],
			]
		);
		$trashed = $this->make_newsletter(
			[
				'post_status' => 'publish',
				'post_date'   => '2026-04-20 10:00:00',
			]
		);
		wp_trash_post( $trashed );
		// Should NOT match: not future, not trashed, no sending_scheduled meta.
		$plain_draft = $this->make_newsletter( [ 'post_status' => 'draft' ] );

		$args = Newsletters_List_REST::align_status_filter_with_scheduled_meta(
			[],
			$this->rest_request( [ 'status' => [ 'future', 'trash' ] ] )
		);

		$query = new WP_Query(
			array_merge(
				$args,
				[
					'post_type'      => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				]
			)
		);

		$this->assertContains( $future_post, $query->posts, 'future post still matches' );
		$this->assertContains( $trashed, $query->posts, 'trashed row survives because user asked for trash' );
		$this->assertContains( $pending_send, $query->posts, 'in-flight scheduled row still surfaces' );
		$this->assertNotContains( $plain_draft, $query->posts, 'plain draft remains excluded' );
	}

	/**
	 * Existing `meta_query` entries are preserved — the filter appends
	 * its clause rather than replacing.
	 */
	public function test_filter_rest_query_appends_to_existing_meta_query() {
		$existing = [
			[
				'key'   => 'something_else',
				'value' => 'foo',
			],
		];
		$args     = Newsletters_List_REST::filter_rest_query(
			[ 'meta_query' => $existing ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$this->rest_request( [ Newsletters_List_REST::IS_PUBLIC_QUERY_PARAM => '1' ] )
		);

		$this->assertCount( 2, $args['meta_query'] );
		$this->assertSame( $existing[0], $args['meta_query'][0] );
		$this->assertSame( 'is_public', $args['meta_query'][1]['key'] );
	}

	/**
	 * Single send-list ID adds a meta_query IN clause with that one value.
	 */
	public function test_filter_send_list_query_adds_in_clause_for_single_id() {
		$args = Newsletters_List_REST::filter_send_list_query(
			[],
			$this->rest_request( [ Newsletters_List_REST::SEND_LIST_QUERY_PARAM => 'list-a' ] )
		);

		$this->assertNotEmpty( $args['meta_query'] );
		$this->assertSame( 'send_list_id', $args['meta_query'][0]['key'] );
		$this->assertSame( 'IN', $args['meta_query'][0]['compare'] );
		$this->assertSame( [ 'list-a' ], $args['meta_query'][0]['value'] );
	}

	/**
	 * Comma-separated IDs split into the IN clause; whitespace stripped.
	 */
	public function test_filter_send_list_query_splits_and_trims_comma_list() {
		$args = Newsletters_List_REST::filter_send_list_query(
			[],
			$this->rest_request( [ Newsletters_List_REST::SEND_LIST_QUERY_PARAM => 'list-a, list-b ,list-c' ] )
		);

		$this->assertSame( [ 'list-a', 'list-b', 'list-c' ], $args['meta_query'][0]['value'] );
	}

	/**
	 * Array values are accepted as-is; empty entries are dropped.
	 */
	public function test_filter_send_list_query_accepts_array_and_drops_empty_entries() {
		$args = Newsletters_List_REST::filter_send_list_query(
			[],
			$this->rest_request( [ Newsletters_List_REST::SEND_LIST_QUERY_PARAM => [ 'list-a', '', 'list-b' ] ] )
		);

		$this->assertSame( [ 'list-a', 'list-b' ], $args['meta_query'][0]['value'] );
	}

	/**
	 * Missing / empty param leaves args alone — no meta_query side-effect.
	 */
	public function test_filter_send_list_query_passes_through_when_param_absent() {
		$args = Newsletters_List_REST::filter_send_list_query(
			[ 'foo' => 'bar' ],
			$this->rest_request( [] )
		);
		$this->assertSame( [ 'foo' => 'bar' ], $args );

		$args = Newsletters_List_REST::filter_send_list_query(
			[ 'foo' => 'bar' ],
			$this->rest_request( [ Newsletters_List_REST::SEND_LIST_QUERY_PARAM => '' ] )
		);
		$this->assertSame( [ 'foo' => 'bar' ], $args );
	}

	/**
	 * End-to-end: with `send_list_id` meta on rows, the filter narrows
	 * the result set to the requested IDs. Newsletters without the meta
	 * drop out of an IN-clause filter, which is the intended behavior
	 * for a "show me list X" filter (distinct from sort, where missing
	 * meta has to round-trip).
	 */
	public function test_filter_send_list_query_narrows_query_to_requested_ids() {
		$list_a = self::factory()->post->create(
			[
				'post_type'   => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status' => 'publish',
				'meta_input'  => [ 'send_list_id' => 'list-a' ],
			]
		);
		$list_b = self::factory()->post->create(
			[
				'post_type'   => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status' => 'publish',
				'meta_input'  => [ 'send_list_id' => 'list-b' ],
			]
		);
		$no_list = self::factory()->post->create(
			[
				'post_type'   => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status' => 'publish',
			]
		);

		$args = Newsletters_List_REST::filter_send_list_query(
			[],
			$this->rest_request( [ Newsletters_List_REST::SEND_LIST_QUERY_PARAM => 'list-a' ] )
		);

		$query = new WP_Query(
			array_merge(
				$args,
				[
					'post_type'      => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
					'post_status'    => 'publish',
					'fields'         => 'ids',
					'posts_per_page' => -1,
				]
			)
		);

		$this->assertContains( $list_a, $query->posts );
		$this->assertNotContains( $list_b, $query->posts );
		$this->assertNotContains( $no_list, $query->posts );
	}

	/**
	 * Filter-options endpoint returns send_list_ids actually used,
	 * collapses duplicates, and ignores auto-draft + other CPTs.
	 */
	public function test_filter_options_send_lists_returns_distinct_values_for_cpt() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$cpt = Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
		self::factory()->post->create(
			[
				'post_type'   => $cpt,
				'post_status' => 'publish',
				'meta_input'  => [ 'send_list_id' => 'list-b' ],
			]
		);
		self::factory()->post->create(
			[
				'post_type'   => $cpt,
				'post_status' => 'draft',
				'meta_input'  => [ 'send_list_id' => 'list-a' ],
			]
		);
		self::factory()->post->create(
			[
				'post_type'   => $cpt,
				'post_status' => 'publish',
				'meta_input'  => [ 'send_list_id' => 'list-a' ],
			]
		);
		self::factory()->post->create(
			[
				'post_type'   => $cpt,
				'post_status' => 'auto-draft',
				'meta_input'  => [ 'send_list_id' => 'list-ignore' ],
			]
		);
		self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'meta_input'  => [ 'send_list_id' => 'list-other' ],
			]
		);

		$response = Newsletters_List_REST::rest_get_filter_options();
		$ids      = array_column( $response->get_data()['send_lists'], 'id' );

		$this->assertContains( 'list-a', $ids );
		$this->assertContains( 'list-b', $ids );
		$this->assertNotContains( 'list-ignore', $ids );
		$this->assertNotContains( 'list-other', $ids );
		$this->assertSame( count( $ids ), count( array_unique( $ids ) ), 'IDs should be distinct' );
	}

	/**
	 * Filter-options returns distinct authors of any non-auto-draft
	 * newsletter — scope-gated to our CPT, with `display_name` labels.
	 */
	public function test_filter_options_authors_returns_distinct_newsletter_authors() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$cpt    = Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
		$alice  = self::factory()->user->create( [ 'display_name' => 'Alice' ] );
		$bob    = self::factory()->user->create( [ 'display_name' => 'Bob' ] );
		$ghost  = self::factory()->user->create( [ 'display_name' => 'Ghost' ] );
		$leaker = self::factory()->user->create( [ 'display_name' => 'Other-CPT Leaker' ] );

		// Two newsletters by Alice (should collapse), one by Bob.
		self::factory()->post->create(
			[
				'post_type'   => $cpt,
				'post_status' => 'publish',
				'post_author' => $alice,
			]
		);
		self::factory()->post->create(
			[
				'post_type'   => $cpt,
				'post_status' => 'draft',
				'post_author' => $alice,
			]
		);
		self::factory()->post->create(
			[
				'post_type'   => $cpt,
				'post_status' => 'publish',
				'post_author' => $bob,
			]
		);
		// Ghost authored only an auto-draft — must not appear.
		self::factory()->post->create(
			[
				'post_type'   => $cpt,
				'post_status' => 'auto-draft',
				'post_author' => $ghost,
			]
		);
		// Leaker authored a different CPT — must not appear.
		self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_author' => $leaker,
			]
		);

		$authors = Newsletters_List_REST::rest_get_filter_options()->get_data()['authors'];
		$ids     = array_column( $authors, 'id' );

		$this->assertContains( $alice, $ids );
		$this->assertContains( $bob, $ids );
		$this->assertNotContains( $ghost, $ids );
		$this->assertNotContains( $leaker, $ids );
		$this->assertSame( count( $ids ), count( array_unique( $ids ) ) );
		foreach ( $authors as $author ) {
			$this->assertIsInt( $author['id'] );
			$this->assertNotSame( '', $author['label'] );
		}
	}

	/**
	 * Filter-options returns categories / tags actually applied to
	 * newsletters in our CPT, never terms from other post types.
	 */
	public function test_filter_options_terms_returns_only_terms_used_on_newsletters() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$cpt        = Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
		$used_cat   = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Used Cat',
			]
		);
		$unused_cat = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Unused Cat',
			]
		);
		$used_tag   = self::factory()->term->create(
			[
				'taxonomy' => 'post_tag',
				'name'     => 'Used Tag',
			]
		);
		$other_cat  = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Other CPT Cat',
			]
		);

		$newsletter = self::factory()->post->create(
			[
				'post_type'   => $cpt,
				'post_status' => 'publish',
			]
		);
		wp_set_object_terms( $newsletter, [ $used_cat ], 'category' );
		wp_set_object_terms( $newsletter, [ $used_tag ], 'post_tag' );

		$other_post = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
			]
		);
		wp_set_object_terms( $other_post, [ $other_cat ], 'category' );

		$options = Newsletters_List_REST::rest_get_filter_options()->get_data();
		$cat_ids = array_column( $options['categories'], 'id' );
		$tag_ids = array_column( $options['tags'], 'id' );

		$this->assertContains( $used_cat, $cat_ids );
		$this->assertNotContains( $unused_cat, $cat_ids );
		$this->assertNotContains( $other_cat, $cat_ids );
		$this->assertContains( $used_tag, $tag_ids );
	}

	/**
	 * Filter-options endpoint denies anonymous users.
	 */
	public function test_rest_filter_options_permission_check_denies_anonymous() {
		wp_set_current_user( 0 );
		$this->assertFalse( Newsletters_List_REST::rest_filter_options_permission_check() );
	}

	/**
	 * Filter-options endpoint allows users with newsletter edit caps.
	 */
	public function test_rest_filter_options_permission_check_allows_editor() {
		$editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );
		$this->assertTrue( Newsletters_List_REST::rest_filter_options_permission_check() );
	}

	/**
	 * Filter-options queries are capped at `FILTER_OPTIONS_LIMIT` rows
	 * so a site with tens of thousands of newsletters can't blow up the
	 * payload (or the SQL). Exercised via `send_list_id`, but the same
	 * literal `LIMIT` clause guards the authors and terms queries too.
	 */
	public function test_filter_options_caps_results_at_filter_options_limit() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$cpt   = Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
		$cap   = Newsletters_List_REST::FILTER_OPTIONS_LIMIT;
		$total = $cap + 5;

		for ( $i = 0; $i < $total; $i++ ) {
			self::factory()->post->create(
				[
					'post_type'   => $cpt,
					'post_status' => 'publish',
					'meta_input'  => [ 'send_list_id' => sprintf( 'list-%04d', $i ) ],
				]
			);
		}

		$send_lists = Newsletters_List_REST::rest_get_filter_options()->get_data()['send_lists'];

		$this->assertCount( $cap, $send_lists, 'Send-list options should be capped at FILTER_OPTIONS_LIMIT.' );
	}

	/**
	 * A user without `edit_others_posts` only sees options derived from
	 * their own newsletters — never leaks authors / terms / send-list
	 * IDs from other publishers' drafts or private rows.
	 */
	public function test_filter_options_scopes_to_user_when_edit_others_posts_is_absent() {
		$cpt   = Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
		$mine  = self::factory()->user->create( [ 'role' => 'author' ] );
		$other = self::factory()->user->create(
			[
				'role'         => 'editor',
				'display_name' => 'Other Editor',
			]
		);

		$my_cat    = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'My Cat',
			]
		);
		$their_cat = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Their Cat',
			]
		);

		$my_newsletter = self::factory()->post->create(
			[
				'post_type'   => $cpt,
				'post_status' => 'draft',
				'post_author' => $mine,
				'meta_input'  => [ 'send_list_id' => 'mine-list' ],
			]
		);
		wp_set_object_terms( $my_newsletter, [ $my_cat ], 'category' );

		$their_newsletter = self::factory()->post->create(
			[
				'post_type'   => $cpt,
				'post_status' => 'draft',
				'post_author' => $other,
				'meta_input'  => [ 'send_list_id' => 'their-list' ],
			]
		);
		wp_set_object_terms( $their_newsletter, [ $their_cat ], 'category' );

		// Acting as `mine` (no edit_others_posts), the dropdown narrows
		// to options derived from their own newsletter only.
		wp_set_current_user( $mine );
		$options = Newsletters_List_REST::rest_get_filter_options()->get_data();

		$author_ids    = array_column( $options['authors'], 'id' );
		$category_ids  = array_column( $options['categories'], 'id' );
		$send_list_ids = array_column( $options['send_lists'], 'id' );

		$this->assertContains( $mine, $author_ids );
		$this->assertNotContains( $other, $author_ids );
		$this->assertContains( $my_cat, $category_ids );
		$this->assertNotContains( $their_cat, $category_ids );
		$this->assertContains( 'mine-list', $send_list_ids );
		$this->assertNotContains( 'their-list', $send_list_ids );

		// Editor (has edit_others_posts) sees the full set.
		wp_set_current_user( $other );
		$options = Newsletters_List_REST::rest_get_filter_options()->get_data();
		$this->assertContains( $mine, array_column( $options['authors'], 'id' ) );
		$this->assertContains( $other, array_column( $options['authors'], 'id' ) );
		$this->assertContains( $my_cat, array_column( $options['categories'], 'id' ) );
		$this->assertContains( $their_cat, array_column( $options['categories'], 'id' ) );
		$this->assertContains( 'mine-list', array_column( $options['send_lists'], 'id' ) );
		$this->assertContains( 'their-list', array_column( $options['send_lists'], 'id' ) );
	}
}
