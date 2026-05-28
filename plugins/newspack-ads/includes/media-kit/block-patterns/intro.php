<?php
// phpcs:ignoreFile
?>
<!-- wp:cover {"url":"<?php echo esc_url( NEWSPACK_ADS_MEDIA_KIT_URL ); ?>assets/images/cover.jpg","hasParallax":true,"dimRatio":0,"isUserOverlayColor":true,"minHeight":100,"minHeightUnit":"vh","isDark":false,"metadata":{"name":"<?php echo esc_html__( 'Intro', 'newspack-ads' ); ?>"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-cover alignfull is-light has-parallax" style="padding-top:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80);min-height:100vh"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-0 has-background-dim"></span><div class="wp-block-cover__image-background has-parallax" style="background-position:50% 50%;background-image:url(<?php echo esc_url( NEWSPACK_ADS_MEDIA_KIT_URL ); ?>assets/images/cover.jpg)"></div><div class="wp-block-cover__inner-container"><!-- wp:image {"width":"72px","aspectRatio":"1","scale":"cover","sizeSlug":"large","linkDestination":"none","align":"center","className":"is-style-rounded"} -->
<figure class="wp-block-image aligncenter size-large is-resized is-style-rounded"><img src="<?php echo esc_url( NEWSPACK_ADS_MEDIA_KIT_URL ); ?>assets/images/image.jpg" alt="" style="aspect-ratio:1;object-fit:cover;width:72px"/></figure>
<!-- /wp:image -->

<!-- wp:paragraph {"align":"center","fontSize":"x-large"} -->
<p class="has-text-align-center has-x-large-font-size"><?php echo esc_html__( 'The News Paper delivers in-depth local reporting and engaging community stories to keep residents informed and connected.', 'newspack-ads' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons"><!-- wp:button {"backgroundColor":"contrast","textColor":"base","style":{"elements":{"link":{"color":{"text":"var:preset|color|base"}}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link has-base-color has-contrast-background-color has-text-color has-background has-link-color wp-element-button"><?php echo esc_html__( 'Get in touch', 'newspack-ads' ); ?></a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div></div>
<!-- /wp:cover -->
