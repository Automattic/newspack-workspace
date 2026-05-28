<?php
/**
 * The template for displaying Collections archives.
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package Newspack\Collections
 *
 * @phpcs:disable WordPress.Security.NonceVerification.Recommended
 */

use Newspack\Collections\Query_Helper;
use Newspack\Collections\Settings;
use Newspack\Collections\Template_Helper;

get_header();

/**
 * Fires at the start of the collections archive template.
 */
do_action( 'newspack_collections_archive_start' );
?>

<section id="primary" class="content-area">
	<header class="page-header">
		<h1 class="page-title"><span class="page-description"><?php echo esc_html( Settings::get_collection_label() ); ?></span></h1>
	</header><!-- .page-header -->

	<main id="main" class="site-main">

		<?php
		if ( have_posts() ) :
			global $wp_query;

			$selected_year    = isset( $_GET[ Settings::YEAR_QUERY_PARAM ] ) ? sanitize_text_field( $_GET[ Settings::YEAR_QUERY_PARAM ] ) : '';
			$highlight_latest = empty( $selected_year ) && ! is_paged() && Settings::get_setting( 'highlight_latest' );

			// Render the intro section only if no year filter is applied, it's the first page of results and "Highlight Most Recent Collection" setting is enabled.
			if ( $highlight_latest ) :
				$latest_collection = $wp_query->posts[0] ?? null;
				if ( $latest_collection ) {
					echo wp_kses_post( Template_Helper::render_collections_intro( $latest_collection, [ 'headingText' => __( 'Latest', 'newspack-plugin' ) ] ) );
				}

				echo wp_kses_post( Template_Helper::render_separator( 'is-latest-collection' ) );
			endif;
			?>

			<!-- Filter controls -->
			<form class="collections-filter" method="get">
				<?php
				$selected_category = isset( $_GET[ Settings::CATEGORY_QUERY_PARAM ] ) ? sanitize_text_field( $_GET[ Settings::CATEGORY_QUERY_PARAM ] ) : '';
				$available_years   = Query_Helper::get_available_years( $selected_category );
				?>

				<div class="collections-filter__select">
					<label for="year"><?php esc_html_e( 'Year:', 'newspack-plugin' ); ?></label>
					<select name="year" id="year">
						<option value="" <?php selected( $selected_year, '' ); ?>><?php esc_html_e( 'All', 'newspack-plugin' ); ?></option>
						<?php foreach ( $available_years as $available_year ) : ?>
							<option value="<?php echo esc_attr( $available_year ); ?>" <?php selected( $selected_year, $available_year ); ?>><?php echo esc_html( $available_year ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<?php
				$categories = Query_Helper::get_collection_categories();

				if ( count( $categories ) > 1 ) :
					?>
					<div class="collections-filter__select">
						<label for="category"><?php echo esc_html( Settings::get_setting( 'category_filter_label', _x( 'Publication:', 'collections category filter label', 'newspack-plugin' ) ) ); ?></label>
						<select name="category" id="category">
							<option value="" <?php selected( $selected_category, '' ); ?>><?php esc_html_e( 'All', 'newspack-plugin' ); ?></option>
							<?php foreach ( $categories as $category ) : ?>
								<option value="<?php echo esc_attr( $category->slug ); ?>" <?php selected( $selected_category, $category->slug ); ?>><?php echo esc_html( $category->name ); ?></option>
							<?php endforeach; ?>
							?>
						</select>
					</div>
				<?php endif; ?>

			</form> <!-- .collections-filter -->

			<?php
			echo wp_kses_post( Template_Helper::render_separator() );

			/**
			 * Fires after the filter controls in the archive template.
			 *
			 * @param string $selected_year     The selected year filter.
			 * @param string $selected_category The selected category filter.
			 */
			do_action( 'newspack_collections_archive_after_filters', $selected_year, $selected_category );
			?>

			<!-- Collections grid -->
			<?php
			$collections = $wp_query->posts;

			// Determine if first collection should be excluded (already shown in intro).
			if ( $highlight_latest && count( $collections ) > 0 ) {
				$collections = array_slice( $collections, 1 );
			}

			// Render the grid using the Collections block.
			echo wp_kses_post( Template_Helper::render_collections_grid( $collections ) );

			/**
			 * Fires before the navigation in the archive template.
			 */
			do_action( 'newspack_collections_archive_before_navigation' );

			// Use Newspack theme navigation if it exists, otherwise use core navigation.
			if ( function_exists( 'newspack_the_posts_navigation' ) ) {
				newspack_the_posts_navigation();
			} else {
				the_posts_navigation();
			}

		else :
			get_template_part( 'template-parts/content/content', 'none' );

		endif;
		?>

	</main><!-- #main -->

</section><!-- #primary -->

<?php
/**
 * Fires at the end of the collections archive template.
 */
do_action( 'newspack_collections_archive_end' );

get_footer();
