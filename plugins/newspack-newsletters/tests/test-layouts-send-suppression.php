<?php
/**
 * Class Test Layouts Send Suppression
 *
 * @package Newspack_Newsletters
 */

/**
 * Asserts the layouts CPT can never trigger an ESP campaign send.
 * Strategy: unconfigured Mailchimp throws on send. The newsletter
 * scenario must throw; the layout scenario must not.
 */
class Layouts_Send_Suppression_Test extends WP_UnitTestCase {
	/**
	 * Pre-test snapshot of the active service-provider slug.
	 *
	 * @var string|false
	 */
	private $previous_provider_slug = false;

	/**
	 * Sentinel for `get_option` so tear_down can tell "unset" from "empty".
	 */
	private const ABSENT = '__absent__';

	/**
	 * Pre-test snapshot of the Mailchimp API key.
	 *
	 * @var string The option's current value, or `self::ABSENT` if unset.
	 */
	private $previous_mailchimp_api_key = self::ABSENT;

	/**
	 * Pre-test snapshot of the current user id.
	 *
	 * @var int
	 */
	private $previous_user_id = 0;

	/**
	 * Whether the layouts CPT was registered before set_up ran.
	 *
	 * @var bool
	 */
	private $layouts_cpt_was_registered = false;

	/**
	 * Test set up.
	 */
	public function set_up() {
		parent::set_up();

		$this->previous_provider_slug     = \Newspack_Newsletters::service_provider();
		$this->previous_mailchimp_api_key = get_option( 'newspack_mailchimp_api_key', self::ABSENT );
		$this->previous_user_id           = get_current_user_id();
		$this->layouts_cpt_was_registered = post_type_exists( \Newspack_Newsletters_Layouts::NEWSPACK_NEWSLETTERS_LAYOUT_CPT );

		\Newspack_Newsletters::set_service_provider( 'mailchimp' );
		delete_option( 'newspack_mailchimp_api_key' );

		// CPT registration is gated on `edit_others_posts`; re-register
		// under an admin so the factory can create posts of this type.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		\Newspack_Newsletters_Layouts::register_layout_cpt();

		add_filter( 'wp_die_handler', [ $this, 'route_wp_die_to_test_handler' ] );
	}

	/**
	 * Test tear down.
	 */
	public function tear_down() {
		remove_filter( 'wp_die_handler', [ $this, 'route_wp_die_to_test_handler' ] );

		wp_set_current_user( $this->previous_user_id );

		if ( false !== $this->previous_provider_slug ) {
			\Newspack_Newsletters::set_service_provider( $this->previous_provider_slug );
		} else {
			delete_option( 'newspack_newsletters_service_provider' );
			\Newspack_Newsletters::memoize_service_provider();
		}

		if ( self::ABSENT === $this->previous_mailchimp_api_key ) {
			delete_option( 'newspack_mailchimp_api_key' );
		} else {
			update_option( 'newspack_mailchimp_api_key', $this->previous_mailchimp_api_key );
		}

		if ( ! $this->layouts_cpt_was_registered && post_type_exists( \Newspack_Newsletters_Layouts::NEWSPACK_NEWSLETTERS_LAYOUT_CPT ) ) {
			unregister_post_type( \Newspack_Newsletters_Layouts::NEWSPACK_NEWSLETTERS_LAYOUT_CPT );
		}

		parent::tear_down();
	}

	/**
	 * Routes wp_die through the bootstrap-installed test handler so the
	 * call surfaces as a catchable WPDieException.
	 *
	 * @param callable|null $default_handler Unused; accepted to satisfy the filter signature.
	 * @return string
	 */
	public function route_wp_die_to_test_handler( $default_handler = null ) {
		unset( $default_handler );
		return 'handle_wpdie_in_tests';
	}

	/**
	 * Sanity check: the parallel newsletter scenario throws. Without this,
	 * the layout assertion below could silently pass for the wrong reason.
	 */
	public function test_publishing_unconfigured_newsletter_dispatches_send() {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status' => 'draft',
			]
		);

		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'Missing or invalid Mailchimp credentials.' );

		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'publish',
			]
		);
	}

	/**
	 * Publishing a layout must never invoke the provider send pipeline.
	 * Covers both the pre_post_update and transition_post_status paths.
	 */
	public function test_publishing_layout_does_not_dispatch_send() {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => \Newspack_Newsletters_Layouts::NEWSPACK_NEWSLETTERS_LAYOUT_CPT,
				'post_status' => 'draft',
			]
		);

		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'publish',
			]
		);

		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/**
	 * Direct invocation: even if a future caller bypasses both upstream
	 * gates, send_newsletter itself must short-circuit for layouts.
	 */
	public function test_send_newsletter_short_circuits_for_layouts() {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => \Newspack_Newsletters_Layouts::NEWSPACK_NEWSLETTERS_LAYOUT_CPT,
				'post_status' => 'draft',
			]
		);

		$provider = \Newspack_Newsletters::get_service_provider();
		$result   = $provider->send_newsletter( get_post( $post_id ) );

		$this->assertNull( $result );
	}
}
