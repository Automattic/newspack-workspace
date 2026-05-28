<?php
/**
 * Contribution Meter Pattern 4: Grid with circular meter highlight.
 *
 * @package Newspack
 */

$image_url = \Newspack\Newspack::plugin_url() . '/includes/images/contribution-meter/contribution-4.jpg';
?>
<!-- wp:columns {"verticalAlignment":null,"metadata":{"name":"<?php echo esc_html__( 'Contribution', 'newspack-plugin' ); ?>"},"align":"wide","className":"newspack-pattern contribution__style-4 newspack-grid"} -->
<div class="wp-block-columns alignwide newspack-pattern contribution__style-4 newspack-grid">
	<!-- wp:column {"width":"66.66%","layout":{"type":"default"}} -->
	<div class="wp-block-column" style="flex-basis:66.66%">
		<!-- wp:image {"sizeSlug":"large","linkDestination":"none"} -->
		<figure class="wp-block-image size-large">
			<img src="<?php echo esc_url( $image_url ); ?>" alt=""/>
		</figure>
		<!-- /wp:image -->

		<!-- wp:heading {"level":3} -->
		<h3 class="wp-block-heading"><?php esc_html_e( 'Support our publication', 'newspack-plugin' ); ?></h3>
		<!-- /wp:heading -->

		<!-- wp:paragraph -->
		<p><?php esc_html_e( 'With the support of readers like you, we provide thoughtfully researched articles for a more informed and connected community. This is your chance to support credible, community-based, public-service journalism. Please join us!', 'newspack-plugin' ); ?></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:column -->

	<!-- wp:column {"verticalAlignment":"top","width":"33.33%"} -->
	<div class="wp-block-column is-vertically-aligned-top" style="flex-basis:33.33%">
		<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|40","bottom":"var:preset|spacing|40","left":"var:preset|spacing|40","right":"var:preset|spacing|40"}},"border":{"radius":"8px"},"elements":{"link":{"color":{"text":"var:preset|color|base"}}}},"backgroundColor":"accent","textColor":"base","layout":{"type":"constrained"}} -->
		<div class="wp-block-group has-base-color has-accent-background-color has-text-color has-background has-link-color" style="border-radius:8px;padding-top:var(--wp--preset--spacing--40);padding-right:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--40);padding-left:var(--wp--preset--spacing--40)">
			<!-- wp:newspack/contribution-meter {"className":"is-style-circular"} /-->
		</div>
		<!-- /wp:group -->

		<!-- wp:paragraph {"fontSize":"small"} -->
		<p class="has-small-font-size"><?php esc_html_e( "Edit and add to this content to tell your publication's story and explain the benefits of becoming a member. This is a good place to mention any special member privileges, let people know that donations are tax-deductible, or provide any legal information.", 'newspack-plugin' ); ?></p>
		<!-- /wp:paragraph -->

		<!-- wp:newspack-blocks/donate {"className":"is-style-modern"} /-->
	</div>
	<!-- /wp:column -->
</div>
<!-- /wp:columns -->
