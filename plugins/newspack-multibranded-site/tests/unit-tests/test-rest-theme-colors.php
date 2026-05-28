<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName

use Newspack_Multibranded_Site\Taxonomy;
use Newspack_Multibranded_Site\Meta\Theme_Colors;

/**
 * Tests editing the Theme_Colors metadata from the rest api.
 */
class Test_Rest_Theme_Colors extends Newspack_Multibranded_Rest_Testcase {

	/**
	 * Gets a sample valid input
	 *
	 * @return array
	 */
	public function get_sample_valid_input() {
		return [
			[
				'name'  => 'color_primary',
				'color' => '#000000',
			],
			[
				'name'  => 'color_secondary',
				'color' => '#0000FF',
			],
			[
				'name'  => 'color_secondary',
				'color' => '#00c',
			],
		];
	}

	/**
	 * Test editing without permissions
	 */
	public function test_unauthorized() {
		wp_set_current_user( 0 );
		$response = $this->dispatch_request_to_edit_termmeta( Theme_Colors::get_key(), $this->get_sample_valid_input() );
		$this->assertSame( 401, $response->get_status() );

		wp_set_current_user( $this->secondary_user_id->ID );
		$response = $this->dispatch_request_to_edit_termmeta( Theme_Colors::get_key(), $this->get_sample_valid_input() );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test setting a valid value
	 */
	public function test_valid_input() {
		wp_set_current_user( $this->user_id->ID );
		$response = $this->dispatch_request_to_edit_termmeta( Theme_Colors::get_key(), $this->get_sample_valid_input() );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->get_sample_valid_input(), $data['meta'][ Theme_Colors::get_key() ] );
	}

	/**
	 * Test setting an invalid value
	 */
	public function test_invalid_input() {
		wp_set_current_user( $this->user_id->ID );
		$invalid  = [
			[
				'name'  => 'color_primary',
				'color' => '#000000',
			],
			[
				'name'  => 'color_secondary',
				'color' => '#0000FF',
			],
			[
				'name'  => 'color_secondary',
				'color' => '#00ZZZZ',
			],
		];
		$response = $this->dispatch_request_to_edit_termmeta( Theme_Colors::get_key(), $invalid );
		$data     = $response->get_data();
		$this->assertSame( 400, $response->get_status() );
	}
}
