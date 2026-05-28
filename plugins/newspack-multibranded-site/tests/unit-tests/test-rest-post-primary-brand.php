<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName

use Newspack_Multibranded_Site\Taxonomy;

/**
 * Tests editing the Post primary brand metadata from the rest api.
 */
class Test_Rest_Post_Primary_Brand extends Newspack_Multibranded_Rest_Testcase {

	/**
	 * A testing brand term.
	 *
	 * @var WP_Post
	 */
	protected $post;

	/**
	 * A testing post by author.
	 *
	 * @var WP_Post
	 */
	protected $post_by_author;

	/**
	 * Setting up the test.
	 *
	 * @before
	 */
	public function set_up() {
		parent::set_up();
		$this->post           = $this->factory->post->create_and_get();
		$this->post_by_author = $this->factory->post->create_and_get( [ 'post_author' => $this->author->ID ] );
	}

	/**
	 * Test editing without permissions
	 */
	public function test_unauthorized() {
		wp_set_current_user( 0 );
		$response = $this->dispatch_request_to_edit_postmeta( $this->post->ID, Taxonomy::PRIMARY_META_KEY, 123 );
		$this->assertSame( 401, $response->get_status() );

		wp_set_current_user( $this->author->ID );
		$response = $this->dispatch_request_to_edit_postmeta( $this->post->ID, Taxonomy::PRIMARY_META_KEY, 123 );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test with permissions
	 */
	public function test_authorized() {
		wp_set_current_user( $this->secondary_user_id->ID );
		$response = $this->dispatch_request_to_edit_postmeta( $this->post_by_author->ID, Taxonomy::PRIMARY_META_KEY, 123 );
		$this->assertSame( 200, $response->get_status() );

		wp_set_current_user( $this->author->ID );
		$response = $this->dispatch_request_to_edit_postmeta( $this->post_by_author->ID, Taxonomy::PRIMARY_META_KEY, 123 );
		$this->assertSame( 200, $response->get_status(), 'Authors have permission to edit their own posts' );

		wp_set_current_user( $this->secondary_user_id->ID );
		$response = $this->dispatch_request_to_edit_postmeta( $this->post->ID, Taxonomy::PRIMARY_META_KEY, 123 );
		$this->assertSame( 200, $response->get_status(), 'Editors have permission to edit other posts' );
		$response = $this->dispatch_request_to_edit_postmeta( $this->post_by_author->ID, Taxonomy::PRIMARY_META_KEY, 123 );
		$this->assertSame( 200, $response->get_status(), 'Editors have permission to edit other posts' );
	}

	/**
	 * Test setting a valid value
	 */
	public function test_valid_input() {
		wp_set_current_user( $this->user_id->ID );
		$response = $this->dispatch_request_to_edit_postmeta( $this->post->ID, Taxonomy::PRIMARY_META_KEY, 123 );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 123, $data['meta'][ Taxonomy::PRIMARY_META_KEY ] );
	}

	/**
	 * Test setting an invalid value
	 */
	public function test_invalid_input() {
		wp_set_current_user( $this->user_id->ID );
		$response = $this->dispatch_request_to_edit_postmeta( $this->post->ID, Taxonomy::PRIMARY_META_KEY, 'invalid' );
		$data     = $response->get_data();
		$this->assertSame( 400, $response->get_status() );
	}
}
