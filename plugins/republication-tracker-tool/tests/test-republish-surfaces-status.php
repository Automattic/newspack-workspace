<?php
/**
 * Class RepublishSurfacesStatusTest
 *
 * @package Republication_Tracker_Tool
 */

/**
 * The widget and the Republish Button block embed the republishable copy in the
 * page, so they follow the same rule as the `/republish/` endpoint: they render
 * only for a post that is publicly viewable and not password-gated, and render
 * nothing otherwise. RewriteEndpointStatusTest covers the endpoint.
 */
class RepublishSurfacesStatusTest extends WP_UnitTestCase {

	/**
	 * Marker placed in each post's body, so a test can tell whether the copy
	 * reached the output at all, independent of the surrounding markup.
	 *
	 * @var string
	 */
	private $body_marker = 'rtt-surface-status-body-marker';

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( 'republication-tracker-tool/republish-button' ) ) {
			register_block_type_from_metadata(
				REPUBLICATION_TRACKER_TOOL_PATH . 'src/blocks/republish-button',
				[
					'render_callback' => [ 'Republication_Tracker_Tool_Republish_Button_Block', 'render_block' ],
				]
			);
		}

		wp_set_current_user( 0 );
	}

	/**
	 * Clean up.
	 */
	public function tear_down() {
		Republication_Tracker_Tool::$modal_rendered = false;
		parent::tear_down();
	}

	/**
	 * Create a post carrying the body marker and make it the single post being
	 * viewed.
	 *
	 * @param array $postarr Arguments for the post to create.
	 * @return WP_Post
	 */
	private function view_post( $postarr ) {
		global $post, $wp_query;

		$post = $this->factory->post->create_and_get(
			array_merge( [ 'post_content' => '<p>' . $this->body_marker . '</p>' ], $postarr )
		);

		$wp_query->is_singular       = true;
		$wp_query->is_single         = true;
		$wp_query->queried_object    = $post;
		$wp_query->queried_object_id = $post->ID;

		return $post;
	}

	/**
	 * Render the widget for the current post.
	 *
	 * @return string
	 */
	private function render_widget() {
		$widget = new Republication_Tracker_Tool_Widget();
		ob_start();
		$widget->widget(
			[
				'before_widget' => '<div class="widget">',
				'after_widget'  => '</div>',
				'before_title'  => '<h2>',
				'after_title'   => '</h2>',
			],
			[ 'text' => 'Widget text' ]
		);
		return ob_get_clean();
	}

	/**
	 * Render the block for the current post.
	 *
	 * @return string
	 */
	private function render_block() {
		return do_blocks( '<!-- wp:republication-tracker-tool/republish-button /-->' );
	}

	/**
	 * Positive control: a published post's copy is embedded by the widget. Fails
	 * if the widget declines everything, or if the marker never reaches the
	 * output for some other reason.
	 */
	public function test_widget_embeds_published_post() {
		$this->view_post( [ 'post_status' => 'publish' ] );
		$this->assertStringContainsString( $this->body_marker, $this->render_widget() );
	}

	/**
	 * Positive control: a published post's copy is embedded by the block.
	 */
	public function test_block_embeds_published_post() {
		$this->view_post( [ 'post_status' => 'publish' ] );
		$this->assertStringContainsString( $this->body_marker, $this->render_block() );
	}

	/**
	 * The widget renders nothing for a post a logged-out visitor could not read.
	 *
	 * @dataProvider non_public_post_provider
	 * @param array  $postarr Arguments for the post to create.
	 * @param string $why     What this case is guarding, for the failure message.
	 */
	public function test_widget_renders_nothing_for_non_public_post( $postarr, $why ) {
		$this->view_post( $postarr );
		$this->assertSame( '', $this->render_widget(), $why );
	}

	/**
	 * The block renders nothing for a post a logged-out visitor could not read.
	 *
	 * @dataProvider non_public_post_provider
	 * @param array  $postarr Arguments for the post to create.
	 * @param string $why     What this case is guarding, for the failure message.
	 */
	public function test_block_renders_nothing_for_non_public_post( $postarr, $why ) {
		$this->view_post( $postarr );
		$this->assertSame( '', $this->render_block(), $why );
	}

	/**
	 * The non-public cases a single-post view can reach. RewriteEndpointStatusTest
	 * also covers `trash`, which has no single view for these surfaces to render on.
	 *
	 * @return array
	 */
	public function non_public_post_provider() {
		return array(
			'draft'    => array(
				array( 'post_status' => 'draft' ),
				'A draft must not be offered for republication.',
			),
			'pending'  => array(
				array( 'post_status' => 'pending' ),
				'A pending post must not be offered for republication.',
			),
			'private'  => array(
				array( 'post_status' => 'private' ),
				'A private post must not be offered for republication.',
			),
			'future'   => array(
				array(
					'post_status' => 'future',
					'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				),
				'A scheduled post must not be offered before its publication date.',
			),
			'password' => array(
				array(
					'post_status'   => 'publish',
					'post_password' => 'correct-horse',
				),
				'A password-protected post must not be offered without the password.',
			),
		);
	}
}
