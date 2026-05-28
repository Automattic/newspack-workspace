<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName

use Newspack_Multibranded_Site\Taxonomy;

/**
 * Tests editing the Url metadata from the rest api.
 */
class Newspack_Multibranded_Rest_Testcase extends WP_UnitTestCase {

	/**
	 * REST Server object.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * The current user id.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * The secondary user id.
	 *
	 * @var int
	 */
	protected $secondary_user_id;

	/**
	 * A testing brand term.
	 *
	 * @var WP_Term
	 */
	protected $term1;

	/**
	 * A testing brand term.
	 *
	 * @var WP_Term
	 */
	protected $term2;

	/**
	 * An author user.
	 *
	 * @var WP_User
	 */
	protected $author;

	/**
	 * A subscriber user.
	 *
	 * @var WP_User
	 */
	protected $subscriber;

	/**
	 * Setting up the test.
	 *
	 * @before
	 */
	public function set_up() {
		parent::set_up();

		// See https://core.trac.wordpress.org/ticket/48300.
		do_action( 'init' );

		global $wp_rest_server;

		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;

		do_action( 'rest_api_init' );

		$this->user_id = $this->factory->user->create_and_get(
			array(
				'role' => 'administrator',
			)
		);

		$this->secondary_user_id = $this->factory->user->create_and_get(
			array(
				'role' => 'editor',
			)
		);

		$this->author = $this->factory->user->create_and_get(
			array(
				'role' => 'author',
			)
		);

		$this->subscriber = $this->factory->user->create_and_get(
			array(
				'role' => 'subscriber',
			)
		);

		$this->term1 = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );
	}

	/**
	 * Dispatches a POST request to edit the term metadata
	 *
	 * @param string $key The new meta key.
	 * @param string $value The new meta value.
	 * @return WP_REST_Response
	 */
	protected function dispatch_request_to_edit_termmeta( $key, $value ) {
		$endpoint = '/wp/v2/' . Taxonomy::SLUG . '/' . $this->term1->term_id;

		$request = new WP_REST_Request(
			'POST',
			$endpoint
		);

		$request->set_header( 'content-type', 'application/json' );

		$request->set_body(
			wp_json_encode(
				[
					'meta' => [
						$key => $value,
					],
				]
			)
		);

		$response = $this->server->dispatch( $request );

		return $response;
	}

	/**
	 * Dispatches a POST request to edit a post metadata
	 *
	 * @param int    $post_id The post ID.
	 * @param string $key The meta key.
	 * @param string $value The meta value.
	 * @return WP_REST_Response
	 */
	protected function dispatch_request_to_edit_postmeta( $post_id, $key, $value ) {
		$endpoint = '/wp/v2/posts/' . $post_id;

		$request = new WP_REST_Request(
			'POST',
			$endpoint
		);

		$request->set_header( 'content-type', 'application/json' );

		$request->set_body(
			wp_json_encode(
				[
					'meta' => [
						$key => $value,
					],
				]
			)
		);

		$response = $this->server->dispatch( $request );

		return $response;
	}

	/**
	 * Dispatches a POST request to edit the site option
	 *
	 * @param string $option_name The option name.
	 * @param string $value The option value.
	 * @return WP_REST_Response
	 */
	protected function dispatch_request_to_edit_option( $option_name, $value ) {
		$endpoint = '/wp/v2/settings';

		$request = new WP_REST_Request(
			'POST',
			$endpoint
		);

		$request->set_header( 'content-type', 'application/json' );

		$request->set_body(
			wp_json_encode(
				[
					$option_name => $value,
				]
			)
		);

		$response = $this->server->dispatch( $request );

		return $response;
	}
}
