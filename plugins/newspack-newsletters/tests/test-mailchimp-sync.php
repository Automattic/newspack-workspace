<?php
/**
 * Tests for Newspack_Newsletters_Mailchimp::sync() — PATCH ordering and rollback.
 *
 * Covers the two-step PATCH workaround for Mailchimp's advanced-segment
 * snapshot behavior (NPPM-2879):
 *  - tag-swap on an existing campaign issues a reset PATCH (segment_opts: {})
 *    followed by the main PATCH;
 *  - whole-audience sends issue a single main PATCH (no reset);
 *  - first-time campaign creation goes through POST without any PATCH;
 *  - a main PATCH failure triggers a rollback PATCH to the previously-captured
 *    recipients;
 *  - a reset PATCH failure is non-fatal — the main PATCH still runs, and no
 *    rollback is attempted (the reset never landed, so prior state is intact).
 *
 * @package Newspack_Newsletters
 */

/**
 * Test the two-step Mailchimp campaign PATCH (reset → main) and the
 * rollback path triggered when the main PATCH throws.
 */
class MailchimpSyncTest extends WP_UnitTestCase {

	/**
	 * Stable test campaign id used in mock responses.
	 *
	 * @var string
	 */
	const FAKE_CAMPAIGN_ID = 'fake-mc-campaign-id';

	/**
	 * Stable test list (audience) id used in mock responses.
	 *
	 * @var string
	 */
	const FAKE_LIST_ID = 'fake-audience-id';

	/**
	 * Captured PATCH calls in order, each as `[ 'endpoint' => string, 'args' => array ]`.
	 *
	 * @var array
	 */
	private $patches = [];

	/**
	 * Captured POST calls in order.
	 *
	 * @var array
	 */
	private $posts = [];

	/**
	 * Per-test setup: configure Mailchimp as the active provider and seed an API key
	 * so the mock's `is_api_configured()` returns true.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'newspack_mailchimp_api_key', 'fake-key-us1' );
		Newspack_Newsletters::set_service_provider( 'mailchimp' );
		$this->patches = [];
		$this->posts   = [];
	}

	/**
	 * Per-test teardown: scrub filters and the API key option.
	 */
	public function tear_down() {
		delete_option( 'newspack_mailchimp_api_key' );
		remove_all_filters( 'mailchimp_mock_get' );
		remove_all_filters( 'mailchimp_mock_post' );
		remove_all_filters( 'mailchimp_mock_put' );
		remove_all_filters( 'mailchimp_mock_patch' );
		remove_all_filters( 'newspack_newsletters_mc_payload_sync' );
		parent::tear_down();
	}

	/**
	 * Create a draft newsletter post and return the WP_Post.
	 *
	 * @return WP_Post
	 */
	private function make_newsletter_post() {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_title'  => 'Test newsletter',
				'post_status' => 'draft',
			]
		);
		return get_post( $post_id );
	}

	/**
	 * Hook the mock PATCH filter to record every call and return a success response.
	 */
	private function capture_patches() {
		add_filter(
			'mailchimp_mock_patch',
			function ( $response, $endpoint, $args ) {
				$this->patches[] = [
					'endpoint' => $endpoint,
					'args'     => $args,
				];
				return [
					'id'     => self::FAKE_CAMPAIGN_ID,
					'status' => 'save',
				];
			},
			10,
			3
		);
	}

	/**
	 * Hook the mock PUT filter to stub the campaign content upload.
	 */
	private function stub_content_put() {
		add_filter(
			'mailchimp_mock_put',
			function ( $response ) {
				return [ 'success' => true ];
			}
		);
	}

	/**
	 * Hook the mock GET filter to return a synthetic existing campaign
	 * (used by the rollback-state capture in sync()).
	 *
	 * @param array $prior_segment_opts The segment_opts to return as the campaign's existing state.
	 */
	private function stub_existing_campaign( $prior_segment_opts ) {
		add_filter(
			'mailchimp_mock_get',
			function ( $response, $endpoint, $args ) use ( $prior_segment_opts ) {
				if ( 'campaigns/' . self::FAKE_CAMPAIGN_ID === $endpoint ) {
					return [
						'id'         => self::FAKE_CAMPAIGN_ID,
						'recipients' => [
							'list_id'      => self::FAKE_LIST_ID,
							'segment_opts' => $prior_segment_opts,
						],
					];
				}
				return $response;
			},
			10,
			3
		);
	}

	/**
	 * Inject a populated `segment_opts` payload via the sync filter. This
	 * bypasses `get_sync_payload()`'s sublist resolution (which would
	 * otherwise require a populated Mailchimp cached-data layer) while
	 * leaving the rest of sync() exercised.
	 *
	 * @param string $value Static-segment value to encode in the condition.
	 */
	private function inject_segment_conditions( $value ) {
		add_filter(
			'newspack_newsletters_mc_payload_sync',
			function ( $payload ) use ( $value ) {
				$payload['recipients']['segment_opts'] = [
					'match'      => 'all',
					'conditions' => [
						[
							'condition_type' => 'StaticSegment',
							'field'          => 'static_segment',
							'op'             => 'static_is',
							'value'          => $value,
						],
					],
				];
				return $payload;
			}
		);
	}

	/**
	 * Convenience helper to run sync() against the active Mailchimp provider.
	 *
	 * @param WP_Post $post Newsletter post.
	 *
	 * @return mixed Result of sync() (array on success, WP_Error on failure).
	 */
	private function sync_post( $post ) {
		$provider = Newspack_Newsletters::get_service_provider_instance( 'mailchimp' );
		return $provider->sync( $post );
	}

	/**
	 * Existing campaign + populated segment_opts: should PATCH segment_opts: {}
	 * first, then PATCH the new conditions. This is the core fix for NPPM-2879.
	 */
	public function test_segment_swap_resets_then_patches_main() {
		$post = $this->make_newsletter_post();
		update_post_meta( $post->ID, 'mc_campaign_id', self::FAKE_CAMPAIGN_ID );
		update_post_meta( $post->ID, 'send_list_id', self::FAKE_LIST_ID );

		$this->stub_existing_campaign(
			[
				'match'      => 'all',
				'conditions' => [
					[
						'condition_type' => 'StaticSegment',
						'field'          => 'static_segment',
						'op'             => 'static_is',
						'value'          => 'old-tag',
					],
				],
			]
		);
		$this->capture_patches();
		$this->stub_content_put();
		$this->inject_segment_conditions( 'new-tag' );

		$this->sync_post( $post );

		$this->assertCount( 2, $this->patches, 'Expected exactly two PATCH calls: reset, then main.' );

		// First PATCH should clear segment_opts.
		$this->assertSame( 'campaigns/' . self::FAKE_CAMPAIGN_ID, $this->patches[0]['endpoint'] );
		$reset_segment_opts = $this->patches[0]['args']['recipients']['segment_opts'] ?? null;
		$this->assertEquals( (object) [], $reset_segment_opts, 'First PATCH must clear segment_opts.' );

		// Second PATCH should carry the new conditions.
		$this->assertSame( 'campaigns/' . self::FAKE_CAMPAIGN_ID, $this->patches[1]['endpoint'] );
		$main_conditions = $this->patches[1]['args']['recipients']['segment_opts']['conditions'] ?? [];
		$this->assertNotEmpty( $main_conditions );
		$this->assertSame( 'new-tag', $main_conditions[0]['value'] );
	}

	/**
	 * Whole-audience send (send_list_id but no send_sublist_id): should issue
	 * exactly one PATCH with empty segment_opts (no spurious reset).
	 */
	public function test_whole_audience_send_skips_reset() {
		$post = $this->make_newsletter_post();
		update_post_meta( $post->ID, 'mc_campaign_id', self::FAKE_CAMPAIGN_ID );
		update_post_meta( $post->ID, 'send_list_id', self::FAKE_LIST_ID );

		$this->capture_patches();
		$this->stub_content_put();

		$this->sync_post( $post );

		$this->assertCount( 1, $this->patches, 'Whole-audience sync should issue exactly one PATCH.' );
		$segment_opts = $this->patches[0]['args']['recipients']['segment_opts'] ?? null;
		$this->assertEquals( (object) [], $segment_opts, 'Whole-audience PATCH should send empty segment_opts.' );
	}

	/**
	 * First-time sync (no mc_campaign_id yet): should POST a new campaign and
	 * not issue any PATCH at all — there is no pre-existing campaign state to
	 * reset against.
	 */
	public function test_new_campaign_only_posts() {
		$post = $this->make_newsletter_post();
		update_post_meta( $post->ID, 'send_list_id', self::FAKE_LIST_ID );

		add_filter(
			'mailchimp_mock_post',
			function ( $response, $endpoint, $args ) {
				$this->posts[] = [
					'endpoint' => $endpoint,
					'args'     => $args,
				];
				if ( 'campaigns' === $endpoint ) {
					return [
						'id'     => 'newly-created-id',
						'status' => 'save',
					];
				}
				return $response;
			},
			10,
			3
		);
		$this->capture_patches();
		$this->stub_content_put();
		$this->inject_segment_conditions( 'tag-a' );

		$this->sync_post( $post );

		$campaigns_posts = array_values(
			array_filter(
				$this->posts,
				function ( $p ) {
					return 'campaigns' === $p['endpoint'];
				}
			)
		);
		$this->assertCount( 1, $campaigns_posts, 'Expected exactly one POST to /campaigns.' );
		$this->assertCount( 0, $this->patches, 'New-campaign path must not issue any PATCH.' );
	}

	/**
	 * Main PATCH failure with populated prior recipients: sync should issue
	 * the reset PATCH, attempt the main PATCH (which fails), then issue a
	 * rollback PATCH restoring the captured prior recipients — and propagate
	 * the original failure as a WP_Error.
	 */
	public function test_main_patch_failure_rolls_back_to_prior_recipients() {
		$post = $this->make_newsletter_post();
		update_post_meta( $post->ID, 'mc_campaign_id', self::FAKE_CAMPAIGN_ID );
		update_post_meta( $post->ID, 'send_list_id', self::FAKE_LIST_ID );

		$prior_segment_opts = [
			'match'      => 'all',
			'conditions' => [
				[
					'condition_type' => 'StaticSegment',
					'field'          => 'static_segment',
					'op'             => 'static_is',
					'value'          => 'old-tag',
				],
			],
		];
		$this->stub_existing_campaign( $prior_segment_opts );
		$this->stub_content_put();
		$this->inject_segment_conditions( 'new-tag' );

		// Reset PATCH (#1) succeeds; main PATCH (#2) fails; rollback PATCH (#3) succeeds.
		add_filter(
			'mailchimp_mock_patch',
			function ( $response, $endpoint, $args ) {
				$this->patches[] = [
					'endpoint' => $endpoint,
					'args'     => $args,
				];
				$call_index = count( $this->patches );
				if ( 2 === $call_index ) {
					return [
						'status' => 500,
						'title'  => 'Internal Server Error',
						'detail' => 'Test-induced main PATCH failure',
					];
				}
				return [
					'id'     => self::FAKE_CAMPAIGN_ID,
					'status' => 'save',
				];
			},
			10,
			3
		);

		$result = $this->sync_post( $post );

		$this->assertInstanceOf( WP_Error::class, $result, 'sync() should surface the main-PATCH error as WP_Error.' );

		$this->assertCount( 3, $this->patches, 'Expected reset PATCH, main PATCH, rollback PATCH.' );

		// First PATCH — reset.
		$this->assertEquals( (object) [], $this->patches[0]['args']['recipients']['segment_opts'], 'Call #1 must clear segment_opts.' );

		// Third PATCH — rollback to the prior recipients.
		$rolled_back = $this->patches[2]['args']['recipients']['segment_opts'] ?? null;
		$this->assertNotEmpty( $rolled_back, 'Rollback PATCH must carry a non-empty segment_opts.' );
		$this->assertSame(
			'old-tag',
			$rolled_back['conditions'][0]['value'] ?? null,
			'Rollback PATCH must restore the prior static-segment value.'
		);
		$this->assertSame(
			self::FAKE_LIST_ID,
			$this->patches[2]['args']['recipients']['list_id'] ?? null,
			'Rollback PATCH must preserve the prior list_id.'
		);
	}

	/**
	 * Reset PATCH failure: sync should swallow the reset error, still run the
	 * main PATCH, and NOT issue a rollback (the reset never landed, so prior
	 * state is intact on Mailchimp's side).
	 */
	public function test_reset_failure_proceeds_with_main_patch() {
		$post = $this->make_newsletter_post();
		update_post_meta( $post->ID, 'mc_campaign_id', self::FAKE_CAMPAIGN_ID );
		update_post_meta( $post->ID, 'send_list_id', self::FAKE_LIST_ID );

		$this->stub_existing_campaign(
			[
				'match'      => 'all',
				'conditions' => [
					[
						'condition_type' => 'StaticSegment',
						'field'          => 'static_segment',
						'op'             => 'static_is',
						'value'          => 'old-tag',
					],
				],
			]
		);
		$this->stub_content_put();
		$this->inject_segment_conditions( 'new-tag' );

		// Reset PATCH (#1) fails; main PATCH (#2) succeeds; no further PATCH expected.
		add_filter(
			'mailchimp_mock_patch',
			function ( $response, $endpoint, $args ) {
				$this->patches[] = [
					'endpoint' => $endpoint,
					'args'     => $args,
				];
				$call_index = count( $this->patches );
				if ( 1 === $call_index ) {
					return [
						'status' => 422,
						'title'  => 'Unprocessable Entity',
						'detail' => 'Test-induced reset failure',
					];
				}
				return [
					'id'     => self::FAKE_CAMPAIGN_ID,
					'status' => 'save',
				];
			},
			10,
			3
		);

		$result = $this->sync_post( $post );

		$this->assertIsArray( $result, 'sync() should succeed when only the reset PATCH fails.' );
		$this->assertCount( 2, $this->patches, 'Reset attempted + main attempted, with no rollback.' );

		$main_value = $this->patches[1]['args']['recipients']['segment_opts']['conditions'][0]['value'] ?? null;
		$this->assertSame( 'new-tag', $main_value, 'Main PATCH must carry the new segment value.' );
	}
}
