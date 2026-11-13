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

		if ( 'text' === $this->button_style ) {
			return $link;
		}
		return $this->add_icon( $link );
	}

	/**
	 * Load the button's styles in the head, so the icon is styled before the
	 * stylesheet the button itself would enqueue lands in the footer.
	 */
	public function display_header() {
		if ( Republication_Tracker_Tool_Jetpack_Sharing::should_display( get_queried_object() ) ) {
			Republication_Tracker_Tool_Jetpack_Sharing::enqueue_style();
		}
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
		// Jetpack switches the preview between styles in the browser, so the icon is always there and the styles hide it for Text only.
		$markup = $this->add_icon( parent::display_preview( false, $force_smart, $button_style ) );
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
	 * @param string $link Sharing link markup.
	 * @return string
	 */
	private function add_icon( $link ) {
		$icon = Republication_Tracker_Tool_Jetpack_Sharing::get_icon();
		return (string) preg_replace_callback(
			'/<a\b[^>]*>/',
			function ( $matches ) use ( $icon ) {
				return $matches[0] . $icon;
			},
			$link,
			1
		);
	}
}
