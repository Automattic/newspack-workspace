<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Tests for ActiveCampaign cleanup when a newsletter post is trashed.
 *
 * Background: `ac_message_id` and `ac_campaign_id` name two different
 * ActiveCampaign entities drawn from two different ID sequences. A message ID
 * handed to `campaign_delete` therefore destroys whatever unrelated campaign
 * happens to carry that number — including automation campaigns and sent ones,
 * which the deletable-status guard exists to protect. This spec pins trash
 * cleanup to the post's own campaign and nothing else.
 *
 * @package Newspack_Newsletters
 */

/**
 * Test ActiveCampaign trash cleanup.
 */
class ActiveCampaignTrashTest extends WP_UnitTestCase {

	/**
	 * Every ActiveCampaign action invoked through the mocked HTTP layer, in
	 * order, as `[ $action, $query_params ]` pairs.
	 *
	 * @var array[]
	 */
	private $calls = [];

	/**
	 * Per-action canned response bodies, keyed by v1 `api_action`. Anything not
	 * listed gets a bare success.
	 *
	 * @var array
	 */
	private $responses = [];

	/**
	 * Set up: configure credentials and intercept all outbound HTTP.
	 */
	public function set_up() {
		parent::set_up();
		$this->calls     = [];
		$this->responses = [];
		Newspack_Newsletters_Active_Campaign::instance()->set_api_credentials(
			[
				'url' => 'https://example.api-us1.com',
				'key' => 'test-key',
			]
		);
		add_filter( 'pre_http_request', [ $this, 'mock_http' ], 10, 3 );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', [ $this, 'mock_http' ], 10 );
		parent::tear_down();
	}

	/**
	 * Intercept outbound requests: record the action plus its query params and
	 * return a canned result.
	 *
	 * @param mixed  $preempt Short-circuit value.
	 * @param array  $args    HTTP request arguments.
	 * @param string $url     Request URL.
	 *
	 * @return array
	 */
	public function mock_http( $preempt, $args, $url ) {
		$query = [];
		$parts = wp_parse_url( $url, PHP_URL_QUERY );
		if ( $parts ) {
			parse_str( $parts, $query );
		}
		$action        = isset( $args['body']['api_action'] ) ? $args['body']['api_action'] : ( isset( $query['api_action'] ) ? $query['api_action'] : '' );
		$this->calls[] = [ $action, $query ];

		$body = isset( $this->responses[ $action ] ) ? $this->responses[ $action ] : [ 'result_code' => 1 ];
		return [
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'body'     => wp_json_encode( $body ),
		];
	}

	/**
	 * The v1 actions invoked, in order.
	 *
	 * @return string[]
	 */
	private function actions() {
		return array_column( $this->calls, 0 );
	}

	/**
	 * Create a newsletter post carrying the given ActiveCampaign meta.
	 *
	 * @param array $meta Meta key/value pairs to set on the post.
	 *
	 * @return int Post ID.
	 */
	private function make_newsletter( $meta = [] ) {
		$post_id = self::factory()->post->create(
			[ 'post_type' => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT ]
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		return $post_id;
	}

	/**
	 * The regression this file exists for: a newsletter that only ever reached
	 * the message stage has no campaign of its own, so trashing it must delete
	 * nothing in ActiveCampaign. Sending its message ID to `campaign_delete`
	 * would destroy a stranger's campaign that merely shares the number.
	 */
	public function test_trash_with_only_a_message_id_deletes_no_campaign() {
		$post_id = $this->make_newsletter( [ 'ac_message_id' => '19171' ] );

		wp_trash_post( $post_id );

		$this->assertNotContains(
			'campaign_delete',
			$this->actions(),
			'A message ID must never reach campaign_delete.'
		);
	}

	/**
	 * The message branch is gone entirely, so trash makes no message lookup
	 * either. Pinning this keeps a future "check the message first" guard from
	 * being mistaken for a safety net: `message_view` can only confirm that a
	 * message exists, never that a campaign of the same number is ours.
	 */
	public function test_trash_with_only_a_message_id_makes_no_requests() {
		$post_id = $this->make_newsletter( [ 'ac_message_id' => '19171' ] );

		wp_trash_post( $post_id );

		$this->assertSame( [], $this->actions(), 'Trash must not call ActiveCampaign at all when the post has no campaign.' );
	}

	/**
	 * The cleanup that should still happen: a post with its own campaign gets
	 * that campaign, and only that campaign, deleted. The stored ID is cleared
	 * once the campaign is really gone, so nothing later reuses a dead pointer.
	 */
	public function test_trash_deletes_the_posts_own_campaign() {
		$this->responses['campaign_list'] = [
			'result_code' => 1,
			[ 'status' => '0' ],
		];
		$post_id = $this->make_newsletter(
			[
				'ac_campaign_id' => '4242',
				'ac_message_id'  => '19171',
			]
		);

		wp_trash_post( $post_id );

		$this->assertContains( 'campaign_delete', $this->actions(), 'The post\'s own campaign should be deleted.' );
		$deleted = [];
		foreach ( $this->calls as $call ) {
			if ( 'campaign_delete' === $call[0] ) {
				$deleted[] = $call[1]['id'];
			}
		}
		$this->assertSame( [ '4242' ], $deleted, 'Only the campaign ID from ac_campaign_id may be deleted.' );
		$this->assertSame( '', get_post_meta( $post_id, 'ac_campaign_id', true ), 'The stored campaign ID should be cleared once the campaign is gone.' );
	}

	/**
	 * A campaign that has already gone out stays put: the deletable-status guard
	 * in delete_campaign() still applies to the campaign branch. The stored ID
	 * stays too, because the campaign it names is still there.
	 */
	public function test_trash_leaves_a_sent_campaign_alone() {
		$this->responses['campaign_list'] = [
			'result_code' => 1,
			[ 'status' => '5' ], // Completed.
		];
		$post_id = $this->make_newsletter( [ 'ac_campaign_id' => '4242' ] );

		wp_trash_post( $post_id );

		$this->assertContains( 'campaign_list', $this->actions(), 'The status guard should be consulted.' );
		$this->assertNotContains( 'campaign_delete', $this->actions(), 'A completed campaign must not be deleted.' );
		$this->assertSame( '4242', get_post_meta( $post_id, 'ac_campaign_id', true ), 'A campaign that survives keeps its stored ID.' );
	}

	/**
	 * Test-send campaigns are disposable and created by us, so they are still
	 * cleaned up unconditionally.
	 */
	public function test_trash_still_cleans_up_test_campaigns() {
		$post_id = $this->make_newsletter( [ 'ac_message_id' => '19171' ] );
		add_post_meta( $post_id, 'ac_test_campaign', '777' );

		wp_trash_post( $post_id );

		$deleted = [];
		foreach ( $this->calls as $call ) {
			if ( 'campaign_delete' === $call[0] ) {
				$deleted[] = $call[1]['id'];
			}
		}
		$this->assertSame( [ '777' ], $deleted, 'Only the test campaign should be deleted.' );
		$this->assertSame( [], get_post_meta( $post_id, 'ac_test_campaign', false ), 'Cleaned-up test campaign meta should be removed.' );
	}

	/**
	 * Posts of other types are none of this provider's business.
	 */
	public function test_trash_ignores_other_post_types() {
		$post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );
		update_post_meta( $post_id, 'ac_campaign_id', '4242' );

		wp_trash_post( $post_id );

		$this->assertSame( [], $this->actions(), 'Non-newsletter posts must not trigger ESP cleanup.' );
	}
}
