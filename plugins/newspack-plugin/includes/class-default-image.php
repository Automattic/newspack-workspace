<?php
/**
 * Handles Default Image functionality.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Handles Default Image functionality.
 */
class Default_Image {
	/**
	 * Form field name.
	 */
	const FIELD_NAME = 'is_default_image';

	/**
	 * Option name.
	 */
	const OPTION_NAME = 'newspack_default_image_url';

	/**
	 * Add hooks.
	 */
	public static function init() {
		add_action( 'template_redirect', [ __CLASS__, 'handle_not_found_image' ] );
		add_action( 'wp_footer', [ __CLASS__, 'add_404_image_handler_function' ] );
	}

	/**
	 * Handle not found image.
	 */
	public static function handle_not_found_image() {
		if ( ! is_404() || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$requested_url = $_SERVER['REQUEST_URI']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( preg_match( '/\.(jpg|jpeg|png|gif|webp)$/i', $requested_url ) ) {
			$default_image_url = get_option( self::OPTION_NAME );
			if ( ! empty( $default_image_url ) ) {
				wp_safe_redirect( $default_image_url, 301 );
				exit;
			}
		}
	}

	/**
	 * Add JavaScript function to handle 404 images.
	 * If an image is served from a different domain, the `template_redirect` action won't catch a 404.
	 * Adding a JS fallback ensures all images will have the default applied.
	 */
	public static function add_404_image_handler_function() {
		$default_image_url = get_option( self::OPTION_NAME );
		if ( ! empty( $default_image_url ) ) {
			?>
				<script>
					document.querySelectorAll('.content-area img').forEach(function(imgEl) {
						imgEl.addEventListener('error', function(){
							if (!imgEl.hasAttribute('data-404-handled')) {
								imgEl.setAttribute('data-404-handled', 'true');
								imgEl.src = "<?php echo esc_js( $default_image_url ); ?>";
								imgEl.removeAttribute('srcset');
							}
						})
					})
				</script>
			<?php

		}
	}
}
Default_Image::init();
