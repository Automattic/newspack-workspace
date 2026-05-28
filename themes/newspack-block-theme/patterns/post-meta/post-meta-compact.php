<?php
/**
 * Title: Compact Byline and Date
 * Slug: newspack-block-theme/post-meta-compact
 * Categories: newspack-block-theme-post-meta
 * Viewport Width: 632
 * Inserter: yes
 * Block Types: newspack/byline, core/post-author, core/post-date
 *
 * @package Newspack_Block_Theme
 */

$registry = WP_Block_Type_Registry::get_instance();
?>
<!-- wp:group {"metadata":{"name":"Post Meta"},"className":"post-meta","style":{"spacing":{"blockGap":"4px"}},"layout":{"type":"flex","orientation":"vertical"}} -->
<div class="wp-block-group post-meta">

<?php if ( $registry->get_registered( 'newspack/byline' ) ) : ?>

	<!-- wp:newspack/byline {"fontSize":"x-small"} /-->

<?php else : ?>

	<!-- wp:post-author {"showAvatar":false,"byline":"By","isLink":true,"fontSize":"x-small"} /-->

<?php endif; ?>

<!-- wp:post-date {"fontSize":"x-small"} /-->

<!-- wp:post-date {"metadata":{"bindings":{"datetime":{"source":"core/post-data","args":{"field":"modified"}}}},"className":"wp-block-post-date__modified-date","fontSize":"x-small"} /-->

</div>
<!-- /wp:group -->
