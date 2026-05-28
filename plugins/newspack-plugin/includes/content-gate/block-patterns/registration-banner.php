<?php
/**
 * Memberships Registration Banner Pattern.
 *
 * @package Newspack
 */

$metering        = \Newspack\Content_Gate\Block_Patterns::get_metering_settings( $pattern_context );
$metering_count  = $metering['count'];
$metering_period = $metering['period'];

?>
<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Registration', 'newspack-plugin' ); ?>"},"align":"wide","style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50","left":"var:preset|spacing|50","right":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|30"},"border":{"radius":"8px","width":"1px"}},"borderColor":"base-3","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} -->
<div class="wp-block-group alignwide has-border-color has-base-3-border-color" style="border-width:1px;border-radius:8px;padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)">

	<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Content', 'newspack-plugin' ); ?>"},"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"constrained","justifyContent":"left"}} -->
	<div class="wp-block-group">
		<!-- wp:paragraph -->
		<p>
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

		<!-- wp:paragraph {"style":{"elements":{"link":{"color":{"text":"var:preset|color|contrast-3"}}}},"textColor":"contrast-3","fontSize":"x-small"} -->
		<p class="has-contrast-3-color has-text-color has-link-color has-x-small-font-size"><a href="#signin_modal"><?php esc_html_e( 'Sign in to an existing account', 'newspack-plugin' ); ?></a></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->

	<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center","flexWrap":"nowrap"}} -->
	<div class="wp-block-buttons">
		<!-- wp:button -->
		<div class="wp-block-button">
			<a class="wp-block-button__link wp-element-button" href="#register_modal">
				<?php esc_html_e( 'Create a free account', 'newspack-plugin' ); ?>
			</a>
		</div>
		<!-- /wp:button -->
	</div>
	<!-- /wp:buttons -->

</div>
<!-- /wp:group -->
