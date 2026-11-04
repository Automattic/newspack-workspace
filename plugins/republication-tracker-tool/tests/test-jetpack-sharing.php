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
	 * Block types this test registered.
	 *
	 * @var string[]
	 */
	private $registered_blocks = [];

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
		foreach ( $this->registered_blocks as $name ) {
			unregister_block_type( $name );
		}
		$this->registered_blocks = [];
		( new ReflectionProperty( 'Republication_Tracker_Tool_Jetpack_Sharing', 'post' ) )->setValue( null, null );
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
	 * The button's styles load in the head on a post's own page, and not where
	 * the button can't show.
	 */
	public function test_styles_load_in_head_only_where_button_shows() {
		wp_dequeue_style( 'republication-tracker-tool-jetpack-sharing' );
		$this->go_to( home_url( '/' ) );
		$this->make_source()->display_header();
		$this->assertFalse( wp_style_is( 'republication-tracker-tool-jetpack-sharing', 'enqueued' ) );

		update_post_meta( $this->test_post->ID, 'republication-tracker-tool-hide-widget', true );
		$this->view_test_post();
		$this->make_source()->display_header();
		$this->assertFalse( wp_style_is( 'republication-tracker-tool-jetpack-sharing', 'enqueued' ) );

		delete_post_meta( $this->test_post->ID, 'republication-tracker-tool-hide-widget' );
		$this->view_test_post();
		$this->make_source()->display_header();
		$this->assertTrue( wp_style_is( 'republication-tracker-tool-jetpack-sharing', 'enqueued' ) );
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
	 * The footer prints the modal once, for the post the button was shown for,
	 * even when a later loop left the global post on another post.
	 */
	public function test_footer_prints_modal_once_for_shown_post() {
		$other_post = self::factory()->post->create_and_get(
			[
				'post_title'  => 'Other Loop Post',
				'post_status' => 'publish',
			]
		);
		$this->view_test_post();
		$this->make_source()->get_display( $this->test_post );
		$GLOBALS['post'] = $other_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		ob_start();
		Republication_Tracker_Tool_Jetpack_Sharing::print_modal();
		Republication_Tracker_Tool_Jetpack_Sharing::print_modal();
		$footer = ob_get_clean();

		$this->assertSame( 1, substr_count( $footer, 'id="republication-tracker-tool-modal"' ) );
		$this->assertStringContainsString( 'Jetpack Sharing Test Post', $footer );
		$this->assertStringNotContainsString( 'Other Loop Post', $footer );
	}

	/**
	 * The footer modal copies the page's own post object as it stands when the
	 * footer prints, rather than a reloaded copy or one taken when the button rendered.
	 */
	public function test_footer_modal_uses_the_shown_post_object() {
		$this->view_test_post();
		global $post;
		$this->make_source()->get_display( $post );
		$post->post_content = '<p>Teaser only.</p>'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		ob_start();
		Republication_Tracker_Tool_Jetpack_Sharing::print_modal();
		$footer = ob_get_clean();

		$this->assertStringContainsString( 'Teaser only.', $footer );
		$this->assertStringNotContainsString( 'Shareable content.', $footer );
	}

	/**
	 * The modal follows the main query's post object even when the queried
	 * object, or the global post a secondary loop left behind, is a separate
	 * copy of the same post.
	 */
	public function test_footer_modal_follows_the_main_query_post() {
		$this->view_test_post();
		global $wp_query;
		$wp_query->queried_object    = clone $wp_query->posts[0];
		$wp_query->queried_object_id = $this->test_post->ID;
		$GLOBALS['post']             = clone $wp_query->posts[0]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->make_source()->get_display( $GLOBALS['post'] );
		$wp_query->posts[0]->post_content = '<p>Teaser only.</p>';

		ob_start();
		Republication_Tracker_Tool_Jetpack_Sharing::print_modal();
		$footer = ob_get_clean();

		$this->assertStringContainsString( 'Teaser only.', $footer );
		$this->assertStringNotContainsString( 'Shareable content.', $footer );
	}

	/**
	 * Where the button can't show, it is left out of Jetpack's enabled services,
	 * so Jetpack prints no empty item or empty share bar for it.
	 */
	public function test_service_left_out_where_button_cannot_show() {
		$enabled = [
			'visible' => [
				'facebook'  => 'Share_Facebook',
				'republish' => 'Republication_Tracker_Tool_Jetpack_Share_Republish',
			],
			'hidden'  => [
				'republish' => 'Republication_Tracker_Tool_Jetpack_Share_Republish',
			],
			'all'     => [
				'facebook'  => 'Share_Facebook',
				'republish' => 'Republication_Tracker_Tool_Jetpack_Share_Republish',
			],
		];

		$this->assertNotFalse( has_filter( 'sharing_enabled', [ 'Republication_Tracker_Tool_Jetpack_Sharing', 'filter_enabled_services' ] ) );

		$this->go_to( home_url( '/' ) );
		$on_home = Republication_Tracker_Tool_Jetpack_Sharing::filter_enabled_services( $enabled );
		$this->assertSame( [ 'facebook' ], array_keys( $on_home['visible'] ) );
		$this->assertSame( [], $on_home['hidden'] );
		$this->assertSame( [ 'facebook' ], array_keys( $on_home['all'] ) );

		$this->view_test_post();
		$on_post = Republication_Tracker_Tool_Jetpack_Sharing::filter_enabled_services( $enabled );
		$this->assertSame( $enabled, $on_post );
	}

	/**
	 * The settings preview always carries the icon, since Jetpack switches the
	 * preview's style in the browser without reloading.
	 */
	public function test_settings_preview_has_icon_in_text_style() {
		$this->assertMatchesRegularExpression( '/<a\b[^>]*><svg\b/', $this->make_source( 'text' )->display_preview( false ) );
	}

	/**
	 * In the settings screen the button keeps its own class only. Jetpack's
	 * preview copies each enabled service by its class, so borrowing another
	 * service's class would show Republish whenever that service is enabled.
	 */
	public function test_settings_preview_uses_only_its_own_class() {
		set_current_screen( 'settings_page_sharing' );

		$this->assertSame( 'republish', $this->make_source()->get_class() );
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

	/**
	 * Register Jetpack's sharing blocks unless Jetpack has, remembering ours to remove again.
	 */
	private function register_sharing_blocks() {
		$registry = WP_Block_Type_Registry::get_instance();
		if ( ! $registry->is_registered( 'jetpack/sharing-buttons' ) ) {
			register_block_type( 'jetpack/sharing-buttons', [ 'provides_context' => [ 'styleType' => 'styleType' ] ] );
			$this->registered_blocks[] = 'jetpack/sharing-buttons';
		}
		if ( ! $registry->is_registered( 'jetpack/sharing-button' ) ) {
			register_block_type( 'jetpack/sharing-button', [ 'uses_context' => [ 'postId', 'styleType' ] ] );
			$this->registered_blocks[] = 'jetpack/sharing-button';
		}
	}

	/**
	 * Build the Sharing Buttons block's button as WordPress passes it to the render filter.
	 *
	 * @param string $service Service ID.
	 * @param string $style   Style type of the parent block.
	 * @return string
	 */
	private function render_block_button( $service = 'republish', $style = 'icon' ) {
		$parsed = [
			'blockName' => 'jetpack/sharing-button',
			'attrs'     => [
				'service' => $service,
				'label'   => 'Republish',
			],
		];
		$this->register_sharing_blocks();
		$block = new WP_Block(
			$parsed,
			[
				'postId'    => $this->test_post->ID,
				'styleType' => $style,
			] 
		);
		return Republication_Tracker_Tool_Jetpack_Sharing::render_block_button( '', $parsed, $block );
	}

	/**
	 * The block button carries Jetpack's classes and opens the modal, with the modal built for the page.
	 */
	public function test_block_button_matches_jetpack_markup_and_opens_modal() {
		$this->view_test_post();

		$markup = $this->render_block_button();

		$this->assertStringContainsString( 'class="jetpack-sharing-button__button style-icon share-republish"', $markup );
		$this->assertStringContainsString( 'data-service="republish"', $markup );
		$this->assertStringContainsString( 'data-modal-trigger="republish"', $markup );
		$this->assertStringContainsString( '<svg', $markup );
		$this->assertStringNotContainsString( 'target="_blank"', $markup );
		$this->assertNotFalse( has_action( 'wp_footer', [ 'Republication_Tracker_Tool_Jetpack_Sharing', 'print_modal' ] ) );
	}

	/**
	 * The hook is wired to Jetpack's block, which passes the layout's style type down as context.
	 */
	public function test_block_button_renders_through_the_block_pipeline() {
		$this->view_test_post();
		$this->register_sharing_blocks();

		$markup = do_blocks( '<!-- wp:jetpack/sharing-buttons {"styleType":"icon"} --><ul><!-- wp:jetpack/sharing-button {"service":"republish"} /--></ul><!-- /wp:jetpack/sharing-buttons -->' );

		$this->assertStringContainsString( 'share-republish', $markup );
		$this->assertStringContainsString( 'style-icon', $markup );
	}

	/**
	 * Text-only style leaves the icon out, like Jetpack's own buttons.
	 */
	public function test_block_button_text_style_has_no_icon() {
		$this->view_test_post();

		$this->assertStringNotContainsString( '<svg', $this->render_block_button( 'republish', 'text' ) );
	}

	/**
	 * The block button is empty where Republish can't be offered.
	 */
	public function test_block_button_is_empty_when_not_offered() {
		update_post_meta( $this->test_post->ID, 'republication-tracker-tool-hide-widget', true );
		$this->view_test_post();
		$this->assertSame( '', $this->render_block_button() );

		delete_post_meta( $this->test_post->ID, 'republication-tracker-tool-hide-widget' );
		$this->go_to( home_url( '/' ) );
		$this->assertSame( '', $this->render_block_button() );
	}

	/**
	 * Other services are left to Jetpack.
	 */
	public function test_block_button_ignores_other_services() {
		$this->view_test_post();

		$this->assertSame( 'kept', Republication_Tracker_Tool_Jetpack_Sharing::render_block_button( 'kept', [ 'attrs' => [ 'service' => 'facebook' ] ] ) );
	}
}
