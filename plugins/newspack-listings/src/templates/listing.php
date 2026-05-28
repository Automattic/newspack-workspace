<?php
/**
 * Template for a single listing item within a curated list.
 *
 * @global array $attributes Block attributes.
 * @package WordPress
 */

use Newspack_Listings\Core;
use Newspack_Listings\Utils;

call_user_func(
	function( $data ) {
		$attributes = $data['attributes'];
		$post       = $data['post'];

		if ( empty( $attributes ) || empty( $post ) ) {
			return;
		}

		// Class names for the listing.
		$classes = array_merge(
			[ 'newspack-listings__listing-post' ],
			Utils\get_term_classes()
		);

		// Get native sponsors.
		$sponsors = Utils\get_sponsors( $post->ID, 'native' );
		?>
	<li class="newspack-listings__listing">
	<article class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
		<?php if ( $attributes['showImage'] ) : ?>
			<?php
			$featured_image = get_the_post_thumbnail( $post->ID, 'large' );
			if ( ! empty( $featured_image ) ) :
				?>
				<figure class="newspack-listings__listing-featured-media">
					<a class="newspack-listings__listing-link" href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>">
						<?php echo wp_kses_post( $featured_image ); ?>
						<?php if ( $attributes['showCaption'] ) : ?>
							<figcaption class="newspack-listings__listing-caption">
								<?php echo wp_kses_post( get_the_post_thumbnail_caption( $post->ID ) ); ?>
							</figcaption>
						<?php endif; ?>
					</a>
				</figure>
			<?php endif; ?>
		<?php endif; ?>

		<div class="newspack-listings__listing-meta">
			<?php
			if ( ! empty( $sponsors ) && isset( $sponsors[0]['sponsor_flag'] ) ) :
				?>
				<span class="cat-links sponsor-label">
					<span class="flag">
						<?php echo esc_html( $sponsors[0]['sponsor_flag'] ); ?>
					</span>
				</span>
				<?php
			elseif ( $attributes['showCategory'] ) :
				$categories = get_the_category( $post->ID );

				if ( is_array( $categories ) && 0 < count( $categories ) ) :
					?>
					<div class="newspack-listings__category cat-links">
						<?php
						$category_index = 0;
						foreach ( $categories as $category ) {
							$term_url = get_term_link( $category->slug, 'category' );

							if ( empty( $term_url ) ) {
								$term_url = '#';
							}
							echo wp_kses_post( $category->name );

							if ( $category_index + 1 < count( $categories ) ) {
								echo esc_html( ', ' );
							}

							$category_index++;
						}
						?>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<a class="newspack-listings__listing-link" href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>">
				<h3 class="newspack-listings__listing-title"><?php echo wp_kses_post( $post->post_title ); ?></h3>
			</a>
			<?php if ( ! empty( $sponsors ) ) : ?>
				<div class="newspack-listings__sponsors">
					<?php
					$sponsor_logos = array_reduce(
						$sponsors,
						function( $acc, $sponsor ) {
							if ( ! empty( $sponsor['sponsor_logo'] ) ) {
								$logo        = $sponsor['sponsor_logo'];
								$logo['url'] = ! empty( $sponsor['sponsor_url'] ) ? $sponsor['sponsor_url'] : false;
								$acc[]       = $logo;
							}
							return $acc;
						},
						[]
					);
					?>
					<?php if ( ! empty( $sponsor_logos ) ) : ?>
						<span class="sponsor-logos">
							<?php
							foreach ( $sponsor_logos as $logo ) {
								if ( empty( $logo['src'] ) || empty( $logo['img_width'] ) || empty( $logo['img_height'] ) ) {
									continue;
								}

								$has_url = ! empty( $logo['url'] );

								echo wp_kses_post(
									sprintf(
										'%1$s<img src="%2$s" width="%3$s" height="%4$s" />%5$s',
										$has_url ? '<a href="' . esc_url( $logo['url'] ) . '" target="_blank" rel="noreferrer">' : '',
										esc_url( $logo['src'] ),
										esc_attr( $logo['img_width'] ),
										esc_attr( $logo['img_height'] ),
										$has_url ? '</a>' : ''
									)
								);
							}
							?>
						</span>
					<?php endif; ?>
					<span class="sponsor-byline">
						<?php
						$sponsor_index = 0;
						$sponsor_count = count( $sponsors );
						foreach ( $sponsors as $sponsor ) {
							$has_url = ! empty( $sponsor['sponsor_url'] );
							echo wp_kses_post(
								sprintf(
									'%1$s%2$s%3$s%4$s%5$s%6$s',
									0 === $sponsor_index ? esc_html( $sponsor['sponsor_byline'] ) . ' ' : '',
									1 < $sponsor_count && $sponsor_index + 1 === $sponsor_count ? __( ' and ', 'newspack-listings' ) : '',
									$has_url ? '<a href="' . esc_url( $sponsor['sponsor_url'] ) . '" target="_blank" rel="noreferrer">' : '',
									esc_html( $sponsor['sponsor_name'] ),
									$has_url ? '</a>' : '',
									2 < $sponsor_count && $sponsor_index + 1 < $sponsor_count ? _x( ', ', 'separator character', 'newspack-listings' ) : ''
								)
							);

							$sponsor_index++;
						}
						?>
					</span>
				</div>
			<?php elseif ( $attributes['showAuthor'] && empty( get_post_meta( $post->ID, 'newspack_listings_hide_author', true ) ) ) : ?>
			<cite>
				<?php echo wp_kses_post( __( 'By', 'newspack-listings' ) . ' ' . get_the_author_meta( 'display_name', $post->post_author ) ); ?>
			</cite>
			<?php endif; ?>

			<a class="newspack-listings__listing-link" href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>">
				<?php
				if ( $attributes['showExcerpt'] ) {
					echo wp_kses_post( Utils\get_listing_excerpt( $post ) );
				}
				?>

				<?php
				if ( $attributes['showTags'] ) :
					$tags = get_the_terms( $post->ID, 'post_tag' );

					if ( is_array( $tags ) && 0 < count( $tags ) ) :
						?>
						<p class="newspack-listings__tags">
							<strong><?php echo esc_html( __( 'Tagged: ', 'newspack-listings' ) ); ?></strong>
							<?php
							$tag_index = 0;
							foreach ( $tags as $tag ) {
								echo wp_kses_post( $tag->name );

								if ( $tag_index + 1 < count( $tags ) ) {
									echo esc_html( ', ' );
								}

								$tag_index++;
							}
							?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
			</a>
		</div>
	</article>
	</li>
		<?php
	},
	$data // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable
);
