<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- Filename must match post type slug for WordPress template hierarchy.
/**
 * The template for displaying archive pages for the newspack_correction post type
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package Newspack
 */

get_header();

?>

	<section id="primary" class="content-area">

		<header class="page-header">

			<span>

				<h1 class="page-title"><?php esc_html_e( 'Corrections and clarifications', 'newspack-theme' ); ?></h1>

				<?php do_action( 'newspack_theme_below_archive_title' ); ?>


			</span>

		</header><!-- .page-header -->

		<?php do_action( 'before_archive_posts' ); ?>

		<main id="main" class="site-main">

		<?php
		if ( have_posts() ) :
			$post_count = 0;

			// Start the Loop.
			while ( have_posts() ) :
				++$post_count;
				the_post();

				get_template_part( 'template-parts/content/content', 'corrections' );

				do_action( 'after_archive_post', $post_count );
				// End the loop.
			endwhile;

			// Previous/next page navigation.
			the_posts_pagination(
				array(
					'mid_size'  => 2,
					'prev_text' => __( 'Previous', 'newspack-theme' ),
					'next_text' => __( 'Next', 'newspack-theme' ),
				)
			);

			// If no content, include the "No posts found" template.
		else :
			get_template_part( 'template-parts/content/content', 'none' );

		endif;
		?>
		</main><!-- #main -->
		<?php
		$archive_layout = get_theme_mod( 'archive_layout', 'default' );
		if ( 'default' === $archive_layout ) {
			get_sidebar();
		}
		?>
	</section><!-- #primary -->

<?php
get_footer();
