<?php
/**
 * Title: Post Header - Style 2
 * Slug: newspack-block-theme/post-header-style-2
 * Categories: newspack-block-theme-post-header
 * Viewport Width: 632
 * Inserter: yes
 *
 * @package Newspack_Block_Theme
 */

?>

<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Post Header', 'newspack-block-theme' ); ?>"},"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group">

	<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"}} -->
	<div class="wp-block-group">
		<!-- wp:post-terms {"term":"category","textAlign":"center","fontSize":"medium"} /-->

		<!-- wp:post-title {"textAlign":"center","level":1} /-->
	</div>
	<!-- /wp:group -->

	<!-- wp:newspack-block-theme/article-subtitle {} /-->

	<!-- wp:pattern {"slug":"newspack-block-theme/post-meta-single-line-avatar"} /-->

</div>
<!-- /wp:group -->
