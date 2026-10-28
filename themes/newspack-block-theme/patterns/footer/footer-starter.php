<?php
/**
 * Title: Footer - Starter
 * Slug: newspack-block-theme/footer-starter
 * Inserter: no
 *
 * Starting content for new footer template parts; see Patterns::template_part_starter_content().
 * Both views start with a copyright line so neither is published empty, and the layout blocks are unlocked so
 * the layout can be rebuilt freely.
 *
 * @package Newspack_Block_Theme
 */

?>
<!-- wp:newspack-ads/ad-slot {"placement":"global_above_footer"} /-->

<!-- wp:newspack/responsive-container -->
<div class="wp-block-newspack-responsive-container newspack-responsive-container"><!-- wp:newspack/responsive-container-breakpoint -->
<div class="wp-block-newspack-responsive-container-breakpoint newspack-responsive-container-breakpoint--desktop"><!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Footer (Desktop)', 'newspack-block-theme' ); ?>"},"style":{"spacing":{"margin":{"top":"var:preset|spacing|80"},"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}},"elements":{"link":{"color":{"text":"var:preset|color|base"}}}},"backgroundColor":"contrast","textColor":"base","className":"is-footer","layout":{"type":"constrained"}} -->
<div class="wp-block-group is-footer has-base-color has-contrast-background-color has-text-color has-background has-link-color" style="margin-top:var(--wp--preset--spacing--80);padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)"><!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Credits', 'newspack-block-theme' ); ?>"},"align":"wide","className":"is-credits","style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"fontSize":"x-small","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} -->
<div class="wp-block-group alignwide is-credits has-x-small-font-size"><!-- wp:newspack/copyright-date {"showSiteTitle":true} /-->

<!-- wp:paragraph {"lock":{"move":true,"remove":true},"metadata":{"name":"<?php esc_html_e( 'Powered By', 'newspack-block-theme' ); ?>"},"className":"powered-by"} -->
<p class="powered-by"><a href="https://newspack.com"><?php esc_html_e( 'Powered by Newspack', 'newspack-block-theme' ); ?></a></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group --></div>
<!-- /wp:group --></div>
<!-- /wp:newspack/responsive-container-breakpoint -->

<!-- wp:newspack/responsive-container-breakpoint {"view":"mobile"} -->
<div class="wp-block-newspack-responsive-container-breakpoint newspack-responsive-container-breakpoint--mobile"><!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Footer (Mobile)', 'newspack-block-theme' ); ?>"},"style":{"spacing":{"margin":{"top":"var:preset|spacing|80"},"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}},"elements":{"link":{"color":{"text":"var:preset|color|base"}}}},"backgroundColor":"contrast","textColor":"base","className":"is-footer","layout":{"type":"constrained"}} -->
<div class="wp-block-group is-footer has-base-color has-contrast-background-color has-text-color has-background has-link-color" style="margin-top:var(--wp--preset--spacing--80);padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)"><!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Credits', 'newspack-block-theme' ); ?>"},"align":"wide","className":"is-credits","style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"fontSize":"x-small","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} -->
<div class="wp-block-group alignwide is-credits has-x-small-font-size"><!-- wp:newspack/copyright-date {"showSiteTitle":true} /-->

<!-- wp:paragraph {"lock":{"move":true,"remove":true},"metadata":{"name":"<?php esc_html_e( 'Powered By', 'newspack-block-theme' ); ?>"},"className":"powered-by"} -->
<p class="powered-by"><a href="https://newspack.com"><?php esc_html_e( 'Powered by Newspack', 'newspack-block-theme' ); ?></a></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group --></div>
<!-- /wp:group --></div>
<!-- /wp:newspack/responsive-container-breakpoint --></div>
<!-- /wp:newspack/responsive-container -->

<!-- wp:newspack-ads/ad-slot {"placement":"sticky"} /-->
