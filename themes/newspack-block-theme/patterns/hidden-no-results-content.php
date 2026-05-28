<?php
// phpcs:ignoreFile
/**
 * Title: Hidden No Results Content
 * Slug: newspack-block-theme/hidden-no-results-content
 * Inserter: no
 *
 * @package Newspack_Block_Theme
 */

?>
<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group">

<!-- wp:paragraph {"fontSize":"x-large"} -->
<p class="has-x-large-font-size"><?php echo esc_html_x( 'Sorry, but nothing matched your search terms. Please try again with some different keywords.', 'Message explaining that there are no results returned from a search', 'newspack-block-theme' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:group {"metadata":{"name":"Posts"},"align":"wide","style":{"spacing":{"margin":{"top":"var:preset|spacing|80"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide" style="margin-top:var(--wp--preset--spacing--80)"><!-- wp:heading {"level":3,"align":"wide"} -->
<h3 class="wp-block-heading alignwide"><?php esc_html_e( 'Latest articles', 'newspack-block-theme' ); ?></h3>
<!-- /wp:heading -->

<!-- wp:newspack-blocks/homepage-articles {"showAvatar":false,"postLayout":"grid","columns":4,"postsToShow":4,"typeScale":3,"align":"wide"} /--></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
