<?php
/**
 * Title: Byline and Date on the same line
 * Slug: newspack-block-theme/post-meta-single-line
 * Categories: newspack-block-theme-post-meta
 * Viewport Width: 632
 * Inserter: yes
 * Block Types: newspack/byline, core/post-author, core/post-date, jetpack/sharing-buttons
 *
 * @package Newspack_Block_Theme
 */

$registry = WP_Block_Type_Registry::get_instance();
?>
<!-- wp:group {"metadata":{"name":"Post Meta"},"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"className":"post-meta","layout":{"type":"constrained"}} -->
<div class="wp-block-group post-meta">

<!-- wp:group {"metadata":{"name":"Meta"},"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"center","verticalAlignment":"center"}} -->
<div class="wp-block-group">

<?php if ( $registry->get_registered( 'newspack/byline' ) ) : ?>

	<!-- wp:newspack/byline /-->

<?php else : ?>

	<!-- wp:post-author {"showAvatar":false,"byline":"By","isLink":true} /-->

<?php endif; ?>

<!-- wp:separator {"style":{"layout":{"selfStretch":"fixed","flexSize":"1px"},"spacing":{"margin":{"top":"0","bottom":"0"}}},"className":"is-style-thick"} -->
<hr class="wp-block-separator has-alpha-channel-opacity is-style-thick" style="margin-top:0;margin-bottom:0"/>
<!-- /wp:separator -->

<!-- wp:post-date /-->

<!-- wp:post-date {"metadata":{"bindings":{"datetime":{"source":"core/post-data","args":{"field":"modified"}}}},"className":"wp-block-post-date__modified-date"} /--></div>
<!-- /wp:group -->

<!-- wp:pattern {"slug":"newspack-block-theme/jetpack-sharing-buttons-center"} /-->

</div>
<!-- /wp:group -->
