<?php
/**
 * Contribution Meter Pattern 1: Two-column with cover image.
 *
 * @package Newspack
 */

$image_url = \Newspack\Newspack::plugin_url() . '/includes/images/contribution-meter/contribution-1.jpg';
?>
<!-- wp:group {"metadata":{"name":"<?php echo esc_html__( 'Contribution', 'newspack-plugin' ); ?>"},"align":"wide","className":"newspack-pattern contribution__style-1","style":{"spacing":{"padding":{"top":"var:preset|spacing|20","bottom":"var:preset|spacing|20","left":"var:preset|spacing|20","right":"var:preset|spacing|20"}},"border":{"width":"1px","color":"#DDDDDD","radius":"8px"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide newspack-pattern contribution__style-1 has-border-color" style="border-color:#DDDDDD;border-width:1px;border-radius:8px;padding-top:var(--wp--preset--spacing--20);padding-right:var(--wp--preset--spacing--20);padding-bottom:var(--wp--preset--spacing--20);padding-left:var(--wp--preset--spacing--20)">
	<!-- wp:columns {"align":"wide","className":"contribution","style":{"spacing":{"padding":{"top":"0","bottom":"0","left":"0","right":"0"},"blockGap":{"top":"var:preset|spacing|20","left":"var:preset|spacing|20"}}}} -->
	<div class="wp-block-columns alignwide contribution" style="padding-top:0;padding-right:0;padding-bottom:0;padding-left:0">
		<!-- wp:column {"verticalAlignment":"stretch","width":"50%"} -->
		<div class="wp-block-column is-vertically-aligned-stretch" style="flex-basis:50%">
			<!-- wp:group {"style":{"dimensions":{"minHeight":"100%"}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"}} -->
			<div class="wp-block-group" style="min-height:100%">
				<!-- wp:cover {"url":"<?php echo esc_url( $image_url ); ?>","dimRatio":50,"overlayColor":"white","isUserOverlayColor":true,"minHeightUnit":"vh","contentPosition":"top center","isDark":false,"sizeSlug":"large","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80","left":"var:preset|spacing|80","right":"var:preset|spacing|80"}},"layout":{"selfStretch":"fill","flexSize":null},"border":{"radius":"4px"}},"layout":{"type":"constrained"}} -->
				<div class="wp-block-cover is-light has-custom-content-position is-position-top-center" style="border-radius:4px;padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--80)">
					<span aria-hidden="true" class="wp-block-cover__background has-white-background-color has-background-dim"></span>
					<img class="wp-block-cover__image-background size-large" alt="" src="<?php echo esc_url( $image_url ); ?>" data-object-fit="cover"/>
					<div class="wp-block-cover__inner-container">
						<!-- wp:newspack/contribution-meter {"thickness":"m"} /-->
					</div>
				</div>
				<!-- /wp:cover -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"verticalAlignment":"center","width":"50%","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80","left":"var:preset|spacing|80","right":"var:preset|spacing|80"}},"border":{"radius":"4px"}}} -->
		<div class="wp-block-column is-vertically-aligned-center" style="border-radius:4px;padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--80);flex-basis:50%">
			<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|80"}},"layout":{"type":"flex","orientation":"vertical","verticalAlignment":"center","justifyContent":"stretch"}} -->
			<div class="wp-block-group">
				<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"constrained"}} -->
				<div class="wp-block-group">
					<!-- wp:heading -->
					<h2 class="wp-block-heading"><?php esc_html_e( 'Support our publication', 'newspack-plugin' ); ?></h2>
					<!-- /wp:heading -->

					<!-- wp:paragraph -->
					<p><?php esc_html_e( 'With the support of readers like you, we provide thoughtfully researched articles for a more informed and connected community. This is your chance to support credible, community-based, public-service journalism. Please join us!', 'newspack-plugin' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->

				<!-- wp:group {"layout":{"type":"constrained"}} -->
				<div class="wp-block-group">
					<!-- wp:newspack-blocks/donate {"className":"is-style-modern"} /-->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->
