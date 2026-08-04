<?php
/**
 * Logged-out My Account template.
 *
 * Renders only the site header, the Reader Activation auth form as the page
 * content, and the site footer — no page title and no sidebar. Mirrors the
 * WooCommerce account page appearance for signed-out visitors.
 *
 * @package Newspack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

get_header();
?>
	<section id="primary" class="content-area">
		<main id="main" class="site-main">
			<?php echo do_shortcode( '[newspack_my_account]' ); ?>
		</main><!-- #main -->
	</section><!-- #primary -->
<?php
get_footer();
