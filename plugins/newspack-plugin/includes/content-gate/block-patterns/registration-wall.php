<?php
/**
 * Memberships Registration Wall Pattern.
 *
 * @package Newspack
 */

?>
<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Registration', 'newspack-plugin' ); ?>"},"align":"wide","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80","left":"var:preset|spacing|80","right":"var:preset|spacing|80"}},"border":{"radius":{"topLeft":"8px","topRight":"8px","bottomLeft":"8px","bottomRight":"8px"},"width":"1px"}},"borderColor":"base-3","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide has-border-color has-base-3-border-color" style="border-width:1px;border-top-left-radius:8px;border-top-right-radius:8px;border-bottom-left-radius:8px;border-bottom-right-radius:8px;padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--80)">
	<!-- wp:heading {"textAlign":"center","level":3,"metadata":{"name":"<?php esc_html_e( 'Title', 'newspack-plugin' ); ?>"}} -->
	<h3 class="wp-block-heading has-text-align-center">
		<?php esc_html_e( 'Continue reading for free', 'newspack-plugin' ); ?>
	</h3>
	<!-- /wp:heading -->

	<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Content', 'newspack-plugin' ); ?>"},"layout":{"type":"constrained","contentSize":"410px"}} -->
	<div class="wp-block-group">
		<!-- wp:paragraph {"align":"center"} -->
		<p class="has-text-align-center">
			<?php esc_html_e( 'Create a free account and unlock unlimited access to this article and all our content.', 'newspack-plugin' ); ?>
		</p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->

	<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Form', 'newspack-plugin' ); ?>"},"layout":{"type":"constrained","contentSize":"410px"}} -->
	<div class="wp-block-group">
		<!-- wp:newspack/reader-registration {"newsletterSubscription":false} -->
		<div class="wp-block-newspack-reader-registration"></div>
		<!-- /wp:newspack/reader-registration -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
