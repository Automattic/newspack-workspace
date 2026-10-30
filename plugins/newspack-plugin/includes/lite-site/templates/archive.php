<?php
/**
 * Template for the lite site archive page
 *
 * @package newspack
 */

namespace Newspack;

?>
<!DOCTYPE html>
<html lang="<?php bloginfo( 'language' ); ?>">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex, follow">
	<title><?php bloginfo( 'name' ); ?></title>
	<?php require __DIR__ . '/lite-site-styles.php'; ?>
	<?php Lite_Site::get_ga4_snippet(); ?>
</head>
<body>
	<header class="back">
		<a href="<?php echo esc_url( home_url() ); ?>" ><?php esc_html_e( 'View full site', 'newspack-plugin' ); ?></a>
	</header>
	<h1><?php bloginfo( 'name' ); ?></h1>
	<hr class="separator">
	<ul class="post-list">
	<?php
	foreach ( Lite_Site::get_archive_posts() as $current_post ) {
		$is_sticky = is_sticky( $current_post->ID );
		printf(
			'<li>%s<a href="/%s/%d">%s</a>%s</li>',
			$is_sticky ? '<h3>' : '',
			esc_attr( Lite_Site::get_url_base() ),
			esc_attr( $current_post->ID ),
			esc_html( $current_post->post_title ),
			$is_sticky ? '</h3>' : ''
		);
	}
	?>
	</ul>
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
	 * Fires after the footer of the lite site archive page.
	 */
	do_action( 'newspack_lite_site_archive_after_footer' );
	?>

</body>
</html>
