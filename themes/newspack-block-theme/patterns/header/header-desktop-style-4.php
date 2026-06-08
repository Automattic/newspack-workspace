<?php
/**
 * Title: Header (Desktop) - Style 4
 * Slug: newspack-block-theme/header-desktop-style-4
 * Inserter: no
 * Block Types: core/template-part/header
 *
 * @package Newspack_Block_Theme
 */

?>
<!-- wp:group {"lock":{"move":true,"remove":true},"metadata":{"name":"<?php esc_html_e( 'Header (Desktop)', 'newspack-block-theme' ); ?>"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|30","bottom":"0","right":"var:preset|spacing|30","left":"var:preset|spacing|30"},"blockGap":"var:preset|spacing|30","margin":{"bottom":"var:preset|spacing|80"}}},"backgroundColor":"base","layout":{"type":"default"}} -->
<div class="wp-block-group has-base-background-color has-background" style="margin-bottom:var(--wp--preset--spacing--80);padding-top:var(--wp--preset--spacing--30);padding-right:var(--wp--preset--spacing--30);padding-bottom:0;padding-left:var(--wp--preset--spacing--30)">

	<!-- wp:group {"lock":{"move":false,"remove":true},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->
	<div class="wp-block-group">
		<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap"}} -->
		<div class="wp-block-group">
			<!-- wp:newspack/overlay-menu {"instanceId":"fedb5a226df2"} -->
				<div class="wp-block-newspack-overlay-menu"><!-- wp:newspack/overlay-menu-trigger {"className":"has-background-transparent","triggerText":"<?php esc_html_e( 'Menu', 'newspack-block-theme' ); ?>","style":{"typography":{"textTransform":"uppercase"},"elements":{"link":{"color":{"text":"var:preset|color|contrast"}}},"spacing":{"padding":{"top":"var:preset|spacing|20","bottom":"var:preset|spacing|20","left":"var:preset|spacing|20","right":"var:preset|spacing|20"}}},"textColor":"contrast"} /-->
				<!-- wp:newspack/overlay-menu-panel -->
				<div class="wp-block-newspack-overlay-menu-panel"><!-- wp:navigation {"className":"is-style-flatten","layout":{"type":"flex","setCascadingProperties":true,"orientation":"vertical"}} /--></div>
				<!-- /wp:newspack/overlay-menu-panel --></div>
			<!-- /wp:newspack/overlay-menu -->

			<!-- wp:site-logo {"width":256,"lock":{"move":true,"remove":true}} /-->

			<!-- wp:navigation {"className":"primary-navigation","layout":{"type":"flex","setCascadingProperties":true,"justifyContent":"left","orientation":"horizontal","flexWrap":"wrap"},"style":{"typography":{"textTransform":"uppercase"}}} /-->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
		<div class="wp-block-group">
			<!-- wp:buttons {"lock":{"move":true,"remove":false},"className":"has-small-size","style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"fontSize":"x-small"} -->
			<div class="wp-block-buttons has-custom-font-size has-small-size has-x-small-font-size">
				<!-- wp:button -->
				<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"><?php esc_html_e( 'Donate', 'newspack-block-theme' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->

			<?php if ( class_exists( 'Newspack\Reader_Activation' ) && \Newspack\Reader_Activation::is_enabled() ) : ?>
				<!-- wp:newspack/my-account-button {"lock":{"move":true,"remove":false},"style":{"elements":{"link":{"color":{"text":"var:preset|color|contrast"}}}},"backgroundColor":"base-2","textColor":"contrast","className":"has-small-size","fontSize":"x-small"} /-->
			<?php endif; ?>

			<!-- wp:newspack/overlay-search {"className":"is-style-icon-only has-background-transparent","style":{"elements":{"link":{"color":{"text":"var:preset|color|contrast"}}},"spacing":{"padding":{"right":"var:preset|spacing|20","left":"var:preset|spacing|20","top":"var:preset|spacing|20","bottom":"var:preset|spacing|20"}}},"textColor":"contrast"} /-->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->

</div>
<!-- /wp:group -->
