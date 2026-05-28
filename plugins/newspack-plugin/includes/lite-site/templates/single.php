<?php
/**
 * Template for the lite site single post page
 *
 * @package newspack
 */

namespace Newspack;

$current_post_id = get_query_var( 'lite_site_id' );
$current_post = get_post( $current_post_id );

if ( ! $current_post ) {
	status_header( 404 );
	exit( 'Post not found' );
}
?>
<!DOCTYPE html>
<html lang="<?php bloginfo( 'language' ); ?>">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo esc_html( $current_post->post_title ); ?> - <?php bloginfo( 'name' ); ?></title>
	<link rel="canonical" href="<?php echo esc_url( get_permalink( $current_post ) ); ?>">
	<?php require __DIR__ . '/lite-site-styles.php'; ?>
	<?php Lite_Site::get_ga4_snippet(); ?>
</head>
<body>
	<header class="back">
		<a href="/<?php echo esc_attr( Lite_Site::get_url_base() ); ?>">← <?php esc_html_e( 'Back to posts', 'newspack-plugin' ); ?></a> |
		<a href="<?php echo esc_url( get_permalink( $current_post ) ); ?>"><?php esc_html_e( 'View full site', 'newspack-plugin' ); ?></a>
	</header>
	<h1><?php echo esc_html( $current_post->post_title ); ?></h1>
	<div class="meta">
		<div class="authors">
			<?php echo wp_kses_post( Lite_Site::get_authors( $current_post ) ); ?>
		</div>
		<div class="date">
			<?php echo get_the_date(); ?>
		</div>
	</div>
	<hr class="separator">

	<div class="content">
		<?php echo wp_kses_post( Lite_Site::clean_content( $current_post->post_content ) ); ?>
	</div>
	<?php
	$footer_html = Lite_Site::get_footer_html();
	if ( ! empty( $footer_html ) ) :
		?>
		<hr class="separator">
		<footer class="site-footer">
			<?php echo wp_kses_post( $footer_html ); ?>
		</footer>
	<?php endif; ?>


	<?php
	/**
	 * Fires after the footer of the lite site single post page.
	 *
	 * @param WP_Post $current_post The current post.
	 */
	do_action( 'newspack_lite_site_single_after_footer', $current_post );
	?>
</body>
</html>
