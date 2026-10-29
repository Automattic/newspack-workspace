<?php
/**
 * Show error messages as inline error notices for signed-in readers. Signed-out
 * readers, whose account forms sit outside newspack-ui, get a snackbar that
 * stays until dismissed.
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
if ( ! \is_user_logged_in() ) {
	My_Account_UI_V1::add_snackbar_notices( $notices, 'error' );
	return;
}
foreach ( $notices as $notice ) {
	?>
	<div class="newspack-ui__notice newspack-ui__notice--error"<?php echo wc_get_notice_data_attr( $notice ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> role="alert">
		<?php Newspack_UI::print_notice_icon( 'error' ); ?>
		<p class="newspack-ui__spacing-top--0 newspack-ui__spacing-bottom--0"><?php echo wc_kses_notice( $notice['notice'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
	</div>
	<?php
}
