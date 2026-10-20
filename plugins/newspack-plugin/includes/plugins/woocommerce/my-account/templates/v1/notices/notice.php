<?php
/**
 * Show notifications as inline info notices, and urgent ones as inline warning notices.
 *
 * @author   Newspack
 * @category WooCommerce Subscriptions/Templates
 * @package  Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

if ( ! $notices ) {
	return;
}
foreach ( $notices as $notice ) {
	$notice_type = \apply_filters( 'newspack_ui_notice_is_urgent', false, $notice['notice'] ) ? 'warning' : 'info';
	?>
	<div class="newspack-ui__notice newspack-ui__notice--<?php echo \esc_attr( $notice_type ); ?>"<?php echo wc_get_notice_data_attr( $notice ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> role="status">
		<?php Newspack_UI::print_notice_icon( $notice_type ); ?>
		<p class="newspack-ui__spacing-top--0 newspack-ui__spacing-bottom--0"><?php echo wc_kses_notice( $notice['notice'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
	</div>
	<?php
}
