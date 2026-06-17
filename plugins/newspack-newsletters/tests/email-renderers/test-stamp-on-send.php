<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Tests that a successful send stamps the producing engine on the newsletter.
 *
 * @package Newspack_Newsletters
 */

// The test-double provider and the test case share this file by design.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

use Newspack\Newsletters\Email_Renderers\Renderer_Controller;

/**
 * Minimal test-double provider whose send() outcome is configurable.
 *
 * Implements every ESP API and hookable method as a no-op stub so the test can
 * drive send_newsletter()'s success branch without any real ESP coupling. Only
 * send() carries behaviour: it returns whatever was injected via set_send_result().
 */
class Stamp_On_Send_Test_Provider extends Newspack_Newsletters_Service_Provider {
	/**
	 * Value send() returns. Defaults to true (successful send).
	 *
	 * @var true|WP_Error
	 */
	private $send_result = true;

	/**
	 * Construct the test-double, registering its service slug.
	 */
	public function __construct() {
		// Intentionally skip parent::__construct(): it registers global send/transition
		// hooks bound to $this, and set_up() builds a fresh provider per test, so those
		// callbacks would accumulate across the suite. These tests call send_newsletter()
		// directly and need only the service slug, not the registered hooks.
		$this->service = 'stamp_on_send_test';
	}

	/**
	 * Inject the result that send() should return.
	 *
	 * @param true|WP_Error $result Desired send() outcome.
	 * @return void
	 */
	public function set_send_result( $result ) {
		$this->send_result = $result;
	}

	/**
	 * Return the injected send result, driving the success or failure branch.
	 *
	 * @param WP_Post $post Newsletter post being sent.
	 * @return true|WP_Error
	 */
	public function send( $post ) {
		unset( $post );
		return $this->send_result;
	}

	/**
	 * No-op save hook stub.
	 *
	 * @param int    $meta_id  Meta ID.
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @return void
	 */
	public function save( $meta_id, $post_id, $meta_key ) {}

	/**
	 * No-op trash hook stub.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function trash( $post_id ) {}

	/**
	 * No-op credentials accessor stub.
	 *
	 * @return array
	 */
	public function api_credentials() {
		return [];
	}

	/**
	 * No-op credentials setter stub.
	 *
	 * @param object $credentials API credentials.
	 * @return void
	 */
	public function set_api_credentials( $credentials ) {}

	/**
	 * No-op credentials check stub.
	 *
	 * @return bool
	 */
	public function has_api_credentials() {
		return true;
	}

	/**
	 * No-op labels stub.
	 *
	 * @param mixed $context Label context.
	 * @return array
	 */
	public static function get_labels( $context = '' ) {
		return [];
	}

	/**
	 * No-op conditional tag support stub.
	 *
	 * @return array
	 */
	public static function get_conditional_tag_support() {
		return [];
	}

	/**
	 * No-op list assignment stub.
	 *
	 * @param string $post_id Post ID.
	 * @param string $list_id List ID.
	 * @return array
	 */
	public function list( $post_id, $list_id ) {
		return [];
	}

	/**
	 * No-op retrieve stub.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public function retrieve( $post_id ) {
		return [];
	}

	/**
	 * No-op test-email stub.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $emails  Recipient emails.
	 * @return array
	 */
	public function test( $post_id, $emails ) {
		return [];
	}

	/**
	 * No-op sync stub.
	 *
	 * @param WP_Post $post Post to sync.
	 * @return array
	 */
	public function sync( $post ) {
		return [];
	}

	/**
	 * No-op lists accessor stub.
	 *
	 * @return array
	 */
	public function get_lists() {
		return [];
	}

	/**
	 * No-op send-lists accessor stub.
	 *
	 * @param array $args     Search args.
	 * @param bool  $to_array Whether to return arrays.
	 * @return array
	 */
	public function get_send_lists( $args, $to_array = false ) {
		return [];
	}

	/**
	 * No-op add-contact stub.
	 *
	 * @param array  $contact Contact data.
	 * @param string $list_id List ID.
	 * @return array
	 */
	public function add_contact( $contact, $list_id = false ) {
		return [];
	}

	/**
	 * No-op contact-data accessor stub.
	 *
	 * @param string $email          Contact email.
	 * @param bool   $return_details Whether to return full details.
	 * @return array
	 */
	public function get_contact_data( $email, $return_details = false ) {
		return [];
	}

	/**
	 * No-op contact-lists accessor stub.
	 *
	 * @param string $email Contact email.
	 * @return array
	 */
	public function get_contact_lists( $email ) {
		return [];
	}

	/**
	 * No-op contact-lists update stub.
	 *
	 * @param string   $email           Contact email.
	 * @param string[] $lists_to_add    Lists to add.
	 * @param string[] $lists_to_remove Lists to remove.
	 * @return bool
	 */
	public function update_contact_lists( $email, $lists_to_add = [], $lists_to_remove = [] ) {
		return true;
	}

	/**
	 * No-op tag-id accessor stub.
	 *
	 * @param string $tag_name            Tag name.
	 * @param bool   $create_if_not_found Whether to create.
	 * @param string $list_id             List ID.
	 * @return int
	 */
	public function get_tag_id( $tag_name, $create_if_not_found = true, $list_id = null ) {
		return 0;
	}

	/**
	 * No-op tag-by-id accessor stub.
	 *
	 * @param int    $tag_id  Tag ID.
	 * @param string $list_id List ID.
	 * @return string
	 */
	public function get_tag_by_id( $tag_id, $list_id = null ) {
		return '';
	}

	/**
	 * No-op create-tag stub.
	 *
	 * @param string $tag     Tag name.
	 * @param string $list_id List ID.
	 * @return array
	 */
	public function create_tag( $tag, $list_id = null ) {
		return [];
	}

	/**
	 * No-op update-tag stub.
	 *
	 * @param string|int $tag_id  Tag ID.
	 * @param string     $tag     Tag name.
	 * @param string     $list_id List ID.
	 * @return array
	 */
	public function update_tag( $tag_id, $tag, $list_id = null ) {
		return [];
	}

	/**
	 * No-op add-tag-to-contact stub.
	 *
	 * @param string     $email   Contact email.
	 * @param string|int $tag     Tag ID.
	 * @param string     $list_id List ID.
	 * @return bool
	 */
	public function add_tag_to_contact( $email, $tag, $list_id = null ) {
		return true;
	}

	/**
	 * No-op remove-tag-from-contact stub.
	 *
	 * @param string     $email   Contact email.
	 * @param string|int $tag     Tag ID.
	 * @param string     $list_id List ID.
	 * @return bool
	 */
	public function remove_tag_from_contact( $email, $tag, $list_id = null ) {
		return true;
	}

	/**
	 * No-op contact-tags accessor stub.
	 *
	 * @param string $email Contact email.
	 * @return array
	 */
	public function get_contact_tags_ids( $email ) {
		return [];
	}

	/**
	 * No-op reader-error-message stub.
	 *
	 * @param array $params    Request params.
	 * @param mixed $raw_error Raw error.
	 * @return string
	 */
	public function get_reader_error_message( $params = [], $raw_error = null ) {
		return '';
	}

	/**
	 * No-op usage-report stub.
	 *
	 * @return array
	 */
	public function get_usage_report() {
		return [];
	}
}

/**
 * Asserts send_newsletter() stamps the active engine on a genuinely successful send.
 */
class Test_Stamp_On_Send extends WP_UnitTestCase {
	/**
	 * Shared test-double provider instance.
	 *
	 * @var Stamp_On_Send_Test_Provider
	 */
	private $provider;

	/**
	 * Test set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->provider = new Stamp_On_Send_Test_Provider();
	}

	/**
	 * Test tear down.
	 */
	public function tear_down() {
		remove_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );
		remove_filter( 'newspack_newsletters_use_woo_renderer', '__return_false' );
		delete_option( 'newspack_newsletters_use_woo_renderer' );
		parent::tear_down();
	}

	/**
	 * Create a draft newsletter and return its ID.
	 *
	 * @return int
	 */
	private function create_draft_newsletter() {
		return self::factory()->post->create(
			[
				'post_type'   => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status' => 'draft',
			]
		);
	}

	/**
	 * Flag ON plus a successful send stamps the WC engine.
	 */
	public function test_successful_send_stamps_wc_when_flag_on() {
		add_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );

		$post_id = $this->create_draft_newsletter();
		$result  = $this->provider->send_newsletter( get_post( $post_id ) );

		$this->assertTrue( $result, 'Harness must drive the success branch.' );
		$this->assertSame( Renderer_Controller::ENGINE_WC, Renderer_Controller::get_post_renderer( $post_id ) );
	}

	/**
	 * Flag OFF plus a successful send stamps the MJML engine.
	 */
	public function test_successful_send_stamps_mjml_when_flag_off() {
		// Force the flag off so the assertion is independent of option/filter state
		// leaked by other tests in the full suite — the filter wins last in Feature_Flag.
		add_filter( 'newspack_newsletters_use_woo_renderer', '__return_false' );

		$post_id = $this->create_draft_newsletter();
		$result  = $this->provider->send_newsletter( get_post( $post_id ) );

		$this->assertTrue( $result, 'Harness must drive the success branch.' );
		$this->assertSame( Renderer_Controller::ENGINE_MJML, Renderer_Controller::get_post_renderer( $post_id ) );
		$this->assertSame( Renderer_Controller::ENGINE_MJML, get_post_meta( $post_id, Renderer_Controller::RENDERER_META, true ) );
	}

	/**
	 * A failed send writes no stamp and leaves the resolver at its MJML default.
	 */
	public function test_failed_send_does_not_stamp() {
		add_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );

		$this->provider->set_send_result( new WP_Error( 'send_failed', 'Send failed.' ) );

		$post_id = $this->create_draft_newsletter();
		// Mark as a scheduled send so the base class suppresses the admin failure email
		// (an unrelated global side effect) on this non-final attempt.
		update_post_meta( $post_id, 'sending_scheduled', true );
		$result = $this->provider->send_newsletter( get_post( $post_id ) );

		$this->assertWPError( $result, 'Harness must drive the failure branch.' );
		// The send was attempted and failed — not skipped — so the post is not marked sent.
		$this->assertFalse( Newspack_Newsletters::is_newsletter_sent( $post_id ), 'A failed send must not mark the newsletter sent.' );
		// And with no stamp written, the resolver returns its intentional MJML default.
		$this->assertSame( '', get_post_meta( $post_id, Renderer_Controller::RENDERER_META, true ) );
		$this->assertSame( Renderer_Controller::ENGINE_MJML, Renderer_Controller::get_post_renderer( $post_id ) );
	}
}
