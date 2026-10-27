<?php
/**
 * Title: Header - Starter
 * Slug: newspack-block-theme/header-starter
 * Inserter: no
 *
 * Starting content for new header template parts; see Patterns::template_part_starter_content().
 * Both views start with a logo and menu so neither is published empty, and the layout blocks are unlocked so
 * it can be rebuilt freely.
 *
 * @package Newspack_Block_Theme
 */

?>
<!-- wp:newspack-ads/ad-slot {"placement":"global_above_header"} /-->

<!-- wp:newspack/responsive-container -->
<div class="wp-block-newspack-responsive-container newspack-responsive-container"><!-- wp:newspack/responsive-container-breakpoint -->
<div class="wp-block-newspack-responsive-container-breakpoint newspack-responsive-container-breakpoint--desktop"><!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Header (Desktop)', 'newspack-block-theme' ); ?>"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30","right":"var:preset|spacing|30","left":"var:preset|spacing|30"},"margin":{"bottom":"var:preset|spacing|80"}}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group has-base-background-color has-background" style="margin-bottom:var(--wp--preset--spacing--80);padding-top:var(--wp--preset--spacing--30);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--30);padding-left:var(--wp--preset--spacing--30)"><!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->
<div class="wp-block-group alignwide"><!-- wp:site-logo {"width":256} /-->

<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"right"}} -->
<div class="wp-block-group"><!-- wp:navigation {"className":"primary-navigation","layout":{"type":"flex","setCascadingProperties":true,"justifyContent":"right","orientation":"horizontal","flexWrap":"wrap"},"style":{"typography":{"textTransform":"uppercase"}}} /-->

<!-- wp:newspack/overlay-search {"className":"is-style-icon-only has-background-transparent","style":{"elements":{"link":{"color":{"text":"var:preset|color|contrast"}}},"spacing":{"padding":{"right":"var:preset|spacing|20","left":"var:preset|spacing|20","top":"var:preset|spacing|20","bottom":"var:preset|spacing|20"}}},"textColor":"contrast"} /-->
<?php if ( class_exists( 'Newspack\Reader_Activation' ) && \Newspack\Reader_Activation::is_enabled() ) : ?>

<!-- wp:newspack/my-account-button {"style":{"elements":{"link":{"color":{"text":"var:preset|color|contrast"}}}},"backgroundColor":"base-2","textColor":"contrast","className":"has-small-size","fontSize":"x-small"} /-->
<?php endif; ?></div>
<!-- /wp:group --></div>
<!-- /wp:group --></div>
<!-- /wp:group --></div>
<!-- /wp:newspack/responsive-container-breakpoint -->

<!-- wp:newspack/responsive-container-breakpoint {"view":"mobile"} -->
<div class="wp-block-newspack-responsive-container-breakpoint newspack-responsive-container-breakpoint--mobile"><!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Header (Mobile)', 'newspack-block-theme' ); ?>"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30","left":"var:preset|spacing|30","right":"var:preset|spacing|30"},"margin":{"bottom":"var:preset|spacing|50"}}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group has-base-background-color has-background" style="margin-bottom:var(--wp--preset--spacing--50);padding-top:var(--wp--preset--spacing--30);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--30);padding-left:var(--wp--preset--spacing--30)"><!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->
<div class="wp-block-group alignwide"><!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
<div class="wp-block-group"><!-- wp:newspack/overlay-menu {"instanceId":"5f3a9c2e7b1d"} -->
<div class="wp-block-newspack-overlay-menu"><!-- wp:newspack/overlay-menu-trigger {"className":"is-style-icon-only has-background-transparent","style":{"elements":{"link":{"color":{"text":"var:preset|color|contrast"}}},"spacing":{"padding":{"right":"0","left":"0"}}},"textColor":"contrast"} /-->

<!-- wp:newspack/overlay-menu-panel -->
<div class="wp-block-newspack-overlay-menu-panel"><!-- wp:navigation {"className":"is-style-flatten","layout":{"type":"flex","setCascadingProperties":true,"orientation":"vertical"}} /--></div>
<!-- /wp:newspack/overlay-menu-panel --></div>
<!-- /wp:newspack/overlay-menu -->

<!-- wp:site-logo {"width":256} /--></div>
<!-- /wp:group -->
<?php if ( class_exists( 'Newspack\Reader_Activation' ) && \Newspack\Reader_Activation::is_enabled() ) : ?>

<!-- wp:newspack/my-account-button {"className":"is-style-icon-only has-x-small-size","style":{"spacing":{"padding":{"top":"0.375rem","bottom":"0.375rem","left":"0.375rem","right":"0.375rem"}},"elements":{"link":{"color":{"text":"var:preset|color|contrast"}}}},"backgroundColor":"base","textColor":"contrast","fontSize":"x-small"} /-->
<?php endif; ?></div>
<!-- /wp:group --></div>
<!-- /wp:group --></div>
<!-- /wp:newspack/responsive-container-breakpoint --></div>
<!-- /wp:newspack/responsive-container -->

<!-- wp:newspack-ads/ad-slot {"placement":"global_below_header","style":{"spacing":{"margin":{"bottom":"var:preset|spacing|50","top":"var:preset|spacing|30"}}}} /-->
