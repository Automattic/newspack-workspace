<?php
/**
 * My Account page for reader account deletion.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;
?>

<div>
	<h1>
		<?php echo esc_html__( 'Your account has been deleted.', 'newspack-plugin' ); ?>
	</h1>
	<p>
		<?php echo esc_html__( "We're sorry to see you go. But hope you'll continue to follow our coverage.", 'newspack-plugin' ); ?>
	</p>
	<a class="woocommerce-Button button" href="<?php echo esc_url( site_url() ); ?>">
		<?php echo esc_html__( 'Return to home page', 'newspack-plugin' ); ?>
	</a>
</div>
