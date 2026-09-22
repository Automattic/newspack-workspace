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
	 * Drive the endpoint as $user_id requesting a post by numeric ID through the
	 * query-variable form, and return the template the filter selects.
	 *
	 * @param int $post_id Target post ID.
	 * @param int $user_id Requesting user, 0 for a logged-out visitor.
	 * @return string Selected template path.
	 */
	private function template_for_request( $post_id, $user_id = 0 ) {
		wp_set_current_user( $user_id );
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
	 * An editor gets no republish view of their own draft either. The guard tests
	 * the post, not the requester, and this is the case that would start passing
	 * silently if a capability exception were ever added: every other case here
	 * runs logged out, so none of them would notice.
	 */
	public function test_editor_is_not_served_their_own_draft() {
		$editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		$post_id   = $this->factory->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $editor_id,
			)
		);
		$this->assertSame(
			$this->incoming_template,
			$this->template_for_request( $post_id, $editor_id ),
			'An editor must not be served a republish view of their own draft.'
		);
	}

	/**
	 * A password-protected post stays declined for a visitor who has entered its
	 * password. The rule reads the post, not the password cookie, so this is the
	 * case that would start passing silently if the check were ever switched to
	 * post_password_required(), which honors the cookie.
	 */
	public function test_password_post_is_not_served_with_valid_password_cookie() {
		$post_id = $this->factory->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'correct-horse',
			)
		);
		$cookie  = 'wp-postpass_' . COOKIEHASH;
		require_once ABSPATH . WPINC . '/class-phpass.php';
		$hasher             = new PasswordHash( 8, true );
		$_COOKIE[ $cookie ] = $hasher->HashPassword( 'correct-horse' ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- the cookie is the fixture under test.

		try {
			$this->assertFalse( post_password_required( $post_id ), 'Fixture check: the cookie should satisfy WordPress\'s own password check.' );
			$template = $this->template_for_request( $post_id );
		} finally {
			unset( $_COOKIE[ $cookie ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		}

		$this->assertSame(
			$this->incoming_template,
			$template,
			'A password-protected post must not be served for republication, even with its password.'
		);
	}

	/**
	 * Drive the endpoint through the rewrite rule, as a `/republish/<value>`
	 * request, and return the template the filter selects. The value reaches
	 * the resolver as a path segment rather than a query variable.
	 *
	 * @param string $value Path segment after `/republish/`.
	 * @return string Selected template path.
	 */
	private function template_for_path_request( $value ) {
		$this->endpoint->register_endpoint();
		$this->set_permalink_structure( '/%postname%/' );
		wp_set_current_user( 0 );
		$this->go_to( home_url( '/republish/' . $value ) );
		return $this->endpoint->filter_template_include( $this->incoming_template );
	}

	/**
	 * Positive control for the path form: a published post requested by its
	 * permalink path is served. Fails if the path form never reaches the
	 * endpoint, which would make the negative path cases below pass vacuously.
	 */
	public function test_published_post_is_served_through_path_form() {
		$this->factory->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => 'rtt-path-published',
			)
		);
		$this->assertSame(
			$this->republish_template,
			$this->template_for_path_request( 'rtt-path-published/' ),
			'A published post should be served when requested through the path form.'
		);
	}

	/**
	 * A path-form value that carries a post ID is held to the same rule as the
	 * query-variable form.
	 *
	 * @dataProvider path_form_non_public_provider
	 * @param array  $postarr Arguments for the post to create.
	 * @param string $why     What this case is guarding, for the failure message.
	 */
	public function test_non_public_post_is_not_served_through_path_form( $postarr, $why ) {
		$post_id = $this->factory->post->create( $postarr );
		$this->assertSame(
			$this->incoming_template,
			$this->template_for_path_request( 'x&p=' . $post_id ),
			$why
		);
	}

	/**
	 * Non-public cases for the path form.
	 *
	 * @return array
	 */
	public function path_form_non_public_provider() {
		return array(
			'draft'    => array(
				array( 'post_status' => 'draft' ),
				'A draft must not be served through the path form.',
			),
			'password' => array(
				array(
					'post_status'   => 'publish',
					'post_password' => 'correct-horse',
				),
				'A password-protected post must not be served through the path form.',
			),
		);
	}

	/**
	 * Every non-public status a post can carry, plus a password-protected
	 * published post.
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
