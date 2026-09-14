<?php
/**
 * Class RewriteEndpointStatusTest
 *
 * @package Republication_Tracker_Tool
 */

/**
 * The republish endpoint serves a post through its template only when that post
 * is publicly viewable and not password-gated. The rule is about the post, not
 * the requester: nobody gets a republish view of an unpublished article, an
 * editor included. Anything else is handed back to normal WordPress handling.
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
	 * A published post is served by the republish template. Positive control: it
	 * fails if the guard declines everything.
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
	 * A post a logged-out visitor could not read at its permalink is handed back
	 * to normal WordPress handling rather than served by the republish template.
	 *
	 * @dataProvider non_public_post_provider
	 * @param array  $postarr Arguments for the post to create.
	 * @param string $why     What this case is guarding, for the failure message.
	 */
	public function test_non_public_post_is_not_served( $postarr, $why ) {
		$post_id = $this->factory->post->create( $postarr );
		$this->assertSame(
			$this->incoming_template,
			$this->template_for_request( $post_id ),
			$why
		);
	}

	/**
	 * The statuses the endpoint reached before the guard, plus the password case.
	 *
	 * `future` and `trash` are here because the report named them as reachable;
	 * they are also the two whose absence would be least obvious from reading the
	 * guard, since neither has a test elsewhere in this plugin.
	 *
	 * @return array
	 */
	public function non_public_post_provider() {
		return array(
			'draft'    => array(
				array( 'post_status' => 'draft' ),
				'A draft must not be served by the republish template to a logged-out visitor.',
			),
			'pending'  => array(
				array( 'post_status' => 'pending' ),
				'A pending post must not be served by the republish template to a logged-out visitor.',
			),
			'private'  => array(
				array( 'post_status' => 'private' ),
				'A private post must not be served by the republish template to a logged-out visitor.',
			),
			'future'   => array(
				array(
					'post_status' => 'future',
					'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				),
				'A scheduled post must not be served before its publication date.',
			),
			'trash'    => array(
				array( 'post_status' => 'trash' ),
				'A trashed post must not be served by the republish template.',
			),
			'password' => array(
				array(
					'post_status'   => 'publish',
					'post_password' => 'correct-horse',
				),
				'A password-protected post must not be served without the password.',
			),
		);
	}
}
