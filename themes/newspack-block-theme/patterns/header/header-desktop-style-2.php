<?php
/**
 * Title: Header (Desktop) - Style 2
 * Slug: newspack-block-theme/header-desktop-style-2
 * Inserter: no
 * Block Types: core/template-part/header
 *
 * @package Newspack_Block_Theme
 */

?>
<!-- wp:group {"lock":{"move":true,"remove":true},"metadata":{"name":"<?php esc_html_e( 'Header (Desktop)', 'newspack-block-theme' ); ?>"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|30","bottom":"0","right":"var:preset|spacing|30","left":"var:preset|spacing|30"},"blockGap":"var:preset|spacing|30","margin":{"bottom":"var:preset|spacing|80"}}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group has-base-background-color has-background" style="margin-bottom:var(--wp--preset--spacing--80);padding-top:var(--wp--preset--spacing--30);padding-right:var(--wp--preset--spacing--30);padding-bottom:0;padding-left:var(--wp--preset--spacing--30)">

	<!-- wp:columns {"verticalAlignment":"center","align":"wide"} -->
	<div class="wp-block-columns alignwide are-vertically-aligned-center">
		<!-- wp:column {"verticalAlignment":"center"} -->
		<div class="wp-block-column is-vertically-aligned-center">
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"verticalAlignment":"center"} -->
		<div class="wp-block-column is-vertically-aligned-center">
			<!-- wp:site-logo {"width":256,"align":"center","lock":{"move":true,"remove":true}} /-->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"verticalAlignment":"center"} -->
		<div class="wp-block-column is-vertically-aligned-center">
			<?php if ( class_exists( 'Newspack\Reader_Activation' ) && \Newspack\Reader_Activation::is_enabled() ) : ?>
				<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"right"}} -->
				<div class="wp-block-group">
					<!-- wp:newspack/my-account-button {"style":{"elements":{"link":{"color":{"text":"var:preset|color|contrast"}}}},"backgroundColor":"base-2","textColor":"contrast","className":"has-small-size","fontSize":"x-small"} /-->
				</div>
				<!-- /wp:group -->
			<?php endif; ?>
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->

	<!-- wp:group {"lock":{"move":false,"remove":true},"metadata":{"name":"<?php esc_html_e( 'Menu', 'newspack-block-theme' ); ?>"},"align":"wide","layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between","verticalAlignment":"center"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:newspack/overlay-menu {"instanceId":"fedb5a226df2"} -->
			<div class="wp-block-newspack-overlay-menu"><!-- wp:newspack/overlay-menu-trigger {"className":"has-background-transparent","triggerText":"<?php esc_html_e( 'Menu', 'newspack-block-theme' ); ?>","style":{"typography":{"textTransform":"uppercase"},"elements":{"link":{"color":{"text":"var:preset|color|contrast"}}},"spacing":{"padding":{"top":"var:preset|spacing|20","bottom":"var:preset|spacing|20","left":"var:preset|spacing|20","right":"var:preset|spacing|20"}}},"textColor":"contrast"} /-->
			<!-- wp:newspack/overlay-menu-panel -->
			<div class="wp-block-newspack-overlay-menu-panel"><!-- wp:navigation {"className":"is-style-flatten","layout":{"type":"flex","setCascadingProperties":true,"orientation":"vertical"}} /--></div>
			<!-- /wp:newspack/overlay-menu-panel --></div>
		<!-- /wp:newspack/overlay-menu -->

		<!-- wp:navigation {"className":"primary-navigation","layout":{"type":"flex","setCascadingProperties":true,"justifyContent":"center","orientation":"horizontal","flexWrap":"wrap"},"style":{"typography":{"textTransform":"uppercase"}}} /-->

		<!-- wp:newspack/overlay-search {"className":"is-style-icon-only has-background-transparent","style":{"elements":{"link":{"color":{"text":"var:preset|color|contrast"}}},"spacing":{"padding":{"right":"var:preset|spacing|20","left":"var:preset|spacing|20","top":"var:preset|spacing|20","bottom":"var:preset|spacing|20"}}},"textColor":"contrast"} /-->
	</div>
	<!-- /wp:group -->

</div>
<!-- /wp:group -->
