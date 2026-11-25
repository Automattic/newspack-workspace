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
				<path d="M12 4.75C7.99594 4.75 4.75 7.99593 4.75 12C4.75 16.0041 7.99593 19.25 12 19.25C16.0041 19.25 19.25 16.0041 19.25 12C19.25 7.99594 16.0041 4.75 12 4.75Z" stroke-linejoin="round" />
				<path d="M13.75 10C13.75 10.9665 12.9665 11.75 12 11.75C11.0335 11.75 10.25 10.9665 10.25 10C10.25 9.0335 11.0335 8.25 12 8.25C12.9665 8.25 13.75 9.0335 13.75 10Z" />
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
				<path d="M12.5 4H6C5.30964 4 4.75 4.5596 4.75 5.25V10.541V12.6662C4.75 13.1282 5.32306 13.343 5.6268 12.9948L7.76724 10.541H12.5C13.1904 10.541 13.75 9.9813 13.75 9.291V5.25C13.75 4.5596 13.1904 4 12.5 4Z" />
				<path d="M12 13H18.5C19.1904 13 19.75 13.5596 19.75 14.25V19.541V21.6662C19.75 22.1282 19.1769 22.343 18.8732 21.9948L16.7328 19.541H12C11.3096 19.541 10.75 18.9813 10.75 18.291V14.25C10.75 13.5596 11.3096 13 12 13Z" />
			</svg>',
		'copy'             =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--copy">
				<path d="M7.75 19.25H18C18.6904 19.25 19.25 18.6904 19.25 18V7.75" />
				<path d="M6 16.25H15C15.6904 16.25 16.25 15.6904 16.25 15V6C16.25 5.30964 15.6904 4.75 15 4.75L6 4.75C5.30964 4.75 4.75 5.30964 4.75 6L4.75 15C4.75 15.6904 5.30964 16.25 6 16.25Z" />
			</svg>',
		'curatedList'      =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--curated-list">
				<path d="M18.5 6C18.5 5.72384 18.2762 5.5 18 5.5H6C5.72385 5.5 5.5 5.72385 5.5 6V18C5.5 18.2762 5.72384 18.5 6 18.5H18C18.2762 18.5 18.5 18.2762 18.5 18V6ZM8.5 13.25V14.75H7V13.25H8.5ZM17 13.25V14.75H10V13.25H17ZM8.5 9.25V10.75H7V9.25H8.5ZM17 9.25V10.75H10V9.25H17ZM20 18C20 19.1046 19.1046 20 18 20H6C4.89544 20 4 19.1046 4 18V6C4 4.89543 4.89543 4 6 4H18C19.1046 4 20 4.89544 20 6V18Z" />
			</svg>',
		'edit'             =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--edit">
				<path d="M5 19.25H12" />
				<path d="M10 15L7.5 15.5L8 13L16 5L18 7L10 15Z" fill="currentColor" />
			</svg>',
		'email'            =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--email">
				<path d="M19.5 9.97266L12 15.4277L4.5 9.97266V17C4.5 17.2762 4.72384 17.5 5 17.5H19C19.2762 17.5 19.5 17.2762 19.5 17V9.97266ZM19.5 7C19.5 6.72384 19.2762 6.5 19 6.5H5C4.72385 6.5 4.5 6.72385 4.5 7V8.11719L12 13.5723L19.5 8.11719V7ZM21 17C21 18.1046 20.1046 19 19 19H5C3.89544 19 3 18.1046 3 17V7C3 5.89543 3.89543 5 5 5H19C20.1046 5 21 5.89544 21 7V17Z" />
			</svg>',
		'emailSend'        =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--email-send">
				<path d="M4.75 13V18L19 12L4.75 6V11M4.75 13L7.5 12L4.75 11M4.75 13V11" />
			</svg>',
		'error'            =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--error">
				<path d="M12 9L12 14M12 15.5V17M11.1284 5.04947L3.83827 18.0097C3.46331 18.6763 3.94502 19.5 4.70985 19.5H19.2902C20.055 19.5 20.5367 18.6763 20.1617 18.0097L12.8716 5.04947C12.4893 4.36982 11.5107 4.36982 11.1284 5.04947Z" />
			</svg>',
		'gift'             =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--gift">
				<path d="M12 19.25H18C18.6904 19.25 19.25 18.6904 19.25 18V10C19.25 9.30964 18.6904 8.75 18 8.75H12M12 19.25H6C5.30964 19.25 4.75 18.6904 4.75 18V10C4.75 9.30964 5.30964 8.75 6 8.75H12M12 19.25V8.75M12 8.75V6.75M12 8.75H9.5C8.11929 8.75 7 7.85457 7 6.75C7 5.64543 8.11929 4.75 9.5 4.75C10.8807 4.75 12 5.64543 12 6.75M12 8.75H14.5C15.8807 8.75 17 7.85457 17 6.75C17 5.64543 15.8807 4.75 14.5 4.75C13.1193 4.75 12 5.64543 12 6.75" />
			</svg>',
		'globe'            =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--globe">
				<path d="M8.25 12C8.25 11.4039 8.30872 10.8175 8.40918 10.25H5.73926C5.58415 10.8062 5.5 11.3927 5.5 12C5.5 12.6072 5.58415 13.1938 5.73926 13.75H8.40918C8.30872 13.1825 8.25 12.5961 8.25 12ZM6.37109 15.25C7.2002 16.6828 8.55927 17.7694 10.1807 18.2412C9.92651 17.8461 9.67691 17.4044 9.44922 16.9316C9.20188 16.418 8.97179 15.8525 8.78027 15.25H6.37109ZM15.2197 15.25C15.0282 15.8525 14.7981 16.418 14.5508 16.9316C14.323 17.4046 14.0726 17.8459 13.8184 18.2412C15.4402 17.7695 16.7997 16.6831 17.6289 15.25H15.2197ZM10.3662 15.25C10.4983 15.6105 10.6442 15.9562 10.8008 16.2812C11.2034 17.1172 11.645 17.7707 12 18.1943C12.355 17.7707 12.7966 17.1172 13.1992 16.2812C13.3558 15.9562 13.5017 15.6105 13.6338 15.25H10.3662ZM13.8184 5.75781C14.0728 6.15325 14.3229 6.59513 14.5508 7.06836C14.7981 7.58198 15.0282 8.14752 15.2197 8.75H17.6289C16.7996 7.31682 15.4403 6.22946 13.8184 5.75781ZM12 5.80469C11.6449 6.22828 11.2036 6.88242 10.8008 7.71875C10.6442 8.04381 10.4983 8.38951 10.3662 8.75H13.6338C13.5017 8.38951 13.3558 8.04381 13.1992 7.71875C12.7964 6.88242 12.3551 6.22828 12 5.80469ZM10.1807 5.75781C8.55916 6.2296 7.20024 7.3171 6.37109 8.75H8.78027C8.97179 8.14752 9.20188 7.58198 9.44922 7.06836C9.67704 6.5953 9.92636 6.15314 10.1807 5.75781ZM9.75 12C9.75 12.5925 9.81837 13.1807 9.93555 13.75H14.0645C14.1816 13.1807 14.25 12.5925 14.25 12C14.25 11.4075 14.1816 10.8193 14.0645 10.25H9.93555C9.81837 10.8193 9.75 11.4075 9.75 12ZM15.75 12C15.75 12.5961 15.6913 13.1825 15.5908 13.75H18.2607C18.4159 13.1938 18.5 12.6072 18.5 12C18.5 11.3928 18.4159 10.8062 18.2607 10.25H15.5908C15.6913 10.8175 15.75 11.4039 15.75 12ZM20 12C20 12.9681 19.828 13.8979 19.5117 14.7588C18.3884 17.8167 15.45 20 12 20C8.55005 20 5.61166 17.8167 4.48828 14.7588C4.17202 13.8979 4 12.9681 4 12C4 11.0319 4.17201 10.1021 4.48828 9.24121C5.61166 6.1833 8.55005 4 12 4C15.45 4 18.3884 6.1833 19.5117 9.24121C19.828 10.1021 20 11.0319 20 12Z" />
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
				<path d="M11.75 12C11.75 13.7949 10.2949 15.25 8.5 15.25C6.70507 15.25 5.25 13.7949 5.25 12C5.25 10.2051 6.70507 8.75 8.5 8.75C10.2949 8.75 11.75 10.2051 11.75 12ZM11.75 12H15.75M19 12H16.25M15.75 12V15H16.25V12M15.75 12H16.25" />
			</svg>',
		'lineSolid'        =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--line-solid">
				<path d="M5 12H19" />
			</svg>',
		'login'            =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--login">
				<path d="M7.75 6.5V6C7.75 5.30964 8.30964 4.75 9 4.75H18C18.6904 4.75 19.25 5.30964 19.25 6V18C19.25 18.6904 18.6904 19.25 18 19.25H9C8.30964 19.25 7.75 18.6904 7.75 18V17.5M5 12H14.5M11.5 15L14.5 12L11.5 9" />
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
				<path d="M11.75 15C11.75 14.3096 11.1904 13.75 10.5 13.75H6.5C5.80964 13.75 5.25 14.3096 5.25 15V17H3.75V15C3.75 13.4812 4.98122 12.25 6.5 12.25H10.5C12.0188 12.25 13.25 13.4812 13.25 15V17H11.75V15ZM18.75 15C18.75 14.3096 18.1904 13.75 17.5 13.75H15V12.25H17.5C19.0188 12.25 20.25 13.4812 20.25 15V17H18.75V15ZM9.5 8.5C9.5 7.94771 9.05229 7.5 8.5 7.5C7.94771 7.5 7.5 7.94771 7.5 8.5C7.5 9.05229 7.94771 9.5 8.5 9.5C9.05229 9.5 9.5 9.05229 9.5 8.5ZM16.5 8.5C16.5 7.94771 16.0523 7.5 15.5 7.5C14.9477 7.5 14.5 7.94771 14.5 8.5C14.5 9.05229 14.9477 9.5 15.5 9.5C16.0523 9.5 16.5 9.05229 16.5 8.5ZM11 8.5C11 9.88071 9.88071 11 8.5 11C7.11929 11 6 9.88071 6 8.5C6 7.11929 7.11929 6 8.5 6C9.88071 6 11 7.11929 11 8.5ZM18 8.5C18 9.88071 16.8807 11 15.5 11C14.1193 11 13 9.88071 13 8.5C13 7.11929 14.1193 6 15.5 6C16.8807 6 18 7.11929 18 8.5Z" />
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
				<path d="M8 21L10.6587 15.1842M13.1523 9.72929L13.8553 8.19144L16 3.5M20.0645 13C19.2118 11.2841 17.8276 9.87898 16.1272 9M3.93555 13C5.40825 10.0366 8.46631 8 12 8C12.636 8 13.2566 8.06597 13.8553 8.19144M13.1523 9.72929C12.7975 9.58156 12.4083 9.5 12 9.5C10.3431 9.5 9 10.8431 9 12.5C9 13.6747 9.67519 14.6917 10.6587 15.1842M13.1523 9.72929L10.6587 15.1842M13.1755 15.261C14.2481 14.8037 15 13.7397 15 12.5C15 12.1819 14.9505 11.8753 14.8587 11.5876" />
				<path d="M12 9.5C10.3431 9.5 9 10.8431 9 12.5C9 13.6747 9.67519 14.6917 10.6587 15.1842L13.1523 9.72929C12.7975 9.58156 12.4083 9.5 12 9.5Z" fill="currentColor" />
			</svg>',
	);
}
