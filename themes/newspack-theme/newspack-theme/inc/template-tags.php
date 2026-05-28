<?php
/**
 * Custom template tags for this theme
 *
 * @package Newspack
 */

if ( ! function_exists( 'newspack_posted_on' ) ) :
	/**
	 * Prints HTML with meta information for the current post-date/time.
	 */
	function newspack_posted_on() {
		if ( true === apply_filters( 'newspack_listings_hide_publish_date', false ) ) {
			return;
		}

		$is_modified = get_the_time( 'U' ) !== get_the_modified_time( 'U' );
		$time_string = sprintf(
			'<time class="entry-date published%1$s" datetime="%2$s">%3$s</time>',
			$is_modified ? '' : ' updated',
			esc_attr( get_the_date( DATE_W3C ) ),
			esc_html( get_the_date() )
		);

		/**
		 * Filters whether to include a hidden <time class="updated"> element.
		 *
		 * The theme outputs a hidden updated time for publishers who use
		 * custom CSS to show it. Plugins that render their own updated
		 * date can return false to suppress the duplicate.
		 *
		 * @param bool $include Whether to include the hidden updated time. Default true.
		 */
		$include_hidden_updated = apply_filters( 'newspack_theme_include_hidden_updated_time', true );

		if ( $is_modified && $include_hidden_updated ) {
			$time_string .= sprintf(
				'<time class="updated" datetime="%1$s">%2$s</time>',
				esc_attr( get_the_modified_date( DATE_W3C ) ),
				esc_html( get_the_modified_date() )
			);
		}

		$allowed_tags = array(
			'time' => array(
				'class'    => array(),
				'datetime' => array(),
			),
		);
		$time_string = wp_kses( $time_string, $allowed_tags );

		if ( is_single() ) {
			echo '<span class="posted-on">' . $time_string; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped via wp_kses above.
		} else {
			printf(
				'<span class="posted-on"><a href="%1$s" rel="bookmark">%2$s</a>',
				esc_url( get_permalink() ),
				$time_string // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped via wp_kses above.
			);
		}

		/**
		 * Fires inside the .posted-on span, after the publish date.
		 *
		 * Used by newspack-plugin to render the "Updated" date
		 * inside the same markup container as the publish date.
		 */
		do_action( 'newspack_theme_posted_on' );

		echo '</span>';
	}
endif;

if ( ! function_exists( 'newspack_posted_by' ) ) :
	/**
	 * Prints HTML with meta information about theme author.
	 */
	function newspack_posted_by() {
		if ( true === apply_filters( 'newspack_listings_hide_author', false ) ) {
			return;
		}

		// Short-circuit function if has newspack_posted_by_overwrite filter.
		$byline = apply_filters( 'pre_newspack_posted_by', false );

		if ( $byline ) :
			echo wp_kses_post( $byline );
			return;
		elseif ( function_exists( 'coauthors_posts_links' ) && ! empty( get_coauthors() ) ) : // phpcs:ignore PHPCompatibility.LanguageConstructs.NewEmptyNonVariable.Found

			$authors      = get_coauthors();
			$author_count = count( $authors );
			$i            = 1;

			foreach ( $authors as $author ) {
				// avatar_img_tag is a property added by Newspack Network plugin to distributed posts.
				$author_avatar = $author->avatar_img_tag ?? coauthors_get_avatar( $author, 80 );

				echo '<span class="author-avatar">' . wp_kses( $author_avatar, newspack_sanitize_avatars() ) . '</span>';
			}
			?>

			<span class="byline">
				<span><?php echo esc_html__( 'by', 'newspack-theme' ); ?></span>
				<?php
				foreach ( $authors as $author ) {

					++$i;
					if ( $author_count === $i ) :
						/* translators: separates last two names; needs a space on either side. */
						$sep = esc_html__( ' and ', 'newspack-theme' );
					elseif ( $author_count > $i ) :
						/* translators: separates all but the last two names; needs a space at the end. */
						$sep = esc_html__( ', ', 'newspack-theme' );
					else :
						$sep = '';
					endif;

					$author_link = get_author_posts_url( $author->ID, $author->user_nicename );

					if ( '#' !== $author_link ) {
						$author_name = sprintf(
							'<a class="url fn n" href="%1$s">%2$s</a>',
							esc_url( $author_link ),
							esc_html( $author->display_name )
						);
					} else {
						$author_name = esc_html( $author->display_name );
					}

					printf(
						'<span class="author vcard">%1$s</span>%2$s ',
						$author_name, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
						esc_html( $sep )
					);
				}
				?>
			</span><!-- .byline -->
			<?php
		else :
			$author = get_the_author();
			if ( empty( $author ) ) {
				return;
			}

			printf(
				/* translators: 1: Author avatar. 2: post author, only visible to screen readers. 3: author link. */
				'<span class="author-avatar">%1$s</span><span class="byline"><span>%2$s</span> <span class="author vcard"><a class="url fn n" href="%3$s">%4$s</a></span></span>',
				get_avatar( get_the_author_meta( 'ID' ) ),
				esc_html__( 'by', 'newspack-theme' ),
				esc_url( get_author_posts_url( get_the_author_meta( 'ID' ) ) ),
				esc_html( $author )
			);

		endif;
	}
endif;

if ( ! function_exists( 'newspack_post_subtitle' ) ) :
	/**
	 * Prints the post subtitle.
	 */
	function newspack_post_subtitle() {
		$subtitle = get_post_meta( get_the_ID(), 'newspack_post_subtitle', true );
		// TODO: Keep in sync with SUBTITLE_ALLOWED_TAGS in js/src/post-subtitle/utils.js.
		$subtitle_allowed_tags = array(
			'b'      => true,
			'strong' => true,
			'i'      => true,
			'em'     => true,
			'mark'   => true,
			'u'      => true,
			'small'  => true,
			'sub'    => true,
			'sup'    => true,
			'a'      => array(
				'href' => true,
			),
		);
		if ( $subtitle ) :
			return wptexturize( wp_kses( $subtitle, $subtitle_allowed_tags ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		endif;
	}
endif;

if ( ! function_exists( 'newspack_author_social_links' ) ) :
	/**
	 * Prints list of social links for the current author.
	 */
	function newspack_author_social_links( $author_id, $size = 24 ) {
		$links = newspack_author_get_social_links( $author_id, $size );

		// Create array of allowed HTML, including SVG markup.
		$allowed_html = array(
			'a'  => array(
				'href'   => array(),
				'title'  => array(),
				'target' => array(),
			),
			'li' => array(
				'class' => array(),
			),
		);
		$allowed_html = array_merge( $allowed_html, newspack_sanitize_svgs() );

		if ( '' !== $links && true === get_theme_mod( 'show_author_social', false ) ) {
			echo '<ul class="author-social-links">' . wp_kses( $links, $allowed_html ) . '</ul>';
		}
	}
endif;

if ( ! function_exists( 'newspack_author_get_social_links' ) ) :
	/**
	 * Gets a list of social links for the current author.
	 *
	 * @param integer $author_id The author ID.
	 * @param integer $size The SVG icon size.
	 */
	function newspack_author_get_social_links( $author_id, $size = 24 ) {
		// Get list of available social profiles.
		$social_profiles = array(
			'facebook',
			'twitter',
			'instagram',
			'linkedin',
			'myspace',
			'pinterest',
			'soundcloud',
			'tumblr',
			'youtube',
			'wikipedia',
			'bluesky',
		);

		// Create empty string for links.
		$links = '';

		foreach ( $social_profiles as $profile ) {
			if ( '' !== get_the_author_meta( $profile, $author_id ) ) {
				if ( 'twitter' === $profile ) {
					$links .= '<li class="twitter"><a href="https://x.com/' . esc_attr( get_the_author_meta( $profile, $author_id ) ) . '" target="_blank">' . newspack_get_social_icon_svg( 'x', $size, 'x' ) . '</a></li>';
				} else {
					$links .= '<li class="' . esc_attr( $profile ) . '"><a href="' . esc_url( get_the_author_meta( $profile, $author_id ) ) . '" target="_blank">' . newspack_get_social_icon_svg( $profile, $size, $profile ) . '</a></li>';
				}
			}
		}

		return $links;
	}
endif;

if ( ! function_exists( 'newspack_comment_count' ) ) :
	/**
	 * Prints HTML with the comment count for the current post.
	 */
	function newspack_comment_count() {
		if ( ! post_password_required() && ( comments_open() || get_comments_number() ) ) {
			echo '<span class="comments-link">';
			echo wp_kses( newspack_get_icon_svg( 'comment', 16 ), newspack_sanitize_svgs() );

			/* translators: %s: Name of current post. Only visible to screen readers. */
			comments_popup_link( sprintf( __( 'Leave a comment<span class="screen-reader-text"> on %s</span>', 'newspack-theme' ), get_the_title() ) );

			echo '</span>';
		}
	}
endif;

if ( ! function_exists( 'newspack_categories' ) ) :
	/**
	 * Prints HTML with the current post's categories.
	 */
	function newspack_categories() {
		$categories_list = '';

		// Use the shared Primary_Category utility from newspack-plugin if available.
		if ( class_exists( 'Newspack\Primary_Category' ) ) {
			$primary = \Newspack\Primary_Category::get();
			if ( $primary ) {
				$categories_list = '<a href="' . esc_url( get_category_link( $primary->term_id ) ) . '" rel="category tag">' . esc_html( $primary->name ) . '</a>';
			}
		}

		if ( ! $categories_list ) {
			/* translators: used between list items; followed by a space. */
			$categories_list = get_the_category_list( '<span class="sep">' . esc_html__( ',', 'newspack-theme' ) . ' </span>' );
		}

		if ( $categories_list ) {
			printf(
				/* translators: 1: posted in label, only visible to screen readers. 2: list of categories. */
				'<span class="cat-links"><span class="screen-reader-text">%1$s</span>%2$s</span>',
				esc_html__( 'Posted in', 'newspack-theme' ),
				wp_kses_post( apply_filters( 'newspack_theme_categories', $categories_list ) )
			);
		}
	}
endif;

if ( ! function_exists( 'newspack_previous_next' ) ) :
	/**
	 * Prints previous and next links for single posts.
	 */
	function newspack_previous_next() {
		$show_prev_next_links = get_theme_mod( 'post_previous_next', false );

		if ( true === $show_prev_next_links && is_singular( 'post' ) ) {
			the_post_navigation(
				array(
					'next_text' => '<span class="meta-nav">' . __( 'Next', 'newspack-theme' ) . '</span> ' .
						'<span class="post-title">%title</span>',
					'prev_text' => '<span class="meta-nav">' . __( 'Previous', 'newspack-theme' ) . '</span> ' .
						'<span class="post-title">%title</span>',
				)
			);
		}
	}
endif;

if ( ! function_exists( 'newspack_entry_footer' ) ) :
	/**
	 * Prints HTML with meta information for the tags and comments.
	 */
	function newspack_entry_footer() {

		// Hide author, post date, category and tag text for pages.
		if ( 'post' === get_post_type() ) {
			/* translators: used between list items; followed by a space. */
			$tags_list = get_the_tag_list( '', '<span class="sep">' . esc_html__( ',', 'newspack-theme' ) . '&nbsp;</span>' );
			if ( $tags_list ) {
				printf(
					/* translators: 1: posted in label, only visible to screen readers. 2: list of tags. */
					'<span class="tags-links"><span>%1$s </span>%2$s</span>',
					esc_html__( 'Tagged:', 'newspack-theme' ),
					wp_kses_post( $tags_list )
				);
			}
		}

		// Edit post link.
		edit_post_link(
			sprintf(
				wp_kses(
					/* translators: %s: Name of current post; only visible to screen readers. */
					__( 'Edit <span class="screen-reader-text">%s</span>', 'newspack-theme' ),
					array(
						'span' => array(
							'class' => array(),
						),
					)
				),
				get_the_title()
			),
			'<span class="edit-link">' . newspack_get_icon_svg( 'edit', 16 ),
			'</span>'
		);
	}
endif;

if ( ! function_exists( 'newspack_post_thumbnail' ) ) :
	/**
	 * Displays an optional post thumbnail.
	 *
	 * Wraps the post thumbnail in an anchor element on index views, or a div
	 * element when on single views.
	 *
	 * @param string $size Optional custom image size name to use.
	 */
	function newspack_post_thumbnail( $size = 'newspack-featured-image' ) {
		if ( ! newspack_can_show_post_thumbnail() ) {
			return;
		}

		$after_first_featured_image = isset( $GLOBALS['newspack_after_first_featured_image'] );

		$default_image_attributes = array(
			'loading'             => $after_first_featured_image ? 'lazy' : 'eager', // Disable lazy loading for first featured image on the page.
			'data-hero-candidate' => $after_first_featured_image ? false : true, // Make this image a hero candidate for AMP prerendering.
			'fetchpriority'       => $after_first_featured_image ? false : 'high',
		);

		if ( is_singular() ) :
			?>

			<figure class="post-thumbnail">

				<?php

				// If using the behind or beside image styles, add the object-fit argument for AMP.
				if ( in_array( newspack_featured_image_position(), array( 'behind', 'beside' ), true ) ) :

					the_post_thumbnail(
						$size,
						wp_parse_args(
							array(
								'object-fit' => 'cover',
							),
							$default_image_attributes
						)
					);
				else :

					if ( 'above' === newspack_featured_image_position() ) :
						the_post_thumbnail(
							$size,
							wp_parse_args(
								array(
									'layout' => 'responsive',
								),
								$default_image_attributes
							)
						);
					else :
						the_post_thumbnail( $size, $default_image_attributes );
					endif;

					newspack_post_thumbnail_caption();
				endif;
				?>

			</figure><!-- .post-thumbnail -->

		<?php else : ?>

			<figure class="post-thumbnail">
				<a class="post-thumbnail-inner" href="<?php the_permalink(); ?>" aria-hidden="true" tabindex="-1">
					<?php the_post_thumbnail( $size, $default_image_attributes ); ?>
				</a>
				<?php if ( get_theme_mod( 'archive_show_captions' ) || get_theme_mod( 'archive_show_credits' ) ) : ?>
					<?php
					$featured_image_id = get_post_thumbnail_id();
					$caption           = wp_get_attachment_caption( $featured_image_id );
					$credit            = method_exists( 'Newspack\Newspack_Image_Credits', 'get_media_credit_string' ) && \Newspack\Newspack_Image_Credits::get_media_credit_string( $featured_image_id );
					if ( $caption || $credit ) :
						?>
						<figcaption>
							<?php if ( get_theme_mod( 'archive_show_captions' ) && $caption ) : ?>
								<?php echo esc_html( $caption ); ?>
							<?php endif; ?>
							<?php if ( get_theme_mod( 'archive_show_credits' ) && $credit ) : ?>
								<?php echo wp_kses_post( \Newspack\Newspack_Image_Credits::get_media_credit_string( get_post_thumbnail_id() ) ); ?>
							<?php endif; ?>
						</figcaption>
					<?php endif; ?>
				<?php endif; ?>
			</figure>

			<?php
		endif; // End is_singular().

		// Set a global variable to identify that the first featured image has been displayed.
		if ( ! isset( $GLOBALS['newspack_after_first_featured_image'] ) ) {
			$GLOBALS['newspack_after_first_featured_image'] = true;
		}
	}
endif;

if ( ! function_exists( 'newspack_post_thumbnail_caption' ) ) {
	/**
	 * Displays a post thumbnail caption and/or credit.
	 *
	 * Wraps the caption and credit in a figcaption and span.
	 */
	function newspack_post_thumbnail_caption() {
		if ( ! newspack_can_show_post_thumbnail() ) {
			return;
		}

		// Check the existance of the caption separately, so filters -- like ones that add ads -- don't interfere.
		$thumbnail      = get_post( get_post_thumbnail_id() );
		$caption_exists = $thumbnail && $thumbnail->post_excerpt;

		// Only get the caption if one exists.
		if ( $caption_exists ) {
			$caption = get_the_excerpt( get_post_thumbnail_id() );
		}

		// Account for featured images that have a credit but no caption.
		if ( ! $caption_exists && class_exists( '\Newspack\Newspack_Image_Credits' ) ) {
			$maybe_newspack_image_credit = \Newspack\Newspack_Image_Credits::get_media_credit_string( get_post_thumbnail_id() );
			if ( strlen( wp_strip_all_tags( $maybe_newspack_image_credit ) ) ) {
				$caption        = $maybe_newspack_image_credit;
				$caption_exists = true;
			}
		}

		if ( $caption_exists ) :
			?>
			<figcaption><span><?php echo wp_kses_post( $caption ); ?></span></figcaption>
			<?php
		endif;
	}
}

if ( ! function_exists( 'newspack_comment_form' ) ) :
	/**
	 * Documentation for function.
	 */
	function newspack_comment_form( $order ) {
		if ( true === $order || strtolower( $order ) === strtolower( get_option( 'comment_order', 'asc' ) ) ) {

			$comment_attributes = array(
				'logged_in_as'       => null,
				'title_reply'        => null,
				'title_reply_before' => null,
				'title_reply_after'  => null,
			);

			$comment_policy = get_theme_mod( 'comment_policy', '' );
			$display_policy = get_theme_mod( 'display_comment_policy', false );

			// Check if there's a comment policy set in the Customizer.
			if ( $display_policy && '' !== trim( $comment_policy ) ) {
				$comment_attributes['title_reply_before'] = '<div class="comment-policy">' . wp_kses_post( $comment_policy ) . '</div>';
			}

			comment_form( $comment_attributes );
		}
	}
endif;

if ( ! function_exists( 'newspack_comments_template' ) ) {

	/**
	 * Output the comment template.
	 */
	function newspack_comments_template() {
		// Add Coral AMP compatibility because they integrated with AMP for WP plugin instead of the official AMP plugin.
		if ( newspack_is_amp() && function_exists( 'coral_talk_comments_amp_template' ) ) {
			coral_talk_comments_amp_template();
		} else {
			comments_template();
		}
	}
}

if ( ! function_exists( 'newspack_the_posts_navigation' ) ) :
	/**
	 * Documentation for function.
	 */
	function newspack_the_posts_navigation() {
		the_posts_pagination(
			array(
				'mid_size'  => 2,
				'prev_text' => sprintf(
					'%s <span class="nav-prev-text">%s</span>',
					newspack_get_icon_svg( 'chevron_left', 22 ),
					__( 'Newer posts', 'newspack-theme' )
				),
				'next_text' => sprintf(
					'<span class="nav-next-text">%s</span> %s',
					__( 'Older posts', 'newspack-theme' ),
					newspack_get_icon_svg( 'chevron_right', 22 )
				),
			)
		);
	}
endif;

if ( ! function_exists( 'newspack_mobile_cta' ) ) :
	/**
	 * Echo a CTA link in the mobile header.
	 */
	function newspack_mobile_cta() {
		$cta_show   = get_theme_mod( 'show_header_cta', false );
		$cta_text   = get_theme_mod( 'header_cta_text', esc_html__( 'Donate', 'newspack-theme' ) );
		$cta_url    = get_theme_mod( 'header_cta_url', '' );
		$cta_target = get_theme_mod( 'header_cta_target', false );

		if ( true === $cta_show && '' !== $cta_url ) {
			echo '<a class="button mb-cta" href="' . esc_url( $cta_url ) . '"';
			if ( true === $cta_target ) {
				echo ' target="_blank"';
			}
			echo '>' . esc_html( $cta_text ) . '</a>';
		}
	}
endif;

/**
 * Check if any header menus are applied; used to show menu toggle on smaller screens.
 */
function newspack_has_menus() {
	// check if primary, secondary or tertiary menus are populated, or if slideout sidebar widget is populated & should show on mobile.
	if ( ( has_nav_menu( 'primary-menu' ) || has_nav_menu( 'secondary-menu' ) || has_nav_menu( 'tertiary-menu' ) ) ||
		( true === get_theme_mod( 'header_show_slideout', false ) && true === get_theme_mod( 'slideout_widget_mobile', false ) && is_active_sidebar( 'header-1' ) ) ) {
		return true;
	} else {
		return false;
	}
}

if ( ! function_exists( 'newspack_primary_menu' ) ) :
	/**
	 * Displays primary menu; created a function to reduce duplication.
	 */
	function newspack_primary_menu() {
		if ( ! has_nav_menu( 'primary-menu' ) ) {
			return;
		}

		// Only set a toolbar-target attributes if the primary menu container exists in the header - if not subpage header.
		$toolbar_attributes = '';
		if ( false === get_theme_mod( 'header_sub_simplified', false ) || is_front_page() ) {
			$toolbar_attributes = 'toolbar-target="site-navigation" toolbar="(min-width: 767px)"';
		}
		?>
		<nav class="main-navigation nav1 dd-menu" aria-label="<?php esc_attr_e( 'Top Menu', 'newspack-theme' ); ?>" <?php echo $toolbar_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php
			wp_nav_menu(
				array(
					'theme_location' => 'primary-menu',
					'menu_class'     => 'main-menu',
					'container'      => false,
					'items_wrap'     => '<ul id="%1$s" class="%2$s">%3$s</ul>',
				)
			);
			?>
		</nav>
		<?php
	}
endif;

if ( ! function_exists( 'newspack_secondary_menu' ) ) :
	/**
	 * Displays secondary menu; created a function to reduce duplication.
	 */
	function newspack_secondary_menu() {
		if ( ! has_nav_menu( 'secondary-menu' ) ) {
			return;
		}

		// Only set a toolbar-target attributes if the secondary menu container exists in the header - if not short or subpage header.
		$toolbar_attributes = '';
		if ( false === get_theme_mod( 'header_sub_simplified', false ) || is_front_page() ) {
			$toolbar_attributes = 'toolbar-target="secondary-nav-contain" toolbar="(min-width: 767px)"';
		}

		?>
		<nav class="secondary-menu nav2 dd-menu" aria-label="<?php esc_attr_e( 'Secondary Menu', 'newspack-theme' ); ?>" <?php echo $toolbar_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php
			wp_nav_menu(
				array(
					'theme_location' => 'secondary-menu',
					'menu_class'     => 'secondary-menu',
					'container'      => false,
					'items_wrap'     => '<ul id="%1$s" class="%2$s">%3$s</ul>',
				)
			);
			?>
		</nav>
		<?php
	}
endif;

if ( ! function_exists( 'newspack_tertiary_menu' ) ) :
	/**
	 * Displays tertiary menu; created a function to reduce duplication.
	 */
	function newspack_tertiary_menu() {
		if ( ! has_nav_menu( 'tertiary-menu' ) ) {
			return;
		}

		// Only set a toolbar-target attributes if the tertiary menu container exists in the header - if not subpage header.
		$toolbar_attributes = '';
		if ( false === get_theme_mod( 'header_sub_simplified', false ) || is_front_page() ) {
			$toolbar_attributes = 'toolbar-target="tertiary-nav-contain" toolbar="(min-width: 767px)"';
		}
		?>
			<nav class="tertiary-menu nav3" aria-label="<?php esc_attr_e( 'Tertiary Menu', 'newspack-theme' ); ?>" <?php echo $toolbar_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<?php
				wp_nav_menu(
					array(
						'theme_location' => 'tertiary-menu',
						'container'      => false,
						'items_wrap'     => '<ul id="%1$s" class="%2$s">%3$s</ul>',
						'depth'          => 1,
					)
				);
				?>
			</nav>
		<?php
	}
endif;

if ( ! function_exists( 'newspack_social_menu_settings' ) ) :
	/**
	 * Displays social links menu; create a function for the wp_nav_menu settings to reduce duplication.
	 */
	function newspack_social_menu_settings() {
		wp_nav_menu(
			array(
				'theme_location' => 'social',
				'menu_class'     => 'social-links-menu',
				'container'      => false,
				'link_before'    => '<span class="screen-reader-text">',
				'link_after'     => '</span>' . newspack_get_icon_svg( 'link' ),
				'depth'          => 1,
			)
		);
	}
endif;

/**
 * Displays social links menu for the header; includes AMP toolbar and toolbar-target attributes.
 */
function newspack_social_menu_header() {
	if ( ! has_nav_menu( 'social' ) ) {
		return;
	}

	$header_simplified     = get_theme_mod( 'header_simplified', false );
	$header_center_logo    = get_theme_mod( 'header_center_logo', false );
	$header_sub_simplified = get_theme_mod( 'header_sub_simplified', false );

	$toolbar_attributes = 'toolbar="(min-width: 767px)" toolbar-target="social-nav-contain"';

	// In some cases when this menu won't appear on desktop, override the Toobar Attributes for AMP, so it doesn't try to "move" it.
	if (
		( true === $header_simplified && ! has_nav_menu( 'secondary-menu' ) ) ||
		( true === $header_sub_simplified && ! is_front_page() ) ||
		( false === $header_simplified && ! has_nav_menu( 'secondary-menu' ) && false === $header_center_logo )
	) {
		$toolbar_attributes = '';
	}
	?>
	<nav class="social-navigation" aria-label="<?php esc_attr_e( 'Social Links Menu', 'newspack-theme' ); ?>" <?php echo $toolbar_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<?php newspack_social_menu_settings(); ?>
	</nav><!-- .social-navigation -->
	<?php
}

/**
 * Displays social links menu for the footer; without AMP-related attributes, to prevent duplication errors.
 */
function newspack_social_menu_footer() {
	if ( ! has_nav_menu( 'social' ) ) {
		return;
	}
	?>
	<nav class="social-navigation" aria-label="<?php esc_attr_e( 'Social Links Menu', 'newspack-theme' ); ?>">
		<?php newspack_social_menu_settings(); ?>
	</nav><!-- .social-navigation -->
	<?php
}
