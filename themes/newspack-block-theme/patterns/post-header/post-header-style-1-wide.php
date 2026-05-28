<?php
/**
 * Title: Post Header - Style 1 (Wide)
 * Slug: newspack-block-theme/post-header-style-1-wide
 * Categories: newspack-block-theme-post-header
 * Inserter: yes
 *
 * @package Newspack_Block_Theme
 */

?>

<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Post Header', 'newspack-block-theme' ); ?>"},"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"constrained","justifyContent":"left"}} -->
<div class="wp-block-group alignwide">

	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"flex","orientation":"vertical"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:post-terms {"term":"category","fontSize":"medium"} /-->

		<!-- wp:post-title {"level":1,"fontSize":"xxxx-large"} /-->
	</div>
	<!-- /wp:group -->

	<!-- wp:newspack-block-theme/article-subtitle {} /-->

	<!-- wp:group {"align":"wide","layout":{"type":"default"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:pattern {"slug":"newspack-block-theme/post-meta-multiple-lines-avatar"} /-->
	</div>
	<!-- /wp:group -->

</div>
<!-- /wp:group -->
