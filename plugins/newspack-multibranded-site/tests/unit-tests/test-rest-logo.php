<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName

use PHPUnit\Framework\TestCase;
use Newspack_Multibranded_Site\Taxonomy;
use Newspack_Multibranded_Site\Meta\Logo;

/**
 * Tests editing the Logo metadata from the rest api.
 */
class Test_Rest_Logo extends Newspack_Multibranded_Rest_Testcase {

	/**
	 * Test editing without permissions
	 */
	public function test_unauthorized() {
		wp_set_current_user( 0 );
		$response = $this->dispatch_request_to_edit_termmeta( Logo::get_key(), 123 );
		$this->assertSame( 401, $response->get_status() );

		wp_set_current_user( $this->secondary_user_id->ID );
		$response = $this->dispatch_request_to_edit_termmeta( Logo::get_key(), 123 );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test setting a valid value
	 */
	public function test_valid_input() {
		wp_set_current_user( $this->user_id->ID );
		$response = $this->dispatch_request_to_edit_termmeta( Logo::get_key(), 123 );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 123, $data['meta'][ Logo::get_key() ] );
	}

	/**
	 * Test setting an invalid value
	 */
	public function test_invalid_input() {
		wp_set_current_user( $this->user_id->ID );
		$response = $this->dispatch_request_to_edit_termmeta( Logo::get_key(), 'invalid' );
		$data     = $response->get_data();
		$this->assertSame( 400, $response->get_status() );
	}
}
