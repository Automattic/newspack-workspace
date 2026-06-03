<?php
/**
 * The [ad] shortcode and its related functions
 *
 * @package super-cool-ad-inserter
 */

/**
 * The SCAIP shortcode function
 * Outputs an aside 'scaip-#' where # is the 'number' argument on the shortcode.
 *
 * @param Array  $atts Shortcode attributes or block properties.
 * @param String $content Shortcode wrapped text; not used in this shortcode.
 * @param String $tag The complete shortcode tag; not used in this shortcode.
 * @return HTML
 * @since 0.1
 */
function scaip_shortcode( $atts = array(), $content = '', $tag = '' ) {
	// This is the shortcode that disables doing shortcodes.
	if ( isset( $atts['no'] ) ) {
		return '';
	}

	if ( ! isset( $atts['number'] ) ) {
		return '';
	}

	ob_start();
	do_action( 'scaip_shortcode', $atts );
	$ad = ob_get_clean();

	if ( empty( trim( $ad ) ) ) {
		return '';
	}

	return sprintf(
		'<aside class="scaip scaip-%1$s %2$s %3$s %4$s %5$s">%6$s</aside>',
		esc_attr( $atts['number'] ),
		isset( $atts['align'] ) ? esc_attr( 'align' . $atts['align'] ) : '',
		isset( $atts['class'] ) ? esc_attr( $atts['class'] ) : '',
		isset( $atts['className'] ) ? esc_attr( $atts['className'] ) : '',
		isset( $atts['customClassName'] ) ? esc_attr( $atts['customClassName'] ) : '',
		$ad
	);
}
add_shortcode( 'scaip', 'scaip_shortcode' );
add_shortcode( 'ad', 'scaip_shortcode' );

/**
 * Outputs the SCAIP sidebar from the shortcode attributes.
 *
 * To prevent this happening, decrease the number of ads that should be inserted to 0, remove the ad widgets form the sidebar, or remove_action('scaip_shortcode', 'scaip_shortcode_do_sidebar');
 *
 * @param Array $args Shortcode attributes or block properties.
 * @since 0.1
 */
function scaip_shortcode_do_sidebar( $args ) {
	dynamic_sidebar( 'scaip-' . esc_attr( $args['number'] ) );
}
add_action( 'scaip_shortcode', 'scaip_shortcode_do_sidebar' );
