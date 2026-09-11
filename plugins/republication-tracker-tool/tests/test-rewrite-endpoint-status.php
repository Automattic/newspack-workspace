<?php
/**
 * Class RewriteEndpointStatusTest
 *
 * @package Republication_Tracker_Tool
 */

/**
 * The republish endpoint should serve a post through its template only when the
 * requesting visitor is entitled to read that post. A visitor who could not read
 * the post through its normal permalink must not read it through /republish/.
 */
class RewriteEndpointStatusTest extends WP_UnitTestCase {

	/**
	 * Endpoint under test.
	 *
	 * @var Republication_Tracker_Tool_Rewrite_Endpoint
	 */
	private $endpoint;

	/**
	 * A path distinct from the republish template; the filter returns it
	 * unchanged when it declines to serve the republish view.
	 *
	 * @var string
	 */
	private $incoming_template = '/tmp/rtt-incoming-theme-template.php';

	/**
	 * The republish template path the filter returns when it serves the view.
	 *
	 * @var string
	 */
	private $republish_template;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->endpoint           = new Republication_Tracker_Tool_Rewrite_Endpoint();
		$this->republish_template = REPUBLICATION_TRACKER_TOOL_PATH . 'templates/republish-template.php';
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		set_query_var( 'republish', '' );
		parent::tear_down();
	}

	/**
	 * Drive the endpoint as a logged-out visitor requesting a post by numeric ID
	 * through the query-variable form, and return the template the filter selects.
	 *
	 * @param int $post_id Target post ID.
	 * @return string Selected template path.
	 */
	private function template_for_request( $post_id ) {
		wp_set_current_user( 0 );
		set_query_var( 'republish', '?p=' . $post_id );
		return $this->endpoint->filter_template_include( $this->incoming_template );
	}

	/**
	 * A published post is served by the republish template.
	 */
	public function test_published_post_is_served() {
		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		$this->assertSame(
			$this->republish_template,
			$this->template_for_request( $post_id ),
			'A published post should be served by the republish template.'
		);
	}

	/**
	 * A draft is not served to a logged-out visitor.
	 */
	public function test_draft_is_not_served() {
		$post_id = $this->factory->post->create( array( 'post_status' => 'draft' ) );
		$this->assertNotSame(
			$this->republish_template,
			$this->template_for_request( $post_id ),
			'A draft must not be served by the republish template to a logged-out visitor.'
		);
	}

	/**
	 * A pending post is not served to a logged-out visitor.
	 */
	public function test_pending_post_is_not_served() {
		$post_id = $this->factory->post->create( array( 'post_status' => 'pending' ) );
		$this->assertNotSame(
			$this->republish_template,
			$this->template_for_request( $post_id ),
			'A pending post must not be served by the republish template to a logged-out visitor.'
		);
	}

	/**
	 * A private post is not served to a logged-out visitor.
	 */
	public function test_private_post_is_not_served() {
		$post_id = $this->factory->post->create( array( 'post_status' => 'private' ) );
		$this->assertNotSame(
			$this->republish_template,
			$this->template_for_request( $post_id ),
			'A private post must not be served by the republish template to a logged-out visitor.'
		);
	}

	/**
	 * A password-protected published post is not served without the password.
	 */
	public function test_password_protected_post_is_not_served() {
		$post_id = $this->factory->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);
		$this->assertNotSame(
			$this->republish_template,
			$this->template_for_request( $post_id ),
			'A password-protected post must not be served without the password.'
		);
	}
}
