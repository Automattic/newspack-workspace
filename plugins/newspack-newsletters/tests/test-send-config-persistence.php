<?php
/**
 * Tests that send-config meta is persisted before the ESP send reads it.
 *
 * Regression coverage for NPPM-2935 (wrong list / whole-audience send) and
 * NPPM-2929 ("Please input sender name and email address"). The send is
 * triggered from `pre_post_update`, which fires inside `wp_update_post()` —
 * before the REST controller writes the request's post meta. The fix commits
 * the request's send-config on `rest_pre_insert_{cpt}` (before wp_update_post).
 *
 * @package Newspack_Newsletters
 */

/**
 * Send-config persistence tests.
 */
class Newsletter_Send_Config_Persistence_Test extends WP_UnitTestCase {

	/**
	 * Set up: act as an administrator so REST meta edits are permitted.
	 * Re-registers meta so WP_UnitTestCase::unregister_all_meta_keys() (called in
	 * tear_down of the previous test) does not leave send-config keys unregistered.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		\Newspack_Newsletters::set_service_provider( 'active_campaign' );
		\Newspack_Newsletters::register_meta();
	}

	/**
	 * A draft REST update carrying a new send_list_id must have that value in
	 * the DB by the time save_post / pre_post_update fire (where the send reads it).
	 */
	public function test_send_list_id_is_persisted_before_save_post_on_rest_update() {
		$cpt     = \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
		$post_id = self::factory()->post->create(
			[
				'post_type'   => $cpt,
				'post_status' => 'draft',
			]
		);
		update_post_meta( $post_id, 'send_list_id', '14' ); // Previously-stored list.

		// Capture what save_post sees — this is where the ESP send/sync reads meta.
		$seen_at_save = null;
		add_action(
			'save_post_' . $cpt,
			function ( $pid ) use ( &$seen_at_save, $post_id ) {
				if ( (int) $pid === (int) $post_id ) {
					$seen_at_save = get_post_meta( $post_id, 'send_list_id', true );
				}
			},
			1,
			1
		);

		// REST update with the newly-selected list; status stays draft (no send).
		$request = new WP_REST_Request( 'POST', '/wp/v2/' . $cpt . '/' . $post_id );
		$request->set_body_params( [ 'meta' => [ 'send_list_id' => '99' ] ] );
		rest_do_request( $request );

		$this->assertSame(
			'99',
			$seen_at_save,
			'send_list_id must be persisted before save_post/pre_post_update fire'
		);
	}

	/**
	 * MERGE GATE: on a publish REST request that selects a segment,
	 * send_sublist_id must be fresh in the DB when pre_post_update (the send
	 * trigger) fires. create_campaign() computes
	 * `segmentid = $has_configured_segment ? $send_sublist_id : 0` from this
	 * exact get_post_meta read, so a fresh non-empty value here is what keeps
	 * the send off segmentid=0 (the entire audience). This asserts the meta is
	 * fresh at send time; the segmentid linkage is by construction in
	 * create_campaign(), which this plan does not modify.
	 */
	public function test_send_sublist_id_is_fresh_when_send_fires_on_publish() {
		$cpt     = \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
		$post_id = self::factory()->post->create(
			[
				'post_type'   => $cpt,
				'post_status' => 'draft',
			]
		);
		// No segment configured before this save (the dangerous case).
		update_post_meta( $post_id, 'send_sublist_id', '' );

		// Capture what pre_post_update sees (priority 1 runs before the send at 10).
		$seen_at_send = 'PROBE_NOT_RUN';
		add_action(
			'pre_post_update',
			function ( $pid ) use ( &$seen_at_send, $post_id ) {
				if ( (int) $pid === (int) $post_id ) {
					$seen_at_send = get_post_meta( $post_id, 'send_sublist_id', true );
				}
			},
			1,
			1
		);

		// Publish with a freshly-selected segment. The real send (priority 10)
		// has no API credentials in tests, so it returns WP_Error and the
		// pre_post_update guard wp_die()s — by then our probe has already run.
		$request = new WP_REST_Request( 'POST', '/wp/v2/' . $cpt . '/' . $post_id );
		$request->set_body_params(
			[
				'status' => 'publish',
				'meta'   => [ 'send_sublist_id' => '42' ],
			]
		);
		try {
			rest_do_request( $request );
		} catch ( WPDieException $e ) {
			unset( $e ); // Expected: credential-less send aborts publish after our probe ran.
		}

		$this->assertSame(
			'42',
			$seen_at_send,
			'send_sublist_id must be fresh when the send fires, so segmentid is never 0'
		);
	}

	/**
	 * Sender fields are persisted before the send reads them (NPPM-2929).
	 */
	public function test_sender_fields_are_persisted_before_save_post() {
		$cpt     = \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
		$post_id = self::factory()->post->create(
			[
				'post_type'   => $cpt,
				'post_status' => 'draft',
			]
		);
		update_post_meta( $post_id, 'senderName', '' );
		update_post_meta( $post_id, 'senderEmail', '' );

		$seen = [];
		add_action(
			'save_post_' . $cpt,
			function ( $pid ) use ( &$seen, $post_id ) {
				if ( (int) $pid === (int) $post_id ) {
					$seen['name']  = get_post_meta( $post_id, 'senderName', true );
					$seen['email'] = get_post_meta( $post_id, 'senderEmail', true );
				}
			},
			1,
			1
		);

		$request = new WP_REST_Request( 'POST', '/wp/v2/' . $cpt . '/' . $post_id );
		$request->set_body_params(
			[
				'meta' => [
					'senderName'  => 'Newsroom',
					'senderEmail' => 'news@example.org',
				],
			]
		);
		rest_do_request( $request );

		$this->assertSame( 'Newsroom', $seen['name'] );
		$this->assertSame( 'news@example.org', $seen['email'] );
	}

	/**
	 * Only fields present in the request are written early; absent fields are
	 * left untouched (partial-update safe).
	 */
	public function test_only_present_send_config_fields_are_written_early() {
		$cpt     = \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
		$post_id = self::factory()->post->create(
			[
				'post_type'   => $cpt,
				'post_status' => 'draft',
			]
		);
		update_post_meta( $post_id, 'send_list_id', '14' );
		update_post_meta( $post_id, 'send_sublist_id', '7' );

		$seen = [];
		add_action(
			'save_post_' . $cpt,
			function ( $pid ) use ( &$seen, $post_id ) {
				if ( (int) $pid === (int) $post_id ) {
					$seen['list']    = get_post_meta( $post_id, 'send_list_id', true );
					$seen['sublist'] = get_post_meta( $post_id, 'send_sublist_id', true );
				}
			},
			1,
			1
		);

		// Request changes only send_list_id; send_sublist_id is absent.
		$request = new WP_REST_Request( 'POST', '/wp/v2/' . $cpt . '/' . $post_id );
		$request->set_body_params( [ 'meta' => [ 'send_list_id' => '99' ] ] );
		rest_do_request( $request );

		$this->assertSame( '99', $seen['list'], 'present field updated early' );
		$this->assertSame( '7', $seen['sublist'], 'absent field left untouched' );
	}

	/**
	 * Creating a new newsletter via REST (no post ID at prepare time) must not
	 * fatal in the handler; meta is still set via the normal write path.
	 */
	public function test_create_path_is_a_no_op_in_handler() {
		$cpt     = \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
		$request = new WP_REST_Request( 'POST', '/wp/v2/' . $cpt );
		$request->set_body_params(
			[
				'title' => 'New draft',
				'meta'  => [ 'send_list_id' => '5' ],
			]
		);
		$response = rest_do_request( $request );

		$this->assertContains( $response->get_status(), [ 200, 201 ], 'create succeeds' );
		$new_id = $response->get_data()['id'];
		$this->assertSame( '5', get_post_meta( $new_id, 'send_list_id', true ) );
	}

	/**
	 * An unauthorized caller is rejected by the route permission check before
	 * the early-write handler runs — nothing is persisted.
	 */
	public function test_unauthorized_user_does_not_trigger_early_write() {
		$cpt     = \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
		$post_id = self::factory()->post->create(
			[
				'post_type'   => $cpt,
				'post_status' => 'draft',
			]
		);
		update_post_meta( $post_id, 'send_list_id', '14' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/' . $cpt . '/' . $post_id );
		$request->set_body_params( [ 'meta' => [ 'send_list_id' => '99' ] ] );
		$response = rest_do_request( $request );

		$this->assertGreaterThanOrEqual( 400, $response->get_status(), 'unauthorized update is rejected' );
		$this->assertSame( '14', get_post_meta( $post_id, 'send_list_id', true ), 'no early write for unauthorized caller' );
	}

	/**
	 * The early-write allowlist is the single source of truth and matches the
	 * send-config fields the ESP send path reads. A new send-config field must
	 * be added here AND wired into the send; this locks the set so the addition
	 * is a deliberate, reviewed edit.
	 */
	public function test_send_config_keys_constant_is_the_expected_set() {
		$this->assertSame(
			[ 'send_list_id', 'send_sublist_id', 'senderName', 'senderEmail' ],
			\Newspack_Newsletters::SEND_CONFIG_META_KEYS
		);
	}

	/**
	 * The orphaned immediate-persist endpoint must be gone — its purpose is now
	 * served correctly by persist_send_config_before_send().
	 */
	public function test_orphaned_api_set_post_meta_is_removed() {
		$this->assertFalse(
			method_exists( '\Newspack_Newsletters', 'api_set_post_meta' ),
			'api_set_post_meta was orphaned dead code and should be removed'
		);
	}

	/**
	 * A malformed non-scalar meta value (e.g. an array for a string send-config
	 * key) must NOT be written early. The scalar guard skips it, so no bogus value
	 * is persisted for the send to read, and the previously-stored scalar survives.
	 * Regression guard for the cast-and-persist edge surfaced in code review.
	 */
	public function test_non_scalar_meta_value_is_not_written_early() {
		$cpt     = \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
		$post_id = self::factory()->post->create(
			[
				'post_type'   => $cpt,
				'post_status' => 'draft',
			]
		);
		update_post_meta( $post_id, 'send_list_id', '14' );

		// The early write, if it happened, would land before save_post fires.
		$seen_at_save = null;
		add_action(
			'save_post_' . $cpt,
			function ( $pid ) use ( &$seen_at_save, $post_id ) {
				if ( (int) $pid === (int) $post_id ) {
					$seen_at_save = get_post_meta( $post_id, 'send_list_id', true );
				}
			},
			1,
			1
		);

		// Send an array where a scalar string is expected.
		$request = new WP_REST_Request( 'POST', '/wp/v2/' . $cpt . '/' . $post_id );
		$request->set_body_params( [ 'meta' => [ 'send_list_id' => [ 'a', 'b' ] ] ] );
		rest_do_request( $request );

		$this->assertSame(
			'14',
			$seen_at_save,
			'a non-scalar meta value must not be written early (no bogus value for the send to read)'
		);
		$this->assertSame(
			'14',
			get_post_meta( $post_id, 'send_list_id', true ),
			'the previously-stored scalar survives a malformed non-scalar update'
		);
	}
}
