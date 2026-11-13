<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Tests for the ActiveCampaign campaign archive visibility setting.
 *
 * ActiveCampaign only lists public campaigns in its campaign archive, and a
 * campaign's visibility can't change once it is sent. These tests pin how the
 * site-wide default, the per-newsletter choice and the newsletter's own
 * visibility combine.
 *
 * @package Newspack_Newsletters
 */

/**
 * Test ActiveCampaign public campaigns.
 */
class ActiveCampaignPublicCampaignTest extends WP_UnitTestCase {

	/**
	 * Body of the last `campaign_create` request.
	 *
	 * @var array|null
	 */
	private $campaign_create_body = null;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->campaign_create_body = null;
		delete_option( Newspack_Newsletters_Active_Campaign::ARCHIVE_VISIBILITY_OPTION );
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
		delete_option( Newspack_Newsletters_Active_Campaign::ARCHIVE_VISIBILITY_OPTION );
		parent::tear_down();
	}

	/**
	 * Return canned responses for the calls campaign creation makes.
	 *
	 * @param mixed  $preempt Short-circuit value.
	 * @param array  $args    HTTP request arguments.
	 * @param string $url     Request URL.
	 *
	 * @return array
	 */
	public function mock_http( $preempt, $args, $url ) {
		$action = '';
		if ( isset( $args['body']['api_action'] ) ) {
			$action = $args['body']['api_action'];
		} elseif ( preg_match( '/api_action=([a-z_]+)/', $url, $matches ) ) {
			$action = $matches[1];
		} elseif ( preg_match( '#/api/3/([a-z_]+)#', $url, $matches ) ) {
			$action = 'v3:' . $matches[1];
		}

		$responses = [
			'list_list'       => [
				'result_code' => 1,
				[
					'id'               => 5,
					'name'             => 'Main',
					'subscriber_count' => 10,
				],
			],
			'v3:audiences'    => [
				'data' => [],
				'meta' => [ 'page' => [ 'total' => 0 ] ],
			],
			'message_add'     => [
				'result_code' => 1,
				'id'          => 555,
			],
			'message_view'    => [
				'result_code' => 1,
				'id'          => 555,
				'html'        => '<p>Hello</p>',
			],
			'campaign_create' => [
				'result_code' => 1,
				'id'          => 777,
			],
		];

		if ( 'campaign_create' === $action ) {
			$this->campaign_create_body = $args['body'];
		}

		return [
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'body'     => wp_json_encode( $responses[ $action ] ?? [ 'result_code' => 1 ] ),
		];
	}

	/**
	 * Create a newsletter ready to have a campaign created.
	 *
	 * @return int Post ID.
	 */
	private function create_newsletter() {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_title'  => 'Archive Newsletter',
				'post_status' => 'draft',
			]
		);
		update_post_meta( $post_id, 'senderName', 'Sender' );
		update_post_meta( $post_id, 'senderEmail', 'sender@example.com' );
		update_post_meta( $post_id, 'send_list_id', '5' );
		update_post_meta( $post_id, Newspack_Newsletters::EMAIL_HTML_META, '<p>Hello</p>' );
		return $post_id;
	}

	/**
	 * Create the ActiveCampaign campaign for a newsletter and return the
	 * `public` value sent to ActiveCampaign.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return int
	 */
	private function create_campaign_public_flag( $post_id ) {
		$create_campaign_method = new ReflectionMethod( Newspack_Newsletters_Active_Campaign::class, 'create_campaign' );
		$create_campaign_method->setAccessible( true );
		$result = $create_campaign_method->invoke( Newspack_Newsletters_Active_Campaign::instance(), get_post( $post_id ) );
		$this->assertNotWPError( $result );
		$this->assertNotNull( $this->campaign_create_body, 'A campaign must be created.' );
		return $this->campaign_create_body['public'];
	}

	/**
	 * Each combination of site default, newsletter visibility and newsletter
	 * choice, with the campaign visibility it must produce.
	 *
	 * @return array
	 */
	public function archive_visibility_cases() {
		return [
			// An unset site default follows the newsletter's visibility.
			'no setting, email only'           => [ null, false, '', 0 ],
			'no setting, email and web'        => [ null, true, '', 1 ],
			'inherit, email only'              => [ 'inherit', false, '', 0 ],
			'inherit, email and web'           => [ 'inherit', true, '', 1 ],
			'always public, email only'        => [ 'public', false, '', 1 ],
			'always private, email and web'    => [ 'private', true, '', 0 ],
			'unknown setting falls to inherit' => [ 'everyone', false, '', 0 ],
			// A newsletter's own choice wins over the default, both ways.
			'opt in, email only'               => [ 'inherit', false, 'public', 1 ],
			'opt out, email and web'           => [ 'inherit', true, 'private', 0 ],
			'opt in over always private'       => [ 'private', false, 'public', 1 ],
			'opt out over always public'       => [ 'public', true, 'private', 0 ],
		];
	}

	/**
	 * The campaign's visibility follows the newsletter's choice, else the site
	 * default, whose "inherit" value follows the newsletter's visibility.
	 *
	 * @dataProvider archive_visibility_cases
	 *
	 * @param string|null $site_default    Site-wide option value, or null for unset.
	 * @param bool        $is_public_on_web Whether the newsletter is "Email and web".
	 * @param string      $choice          The newsletter's own choice.
	 * @param int         $expected_public The `public` value sent to ActiveCampaign.
	 */
	public function test_campaign_visibility( $site_default, $is_public_on_web, $choice, $expected_public ) {
		if ( null !== $site_default ) {
			update_option( Newspack_Newsletters_Active_Campaign::ARCHIVE_VISIBILITY_OPTION, $site_default );
		}
		$post_id = $this->create_newsletter();
		update_post_meta( $post_id, 'is_public', $is_public_on_web );
		update_post_meta( $post_id, Newspack_Newsletters_Active_Campaign::ARCHIVE_VISIBILITY_META, $choice );

		$this->assertSame( (bool) $expected_public, Newspack_Newsletters_Active_Campaign::is_public_campaign( $post_id ) );
		$this->assertSame( $expected_public, $this->create_campaign_public_flag( $post_id ) );
	}

	/**
	 * The provider, and with it the controller, is built during `init` at the
	 * default priority. A callback added at that same priority while it runs is
	 * skipped, so the meta would never be registered and the editor's archive
	 * choice would save nothing.
	 */
	public function test_meta_is_registered_after_the_provider_is_built() {
		Newspack_Newsletters_Active_Campaign::instance();
		$priority = has_action( 'init', [ Newspack_Newsletters_Active_Campaign_Controller::class, 'register_meta' ] );
		$this->assertIsInt( $priority );
		$this->assertGreaterThan( 10, $priority );
	}

	/**
	 * The editor saves the choice through the REST API, which only writes
	 * registered meta.
	 */
	public function test_choice_saved_through_rest_api_applies() {
		Newspack_Newsletters_Active_Campaign_Controller::register_meta();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$post_id = $this->create_newsletter();

		$request = new WP_REST_Request( 'POST', '/wp/v2/' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT . '/' . $post_id );
		$request->set_body_params( [ 'meta' => [ Newspack_Newsletters_Active_Campaign::ARCHIVE_VISIBILITY_META => 'public' ] ] );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );

		$this->assertTrue( Newspack_Newsletters_Active_Campaign::is_public_campaign( $post_id ) );
	}
}
