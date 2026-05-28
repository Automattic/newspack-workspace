<?php
/**
 * Title: Avatar, Byline and Date on multiple lines
 * Slug: newspack-block-theme/post-meta-multiple-lines-avatar
 * Categories: newspack-block-theme-post-meta
 * Viewport Width: 632
 * Inserter: yes
 * Block Types: newspack/byline, newspack/avatar, core/avatar, core/post-author, core/post-date, jetpack/sharing-buttons
 *
 * @package Newspack_Block_Theme
 */

$registry = WP_Block_Type_Registry::get_instance();
?>
<!-- wp:group {"metadata":{"name":"Post Meta"},"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"className":"post-meta","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"bottom"}} -->
<div class="wp-block-group post-meta">

<!-- wp:group {"lock":{"move":false,"remove":true},"metadata":{"name":"Meta"},"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
<div class="wp-block-group">

<?php if ( $registry->get_registered( 'newspack/byline' ) ) : ?>

	<!-- wp:newspack/avatar {"size":48,"linkToAuthorArchive":true,"lock":{"move":true,"remove":true}} /-->

<?php else : ?>

	<!-- wp:avatar {"size":48,"lock":{"move":true,"remove":true}} /-->

<?php endif; ?>

<!-- wp:group {"templateLock":"all","lock":{"move":true,"remove":true},"metadata":{"name":"Byline + Date"},"style":{"spacing":{"blockGap":"4px"}},"layout":{"type":"flex","orientation":"vertical"}} -->
<div class="wp-block-group">

<?php if ( $registry->get_registered( 'newspack/byline' ) ) : ?>

	<!-- wp:newspack/byline {"lock":{"move":true,"remove":true}} /-->

<?php else : ?>

	<!-- wp:post-author {"showAvatar":false,"byline":"By","isLink":true} /-->

<?php endif; ?>

<!-- wp:post-date {"format":"F j, Y"} /-->

<!-- wp:post-date {"metadata":{"bindings":{"datetime":{"source":"core/post-data","args":{"field":"modified"}}}},"className":"wp-block-post-date__modified-date","format":"F j, Y"} /--></div>
<!-- /wp:group --></div>
<!-- /wp:group -->

<!-- wp:pattern {"slug":"newspack-block-theme/jetpack-sharing-buttons"} /-->

</div>
<!-- /wp:group -->
