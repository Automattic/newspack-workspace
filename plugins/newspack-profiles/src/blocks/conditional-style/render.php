<?php
/**
 * Server-side rendering for Conditional Style block.
 *
 * @package newspack-profiles
 */

declare( strict_types=1 );

$field_value = isset( $attributes['fieldName'] ) ? trim( strval( $attributes['fieldName'] ) ) : '';
$styles      = isset( $attributes['styles'] ) && is_array( $attributes['styles'] ) ? $attributes['styles'] : array();

$fallback_style = isset( $attributes['fallbackStyle'] ) && is_array( $attributes['fallbackStyle'] )
	? $attributes['fallbackStyle']
	: array();

$fallback_text_color = isset( $fallback_style['textColor'] ) && '' !== strval( $fallback_style['textColor'] )
	? sanitize_text_field( strval( $fallback_style['textColor'] ) )
	: '#000000';

$fallback_background_color = isset( $fallback_style['backgroundColor'] ) && '' !== strval( $fallback_style['backgroundColor'] )
	? sanitize_text_field( strval( $fallback_style['backgroundColor'] ) )
	: '#f3f4f6';

$context = isset( $block->context['remote-data-blocks/remoteData'] ) && is_array( $block->context['remote-data-blocks/remoteData'] )
	? $block->context['remote-data-blocks/remoteData']
	: array();

$applied_text_color       = $fallback_text_color;
$applied_background_color = $fallback_background_color;

if ( ! empty( $field_value ) && isset( $styles[ $field_value ] ) && is_array( $styles[ $field_value ] ) ) {
	$matched_style = $styles[ $field_value ];

	$applied_text_color = isset( $matched_style['textColor'] ) && '' !== strval( $matched_style['textColor'] )
		? sanitize_text_field( strval( $matched_style['textColor'] ) )
		: $fallback_text_color;

	$applied_background_color = isset( $matched_style['backgroundColor'] ) && '' !== strval( $matched_style['backgroundColor'] )
		? sanitize_text_field( strval( $matched_style['backgroundColor'] ) )
		: $fallback_background_color;
}

$inline_styles = array_filter(
	array(
		'overflow: hidden;',
		! empty( $applied_text_color ) ? sprintf( '--np-conditional-style-text-color: %s;', esc_attr( preg_replace( '/[^a-zA-Z0-9_ ().,#%\/-]+/', '', $applied_text_color ) ) ) : '',
		! empty( $applied_background_color ) ? sprintf( 'background-color: %s;', esc_attr( preg_replace( '/[^a-zA-Z0-9_ ().,#%\/-]+/', '', $applied_background_color ) ) ) : '',
	)
);

$wrapper_attributes = get_block_wrapper_attributes(
	array( 'style' => implode( '', $inline_styles ) )
);

$allowed_html = wp_kses_allowed_html( 'post' );

$allowed_html['svg'] = array(
	'width'       => true,
	'height'      => true,
	'viewBox'     => true,
	'aria-hidden' => true,
	'focusable'   => true,
);

$allowed_html['path'] = array(
	'd' => true,
);

?>
<div <?php echo wp_kses_data( $wrapper_attributes ); ?>>
	<?php echo wp_kses( $content, $allowed_html ); ?>
</div>
