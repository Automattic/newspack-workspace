<?php
/**
 * Bluesky contact method for Yoast SEO.
 *
 * Registers Bluesky as an additional contact method in user profiles
 * while Yoast SEO does not include it natively.
 *
 * @package Newspack
 */

namespace Newspack;

use Yoast\WP\SEO\User_Meta\Domain\Additional_Contactmethod_Interface;

defined( 'ABSPATH' ) || exit;

/**
 * Bluesky contact method implementation.
 */
class Yoast_Bluesky_Contact_Method implements Additional_Contactmethod_Interface {

	/**
	 * Returns the key of the Bluesky contact method.
	 *
	 * @return string The key of the Bluesky contact method.
	 */
	public function get_key(): string {
		return 'bluesky';
	}

	/**
	 * Returns the label of the Bluesky field.
	 *
	 * @return string The label of the Bluesky field.
	 */
	public function get_label(): string {
		return __( 'Bluesky profile URL', 'newspack-plugin' );
	}
}
