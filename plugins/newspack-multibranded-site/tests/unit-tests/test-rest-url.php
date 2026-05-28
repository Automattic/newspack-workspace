<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName

use Newspack_Multibranded_Site\Taxonomy;
use Newspack_Multibranded_Site\Meta\Url;

/**
 * Tests editing the Url metadata from the rest api.
 */
class Test_Rest_Url extends Newspack_Multibranded_Rest_Testcase {

	/**
	 * Test editing without permissions
	 */
	public function test_unauthorized() {
		wp_set_current_user( 0 );
		$response = $this->dispatch_request_to_edit_termmeta( Url::get_key(), 'yes' );
		$this->assertSame( 401, $response->get_status() );

		wp_set_current_user( $this->secondary_user_id->ID );
		$response = $this->dispatch_request_to_edit_termmeta( Url::get_key(), 'yes' );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test setting a valid value
	 */
	public function test_valid_input() {
		wp_set_current_user( $this->user_id->ID );
		$response = $this->dispatch_request_to_edit_termmeta( Url::get_key(), 'yes' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'yes', $data['meta'][ Url::get_key() ] );
	}

	/**
	 * Test setting an invalid value
	 */
	public function test_invalid_input() {
		wp_set_current_user( $this->user_id->ID );
		$response = $this->dispatch_request_to_edit_termmeta( Url::get_key(), 'invalid' );
		$data     = $response->get_data();
		$this->assertSame( 400, $response->get_status() );
	}
}
