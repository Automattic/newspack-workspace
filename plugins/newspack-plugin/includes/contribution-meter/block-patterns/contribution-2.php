<?php
/**
 * Contribution Meter Pattern 2: Cover image with circular meter overlay.
 *
 * @package Newspack
 */

$image_url = \Newspack\Newspack::plugin_url() . '/includes/images/contribution-meter/contribution-2.jpg';
?>
<!-- wp:cover {"url":"<?php echo esc_url( $image_url ); ?>","dimRatio":0,"isUserOverlayColor":true,"minHeight":75,"minHeightUnit":"vh","contentPosition":"bottom right","sizeSlug":"large","metadata":{"name":"<?php echo esc_html__( 'Contribution', 'newspack-plugin' ); ?>"},"align":"wide","className":"newspack-pattern contribution__style-2","style":{"spacing":{"padding":{"right":"var:preset|spacing|80","left":"var:preset|spacing|80","top":"var:preset|spacing|80","bottom":"var:preset|spacing|80"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-cover alignwide has-custom-content-position is-position-bottom-right newspack-pattern contribution__style-2" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--80);min-height:75vh">
	<span aria-hidden="true" class="wp-block-cover__background has-background-dim-0 has-background-dim"></span>
	<img class="wp-block-cover__image-background size-large" alt="" src="<?php echo esc_url( $image_url ); ?>" data-object-fit="cover"/>
	<div class="wp-block-cover__inner-container">
		<!-- wp:group {"style":{"elements":{"link":{"color":{"text":"var:preset|color|contrast"}}},"border":{"radius":"6px"},"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50","left":"var:preset|spacing|50","right":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|80"},"shadow":"var:preset|shadow|elevation-3"},"backgroundColor":"base","textColor":"contrast","layout":{"type":"flex","flexWrap":"nowrap"}} -->
		<div class="wp-block-group has-contrast-color has-base-background-color has-text-color has-background has-link-color" style="border-radius:6px;padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50);box-shadow:var(--wp--preset--shadow--elevation-3)">
			<!-- wp:newspack/contribution-meter {"thickness":"m","className":"is-style-circular"} /-->

			<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"left"}} -->
			<div class="wp-block-buttons">
				<!-- wp:button -->
				<div class="wp-block-button">
					<a class="wp-block-button__link wp-element-button" href="/support-our-publication/"><?php esc_html_e( 'Donate Now', 'newspack-plugin' ); ?></a>
				</div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:group -->
	</div>
</div>
<!-- /wp:cover -->
