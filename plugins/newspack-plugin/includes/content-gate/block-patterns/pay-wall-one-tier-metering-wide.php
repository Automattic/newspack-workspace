<?php
/**
 * Memberships Paywall with One Tier and Metering Pattern (Wide/Block Theme Layout).
 *
 * @package Newspack
 */

$metering        = \Newspack\Content_Gate\Block_Patterns::get_metering_settings( $pattern_context );
$metering_count  = $metering['count'];
$metering_period = $metering['period'];
$product_id      = \Newspack\Content_Gate\Block_Patterns::get_subscription_product_id( $pattern_context );

$checkout_attrs = [
	'text'  => esc_html__( 'Become a member', 'newspack-plugin' ),
	'width' => 100,
	'align' => 'center',
];
if ( $product_id ) {
	$checkout_attrs['product'] = (string) $product_id;
}

?>
<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Subscription', 'newspack-plugin' ); ?>"},"align":"wide","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80","left":"var:preset|spacing|80","right":"var:preset|spacing|80"}},"border":{"radius":{"topLeft":"8px","topRight":"8px","bottomLeft":"8px","bottomRight":"8px"},"width":"1px"}},"borderColor":"base-3","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide has-border-color has-base-3-border-color" style="border-width:1px;border-top-left-radius:8px;border-top-right-radius:8px;border-bottom-left-radius:8px;border-bottom-right-radius:8px;padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--80)">
	<!-- wp:heading {"textAlign":"center","level":3,"metadata":{"name":"<?php esc_html_e( 'Title', 'newspack-plugin' ); ?>"}} -->
	<h3 class="wp-block-heading has-text-align-center">
		<?php esc_html_e( 'Unlock the full article', 'newspack-plugin' ); ?>
	</h3>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"align":"center"} -->
	<p class="has-text-align-center">
		<?php esc_html_e( 'Join a community of passionate readers and never miss a story.', 'newspack-plugin' ); ?>
	</p>
	<!-- /wp:paragraph -->

	<!-- wp:columns {"metadata":{"name":"<?php esc_html_e( 'Content', 'newspack-plugin' ); ?>"},"className":"is-style-borders","style":{"spacing":{"margin":{"top":"var:preset|spacing|80","bottom":"0"}}}} -->
	<div class="wp-block-columns is-style-borders" style="margin-top:var(--wp--preset--spacing--80);margin-bottom:0">
		<!-- wp:column -->
		<div class="wp-block-column">
			<!-- wp:paragraph {"align":"center"} -->
			<p class="has-text-align-center">
				<?php
				printf(
					wp_kses_post(
						/* translators: 1: number of free articles, 2: period label such as "month" or "week". */
						_n(
							'Get %1$s free article every %2$s with a free account.',
							'Get %1$s free articles every %2$s with a free account.',
							$metering_count,
							'newspack-plugin'
						)
					),
					'<strong>' . esc_html( $metering_count ) . '</strong>',
					esc_html( $metering_period )
				);
				?>
			</p>
			<!-- /wp:paragraph -->

			<!-- wp:newspack/reader-registration {"newsletterSubscription":false,"hideOauth":true,"className":"is-style-inline"} -->
			<div class="wp-block-newspack-reader-registration is-style-inline"></div>
			<!-- /wp:newspack/reader-registration -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column -->
		<div class="wp-block-column">
			<!-- wp:paragraph {"align":"center"} -->
			<p class="has-text-align-center">
				<?php esc_html_e( 'Support our journalism and get unlimited access to our full archive.', 'newspack-plugin' ); ?>
			</p>
			<!-- /wp:paragraph -->

			<!-- wp:newspack-blocks/checkout-button <?php echo wp_json_encode( $checkout_attrs ); ?> /-->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->

</div>
<!-- /wp:group -->
