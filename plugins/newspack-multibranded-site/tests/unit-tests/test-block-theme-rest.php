<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class TestBlockThemeRest
 *
 * @package Newspack_Multibranded_Site
 */

use Newspack_Multibranded_Site\Block_Theme\Api;
use Newspack_Multibranded_Site\Meta\Block_Theme_Menus;
use Newspack_Multibranded_Site\Meta\Block_Theme_Parts;

/**
 * The block theme brand endpoints and meta.
 */
class TestBlockThemeRest extends Newspack_Multibranded_Rest_Testcase {

	/**
	 * Set up. The routes are only registered on block themes, so register them here.
	 */
	public function set_up() {
		parent::set_up();
		Api::register_routes();
	}

	/**
	 * Only people who can manage brands and edit the Site Editor can make copies.
	 */
	public function test_copy_menu_permissions() {
		$source = $this->factory->post->create(
			[
				'post_type'   => 'wp_navigation',
				'post_title'  => 'Main',
				'post_status' => 'publish',
			]
		);
		$request = new WP_REST_Request( 'POST', '/newspack-multibranded-site/v1/brands/' . $this->term1->term_id . '/copy-menu' );
		$request->set_param( 'source', $source );

		wp_set_current_user( $this->subscriber->ID );
		$this->assertSame( 403, $this->server->dispatch( $request )->get_status() );

		wp_set_current_user( $this->user_id->ID );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringEndsWith( '[' . $this->term1->name . ']', $response->get_data()['label'] );
	}

	/**
	 * Copying for a brand that doesn't exist fails cleanly.
	 */
	public function test_copy_part_unknown_brand() {
		wp_set_current_user( $this->user_id->ID );
		$request = new WP_REST_Request( 'POST', '/newspack-multibranded-site/v1/brands/999999/copy-part' );
		$request->set_param( 'slot', 'header-desktop' );
		$this->assertSame( 404, $this->server->dispatch( $request )->get_status() );
	}

	/**
	 * Brand choices save through the brand endpoint with the rest of the brand's settings.
	 */
	public function test_meta_saves_through_brand_endpoint() {
		wp_set_current_user( $this->user_id->ID );
		$request = new WP_REST_Request( 'POST', '/wp/v2/brand/' . $this->term1->term_id );
		$request->set_body_params(
			[
				'meta' => [
					Block_Theme_Menus::get_key() => [ '12' => 34 ],
					Block_Theme_Parts::get_key() => [ 'header-desktop' => 'header-desktop-brand' ],
				],
			]
		);
		$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );
		$this->assertSame( [ 12 => 34 ], get_term_meta( $this->term1->term_id, Block_Theme_Menus::get_key(), true ) );
		$this->assertSame( [ 'header-desktop' => 'header-desktop-brand' ], get_term_meta( $this->term1->term_id, Block_Theme_Parts::get_key(), true ) );
	}
}
