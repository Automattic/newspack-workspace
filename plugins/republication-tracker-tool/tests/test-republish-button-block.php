<?php
/**
 * Class RepublishButtonBlockTest
 *
 * @package Republication_Tracker_Tool
 */

/**
 * Test Republish Button Block functionality.
 */
class RepublishButtonBlockTest extends WP_UnitTestCase {

	/**
	 * Test post.
	 *
	 * @var WP_Post
	 */
	private $test_post;

	/**
	 * Set up test environment.
	 */
	public function set_up() {
		parent::set_up();

		// Register the block type so get_block_wrapper_attributes() works.
		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( 'republication-tracker-tool/republish-button' ) ) {
			register_block_type_from_metadata(
				REPUBLICATION_TRACKER_TOOL_PATH . 'src/blocks/republish-button',
				[
					'render_callback' => [ 'Republication_Tracker_Tool_Republish_Button_Block', 'render_block' ],
				]
			);
		}

		$this->test_post = $this->factory->post->create_and_get(
			[
				'post_title'   => 'Test Post for Block',
				'post_content' => '<p>Test content for block display.</p>',
				'post_status'  => 'publish',
			]
		);
	}

	/**
	 * Clean up after tests.
	 */
	public function tear_down() {
		wp_delete_post( $this->test_post->ID, true );
		Republication_Tracker_Tool::$modal_rendered = false;
		parent::tear_down();
	}

	/**
	 * Helper to set up a singular post context.
	 */
	private function set_singular_context() {
		global $post, $wp_query;
		$post                        = $this->test_post;
		$wp_query->is_singular       = true;
		$wp_query->is_single         = true;
		$wp_query->queried_object    = $this->test_post;
		$wp_query->queried_object_id = $this->test_post->ID;
	}

	/**
	 * Helper to render the block through the standard pipeline so
	 * WP_Block_Supports context is set correctly.
	 *
	 * @param array $attrs Block attributes.
	 * @return string Rendered HTML.
	 */
	private function render_block( $attrs = [] ) {
		$json       = empty( $attrs ) ? '' : ' ' . wp_json_encode( (object) $attrs );
		$serialized = '<!-- wp:republication-tracker-tool/republish-button' . $json . ' /-->';
		return do_blocks( $serialized );
	}

	/**
	 * Test block renders on singular post view.
	 */
	public function test_block_renders_on_singular_post() {
		$this->set_singular_context();

		$output = $this->render_block();

		$this->assertStringContainsString( 'wp-block-republication-tracker-tool-republish-button', $output );
		$this->assertStringContainsString( 'wp-block-buttons', $output );
		$this->assertStringContainsString( 'wp-block-button__link', $output );
		$this->assertStringContainsString( 'wp-element-button', $output );
		$this->assertStringContainsString( 'Republish This Story', $output );
		$this->assertStringContainsString( 'data-modal-trigger="republish"', $output );
	}

	/**
	 * Test block returns empty on non-singular views.
	 */
	public function test_block_empty_on_non_singular() {
		global $wp_query;
		$wp_query->is_singular = false;
		$wp_query->is_single   = false;

		$output = Republication_Tracker_Tool_Republish_Button_Block::render_block( [] );

		$this->assertEmpty( $output );
	}

	/**
	 * Test block respects post type filter.
	 */
	public function test_block_respects_post_type_filter() {
		$this->set_singular_context();

		add_filter(
			'republication_tracker_tool_post_types',
			function () {
				return [ 'page' ];
			}
		);

		$output = Republication_Tracker_Tool_Republish_Button_Block::render_block( [] );

		$this->assertEmpty( $output );

		remove_all_filters( 'republication_tracker_tool_post_types' );
	}

	/**
	 * Test block respects hide widget meta.
	 */
	public function test_block_respects_hide_meta() {
		$this->set_singular_context();
		update_post_meta( $this->test_post->ID, 'republication-tracker-tool-hide-widget', true );

		$output = Republication_Tracker_Tool_Republish_Button_Block::render_block( [] );

		$this->assertEmpty( $output );

		delete_post_meta( $this->test_post->ID, 'republication-tracker-tool-hide-widget' );
	}

	/**
	 * Test block respects hide_republication_widget filter.
	 */
	public function test_block_respects_hide_filter() {
		$this->set_singular_context();
		add_filter( 'hide_republication_widget', '__return_true' );

		$output = Republication_Tracker_Tool_Republish_Button_Block::render_block( [] );

		$this->assertEmpty( $output );

		remove_filter( 'hide_republication_widget', '__return_true' );
	}

	/**
	 * Test modal is only rendered once across multiple blocks.
	 */
	public function test_single_modal_across_multiple_blocks() {
		$this->set_singular_context();

		$output1 = $this->render_block();
		$output2 = $this->render_block();

		$combined = $output1 . $output2;

		$this->assertEquals( 1, substr_count( $combined, 'id="republication-tracker-tool-modal"' ), 'Single modal in combined output.' );
		$this->assertEquals( 2, substr_count( $combined, 'data-modal-trigger="republish"' ), 'Two trigger buttons in combined output.' );
	}

	/**
	 * Test empty buttonText falls back to translated default.
	 */
	public function test_empty_attributes_fallback() {
		$this->set_singular_context();

		$output = $this->render_block(
			[
				'buttonText' => '',
			]
		);

		$this->assertStringContainsString( 'Republish This Story', $output );
	}

	/**
	 * Block no longer emits the message paragraph.
	 */
	public function test_block_does_not_emit_message_paragraph() {
		$this->set_singular_context();

		$output = $this->render_block();

		$this->assertStringNotContainsString( 'wp-block-republication-tracker-tool-republish-button__message', $output );
	}

	/**
	 * Block emits the license badge by default when a license is configured.
	 */
	public function test_block_emits_license_badge_by_default() {
		update_option( 'republication_tracker_tool_license', 'cc-by-nd-4.0' );

		$this->set_singular_context();

		$output = $this->render_block();

		$this->assertStringContainsString( '__license', $output );
		$this->assertStringContainsString( 'rel="noreferrer license"', $output );
		$this->assertStringContainsString( REPUBLICATION_TRACKER_TOOL_LICENSES['cc-by-nd-4.0']['url'], $output );

		delete_option( 'republication_tracker_tool_license' );
	}

	/**
	 * Block omits the license badge when showLicense is false.
	 */
	public function test_block_omits_license_when_disabled() {
		update_option( 'republication_tracker_tool_license', 'cc-by-nd-4.0' );

		$this->set_singular_context();

		$output = $this->render_block( [ 'showLicense' => false ] );

		$this->assertStringNotContainsString( '__license', $output );

		delete_option( 'republication_tracker_tool_license' );
	}

	/**
	 * Block omits the license badge when no recognizable license is set,
	 * even if showLicense is true.
	 */
	public function test_block_omits_license_when_no_license_set() {
		update_option( 'republication_tracker_tool_license', 'not-a-real-license' );

		$this->set_singular_context();

		$output = $this->render_block();

		$this->assertStringNotContainsString( '__license', $output );

		delete_option( 'republication_tracker_tool_license' );
	}
}
