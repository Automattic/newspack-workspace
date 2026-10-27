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
<div class="wp-block-group is-footer has-base-color has-contrast-background-color has-text-color has-background has-link-color" style="margin-top:var(--wp--preset--spacing--80);padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)"><!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Copyright', 'newspack-block-theme' ); ?>"},"align":"wide","style":{"spacing":{"blockGap":"0.25em"}},"fontSize":"x-small","layout":{"type":"flex","flexWrap":"wrap"}} -->
<div class="wp-block-group alignwide has-x-small-font-size"><!-- wp:newspack/copyright-date /-->

<!-- wp:site-title {"level":0,"style":{"typography":{"fontStyle":"normal","fontWeight":"400"}},"fontSize":"x-small"} /--></div>
<!-- /wp:group --></div>
<!-- /wp:group --></div>
<!-- /wp:newspack/responsive-container-breakpoint -->

<!-- wp:newspack/responsive-container-breakpoint {"view":"mobile"} -->
<div class="wp-block-newspack-responsive-container-breakpoint newspack-responsive-container-breakpoint--mobile"><!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Footer (Mobile)', 'newspack-block-theme' ); ?>"},"style":{"spacing":{"margin":{"top":"var:preset|spacing|80"},"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}},"elements":{"link":{"color":{"text":"var:preset|color|base"}}}},"backgroundColor":"contrast","textColor":"base","className":"is-footer","layout":{"type":"constrained"}} -->
<div class="wp-block-group is-footer has-base-color has-contrast-background-color has-text-color has-background has-link-color" style="margin-top:var(--wp--preset--spacing--80);padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)"><!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Copyright', 'newspack-block-theme' ); ?>"},"align":"wide","style":{"spacing":{"blockGap":"0.25em"}},"fontSize":"x-small","layout":{"type":"flex","flexWrap":"wrap"}} -->
<div class="wp-block-group alignwide has-x-small-font-size"><!-- wp:newspack/copyright-date /-->

<!-- wp:site-title {"level":0,"style":{"typography":{"fontStyle":"normal","fontWeight":"400"}},"fontSize":"x-small"} /--></div>
<!-- /wp:group --></div>
<!-- /wp:group --></div>
<!-- /wp:newspack/responsive-container-breakpoint --></div>
<!-- /wp:newspack/responsive-container -->

<!-- wp:newspack-ads/ad-slot {"placement":"sticky"} /-->
