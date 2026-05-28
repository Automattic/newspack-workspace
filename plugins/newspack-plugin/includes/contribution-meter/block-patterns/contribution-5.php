<?php
/**
 * Contribution Meter Pattern 5: Grid with image and thin meter.
 *
 * @package Newspack
 */

$image_url = \Newspack\Newspack::plugin_url() . '/includes/images/contribution-meter/contribution-5.jpg';
?>
<!-- wp:columns {"metadata":{"name":"<?php echo esc_html__( 'Contribution', 'newspack-plugin' ); ?>"},"align":"wide","className":"newspack-pattern contribution__style-5 newspack-grid"} -->
<div class="wp-block-columns alignwide newspack-pattern contribution__style-5 newspack-grid">
	<!-- wp:column {"width":"33.33%"} -->
	<div class="wp-block-column" style="flex-basis:33.33%">
		<!-- wp:heading -->
		<h2 class="wp-block-heading"><?php esc_html_e( 'Support our publication', 'newspack-plugin' ); ?></h2>
		<!-- /wp:heading -->

		<!-- wp:paragraph -->
		<p><?php esc_html_e( 'With the support of readers like you, we provide thoughtfully researched articles for a more informed and connected community. This is your chance to support credible, community-based, public-service journalism. Please join us!', 'newspack-plugin' ); ?></p>
		<!-- /wp:paragraph -->

		<!-- wp:newspack-blocks/donate {"className":"is-style-modern"} /-->
	</div>
	<!-- /wp:column -->

	<!-- wp:column {"width":"66.66%","layout":{"type":"default"}} -->
	<div class="wp-block-column" style="flex-basis:66.66%">
		<!-- wp:image {"sizeSlug":"large","linkDestination":"none"} -->
		<figure class="wp-block-image size-large">
			<img src="<?php echo esc_url( $image_url ); ?>" alt=""/>
		</figure>
		<!-- /wp:image -->

		<!-- wp:newspack/contribution-meter {"thickness":"xs"} /-->

		<!-- wp:paragraph -->
		<p><?php esc_html_e( "Edit and add to this content to tell your publication's story and explain the benefits of becoming a member. This is a good place to mention any special member privileges, let people know that donations are tax-deductible, or provide any legal information.", 'newspack-plugin' ); ?></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:column -->
</div>
<!-- /wp:columns -->
