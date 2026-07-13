<?php
/**
 * Title: Header (Mobile) - Style 6
 * Slug: newspack-block-theme/header-mobile-style-6
 * Viewport Width: 632
 * Inserter: no
 * Block Types: core/template-part/header
 *
 * @package Newspack_Block_Theme
 */

?>
<!-- wp:group {"lock":{"move":true,"remove":true},"metadata":{"name":"<?php esc_html_e( 'Header (Mobile)', 'newspack-block-theme' ); ?>"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|30","bottom":"0","left":"var:preset|spacing|30","right":"var:preset|spacing|30"},"margin":{"bottom":"var:preset|spacing|50"}}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group has-base-background-color has-background" style="margin-bottom:var(--wp--preset--spacing--50);padding-top:var(--wp--preset--spacing--30);padding-right:var(--wp--preset--spacing--30);padding-bottom:0;padding-left:var(--wp--preset--spacing--30)">

	<!-- wp:group {"lock":{"move":true,"remove":true},"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","justifyContent":"space-between","flexWrap":"nowrap"}} -->
	<div class="wp-block-group alignwide">

		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
		<div class="wp-block-group">
			<!-- wp:site-logo {"width":256,"lock":{"move":false,"remove":true}} /-->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
		<div class="wp-block-group">
			<?php if ( class_exists( 'Newspack\Reader_Activation' ) && \Newspack\Reader_Activation::is_enabled() ) : ?>
				<!-- wp:newspack/my-account-button {"lock":{"move":false,"remove":true},"className":"is-style-icon-only has-x-small-size","style":{"spacing":{"padding":{"top":"0.375rem","bottom":"0.375rem","left":"0.375rem","right":"0.375rem"}},"elements":{"link":{"color":{"text":"var:preset|color|contrast"}}}},"backgroundColor":"base","textColor":"contrast","fontSize":"x-small"} /-->
			<?php endif; ?>

			<!-- wp:newspack/overlay-search {"className":"is-style-icon-only has-background-transparent","style":{"elements":{"link":{"color":{"text":"var:preset|color|contrast"}}},"spacing":{"padding":{"right":"var:preset|spacing|20","left":"var:preset|spacing|20","top":"var:preset|spacing|20","bottom":"var:preset|spacing|20"}}},"textColor":"contrast"} /-->

			<!-- wp:newspack/overlay-menu {"instanceId":"1a382fccbd5d"} -->
				<div class="wp-block-newspack-overlay-menu"><!-- wp:newspack/overlay-menu-trigger {"className":"is-style-icon-only has-background-transparent","style":{"elements":{"link":{"color":{"text":"var:preset|color|contrast"}}},"spacing":{"padding":{"right":"0","left":"0"}}},"textColor":"contrast"} /-->
				<!-- wp:newspack/overlay-menu-panel {"slideDirection":"right"} -->
				<div class="wp-block-newspack-overlay-menu-panel"><!-- wp:navigation {"className":"is-style-flatten","layout":{"type":"flex","setCascadingProperties":true,"orientation":"vertical"}} /--></div>
				<!-- /wp:newspack/overlay-menu-panel --></div>
			<!-- /wp:newspack/overlay-menu -->
		</div>
		<!-- /wp:group -->

	</div>
	<!-- /wp:group -->

</div>
<!-- /wp:group -->
