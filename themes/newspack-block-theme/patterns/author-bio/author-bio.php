<?php
/**
 * Title: Author Bio
 * Slug: newspack-block-theme/author-bio
 * Categories: newspack-block-theme-author-bio
 * Viewport Width: 632
 * Inserter: yes
 * Block Types: newspack-blocks/author-profile, core/post-author-name, core/post-author-biography
 *
 * @package Newspack_Block_Theme
 */

$registry = WP_Block_Type_Registry::get_instance();
?>
<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Author Bio', 'newspack-block-theme' ); ?>"},"layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"}} -->
<div class="wp-block-group">

	<!-- wp:separator {"className":"is-style-wide"} -->
	<hr class="wp-block-separator has-alpha-channel-opacity is-style-wide"/>
	<!-- /wp:separator -->

<?php if ( $registry->get_registered( 'newspack-blocks/author-profile' ) ) : ?>

	<!-- wp:newspack-blocks/author-profile {"isContextual":true,"layoutVersion":2,"variation":"compact"} /-->

<?php else : ?>

	<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Content', 'newspack-block-theme' ); ?>"},"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"default"}} -->
	<div class="wp-block-group">

		<!-- wp:post-author-name {"style":{"elements":{"link":{"color":{"text":"var:preset|color|contrast"}}},"typography":{"fontStyle":"normal","fontWeight":"700"},"spacing":{"margin":{"top":"0","bottom":"0"}}},"textColor":"contrast","fontSize":"large"} /-->

		<!-- wp:post-author-biography /-->

	</div>
	<!-- /wp:group -->

<?php endif; ?>

	<!-- wp:separator {"className":"is-style-wide"} -->
	<hr class="wp-block-separator has-alpha-channel-opacity is-style-wide"/>
	<!-- /wp:separator -->

</div>
<!-- /wp:group -->
