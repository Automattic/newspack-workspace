<?php
/**
 * Minimal stand-in for Jetpack's Sharing_Source, which is not loaded in tests.
 *
 * Mirrors only what the Republish sharing source relies on: the constructor,
 * `get_link()` with its `data-` attributes, and `display_preview()`.
 *
 * @package Republication_Tracker_Tool
 */

if ( class_exists( 'Sharing_Source' ) ) {
	return;
}

/**
 * Jetpack Sharing_Source mock.
 */
abstract class Sharing_Source {

	/**
	 * Button style.
	 *
	 * @var string
	 */
	public $button_style = 'icon-text';

	/**
	 * Whether links open in a new window.
	 *
	 * @var bool
	 */
	public $open_link_in_new;

	/**
	 * Whether to use the official button.
	 *
	 * @var bool
	 */
	public $smart = false;

	/**
	 * Service ID.
	 *
	 * @var string
	 */
	protected $id;

	/**
	 * Constructor.
	 *
	 * @param string $id       Service ID.
	 * @param array  $settings Settings.
	 */
	public function __construct( $id, array $settings ) {
		$this->id               = $id;
		$this->open_link_in_new = true;
		if ( isset( $settings['button_style'] ) ) {
			$this->button_style = $settings['button_style'];
		}
	}

	/**
	 * Service ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Service name.
	 *
	 * @return string
	 */
	abstract public function get_name();

	/**
	 * Sharing link markup.
	 *
	 * @param string $url             URL.
	 * @param string $text            Text.
	 * @param string $accessible_name Accessible name.
	 * @param string $query           Query string.
	 * @param string $id              ID.
	 * @param array  $data_attributes Data attributes.
	 * @return string
	 */
	public function get_link( $url, $text, $accessible_name = '', $query = '', $id = false, $data_attributes = [] ) {
		$data = '';
		foreach ( $data_attributes as $key => $value ) {
			$data .= sprintf( ' data-%s="%s"', esc_attr( $key ), esc_attr( $value ) );
		}
		return sprintf(
			'<a class="share-%1$s sd-button" href="%2$s"%3$s%4$s><span id="%5$s" hidden>%6$s</span><span>%7$s</span></a>',
			esc_attr( $this->get_class() ),
			esc_url( $url ),
			$this->open_link_in_new ? ' target="_blank"' : '',
			$data,
			esc_attr( (string) $id ),
			esc_html( $accessible_name ),
			esc_html( $text )
		);
	}

	/**
	 * Service class.
	 *
	 * @return string
	 */
	public function get_class() {
		return $this->id;
	}

	/**
	 * Settings preview markup.
	 *
	 * @param bool        $echo         Whether to print.
	 * @param bool        $force_smart  Whether to force the official button.
	 * @param string|null $button_style Button style.
	 * @return string|void
	 */
	public function display_preview( $echo = true, $force_smart = false, $button_style = null ) {
		$markup = sprintf( '<a class="share-%s sd-button" href="javascript:void(0)"><span>%s</span></a>', esc_attr( $this->get_class() ), esc_html( $this->get_name() ) );
		if ( ! $echo ) {
			return $markup;
		}
		echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
