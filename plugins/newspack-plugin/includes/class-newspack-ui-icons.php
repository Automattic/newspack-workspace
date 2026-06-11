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
		echo wp_kses( self::get_svg( $icon ), self::sanitize_svgs() );
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
			),
			'g'        => array(
				'fill'      => true,
				'fill-rule' => true,
			),
			'title'    => array(
				'title' => true,
			),
			'path'     => array(
				'd'         => true,
				'fill'      => true,
				'fill-rule' => true,
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
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--account">
				<path fill-rule="evenodd" clip-rule="evenodd" d="M7.25 16.437a6.5 6.5 0 1 1 9.5 0V16A2.75 2.75 0 0 0 14 13.25h-4A2.75 2.75 0 0 0 7.25 16v.437Zm1.5 1.193a6.47 6.47 0 0 0 3.25.87 6.47 6.47 0 0 0 3.25-.87V16c0-.69-.56-1.25-1.25-1.25h-4c-.69 0-1.25.56-1.25 1.25v1.63ZM4 12a8 8 0 1 1 16 0 8 8 0 0 1-16 0Zm10-2a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z" />
			</svg>',
		'ad'               =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--ad">
				<path d="M19 5H5C4.46957 5 3.96086 5.21071 3.58579 5.58579C3.21071 5.96086 3 6.46957 3 7V17C3 17.5304 3.21071 18.0391 3.58579 18.4142C3.96086 18.7893 4.46957 19 5 19H19C19.5304 19 20.0391 18.7893 20.4142 18.4142C20.7893 18.0391 21 17.5304 21 17V7C21 6.46957 20.7893 5.96086 20.4142 5.58579C20.0391 5.21071 19.5304 5 19 5ZM19.5 17C19.5 17.1326 19.4473 17.2598 19.3536 17.3536C19.2598 17.4473 19.1326 17.5 19 17.5H5C4.86739 17.5 4.74021 17.4473 4.64645 17.3536C4.55268 17.2598 4.5 17.1326 4.5 17V7C4.5 6.86739 4.55268 6.74021 4.64645 6.64645C4.74021 6.55268 4.86739 6.5 5 6.5H19C19.1326 6.5 19.2598 6.55268 19.3536 6.64645C19.4473 6.74021 19.5 6.86739 19.5 7V17ZM16.007 9.736C15.568 9.50644 15.0783 9.3909 14.583 9.4H12.487V15H14.583C15.116 15 15.591 14.89 16.007 14.668C16.423 14.444 16.75 14.124 16.987 13.708C17.224 13.289 17.343 12.787 17.343 12.2C17.343 11.613 17.224 11.112 16.987 10.696C16.7603 10.2877 16.4198 9.95421 16.007 9.736ZM16.039 13.208C15.916 13.491 15.734 13.708 15.491 13.86C15.248 14.012 14.946 14.088 14.583 14.088H13.511V10.312H14.583C14.946 10.312 15.248 10.388 15.491 10.54C15.734 10.692 15.916 10.91 16.039 11.192C16.162 11.475 16.223 11.811 16.223 12.2C16.223 12.589 16.162 12.925 16.039 13.208ZM8.387 9.4L6.395 15H7.387L7.854 13.672H9.889L10.355 15H11.347L9.355 9.4H8.387ZM8.168 12.776L8.871 10.776L9.574 12.776H8.168Z" />
			</svg>',
		'arrowLeft'        =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--arrow-left">
				<path d="M20 11.2H6.8l3.7-3.7-1-1L3.9 12l5.6 5.5 1-1-3.7-3.7H20z" />
			</svg>',
		'arrowRight'       =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--arrow-right">
				<path d="m14.5 6.5-1 1 3.7 3.7H4v1.6h13.2l-3.7 3.7 1 1 5.6-5.5z" />
			</svg>',
		'check'            =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--check">
				<path d="M16.5 7.5 10 13.9l-2.5-2.4-1 1 3.5 3.6 7.5-7.6z" />
			</svg>',
		'chevronDown'      =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--chevron-down">
				<path d="M17.5 11.6L12 16l-5.5-4.4.9-1.2L12 14l4.5-3.6 1 1.2z" />
			</svg>',
		'chevronDownSmall' =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--chevron-down-small">
				<path d="m15.99 10.889-3.988 3.418-3.988-3.418.976-1.14 3.012 2.582 3.012-2.581.976 1.139Z" />
			</svg>',
		'chevronLeft'      =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--chevron-left">
				<path d="M14.6 7l-1.2-1L8 12l5.4 6 1.2-1-4.6-5z" />
			</svg>',
		'chevronRight'     =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--chevron-right">
				<path d="M10.6 6L9.4 7l4.6 5-4.6 5 1.2 1 5.4-6z" />
			</svg>',
		'close'            =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--close">
				<path d="m13.06 12 6.47-6.47-1.06-1.06L12 10.94 5.53 4.47 4.47 5.53 10.94 12l-6.47 6.47 1.06 1.06L12 13.06l6.47 6.47 1.06-1.06L13.06 12Z" />
			</svg>',
		'closeSmall'       =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--close-small">
				<path d="M12 13.06l3.712 3.713 1.061-1.06L13.061 12l3.712-3.712-1.06-1.06L12 10.938 8.288 7.227l-1.061 1.06L10.939 12l-3.712 3.712 1.06 1.061L12 13.061z" />
			</svg>',
		'collections'      =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--collections">
				<path d="M5.5 17.0009V18.5009H7.5V17.0009H5.5ZM5.5 20.0009C4.67188 20.0009 4 19.329 4 18.5009V17.0009V16.2509V15.5009V8.50092V7.75092V7.00092V5.50092C4 4.6728 4.67188 4.00092 5.5 4.00092C7.33333 4.00092 9.16667 4.00092 11 4.00092C11.6438 4.00092 12.1906 4.40405 12.4062 4.9728C12.5813 4.78217 12.8094 4.63842 13.075 4.56655L14.9344 4.05092C15.7063 3.83842 16.5 4.3103 16.7063 5.10717L17.2688 7.26967L17.4563 7.99467L19.3875 15.4415L19.575 16.1665L19.9469 17.604C20.1531 18.4009 19.6969 19.2197 18.925 19.4322L17.0625 19.9478C16.2906 20.1603 15.4969 19.6884 15.2906 18.8915L14.7281 16.729L14.5406 16.004L12.6125 8.5603L12.5 8.13217V8.50092V15.5009V16.2509V17.0009V18.5009C12.5 19.329 11.8281 20.0009 11 20.0009C9.08318 20.0009 7.41682 20.0009 5.5 20.0009ZM9 18.5009H11V17.0009H9V18.5009ZM7.5 5.50092H5.5V7.00092H7.5V5.50092ZM7.5 8.50092H5.5V15.5009H7.5V8.50092ZM9 7.00092H11V5.50092H9V7.00092ZM11 15.5009V8.50092H9V15.5009H11ZM17.7531 15.1165L16.0094 8.3978L14.2437 8.8853L15.9875 15.604L17.7531 15.1165ZM16.3656 17.0572L16.7375 18.4853L18.5 17.9978C18.5 17.9947 18.5 17.9915 18.5 17.9884V17.9853L18.1344 16.5728L16.3687 17.0603L16.3656 17.0572ZM13.8687 7.43217L15.6344 6.94467L15.2625 5.51655L13.5 6.00405C13.5 6.00717 13.5 6.0103 13.5 6.01655L13.8656 7.42905L13.8687 7.43217Z" />
			</svg>',
		'comments'         =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--comments">
				<path d="M14 10.1V4c0-.6-.4-1-1-1H5c-.6 0-1 .4-1 1v8.3c0 .3.2.7.6.8.1.1.2.1.3.1.2 0 .5-.1.6-.3l1.8-1.8H13c.6 0 1-.4 1-1zm-1.5-.5H6.7l-1.2 1.2V4.5h7v5.1zM19 12h-8c-.6 0-1 .4-1 1v6.1c0 .6.4 1 1 1h5.7l1.8 1.8c.1.2.4.3.6.3.1 0 .2 0 .3-.1.4-.1.6-.5.6-.8V13c0-.6-.4-1-1-1zm-.5 7.8l-1.2-1.2h-5.8v-5.1h7v6.3z" />
			</svg>',
		'copy'             =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--copy">
				<path fill-rule="evenodd" clip-rule="evenodd" d="M5.625 5.5h9.75c.069 0 .125.056.125.125v9.75a.125.125 0 0 1-.125.125h-9.75a.125.125 0 0 1-.125-.125v-9.75c0-.069.056-.125.125-.125ZM4 5.625C4 4.728 4.728 4 5.625 4h9.75C16.273 4 17 4.728 17 5.625v9.75c0 .898-.727 1.625-1.625 1.625h-9.75A1.625 1.625 0 0 1 4 15.375v-9.75Zm14.5 11.656v-9H20v9C20 18.8 18.77 20 17.251 20H6.25v-1.5h11.001c.69 0 1.249-.528 1.249-1.219Z" />
			</svg>',
		'curatedList'      =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--curated-list">
				<path d="M5.5 7.5H7.5V9.5H5.5V7.5ZM5.5 13.5H7.5V11.5H5.5V13.5ZM8.5 9.5H15.5V7.5H8.5V9.5ZM8.5 13.5H15.5V11.5H8.5V13.5ZM16.375 18H4.625C4.19402 18 3.7807 17.8288 3.47595 17.524C3.1712 17.2193 3 16.806 3 16.375V4.625C3 3.728 3.728 3 4.625 3H16.375C17.273 3 18 3.728 18 4.625V16.375C18 17.273 17.273 18 16.375 18ZM4.625 16.5H16.375C16.4082 16.5 16.4399 16.4868 16.4634 16.4634C16.4868 16.4399 16.5 16.4082 16.5 16.375V4.625C16.5 4.59185 16.4868 4.56005 16.4634 4.53661C16.4399 4.51317 16.4082 4.5 16.375 4.5H4.625C4.59185 4.5 4.56005 4.51317 4.53661 4.53661C4.51317 4.56005 4.5 4.59185 4.5 4.625V16.375C4.5 16.444 4.556 16.5 4.625 16.5ZM20.25 19C20.25 19.69 19.69 20.25 19.001 20.25H6V21.75H19.001C19.3621 21.75 19.7196 21.6789 20.0532 21.5406C20.3868 21.4024 20.6899 21.1999 20.9452 20.9445C21.2005 20.6891 21.4029 20.3859 21.541 20.0523C21.6791 19.7187 21.7501 19.3611 21.75 19V8H20.25V19Z" />
			</svg>',
		'edit'             =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--edit">
				<path d="m19 7-3-3-8.5 8.5-1 4 4-1L19 7Zm-7 11.5H5V20h7v-1.5Z" />
			</svg>',
		'email'            =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--email">
				<path fill-rule="evenodd" clip-rule="evenodd" d="M3 7c0-1.1.9-2 2-2h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Zm2-.5h14c.3 0 .5.2.5.5v1L12 13.5 4.5 7.9V7c0-.3.2-.5.5-.5Zm-.5 3.3V17c0 .3.2.5.5.5h14c.3 0 .5-.2.5-.5V9.8L12 15.4 4.5 9.8Z" />
			</svg>',
		'emailSend'        =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--email-send">
				<path d="M19.585 11.33L5.08501 4.07999C4.94513 4.01048 4.78703 3.98638 4.63278 4.01105C4.47854 4.03572 4.33585 4.10794 4.22463 4.21762C4.11341 4.3273 4.03921 4.46896 4.01238 4.62285C3.98556 4.77673 4.00746 4.93515 4.07501 5.07599L7.41701 12L4.07501 18.924C4.0071 19.0649 3.98493 19.2235 4.01159 19.3776C4.03826 19.5316 4.11243 19.6736 4.22373 19.7834C4.33503 19.8933 4.47788 19.9656 4.63232 19.9903C4.78675 20.0149 4.94503 19.9907 5.08501 19.921L19.585 12.671C19.7097 12.6087 19.8145 12.513 19.8878 12.3945C19.9611 12.2759 20 12.1393 20 12C20 11.8606 19.9611 11.724 19.8878 11.6055C19.8145 11.487 19.7097 11.3922 19.585 11.33ZM6.38101 17.595L8.72101 12.749H12V11.249H8.72001L6.38201 6.40399L17.573 12L6.38101 17.596V17.595Z" />
			</svg>',
		'error'            =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--error">
				<path fill-rule="evenodd" clip-rule="evenodd" d="M12.218 5.377a.25.25 0 0 0-.436 0l-7.29 12.96a.25.25 0 0 0 .218.373h14.58a.25.25 0 0 0 .218-.372l-7.29-12.96Zm-1.743-.735c.669-1.19 2.381-1.19 3.05 0l7.29 12.96a1.75 1.75 0 0 1-1.525 2.608H4.71a1.75 1.75 0 0 1-1.525-2.608l7.29-12.96ZM12.75 17.46h-1.5v-1.5h1.5v1.5Zm-1.5-3h1.5v-5h-1.5v5Z" />
			</svg>',
		'gift'             =>
			'<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--gift">
				<path d="M17.25 6.5C17.25 5.83696 16.9866 5.20107 16.5178 4.73223C16.0489 4.26339 15.413 4 14.75 4C14.087 4 13.4511 4.26339 12.9822 4.73223C12.5134 5.20107 12.25 5.83696 12.25 6.5C12.25 5.83696 11.9866 5.20107 11.5178 4.73223C11.0489 4.26339 10.413 4 9.75 4C9.08696 4 8.45107 4.26339 7.98223 4.73223C7.51339 5.20107 7.25 5.83696 7.25 6.5C7.25 7.16304 7.51339 7.79893 7.98223 8.26777C8.45107 8.73661 9.08696 9 9.75 9H4V20H20V9H14.75C15.413 9 16.0489 8.73661 16.5178 8.26777C16.9866 7.79893 17.25 7.16304 17.25 6.5ZM8.75 6.5C8.75 5.95 9.2 5.5 9.75 5.5C10.3 5.5 10.75 5.95 10.75 6.5V7.5H9.75C9.2 7.5 8.75 7.05 8.75 6.5ZM11.5 18.5H5.5V10.5H11.5V18.5ZM13.75 6.5C13.75 5.95 14.2 5.5 14.75 5.5C15.3 5.5 15.75 5.95 15.75 6.5C15.75 7.05 15.3 7.5 14.75 7.5H13.75V6.5ZM18.5 10.5V18.5H13V10.5H18.5Z" fill="#1E1E1E"/>
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
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--info">
				<path fill-rule="evenodd" clip-rule="evenodd" d="M5.5 12a6.5 6.5 0 1 0 13 0 6.5 6.5 0 0 0-13 0ZM12 4a8 8 0 1 0 0 16 8 8 0 0 0 0-16Zm.75 4v1.5h-1.5V8h1.5Zm0 8v-5h-1.5v5h1.5Z" />
			</svg>',
		'key'              =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--key">
				<path d="M10.4984 9.5C10.4984 7.29063 12.289 5.5 14.4984 5.5C16.7078 5.5 18.4984 7.29063 18.4984 9.5C18.4984 11.7094 16.7078 13.5 14.4984 13.5C14.1734 13.5 13.8578 13.4625 13.5578 13.3875C13.3047 13.325 13.0359 13.4 12.8515 13.5875L11.939 14.5H10.2484C9.83279 14.5 9.49841 14.8344 9.49841 15.25V16.5H8.24841C7.83279 16.5 7.49841 16.8344 7.49841 17.25V18.5H5.49841V16.0594L10.4109 11.1469C10.5953 10.9625 10.6703 10.6938 10.6109 10.4406C10.539 10.1406 10.4984 9.825 10.4984 9.5ZM14.4984 4C11.4609 4 8.99841 6.4625 8.99841 9.5C8.99841 9.79688 9.02029 10.0875 9.06716 10.3719L4.21716 15.2188C4.07654 15.3594 3.99841 15.55 3.99841 15.75V19.25C3.99841 19.6656 4.33279 20 4.74841 20H8.24841C8.66404 20 8.99841 19.6656 8.99841 19.25V18H10.2484C10.664 18 10.9984 17.6656 10.9984 17.25V16H12.2484C12.4484 16 12.639 15.9219 12.7797 15.7812L13.6297 14.9312C13.914 14.975 14.2047 15 14.5015 15C17.539 15 20.0015 12.5375 20.0015 9.5C20.0015 6.4625 17.5359 4 14.4984 4ZM15.4984 9.5C15.7636 9.5 16.018 9.39464 16.2055 9.20711C16.3931 9.01957 16.4984 8.76522 16.4984 8.5C16.4984 8.23478 16.3931 7.98043 16.2055 7.79289C16.018 7.60536 15.7636 7.5 15.4984 7.5C15.2332 7.5 14.9788 7.60536 14.7913 7.79289C14.6038 7.98043 14.4984 8.23478 14.4984 8.5C14.4984 8.76522 14.6038 9.01957 14.7913 9.20711C14.9788 9.39464 15.2332 9.5 15.4984 9.5Z" />
			</svg>',
		'lineSolid'        =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--line-solid">
				<path d="M5 11.25h14v1.5H5z" />
			</svg>',
		'login'            =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--login">
				<path d="M11 14.5l1.1 1.1 3-3 .5-.5-.6-.6-3-3-1 1 1.7 1.7H5v1.5h7.7L11 14.5zM16.8 5h-7c-1.1 0-2 .9-2 2v1.5h1.5V7c0-.3.2-.5.5-.5h7c.3 0 .5.2.5.5v10c0 .3-.2.5-.5.5h-7c-.3 0-.5-.2-.5-.5v-1.5H7.8V17c0 1.1.9 2 2 2h7c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2z" />
			</svg>',
		'logout'           =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--logout">
				<path d="M19.03 8.47L22.56 12L19.03 15.53L17.97 14.47L19.69 12.75H8.5V11.25H19.69L17.97 9.53L19.03 8.47ZM13.5 17C13.5 17.1326 13.4473 17.2598 13.3536 17.3536C13.2598 17.4473 13.1326 17.5 13 17.5H6C5.86739 17.5 5.74021 17.4473 5.64645 17.3536C5.55268 17.2598 5.5 17.1326 5.5 17V7C5.5 6.86739 5.55268 6.74021 5.64645 6.64645C5.74021 6.55268 5.86739 6.5 6 6.5H13C13.1326 6.5 13.2598 6.55268 13.3536 6.64645C13.4473 6.74021 13.5 6.86739 13.5 7V8.5H15V7C15 6.46957 14.7893 5.96086 14.4142 5.58579C14.0391 5.21071 13.5304 5 13 5H6C5.46957 5 4.96086 5.21071 4.58579 5.58579C4.21071 5.96086 4 6.46957 4 7V17C4 17.5304 4.21071 18.0391 4.58579 18.4142C4.96086 18.7893 5.46957 19 6 19H13C13.5304 19 14.0391 18.7893 14.4142 18.4142C14.7893 18.0391 15 17.5304 15 17V15.5H13.5V17Z" />
			</svg>',
		'menu'             =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--menu">
				<path d="M5 5v1.5h14V5H5zm0 7.8h14v-1.5H5v1.5zM5 19h14v-1.5H5V19z" />
			</svg>',
		'more'             =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--more">
				<path d="M13 19h-2v-2h2v2zm0-6h-2v-2h2v2zm0-6h-2V5h2v2z" />
			</svg>',
		'newspack'         =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--newspack">
				<path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18m1.773 13.09-4.228-4.226v4.227H7.91V7.909l8.182 8.182zm2.318-2.317-1.227-1.228h1.227zm0-2.318h-2.318l-1.228-1.228h3.546zm0-2.319h-4.636L10.227 7.91h5.864z" />
			</svg>',
		'search'           =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--search">
				<path d="M13 5c-3.3 0-6 2.7-6 6 0 1.4.5 2.7 1.3 3.7l-3.8 3.8 1.1 1.1 3.8-3.8c1 .8 2.3 1.3 3.7 1.3 3.3 0 6-2.7 6-6S16.3 5 13 5zm0 10.5c-2.5 0-4.5-2-4.5-4.5s2-4.5 4.5-4.5 4.5 2 4.5 4.5-2 4.5-4.5 4.5z" />
			</svg>',
		'people'           =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--people">
				<path d="M15.5 9.5a1 1 0 100-2 1 1 0 000 2zm0 1.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5zm-2.25 6v-2a2.75 2.75 0 00-2.75-2.75h-4A2.75 2.75 0 003.75 15v2h1.5v-2c0-.69.56-1.25 1.25-1.25h4c.69 0 1.25.56 1.25 1.25v2h1.5zm7-2v2h-1.5v-2c0-.69-.56-1.25-1.25-1.25H15v-1.5h2.5A2.75 2.75 0 0120.25 15zM9.5 8.5a1 1 0 11-2 0 1 1 0 012 0zm1.5 0a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z" fill-rule="evenodd" />
			</svg>',
		'trash'            =>
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--trash">
				<path fill-rule="evenodd" clip-rule="evenodd" d="M12 5.5A2.25 2.25 0 0 0 9.878 7h4.244A2.251 2.251 0 0 0 12 5.5ZM12 4a3.751 3.751 0 0 0-3.675 3H5v1.5h1.27l.818 8.997a2.75 2.75 0 0 0 2.739 2.501h4.347a2.75 2.75 0 0 0 2.738-2.5L17.73 8.5H19V7h-3.325A3.751 3.751 0 0 0 12 4Zm4.224 4.5H7.776l.806 8.861a1.25 1.25 0 0 0 1.245 1.137h4.347a1.25 1.25 0 0 0 1.245-1.137l.805-8.861Z" />
			</svg>',
	);
}
