<?php
/**
 * Server-side rendering of the `newspack-blocks/donate` block.
 *
 * @package WordPress
 */

defined( 'ABSPATH' ) || exit;

require_once NEWSPACK_BLOCKS__PLUGIN_DIR . 'src/blocks/donate/frontend/class-newspack-blocks-donate-renderer-frequency-based.php';
require_once NEWSPACK_BLOCKS__PLUGIN_DIR . 'src/blocks/donate/frontend/class-newspack-blocks-donate-renderer-tiers-based.php';

/**
 * Server-side rendering of the `newspack-blocks/donate` block.
 */
class Newspack_Blocks_Donate_Renderer {

	/**
	 * Get the keys of the billing fields to render for logged out users.
	 *
	 * @return array
	 */
	public static function get_billing_fields_keys() {
		$fields = [
			'billing_first_name',
			'billing_last_name',
			'billing_email',
		];
		/**
		 * Filters the billing fields used on modal checkout.
		 *
		 * @param array $fields Billing fields.
		 */
		return apply_filters( 'newspack_blocks_donate_billing_fields_keys', $fields );
	}

	/**
	 * Enqueue frontend scripts and styles.
	 *
	 * @param string $handle_slug The slug of the script to enqueue.
	 * @param array  $dependencies The dependencies of the script to enqueue.
	 */
	private static function enqueue_scripts( $handle_slug, $dependencies = [ 'wp-i18n' ] ) {
		$has_css = true;
		switch ( $handle_slug ) {
			case 'frequency-based':
				$filename = 'frequencyBased';
				break;
			case 'tiers-based':
				$filename = 'tiersBased';
				break;
			default:
				$filename = false;
				break;
		}

		if ( false === $filename ) {
			return;
		}

		$handle      = Newspack_Blocks::SCRIPT_HANDLES[ $handle_slug ];
		$script_data = Newspack_Blocks::script_enqueue_helper( NEWSPACK_BLOCKS__BLOCKS_DIRECTORY . '/' . $filename . '.js' );
		wp_enqueue_script(
			$handle,
			$script_data['script_path'],
			$dependencies,
			NEWSPACK_BLOCKS__VERSION,
			true
		);
		wp_script_add_data( $handle, 'async', true );

		if ( $has_css ) {
			$style_path = NEWSPACK_BLOCKS__BLOCKS_DIRECTORY . $filename . '.css';
			wp_enqueue_style(
				$handle,
				plugins_url( $style_path, NEWSPACK_BLOCKS__PLUGIN_FILE ),
				[],
				NEWSPACK_BLOCKS__VERSION
			);
			wp_style_add_data( $handle, 'rtl', 'replace' );
		}
	}

	/**
	 * Renders the `newspack-blocks/donate` block on server.
	 *
	 * @param array $attributes The block attributes.
	 *
	 * @return string
	 */
	public static function render( $attributes ) {
		if ( ! class_exists( 'Newspack\Donations' ) ) {
			return '';
		}

		$configuration = Newspack_Blocks_Donate_Renderer_Frequency_Based::get_configuration( $attributes );
		if ( \is_wp_error( $configuration ) ) {
			return '';
		}

		Newspack_Blocks::enqueue_view_assets( 'donate' );
		wp_script_add_data( 'newspack-blocks-donate', 'async', true );

		if ( true === $attributes['useModalCheckout'] ) {
			\Newspack_Blocks\Modal_Checkout::enqueue_modal();
		}

		if ( $configuration['is_tier_based_layout'] ) {
			self::enqueue_scripts( 'tiers-based' );
			return Newspack_Blocks_Donate_Renderer_Tiers_Based::render( $attributes );
		} else {
			self::enqueue_scripts( 'frequency-based' );
			return Newspack_Blocks_Donate_Renderer_Frequency_Based::render( $attributes );
		}
	}
}
new Newspack_Blocks_Donate_Renderer();
