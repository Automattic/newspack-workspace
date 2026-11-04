<?php
/**
 * Republish button for Jetpack's sharing buttons.
 *
 * Loaded from the `sharing_services` filter, because `Sharing_Source` only
 * exists once Jetpack's Sharing module has loaded.
 *
 * @package Republication_Tracker_Tool
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Sharing_Source' ) ) {
	return;
}

/**
 * Jetpack sharing source that opens the republish modal.
 */
class Republication_Tracker_Tool_Jetpack_Share_Republish extends Sharing_Source {

	/**
	 * Service short name.
	 *
	 * @var string
	 */
	public $shortname = Republication_Tracker_Tool_Jetpack_Sharing::SERVICE;

	/**
	 * Constructor.
	 *
	 * @param string $id       Sharing source ID.
	 * @param array  $settings Sharing settings.
	 */
	public function __construct( $id, array $settings ) {
		parent::__construct( $id, $settings );

		// The parent sets this from a filter that defaults to true, and the link opens a modal on the same page.
		$this->open_link_in_new = false;
	}

	/**
	 * Service name, shown in Jetpack's sharing settings.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Republish', 'republication-tracker-tool' );
	}

	/**
	 * Service class.
	 *
	 * @return string
	 */
	public function get_class() {
		// Jetpack's settings preview drops buttons without an official widget when the style is "Official buttons", except Print.
		if ( is_admin() ) {
			return $this->id . ' preview-print';
		}
		return $this->id;
	}

	/**
	 * Markup of the sharing button.
	 *
	 * @param WP_Post $post The post object.
	 * @return string
	 */
	public function get_display( $post ) {
		if ( ! Republication_Tracker_Tool_Jetpack_Sharing::should_display( $post ) ) {
			return '';
		}

		Republication_Tracker_Tool_Jetpack_Sharing::queue_modal( $post );

		$link = $this->get_link(
			get_permalink( $post ) . '#show-republish',
			_x( 'Republish', 'Jetpack sharing button', 'republication-tracker-tool' ),
			__( 'Republish this article', 'republication-tracker-tool' ),
			'',
			'sharing-republish-' . $post->ID,
			[ 'modal-trigger' => 'republish' ]
		);

		return $this->add_icon( $link );
	}

	/**
	 * The republish modal is not available on AMP pages.
	 *
	 * @param WP_Post $post The post object.
	 * @return false
	 */
	public function get_amp_display( $post ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return false;
	}

	/**
	 * Markup of the button in Jetpack's sharing settings preview.
	 *
	 * @param bool        $echo         Whether to print the markup.
	 * @param bool        $force_smart  Whether to force the official button.
	 * @param string|null $button_style Button style.
	 * @return string|void
	 */
	public function display_preview( $echo = true, $force_smart = false, $button_style = null ) {
		$markup = $this->add_icon( parent::display_preview( false, $force_smart, $button_style ), $button_style );
		if ( ! $echo ) {
			return $markup;
		}
		echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Jetpack and add_icon().
	}

	/**
	 * Insert the icon at the start of a sharing link.
	 *
	 * Jetpack draws its own icons with a font in CSS and escapes the link text,
	 * so the icon is added to the markup after Jetpack builds it.
	 *
	 * @param string      $link         Sharing link markup.
	 * @param string|null $button_style Button style, defaulting to the site's.
	 * @return string
	 */
	private function add_icon( $link, $button_style = null ) {
		$button_style = $button_style ? $button_style : $this->button_style;
		if ( 'text' === $button_style ) {
			return $link;
		}
		return (string) preg_replace( '/<a\b[^>]*>/', '$0' . Republication_Tracker_Tool_Jetpack_Sharing::get_icon(), $link, 1 );
	}
}
