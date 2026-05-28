<?php
/**
 * Memberships Donation Wall Pattern.
 *
 * @package Newspack
 */

$donate_settings = [
	'className'           => 'is-style-modern',
	'manual'              => true,
	'amounts'             => [
		'once'  => [ 9, 20, 90, 20 ],
		'month' => [ 7, 15, 30, 15 ],
		'year'  => [ 84, 180, 360, 180 ],
	],
	'disabledFrequencies' => [
		'once'  => true,
		'month' => false,
		'year'  => false,
	],
	'minimumDonation'     => 5,
	'layoutOption'        => 'tiers',
];
?>
<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Donation', 'newspack-plugin' ); ?>"},"align":"wide","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80","left":"var:preset|spacing|80","right":"var:preset|spacing|80"}},"border":{"radius":"8px","width":"1px"}},"borderColor":"base-3","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide has-border-color has-base-3-border-color" style="border-width:1px;border-radius:8px;padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--80)">

	<!-- wp:heading {"textAlign":"center","level":3,"metadata":{"name":"<?php esc_html_e( 'Title', 'newspack-plugin' ); ?>"}} -->
	<h3 class="wp-block-heading has-text-align-center">
		<?php esc_html_e( 'Choose an option to continue reading', 'newspack-plugin' ); ?>
	</h3>
	<!-- /wp:heading -->

	<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Donation', 'newspack-plugin' ); ?>"},"align":"wide","layout":{"type":"constrained","contentSize":"964px"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:newspack-blocks/donate <?php echo wp_json_encode( $donate_settings ); ?> /-->
	</div>
	<!-- /wp:group -->

	<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
	<div class="wp-block-buttons">
		<!-- wp:button {"backgroundColor":"base","textColor":"contrast","style":{"elements":{"link":{"color":{"text":"var:preset|color|contrast"}}}},"fontSize":"small"} -->
		<div class="wp-block-button has-custom-font-size has-small-font-size">
			<a class="wp-block-button__link has-contrast-color has-base-background-color has-text-color has-background has-link-color wp-element-button" href="#signin_modal">
				<?php esc_html_e( 'Sign in to an existing account', 'newspack-plugin' ); ?>
			</a>
		</div>
		<!-- /wp:button -->
	</div>
	<!-- /wp:buttons -->

</div>
<!-- /wp:group -->
