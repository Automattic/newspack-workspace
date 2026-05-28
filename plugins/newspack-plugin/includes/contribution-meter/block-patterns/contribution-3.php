<?php
/**
 * Contribution Meter Pattern 3: Vertical layout with image.
 *
 * @package Newspack
 */

$image_url = \Newspack\Newspack::plugin_url() . '/includes/images/contribution-meter/contribution-3.jpg';
?>
<!-- wp:group {"metadata":{"name":"<?php echo esc_html__( 'Contribution', 'newspack-plugin' ); ?>"},"className":"newspack-pattern contribution__style-3","style":{"spacing":{"blockGap":"0"}},"layout":{"type":"default"}} -->
<div class="wp-block-group newspack-pattern contribution__style-3">

	<!-- wp:image {"sizeSlug":"large","linkDestination":"none","style":{"border":{"radius":{"topLeft":"8px","topRight":"8px"}}}} -->
	<figure class="wp-block-image size-large has-custom-border">
		<img src="<?php echo esc_url( $image_url ); ?>" alt="" style="border-top-left-radius:8px;border-top-right-radius:8px"/>
	</figure>
	<!-- /wp:image -->

	<!-- wp:group {"style":{"elements":{"link":{"color":{"text":"var:preset|color|accent"}}},"color":{"background":"#f7f7f7"},"spacing":{"padding":{"top":"var:preset|spacing|40","bottom":"var:preset|spacing|40","left":"var:preset|spacing|40","right":"var:preset|spacing|40"}},"border":{"radius":{"bottomLeft":"8px","bottomRight":"8px"}}},"textColor":"accent","layout":{"type":"default"}} -->
	<div class="wp-block-group has-accent-color has-text-color has-background has-link-color" style="border-bottom-left-radius:8px;border-bottom-right-radius:8px;background-color:#f7f7f7;padding-top:var(--wp--preset--spacing--40);padding-right:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--40);padding-left:var(--wp--preset--spacing--40)">
		<!-- wp:newspack/contribution-meter {"thickness":"l"} /-->
	</div>
	<!-- /wp:group -->

</div>
<!-- /wp:group -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'With the support of readers like you, we provide thoughtfully researched articles for a more informed and connected community. This is your chance to support credible, community-based, public-service journalism. Please join us!', 'newspack-plugin' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:newspack-blocks/donate {"className":"is-style-modern"} /-->
