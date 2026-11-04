<?php
/**
 * Tests for the Republish button in Jetpack's sharing buttons.
 *
 * @package Republication_Tracker_Tool
 */

/**
 * Jetpack sharing integration tests.
 */
class JetpackSharingTest extends WP_UnitTestCase {

	/**
	 * Post shown on the page.
	 *
	 * @var WP_Post
	 */
	private $test_post;

	/**
	 * Load the Sharing_Source stand-in, since Jetpack is not loaded in tests.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once __DIR__ . '/mocks/class-sharing-source-mock.php';
	}

	/**
	 * Set up a published post and register the service class.
	 */
	public function set_up() {
		parent::set_up();
		$this->test_post = self::factory()->post->create_and_get(
			[
				'post_title'   => 'Jetpack Sharing Test Post',
				'post_content' => '<p>Shareable content.</p>',
				'post_status'  => 'publish',
			]
		);
		Republication_Tracker_Tool_Jetpack_Sharing::register_service( [] );
	}

	/**
	 * Reset the page-level state the integration keeps.
	 */
	public function tear_down() {
		Republication_Tracker_Tool::$modal_rendered = false;
		$post_id = new ReflectionProperty( 'Republication_Tracker_Tool_Jetpack_Sharing', 'post_id' );
		$post_id->setAccessible( true );
		$post_id->setValue( null, 0 );
		remove_action( 'wp_footer', [ 'Republication_Tracker_Tool_Jetpack_Sharing', 'print_modal' ] );
		parent::tear_down();
	}

	/**
	 * Put the test post on its own page.
	 */
	private function view_test_post() {
		$this->go_to( get_permalink( $this->test_post ) );
	}

	/**
	 * Build the sharing source as Jetpack would.
	 *
	 * @param string $button_style Jetpack button style.
	 * @return Republication_Tracker_Tool_Jetpack_Share_Republish
	 */
	private function make_source( $button_style = 'icon-text' ) {
		return new Republication_Tracker_Tool_Jetpack_Share_Republish( Republication_Tracker_Tool_Jetpack_Sharing::SERVICE, [ 'button_style' => $button_style ] );
	}

	/**
	 * Republish is offered to Jetpack as a sharing service.
	 */
	public function test_service_is_added_to_jetpack_services() {
		$services = Republication_Tracker_Tool_Jetpack_Sharing::register_service( [ 'print' => 'Share_Print' ] );

		$this->assertSame( 'Republication_Tracker_Tool_Jetpack_Share_Republish', $services['republish'] );
		$this->assertSame( 'Share_Print', $services['print'] );
	}

	/**
	 * On the post's page, the button opens the modal in place, with an icon.
	 */
	public function test_button_opens_modal_on_post_page() {
		$this->view_test_post();

		$markup = $this->make_source()->get_display( $this->test_post );

		$this->assertStringContainsString( 'data-modal-trigger="republish"', $markup );
		$this->assertStringContainsString( get_permalink( $this->test_post ) . '#show-republish', $markup );
		$this->assertStringNotContainsString( 'target="_blank"', $markup );
		$this->assertMatchesRegularExpression( '/<a\b[^>]*><svg\b/', $markup );
		$this->assertNotFalse( has_action( 'wp_footer', [ 'Republication_Tracker_Tool_Jetpack_Sharing', 'print_modal' ] ) );
	}

	/**
	 * The text-only style gets no icon.
	 */
	public function test_text_style_has_no_icon() {
		$this->view_test_post();

		$markup = $this->make_source( 'text' )->get_display( $this->test_post );

		$this->assertStringContainsString( 'data-modal-trigger="republish"', $markup );
		$this->assertStringNotContainsString( '<svg', $markup );
	}

	/**
	 * Archives list many posts but the modal holds one, so no button there.
	 */
	public function test_no_button_on_archive() {
		$this->go_to( home_url( '/' ) );

		$this->assertSame( '', $this->make_source()->get_display( $this->test_post ) );
	}

	/**
	 * A different post's share buttons on the same page get no button.
	 */
	public function test_no_button_for_other_post_on_page() {
		$other_post = self::factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->view_test_post();

		$this->assertSame( '', $this->make_source()->get_display( $other_post ) );
	}

	/**
	 * A post hidden from republication gets no button.
	 */
	public function test_no_button_when_post_hidden_from_republication() {
		update_post_meta( $this->test_post->ID, 'republication-tracker-tool-hide-widget', true );
		$this->view_test_post();

		$this->assertSame( '', $this->make_source()->get_display( $this->test_post ) );
	}

	/**
	 * The footer prints the modal for the post when nothing else has.
	 */
	public function test_footer_prints_modal_once() {
		$this->view_test_post();
		$this->make_source()->get_display( $this->test_post );

		ob_start();
		Republication_Tracker_Tool_Jetpack_Sharing::print_modal();
		Republication_Tracker_Tool_Jetpack_Sharing::print_modal();
		$footer = ob_get_clean();

		$this->assertSame( 1, substr_count( $footer, 'id="republication-tracker-tool-modal"' ) );
		$this->assertStringContainsString( 'Jetpack Sharing Test Post', $footer );
	}

	/**
	 * The footer leaves the modal to the widget or block that already printed it.
	 */
	public function test_footer_skips_modal_already_rendered() {
		$this->view_test_post();
		$this->make_source()->get_display( $this->test_post );
		Republication_Tracker_Tool::$modal_rendered = true;

		ob_start();
		Republication_Tracker_Tool_Jetpack_Sharing::print_modal();
		$this->assertSame( '', ob_get_clean() );
	}
}
