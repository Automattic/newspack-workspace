<?php
/**
 * SVG Icons class
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Returns HTML markup for SVG icons, based on name.
 */
class Newspack_UI_Icons {

	/**
	 * Gets the SVG code for a given icon.
	 *
	 * @param string $icon Name of the icon to render.
	 */
	public static function get_svg( $icon ) {
		$arr = self::$ui_icons;
		if ( array_key_exists( $icon, $arr ) ) {
			return $arr[ $icon ];
		}
		return null;
	}

	/**
	 * Sanitizes the SVG code for a given icon
	 *
	 * @param string $icon Name of the icon to render.
	 */
	public static function print_svg( $icon ) {
		$svg = self::get_svg( $icon );
		if ( null === $svg ) {
			return;
		}
		echo wp_kses( $svg, self::sanitize_svgs() );
	}

	/**
	 * Returns an array of 'acceptable' SVG tags to use with wp_kses().
	 */
	public static function sanitize_svgs() {
		$svg_args = array(
			'svg'      => array(
				'class'           => true,
				'aria-hidden'     => true,
				'aria-labelledby' => true,
				'role'            => true,
				'xmlns'           => true,
				'width'           => true,
				'height'          => true,
				'viewbox'         => true,
				'style'           => true,
				'stroke'          => true,
				'stroke-width'    => true,
			),
			'g'        => array(
				'fill'      => true,
				'fill-rule' => true,
			),
			'title'    => array(
				'title' => true,
			),
			'path'     => array(
				'd'               => true,
				'fill'            => true,
				'fill-rule'       => true,
				'stroke'          => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
			),
			'circle'   => array(
				'cx'   => true,
				'cy'   => true,
				'r'    => true,
				'fill' => true,
			),
			'defs'     => true,
			'clipPath' => true,
			'polygon'  => array(
				'points' => true,
			),
		);

		return $svg_args;
	}

	/**
	 * User Interface icons – svg sources.
	 *
	 * @var array
	 */
	public static $ui_icons = array(
		'account'          =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--account">
				<path d="M8 18.0476V16C8 14.8954 8.89543 14 10 14H14C15.1046 14 16 14.8954 16 16V18.0476" />
				<path d="M12 4.75C7.99594 4.75 4.75 7.99593 4.75 12C4.75 16.0041 7.99593 19.25 12 19.25C16.0041 19.25 19.25 16.0041 19.25 12C19.25 7.99594 16.0041 4.75 12 4.75Z" />
				<path d="M14 10a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z" fill="currentColor" stroke="none" />
			</svg>',
		'ad'               =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--ad">
				<path d="M19 5H5C4.46957 5 3.96086 5.21071 3.58579 5.58579C3.21071 5.96086 3 6.46957 3 7V17C3 17.5304 3.21071 18.0391 3.58579 18.4142C3.96086 18.7893 4.46957 19 5 19H19C19.5304 19 20.0391 18.7893 20.4142 18.4142C20.7893 18.0391 21 17.5304 21 17V7C21 6.46957 20.7893 5.96086 20.4142 5.58579C20.0391 5.21071 19.5304 5 19 5ZM19.5 17C19.5 17.1326 19.4473 17.2598 19.3536 17.3536C19.2598 17.4473 19.1326 17.5 19 17.5H5C4.86739 17.5 4.74021 17.4473 4.64645 17.3536C4.55268 17.2598 4.5 17.1326 4.5 17V7C4.5 6.86739 4.55268 6.74021 4.64645 6.64645C4.74021 6.55268 4.86739 6.5 5 6.5H19C19.1326 6.5 19.2598 6.55268 19.3536 6.64645C19.4473 6.74021 19.5 6.86739 19.5 7V17ZM16.007 9.736C15.568 9.50644 15.0783 9.3909 14.583 9.4H12.487V15H14.583C15.116 15 15.591 14.89 16.007 14.668C16.423 14.444 16.75 14.124 16.987 13.708C17.224 13.289 17.343 12.787 17.343 12.2C17.343 11.613 17.224 11.112 16.987 10.696C16.7603 10.2877 16.4198 9.95421 16.007 9.736ZM16.039 13.208C15.916 13.491 15.734 13.708 15.491 13.86C15.248 14.012 14.946 14.088 14.583 14.088H13.511V10.312H14.583C14.946 10.312 15.248 10.388 15.491 10.54C15.734 10.692 15.916 10.91 16.039 11.192C16.162 11.475 16.223 11.811 16.223 12.2C16.223 12.589 16.162 12.925 16.039 13.208ZM8.387 9.4L6.395 15H7.387L7.854 13.672H9.889L10.355 15H11.347L9.355 9.4H8.387ZM8.168 12.776L8.871 10.776L9.574 12.776H8.168Z" />
			</svg>',
		'arrowLeft'        =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--arrow-left">
				<path d="M20 12L5 12M10 7L5 12L10 17" />
			</svg>',
		'arrowRight'       =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--arrow-right">
				<path d="M4 12L19 12M14 17L19 12L14 7" />
			</svg>',
		'caution'          =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--caution">
				<path d="M12 8L12 13M12 14.5L12 16M4.75 12C4.75 7.99593 7.99594 4.75 12 4.75C16.0041 4.75 19.25 7.99594 19.25 12C19.25 16.0041 16.0041 19.25 12 19.25C7.99594 19.25 4.75 16.0041 4.75 12Z" stroke-linejoin="round" />
			</svg>',
		'chartReport'      =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--chart-report">
				<path d="M4.75 6C4.75 5.30964 5.30964 4.75 6 4.75H18C18.6904 4.75 19.25 5.30964 19.25 6V15C19.25 15.6904 18.6904 16.25 18 16.25H6C5.30964 16.25 4.75 15.6904 4.75 15V6Z" />
				<path d="M10.5 16.25L8.75 19.25M13.5 16.25L15.25 19.25" stroke-linecap="round" />
				<path d="M8.75 13V9.75M12 13V8M15.251 13V11" />
			</svg>',
		'check'            =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--check">
				<path d="M7 12L10 15L17 8" />
			</svg>',
		'chevronDown'      =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--chevron-down">
				<path d="M7 11L12 15L17 11" />
			</svg>',
		'chevronDownSmall' =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--chevron-down-small">
				<path d="M8.5 10.5L12 13.5L15.5 10.5" />
			</svg>',
		'chevronLeft'      =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--chevron-left">
				<path d="M14 6.5L9 12L14 17.5" />
			</svg>',
		'chevronRight'     =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--chevron-right">
				<path d="M10 6.5L15 12L10 17.5" />
			</svg>',
		'close'            =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--close">
				<path d="M5 19L19 5M19 19L5 5" />
			</svg>',
		'closeSmall'       =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--close-small">
				<path d="M7.75 16.25L16.25 7.75M16.25 16.25L7.75 7.75" />
			</svg>',
		'collections'      =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--collections">
				<path d="M5.5 17.0009V18.5009H7.5V17.0009H5.5ZM5.5 20.0009C4.67188 20.0009 4 19.329 4 18.5009V17.0009V16.2509V15.5009V8.50092V7.75092V7.00092V5.50092C4 4.6728 4.67188 4.00092 5.5 4.00092C7.33333 4.00092 9.16667 4.00092 11 4.00092C11.6438 4.00092 12.1906 4.40405 12.4062 4.9728C12.5813 4.78217 12.8094 4.63842 13.075 4.56655L14.9344 4.05092C15.7063 3.83842 16.5 4.3103 16.7063 5.10717L17.2688 7.26967L17.4563 7.99467L19.3875 15.4415L19.575 16.1665L19.9469 17.604C20.1531 18.4009 19.6969 19.2197 18.925 19.4322L17.0625 19.9478C16.2906 20.1603 15.4969 19.6884 15.2906 18.8915L14.7281 16.729L14.5406 16.004L12.6125 8.5603L12.5 8.13217V8.50092V15.5009V16.2509V17.0009V18.5009C12.5 19.329 11.8281 20.0009 11 20.0009C9.08318 20.0009 7.41682 20.0009 5.5 20.0009ZM9 18.5009H11V17.0009H9V18.5009ZM7.5 5.50092H5.5V7.00092H7.5V5.50092ZM7.5 8.50092H5.5V15.5009H7.5V8.50092ZM9 7.00092H11V5.50092H9V7.00092ZM11 15.5009V8.50092H9V15.5009H11ZM17.7531 15.1165L16.0094 8.3978L14.2437 8.8853L15.9875 15.604L17.7531 15.1165ZM16.3656 17.0572L16.7375 18.4853L18.5 17.9978C18.5 17.9947 18.5 17.9915 18.5 17.9884V17.9853L18.1344 16.5728L16.3687 17.0603L16.3656 17.0572ZM13.8687 7.43217L15.6344 6.94467L15.2625 5.51655L13.5 6.00405C13.5 6.00717 13.5 6.0103 13.5 6.01655L13.8656 7.42905L13.8687 7.43217Z" />
			</svg>',
		'comments'         =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--comments">
				<path d="M7 10.35H13A0.25 0.25 0 0 0 13.25 10.1V4A0.25 0.25 0 0 0 13 3.75H5A0.25 0.25 0 0 0 4.75 4V12.6ZM17 19.35H11A0.25 0.25 0 0 1 10.75 19.1V13A0.25 0.25 0 0 1 11 12.75H19A0.25 0.25 0 0 1 19.25 13V21.6Z" stroke-linejoin="round" />
			</svg>',
		'copy'             =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--copy">
				<path d="M5.625 4.75H15.375C15.858 4.75 16.25 5.142 16.25 5.625V15.375C16.25 15.858 15.858 16.25 15.375 16.25H5.625C5.142 16.25 4.75 15.858 4.75 15.375V5.625C4.75 5.142 5.142 4.75 5.625 4.75Z" />
				<path d="M19.25 8.281V17.281C19.25 18.386 18.356 19.25 17.251 19.25H6.25" />
			</svg>',
		'curatedList'      =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--curated-list">
				<path d="M5.5 7.5H7.5V9.5H5.5V7.5ZM5.5 13.5H7.5V11.5H5.5V13.5ZM8.5 9.5H15.5V7.5H8.5V9.5ZM8.5 13.5H15.5V11.5H8.5V13.5ZM16.375 18H4.625C4.19402 18 3.7807 17.8288 3.47595 17.524C3.1712 17.2193 3 16.806 3 16.375V4.625C3 3.728 3.728 3 4.625 3H16.375C17.273 3 18 3.728 18 4.625V16.375C18 17.273 17.273 18 16.375 18ZM4.625 16.5H16.375C16.4082 16.5 16.4399 16.4868 16.4634 16.4634C16.4868 16.4399 16.5 16.4082 16.5 16.375V4.625C16.5 4.59185 16.4868 4.56005 16.4634 4.53661C16.4399 4.51317 16.4082 4.5 16.375 4.5H4.625C4.59185 4.5 4.56005 4.51317 4.53661 4.53661C4.51317 4.56005 4.5 4.59185 4.5 4.625V16.375C4.5 16.444 4.556 16.5 4.625 16.5ZM20.25 19C20.25 19.69 19.69 20.25 19.001 20.25H6V21.75H19.001C19.3621 21.75 19.7196 21.6789 20.0532 21.5406C20.3868 21.4024 20.6899 21.1999 20.9452 20.9445C21.2005 20.6891 21.4029 20.3859 21.541 20.0523C21.6791 19.7187 21.7501 19.3611 21.75 19V8H20.25V19Z" />
			</svg>',
		'edit'             =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--edit">
				<path d="M5 19.25H12" />
				<path d="M10 15L7.5 15.5L8 13L16 5L18 7L10 15Z" fill="currentColor" />
			</svg>',
		'email'            =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--email">
				<path fill-rule="evenodd" clip-rule="evenodd" d="M3 7c0-1.1.9-2 2-2h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Zm2-.5h14c.3 0 .5.2.5.5v1L12 13.5 4.5 7.9V7c0-.3.2-.5.5-.5Zm-.5 3.3V17c0 .3.2.5.5.5h14c.3 0 .5-.2.5-.5V9.8L12 15.4 4.5 9.8Z" />
			</svg>',
		'emailSend'        =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--email-send">
				<path d="M8.25 12L4.75 4.75L19.25 12L4.75 19.25Z" stroke-linejoin="round" />
				<path d="M8.25 12H12" />
			</svg>',
		'error'            =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--error">
				<path d="M12 9L12 14M12 15.5V17M11.1284 5.04947L3.83827 18.0097C3.46331 18.6763 3.94502 19.5 4.70985 19.5H19.2902C20.055 19.5 20.5367 18.6763 20.1617 18.0097L12.8716 5.04947C12.4893 4.36982 11.5107 4.36982 11.1284 5.04947Z" />
			</svg>',
		'gift'             =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--gift">
				<path d="M4.75 9.75H19.25V19.25H4.75Z" />
				<path d="M12.25 9.75V19.25" />
				<path d="M9.75 8.25A1.75 1.75 0 1 1 11.5 6.5V8.25Z" />
				<path d="M14.75 8.25A1.75 1.75 0 1 0 13 6.5V8.25Z" />
			</svg>',
		'globe'            =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--globe">
				<path d="M12 4c-4.4 0-8 3.6-8 8s3.6 8 8 8 8-3.6 8-8-3.6-8-8-8Zm6.5 8c0 .6 0 1.2-.2 1.8h-2.7c0-.6.2-1.1.2-1.8s0-1.2-.2-1.8h2.7c.2.6.2 1.1.2 1.8Zm-.9-3.2h-2.4c-.3-.9-.7-1.8-1.1-2.4-.1-.2-.2-.4-.3-.5 1.6.5 3 1.6 3.8 3ZM12.8 17c-.3.5-.6 1-.8 1.3-.2-.3-.5-.8-.8-1.3-.3-.5-.6-1.1-.8-1.7h3.3c-.2.6-.5 1.2-.8 1.7Zm-2.9-3.2c-.1-.6-.2-1.1-.2-1.8s0-1.2.2-1.8H14c.1.6.2 1.1.2 1.8s0 1.2-.2 1.8H9.9ZM11.2 7c.3-.5.6-1 .8-1.3.2.3.5.8.8 1.3.3.5.6 1.1.8 1.7h-3.3c.2-.6.5-1.2.8-1.7Zm-1-1.2c-.1.2-.2.3-.3.5-.4.7-.8 1.5-1.1 2.4H6.4c.8-1.4 2.2-2.5 3.8-3Zm-1.8 8H5.7c-.2-.6-.2-1.1-.2-1.8s0-1.2.2-1.8h2.7c0 .6-.2 1.1-.2 1.8s0 1.2.2 1.8Zm-2 1.4h2.4c.3.9.7 1.8 1.1 2.4.1.2.2.4.3.5-1.6-.5-3-1.6-3.8-3Zm7.4 3c.1-.2.2-.3.3-.5.4-.7.8-1.5 1.1-2.4h2.4c-.8 1.4-2.2 2.5-3.8 3Z" />
			</svg>',
		'google'           =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--google">
				<path fill-rule="evenodd" clip-rule="evenodd" d="M19.6 10.227C19.6 9.51801 19.536 8.83701 19.418 8.18201H10V12.05H15.382C15.2706 12.6619 15.0363 13.2448 14.6932 13.7635C14.3501 14.2822 13.9054 14.726 13.386 15.068V17.578H16.618C18.509 15.836 19.6 13.273 19.6 10.228V10.227Z" fill="#4285F4" />
				<path fill-rule="evenodd" clip-rule="evenodd" d="M9.99996 20C12.7 20 14.964 19.105 16.618 17.577L13.386 15.068C12.491 15.668 11.346 16.023 9.99996 16.023C7.39496 16.023 5.18996 14.263 4.40496 11.9H1.06396V14.49C1.89597 16.1468 3.17234 17.5395 4.7504 18.5126C6.32846 19.4856 8.14603 20.0006 9.99996 20Z" fill="#34A853" />
				<path fill-rule="evenodd" clip-rule="evenodd" d="M4.405 11.9C4.205 11.3 4.091 10.66 4.091 10C4.091 9.34001 4.205 8.70001 4.405 8.10001V5.51001H1.064C0.364015 6.90321 -0.000359433 8.44084 2.66054e-07 10C2.66054e-07 11.614 0.386 13.14 1.064 14.49L4.404 11.9H4.405Z" fill="#FBBC05" />
				<path fill-rule="evenodd" clip-rule="evenodd" d="M9.99996 3.977C11.468 3.977 12.786 4.482 13.823 5.473L16.691 2.605C14.959 0.99 12.695 0 9.99996 0C6.08996 0 2.70996 2.24 1.06396 5.51L4.40396 8.1C5.19196 5.736 7.39596 3.977 9.99996 3.977Z" fill="#EA4335" />
			</svg>',
		'info'             =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--info">
				<path d="M12 16V11M12 9.5V8M19.25 12C19.25 16.0041 16.0041 19.25 12 19.25C7.99594 19.25 4.75 16.0041 4.75 12C4.75 7.99594 7.99594 4.75 12 4.75C16.0041 4.75 19.25 7.99594 19.25 12Z" stroke-linejoin="round" />
			</svg>',
		'key'              =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--key">
				<path d="M9.8825 10.614A4.75 4.75 0 1 1 13.383 14.117L12.25 15.25H10.25V17.25H8.25V19.25H4.75V15.7465L9.8825 10.614Z" />
				<path d="M15.4984 9.5C15.7636 9.5 16.018 9.39464 16.2055 9.20711C16.3931 9.01957 16.4984 8.76522 16.4984 8.5C16.4984 8.23478 16.3931 7.98043 16.2055 7.79289C16.018 7.60536 15.7636 7.5 15.4984 7.5C15.2332 7.5 14.9788 7.60536 14.7913 7.79289C14.6038 7.98043 14.4984 8.23478 14.4984 8.5C14.4984 8.76522 14.6038 9.01957 14.7913 9.20711C14.9788 9.39464 15.2332 9.5 15.4984 9.5Z" fill="currentColor" stroke="none" />
			</svg>',
		'lineSolid'        =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--line-solid">
				<path d="M5 12H19" />
			</svg>',
		'login'            =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--login">
				<path d="M8.55 8.5V7C8.55 6.31 9.11 5.75 9.8 5.75H16.8C17.49 5.75 18.05 6.31 18.05 7V17C18.05 17.69 17.49 18.25 16.8 18.25H9.8C9.11 18.25 8.55 17.69 8.55 17V15.5" />
				<path d="M5 12H14.5M11.5 15L14.5 12L11.5 9" />
			</svg>',
		'logout'           =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--logout">
				<path d="M14.25 8.5V7A1.25 1.25 0 0 0 13 5.75H6A1.25 1.25 0 0 0 4.75 7V17A1.25 1.25 0 0 0 6 18.25H13A1.25 1.25 0 0 0 14.25 17V15.5M8.5 12H21.5M18.5 9L21.5 12L18.5 15" />
			</svg>',
		'menu'             =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--menu">
				<path d="M5 12H19M5 18.25H19M5 5.75H19" />
			</svg>',
		'more'             =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--more">
				<path d="M11.75 18.25L12.25 18.25L12.25 17.75L11.75 17.75L11.75 18.25Z" fill="currentColor" stroke="none" />
				<path d="M11.75 12.25L12.25 12.25L12.25 11.75L11.75 11.75L11.75 12.25Z" fill="currentColor" stroke="none" />
				<path d="M11.75 6.25L12.25 6.25L12.25 5.75L11.75 5.75L11.75 6.25Z" fill="currentColor" stroke="none" />
				<path d="M11.75 18.25L12.25 18.25L12.25 17.75L11.75 17.75L11.75 18.25Z" />
				<path d="M11.75 12.25L12.25 12.25L12.25 11.75L11.75 11.75L11.75 12.25Z" />
				<path d="M11.75 6.25L12.25 6.25L12.25 5.75L11.75 5.75L11.75 6.25Z" />
			</svg>',
		'newspack'         =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--newspack">
				<path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18m1.773 13.09-4.228-4.226v4.227H7.91V7.909l8.182 8.182zm2.318-2.317-1.227-1.228h1.227zm0-2.318h-2.318l-1.228-1.228h3.546zm0-2.319h-4.636L10.227 7.91h5.864z" />
			</svg>',
		'search'           =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--search">
				<path d="M5 19L9.28769 14.7123M18.25 11C18.25 13.8995 15.8995 16.25 13 16.25C10.1005 16.25 7.75 13.8995 7.75 11C7.75 8.10051 10.1005 5.75 13 5.75C15.8995 5.75 18.25 8.10051 18.25 11Z" />
			</svg>',
		'notAllowed'       =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--not-allowed">
				<path d="M6.87347 6.87348L17.1265 17.1265M4.75 12C4.75 7.99593 7.99594 4.75 12 4.75C16.0041 4.75 19.25 7.99594 19.25 12C19.25 16.0041 16.0041 19.25 12 19.25C7.99593 19.25 4.75 16.0041 4.75 12Z" stroke-linejoin="round" />
			</svg>',
		'people'           =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--people">
				<path d="M15.5 9.5a1 1 0 100-2 1 1 0 000 2zm0 1.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5zm-2.25 6v-2a2.75 2.75 0 00-2.75-2.75h-4A2.75 2.75 0 003.75 15v2h1.5v-2c0-.69.56-1.25 1.25-1.25h4c.69 0 1.25.56 1.25 1.25v2h1.5zm7-2v2h-1.5v-2c0-.69-.56-1.25-1.25-1.25H15v-1.5h2.5A2.75 2.75 0 0120.25 15zM9.5 8.5a1 1 0 11-2 0 1 1 0 012 0zm1.5 0a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z" fill-rule="evenodd" />
			</svg>',
		'pending'          =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--pending">
				<path d="M12 16C10.9391 16 9.92172 15.5786 9.17157 14.8284C8.42143 14.0783 8 13.0609 8 12H12V8C13.0609 8 14.0783 8.42143 14.8284 9.17157C15.5786 9.92172 16 10.9391 16 12C16 13.0609 15.5786 14.0783 14.8284 14.8284C14.0783 15.5786 13.0609 16 12 16Z" fill="currentColor" fill-rule="evenodd" clip-rule="evenodd" stroke="none" />
				<path d="M12 19.25C16.0041 19.25 19.25 16.0041 19.25 12C19.25 7.99594 16.0041 4.75 12 4.75C7.99594 4.75 4.75 7.99594 4.75 12C4.75 16.0041 7.99594 19.25 12 19.25Z" stroke-linejoin="round" />
			</svg>',
		'published'        =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--published">
				<path d="M12 19.25C16.0041 19.25 19.25 16.0041 19.25 12C19.25 7.99594 16.0041 4.75 12 4.75C7.99594 4.75 4.75 7.99594 4.75 12C4.75 16.0041 7.99594 19.25 12 19.25Z" stroke-linejoin="round" />
				<path d="M9 12L11 14L15 10" />
			</svg>',
		'scheduled'        =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--scheduled">
				<path d="M12 19.25C16.0041 19.25 19.25 16.0041 19.25 12C19.25 7.99594 16.0041 4.75 12 4.75C7.99594 4.75 4.75 7.99594 4.75 12C4.75 16.0041 7.99594 19.25 12 19.25Z" stroke-linejoin="round" />
				<path d="M9.5 12.25H12.25V8" />
			</svg>',
		'seen'             =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--seen">
				<path d="M20.0645 13C18.5918 10.0366 15.5337 8 12 8C8.46631 8 5.40825 10.0366 3.93555 13" />
				<circle cx="12" cy="12.5" r="3" fill="currentColor" />
			</svg>',
		'trash'            =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--trash">
				<path d="M5 7.5H7M19 7.5H17M17 7.5L16.1588 17.1733C16.069 18.2068 15.2038 19 14.1664 19H9.83363C8.79622 19 7.93102 18.2068 7.84115 17.1733L7 7.5M17 7.5H14.9897M7 7.5H9.01027M14.9897 7.5C14.8627 5.96012 13.5727 4.75 12 4.75C10.4273 4.75 9.13731 5.96012 9.01027 7.5M14.9897 7.5H9.01027" />
			</svg>',
		'unseen'           =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--unseen">
				<path d="M8.55 19.8L10.6587 15.1842M13.1523 9.72929L13.8553 8.19144L16 3.5M20.0645 13C19.2118 11.2841 17.8276 9.87898 16.1272 9M3.93555 13C5.40825 10.0366 8.46631 8 12 8C12.636 8 13.2566 8.06597 13.8553 8.19144M13.1523 9.72929C12.7975 9.58156 12.4083 9.5 12 9.5C10.3431 9.5 9 10.8431 9 12.5C9 13.6747 9.67519 14.6917 10.6587 15.1842M13.1523 9.72929L10.6587 15.1842" />
				<path d="M12 9.5C10.3431 9.5 9 10.8431 9 12.5C9 13.6747 9.67519 14.6917 10.6587 15.1842L13.1523 9.72929C12.7975 9.58156 12.4083 9.5 12 9.5Z" fill="currentColor" />
				<path d="M12.5 16C14.2 15.8 15.5 14.3 15.5 12.5C15.5 10.7 15.3 11.1 14.9 10.6L12.4 16Z" fill="currentColor" stroke="none" />
			</svg>',
	);
}
