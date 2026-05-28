<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName

use PHPUnit\Framework\TestCase;
use Newspack_Multibranded_Site\Taxonomy;
use Newspack_Multibranded_Site\Meta\Show_Page_On_Front;

/**
 * Tests editing the Url metadata from the rest api.
 */
class Test_PageOnFront extends Newspack_Multibranded_Rest_Testcase {

	/**
	 * Test editing without permissions
	 */
	public function test_unauthorized() {
		wp_set_current_user( 0 );
		$response = $this->dispatch_request_to_edit_termmeta( Show_Page_On_Front::get_key(), 'yes' );
		$this->assertSame( 401, $response->get_status() );

		wp_set_current_user( $this->secondary_user_id->ID );
		$response = $this->dispatch_request_to_edit_termmeta( Show_Page_On_Front::get_key(), 'yes' );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test setting a valid value
	 */
	public function test_valid_input() {
		wp_set_current_user( $this->user_id->ID );
		$response = $this->dispatch_request_to_edit_termmeta( Show_Page_On_Front::get_key(), 2 );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, $data['meta'][ Show_Page_On_Front::get_key() ] );
	}

	/**
	 * Test deleting the meta value
	 */
	public function test_delete() {
		wp_set_current_user( $this->user_id->ID );
		$response = $this->dispatch_request_to_edit_termmeta( Show_Page_On_Front::get_key(), 2 );

		$response = $this->dispatch_request_to_edit_termmeta( Show_Page_On_Front::get_key(), null );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $data['meta'][ Show_Page_On_Front::get_key() ] );
		$this->assertEmpty( get_term_meta( $this->term1->term_id, Show_Page_On_Front::get_key(), true ) );
	}

	/**
	 * Test setting an invalid value
	 */
	public function test_invalid_input() {
		wp_set_current_user( $this->user_id->ID );
		$response = $this->dispatch_request_to_edit_termmeta( Show_Page_On_Front::get_key(), 'asd' );
		$data     = $response->get_data();
		$this->assertSame( 400, $response->get_status() );
	}
}
