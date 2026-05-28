<?php
/**
 * Correction Loop Block Pattern.
 *
 * @package Newspack
 */

?>

<!-- wp:group -->
<div class="wp-block-group">
<!-- wp:query {"queryId":0,"query":{"perPage":20,"pages":0,"offset":0,"postType":"newspack_correction","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false,"taxQuery":null,"parents":[]},"tagName":"main","layout":{"type":"constrained"}} -->
<main class="wp-block-query">

	<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30","margin":{"bottom":"var:preset|spacing|80"}}},"layout":{"type":"flex","orientation":"vertical"}} -->
	<div class="wp-block-group" style="margin-bottom:var(--wp--preset--spacing--80)">
		<!-- wp:heading {"level":1} -->
		<h1 class="wp-block-heading"><?php esc_html_e( 'Corrections & Clarifications', 'newspack-plugin' ); ?></h1>
		<!-- /wp:heading -->

		<!-- wp:paragraph -->
		<p><?php esc_html_e( 'We are committed to truthful, transparent, and accurate reporting. When we make a mistake, we correct it promptly and note the change clearly. Clarifications, when needed, provide additional context.', 'newspack-plugin' ); ?></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->

	<!-- wp:post-template {"layout":{"type":"default"}} -->
		<!-- wp:newspack/correction-item /-->
	<!-- /wp:post-template -->

	<!-- wp:group {"metadata":{"name":"Pagination"},"className":"wp-block-query-pagination__container","style":{"spacing":{"margin":{"top":"var:preset|spacing|80"},"blockGap":"var:preset|spacing|30"}},"layout":{"type":"default"}} -->
	<div class="wp-block-group wp-block-query-pagination__container" style="margin-top:var(--wp--preset--spacing--80)">
		<!-- wp:separator {"className":"is-style-wide","style":{"spacing":{"margin":{"bottom":"var:preset|spacing|30"}}}} -->
		<hr class="wp-block-separator has-alpha-channel-opacity is-style-wide" style="margin-bottom:var(--wp--preset--spacing--30)"/>
		<!-- /wp:separator -->

		<!-- wp:query-pagination {"paginationArrow":"arrow","showLabel":false,"layout":{"type":"flex","justifyContent":"space-between"}} -->
			<!-- wp:query-pagination-previous /-->

			<!-- wp:query-pagination-numbers {"midSize":1} /-->

			<!-- wp:query-pagination-next /-->
		<!-- /wp:query-pagination -->
	</div>
	<!-- /wp:group -->

</main>
<!-- /wp:query -->
</div>
<!-- /wp:group -->
