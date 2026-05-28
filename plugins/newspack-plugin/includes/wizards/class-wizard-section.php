<?php
/**
 * Wizard Section Object, for inheritence.
 *
 * @package Newspack
 */

namespace Newspack\Wizards;

use WP_Error;

/**
 * Abstract class for wizard sections.
 */
abstract class Wizard_Section {
	
	/**
	 * The WP capability required to access this section.
	 *
	 * @var string
	 */
	protected $capability = 'manage_options';

	/**
	 * Parent tab slug.
	 * 
	 * @var string
	 */
	protected $wizard_slug = '';

	/**
	 * Initialize.
	 * 
	 * @param array $args Section arguments.
	 */
	public function __construct( $args = [] ) {
		$this->wizard_slug = $args['wizard_slug'] ?? '';
		// If inheriting class has method `register_rest_routes` bind method to action.
		if ( method_exists( $this, 'register_rest_routes' ) ) {
			add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		}
	}
	
	/**
	 * Check capabilities for using API.
	 *
	 * @return bool|WP_Error
	 */
	public function api_permissions_check() {
		if ( ! current_user_can( $this->capability ) ) {
			return new WP_Error(
				'newspack_rest_forbidden',
				esc_html__( 'You cannot use this resource.', 'newspack' ),
				[
					'status' => 403,
				]
			);
		}
		return true;
	}
}
