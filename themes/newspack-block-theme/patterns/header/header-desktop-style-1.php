<?php
/**
 * Title: Header (Desktop) - Style 1
 * Slug: newspack-block-theme/header-desktop-style-1
 * Inserter: no
 * Block Types: core/template-part/header
 *
 * @package Newspack_Block_Theme
 */

?>
<!-- wp:group {"lock":{"move":true,"remove":true},"metadata":{"name":"<?php esc_html_e( 'Header (Desktop)', 'newspack-block-theme' ); ?>"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|30","bottom":"0","right":"var:preset|spacing|30","left":"var:preset|spacing|30"},"blockGap":"var:preset|spacing|30","margin":{"bottom":"var:preset|spacing|80"}}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group has-base-background-color has-background" style="margin-bottom:var(--wp--preset--spacing--80);padding-top:var(--wp--preset--spacing--30);padding-right:var(--wp--preset--spacing--30);padding-bottom:0;padding-left:var(--wp--preset--spacing--30)">
	<!-- wp:group {"templateLock":false,"lock":{"move":false,"remove":false},"metadata":{"name":"Top"},"align":"wide","layout":{"type":"flex","justifyContent":"space-between"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:site-logo {"width":256,"lock":{"move":true,"remove":true}} /-->

		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
		<div class="wp-block-group">
			<!-- wp:buttons {"lock":{"move":true,"remove":false},"className":"has-small-size","style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"fontSize":"x-small"} -->
			<div class="wp-block-buttons has-custom-font-size has-small-size has-x-small-font-size">
				<!-- wp:button -->
				<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Donate</a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->

			<?php if ( class_exists( 'Newspack\Reader_Activation' ) && \Newspack\Reader_Activation::is_enabled() ) : ?>
				<!-- wp:newspack/my-account-button {"lock":{"move":true,"remove":false},"style":{"elements":{"link":{"color":{"text":"var:preset|color|contrast"}}}},"backgroundColor":"base-2","textColor":"contrast","className":"has-small-size","fontSize":"x-small"} /-->
			<?php endif; ?>
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->

	<!-- wp:group {"lock":{"move":false,"remove":true},"metadata":{"name":"Menu"},"align":"wide","layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between","verticalAlignment":"center"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:navigation {"className":"primary-navigation","layout":{"type":"flex","setCascadingProperties":true,"justifyContent":"left","orientation":"horizontal","flexWrap":"wrap"},"style":{"typography":{"textTransform":"uppercase"}}} /-->

		<!-- wp:template-part {"slug":"search-menu","theme":"newspack-block-theme","tagName":"div","className":"search-menu"} /-->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
