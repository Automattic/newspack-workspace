<?php
/**
 * Title: Post Header - Style 2 (Wide)
 * Slug: newspack-block-theme/post-header-style-2-wide
 * Categories: newspack-block-theme-post-header
 * Inserter: yes
 *
 * @package Newspack_Block_Theme
 */

?>

<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Post Header', 'newspack-block-theme' ); ?>"},"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide">

	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:post-terms {"term":"category","textAlign":"center","fontSize":"medium"} /-->

		<!-- wp:post-title {"textAlign":"center","level":1,"fontSize":"xxxx-large"} /-->
	</div>
	<!-- /wp:group -->

	<!-- wp:newspack-block-theme/article-subtitle {} /-->

	<!-- wp:pattern {"slug":"newspack-block-theme/post-meta-single-line-avatar"} /-->

</div>
<!-- /wp:group -->
