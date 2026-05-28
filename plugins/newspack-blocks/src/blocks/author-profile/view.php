<?php
/**
 * Server-side render functions for the Author Profile block.
 *
 * @package WordPress
 */

/**
 * Register block bindings source for author data.
 *
 * This allows core blocks to bind their content to author fields using:
 * {"metadata":{"bindings":{"content":{"source":"newspack-blocks/author","args":{"key":"name"}}}}}
 *
 * Supported keys: name, bio, url, archive_url, archive_link_text, newspack_job_title, newspack_role, newspack_employer
 */
function newspack_blocks_register_author_bindings_source() {
	// Block bindings require WordPress 6.5+.
	if ( ! function_exists( 'register_block_bindings_source' ) ) {
		return;
	}

	register_block_bindings_source(
		'newspack-blocks/author',
		[
			'label'              => __( 'Author Profile', 'newspack-blocks' ),
			'get_value_callback' => 'newspack_blocks_get_author_binding_value',
			'uses_context'       => [ 'newspack-blocks/author' ],
		]
	);
}
add_action( 'init', 'newspack_blocks_register_author_bindings_source' );

/**
 * Get a value from the author context for block bindings.
 *
 * @param array    $source_args    Array containing 'key' for the author field to retrieve.
 * @param WP_Block $block_instance The block instance.
 * @param string   $attribute_name The attribute being bound.
 * @return mixed The value for the binding, or null if not found.
 */
function newspack_blocks_get_author_binding_value( $source_args, $block_instance, $attribute_name ) {
	$author = $block_instance->context['newspack-blocks/author'] ?? null;

	if ( ! $author || empty( $source_args['key'] ) ) {
		return null;
	}

	$key = $source_args['key'];

	// Handle special cases.
	switch ( $key ) {
		case 'archive_url':
		case 'url':
			return $author['url'] ?? '';

		case 'archive_link_text':
			$name = $author['name'] ?? '';
			if ( ! $name ) {
				return '';
			}
			/* translators: %s: author name */
			return sprintf( __( 'More by %s', 'newspack-blocks' ), $name );

		default:
			// Empty string prevents saved editor placeholders from leaking to frontend.
			return $author[ $key ] ?? '';
	}
}

/**
 * Dynamic block registration.
 */
function newspack_blocks_register_author_profile() {
	$block_json = json_decode(
		file_get_contents( __DIR__ . '/block.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		true
	);

	if ( class_exists( '\Newspack\Authors_Custom_Fields' ) ) {
		$author_custom_fields = \Newspack\Authors_Custom_Fields::get_custom_fields();
		foreach ( $author_custom_fields as $field ) {
			$block_json['attributes'][ 'show' . $field['name'] ] = [
				'type'    => 'boolean',
				'default' => true,
			];
		}
	}

	register_block_type(
		'newspack-blocks/' . $block_json['name'],
		[
			'api_version'      => $block_json['apiVersion'],
			'attributes'       => $block_json['attributes'],
			'render_callback'  => 'newspack_blocks_render_block_author_profile',
			'uses_context'     => $block_json['usesContext'] ?? [],
			// Note: provides_context is declared here for registration but the actual
			// context injection happens in newspack_blocks_render_nested_author_profile()
			// via new WP_Block() with a merged context array.
			'provides_context' => [
				'newspack-blocks/author' => 'author',
			],
		]
	);

	// Register layout styles only for classic themes. Block themes use
	// InnerBlocks (nested mode) for layout control instead.
	if ( ! wp_is_block_theme() ) {
		register_block_style(
			'newspack-blocks/author-profile',
			[
				'name'       => 'default',
				'label'      => _x( 'Default', 'block style', 'newspack-blocks' ),
				'is_default' => true,
			]
		);
		register_block_style(
			'newspack-blocks/author-profile',
			[
				'name'  => 'center',
				'label' => _x( 'Centered', 'block style', 'newspack-blocks' ),
			]
		);
	}
}

/**
 * Given a numeric ID, get the corresponding WP user or Co-authors Plus guest author.
 *
 * @param int     $author_id Author ID to look up.
 * @param int     $avatar_size Size of the avatar image to fetch.
 * @param boolean $hide_default If true, don't show default avatars.
 * @param boolean $is_guest_author If true, search for guest authors. If false, only search for WP users.
 * @return object|boolean Author object in standardized format, or false if none exists.
 */
function newspack_blocks_get_author_or_guest_author( $author_id, $avatar_size = 128, $hide_default = false, $is_guest_author = true ) {
	$wp_user = get_user_by( 'id', $author_id );
	$author  = false;

	// First, see if the $author_id is a guest author.
	if ( class_exists( 'CoAuthors_Guest_Authors' ) && $is_guest_author ) {
		// Check if the ID given is a WP user with linked guest author.
		$linked_guest_author = false;

		if ( $wp_user ) {
			$linked_guest_author = WP_REST_Newspack_Authors_Controller::get_linked_guest_author( $wp_user->user_login );
		}

		if ( $linked_guest_author && isset( $linked_guest_author->ID ) ) {
			$author_id = $linked_guest_author->ID;
		}

		$author = ( new CoAuthors_Guest_Authors() )->get_guest_author_by( 'id', $author_id );
		$avatar = function_exists( 'coauthors_get_avatar' ) ? coauthors_get_avatar( $author, $avatar_size ) : false;

		// Format CAP guest author object to return to the render function.
		if ( $author && isset( $author->ID ) ) {
			$author = WP_REST_Newspack_Authors_Controller::fill_guest_author_data(
				[
					'id' => $author_id,
				],
				$author
			);

			if ( $avatar && ( false === strpos( $avatar, 'avatar-default' ) || ! $hide_default ) ) {
				$author['avatar'] = $avatar;
			}
		}
	}

	// If $author is still false, see if it's a standard WP User.
	if ( ! $author && $wp_user && isset( $wp_user->data ) ) {
		$author = WP_REST_Newspack_Authors_Controller::fill_user_data(
			[
				'id' => $author_id,
			],
			$wp_user
		);

		$avatar = get_avatar( $author_id, $avatar_size );
		if ( $avatar && ( false === strpos( $avatar, 'avatar-default' ) || ! $hide_default ) ) {
			$author['avatar'] = $avatar;
		}
	}

	return $author;
}

/**
 * Check if custom byline is active for a post.
 *
 * @param int $post_id Post ID to check.
 * @return bool True if custom byline is active.
 */
function newspack_blocks_is_custom_byline_active( $post_id ) {
	return (bool) get_post_meta( $post_id, '_newspack_byline_active', true );
}

/**
 * Get authors for a post (CAP or default).
 *
 * @param int   $post_id Post ID to get authors for.
 * @param array $attributes Block attributes.
 * @return array Array of author data.
 */
function newspack_blocks_get_post_authors( $post_id, $attributes ) {
	$authors      = [];
	$avatar_size  = intval( $attributes['avatarSize'] ?? 128 );
	$hide_default = $attributes['avatarHideDefault'] ?? false;

	// Try Co-Authors Plus first. When CAP is active, always return its result
	// and never fall back to post_author, which could show the wrong person.
	if ( function_exists( 'get_coauthors' ) ) {
		$coauthors = get_coauthors( $post_id );
		foreach ( $coauthors as $coauthor ) {
			$author = newspack_blocks_get_contextual_author( $coauthor, $attributes );
			if ( $author ) {
				$authors[] = $author;
			}
		}
		return $authors;
	}

	// Fallback to default author.
	$author_id = get_post_field( 'post_author', $post_id );
	if ( $author_id ) {
		$author = newspack_blocks_get_author_or_guest_author(
			$author_id,
			$avatar_size,
			$hide_default,
			false // Not a guest author.
		);
		if ( $author ) {
			$authors[] = $author;
		}
	}

	return $authors;
}

/**
 * Convert a WP_User, WP_Post (guest author), or stdClass to the expected format.
 *
 * Note: get_coauthors() returns stdClass objects, not WP_User.
 * Guest authors from get_queried_object() are WP_Post objects.
 *
 * @param object $author_obj Author object from WP_User, WP_Post, or stdClass.
 * @param array  $attributes Block attributes.
 * @return array|null Author data array or null if invalid.
 */
function newspack_blocks_get_contextual_author( $author_obj, $attributes ) {
	$avatar_size  = intval( $attributes['avatarSize'] ?? 128 );
	$hide_default = $attributes['avatarHideDefault'] ?? false;

	// WP_Post object - guest author from get_queried_object() on author archives.
	if ( $author_obj instanceof WP_Post && 'guest-author' === $author_obj->post_type ) {
		return newspack_blocks_get_author_or_guest_author(
			$author_obj->ID,
			$avatar_size,
			$hide_default,
			true // Is guest author.
		);
	}

	// WP_User object - regular author from get_queried_object() on author archives.
	if ( $author_obj instanceof WP_User ) {
		return newspack_blocks_get_author_or_guest_author(
			$author_obj->ID,
			$avatar_size,
			$hide_default,
			false
		);
	}

	// stdClass from get_coauthors() - check 'type' property to determine guest vs user.
	if ( is_object( $author_obj ) && isset( $author_obj->ID ) ) {
		$is_guest = isset( $author_obj->type ) && 'guest-author' === $author_obj->type;
		return newspack_blocks_get_author_or_guest_author(
			$author_obj->ID,
			$avatar_size,
			$hide_default,
			$is_guest
		);
	}

	return null;
}

/**
 * Render a single author profile card.
 *
 * @param array $author Author data array.
 * @param array $attributes Block attributes.
 * @return string Rendered HTML.
 */
function newspack_blocks_render_author_profile_card( $author, $attributes ) {
	return Newspack_Blocks::template_include(
		'author-profile-card',
		[
			'attributes' => $attributes,
			'author'     => $author,
		]
	);
}

/**
 * Block render callback.
 *
 * @param array    $attributes Block attributes.
 * @param string   $content Block inner content.
 * @param WP_Block $block Block instance.
 * @return string Rendered block HTML.
 */
function newspack_blocks_render_block_author_profile( $attributes, $content, $block ) {
	$layout_version = $attributes['layoutVersion'] ?? 1;

	// Get authors based on mode.
	$authors = newspack_blocks_get_authors_for_render( $attributes, $block );

	if ( empty( $authors ) ) {
		return '';
	}

	Newspack_Blocks::enqueue_view_assets( 'author-profile' );

	// NESTED MODE: Determined by layoutVersion attribute, not theme type.
	// Once a block is created in nested mode (layoutVersion 2), it stays nested.
	// Block themes automatically set layoutVersion 2 for NEW blocks.
	// Fall back to flat rendering if a v2 block is used in a classic theme.
	if ( 2 === $layout_version && ! empty( $block->inner_blocks ) ) {
		$registry          = WP_Block_Type_Registry::get_instance();
		$has_nested_blocks = $registry->is_registered( 'newspack/avatar' ) && $registry->is_registered( 'newspack/author-profile-social' );
		if ( ! wp_is_block_theme() || ! $has_nested_blocks ) {
			return newspack_blocks_render_flat_author_profiles( $authors, $attributes );
		}
		return newspack_blocks_render_nested_author_profile( $authors, $attributes, $block );
	}

	// FLAT MODE: Use existing template rendering.
	return newspack_blocks_render_flat_author_profiles( $authors, $attributes );
}

/**
 * Get authors for rendering based on block mode.
 *
 * @param array    $attributes Block attributes.
 * @param WP_Block $block Block instance.
 * @return array Array of author data.
 */
function newspack_blocks_get_authors_for_render( $attributes, $block ) {
	$is_contextual = ! empty( $attributes['isContextual'] );

	// SPECIFIC MODE: Get single author by ID.
	if ( ! $is_contextual ) {
		if ( empty( $attributes['authorId'] ) ) {
			return [];
		}

		$author = newspack_blocks_get_author_or_guest_author(
			intval( $attributes['authorId'] ),
			intval( $attributes['avatarSize'] ),
			$attributes['avatarHideDefault'],
			$attributes['isGuestAuthor']
		);

		return $author ? [ $author ] : [];
	}

	// CONTEXTUAL MODE: Auto-detect authors.
	$post_id = $block->context['postId'] ?? get_the_ID();

	// On author archives: render the queried author.
	if ( is_author() ) {
		$queried = get_queried_object();
		$author  = newspack_blocks_get_contextual_author( $queried, $attributes );
		return $author ? [ $author ] : [];
	}

	// On single posts: if custom byline is active, show the referenced authors (if any).
	if ( newspack_blocks_is_custom_byline_active( $post_id ) ) {
		if ( class_exists( 'Newspack\Bylines' ) ) {
			$byline_authors = \Newspack\Bylines::get_post_byline_authors( $post_id );
			$authors        = [];
			foreach ( $byline_authors as $wp_user ) {
				if ( $wp_user instanceof WP_User ) {
					$author = newspack_blocks_get_contextual_author( $wp_user, $attributes );
					if ( $author ) {
						$authors[] = $author;
					}
				}
			}
			return $authors;
		}
		// Bylines class unavailable: byline is active so don't show post authors.
		return [];
	}

	// Get authors (CAP or default).
	return newspack_blocks_get_post_authors( $post_id, $attributes );
}

/**
 * Render author profiles using flat template (existing behavior).
 *
 * @param array $authors Array of author data.
 * @param array $attributes Block attributes.
 * @return string Rendered HTML.
 */
function newspack_blocks_render_flat_author_profiles( $authors, $attributes ) {
	$output         = '';
	$show_empty_bio = $attributes['showEmptyBio'] ?? false;

	foreach ( $authors as $author ) {
		// Skip authors with no bio if configured.
		if ( empty( $author['bio'] ) && ! $show_empty_bio ) {
			continue;
		}
		$output .= newspack_blocks_render_author_profile_card( $author, $attributes );
	}

	return $output;
}

/**
 * Render author profiles using nested inner blocks.
 *
 * Renders the full inner block tree (including core/columns) and post-processes
 * the HTML to wrap specific paragraphs in links.
 *
 * @param array    $authors Array of author data.
 * @param array    $attributes Block attributes.
 * @param WP_Block $block Block instance.
 * @return string Rendered HTML.
 */
function newspack_blocks_render_nested_author_profile( $authors, $attributes, $block ) {
	$output         = '';
	$show_empty_bio = $attributes['showEmptyBio'] ?? false;

	foreach ( $authors as $author ) {
		if ( empty( $author['bio'] ) && ! $show_empty_bio ) {
			continue;
		}

		$extra_classes = [ 'is-nested-mode' ];
		$classes       = Newspack_Blocks::block_classes( 'author-profile', $attributes, $extra_classes );

		// Render entire inner block tree with author context.
		// WP_Block propagates available_context to all descendants automatically
		// through core/columns > core/column > child blocks.
		$author_html = '';
		foreach ( $block->inner_blocks as $inner_block ) {
			$inner_block_instance = new WP_Block(
				$inner_block->parsed_block,
				array_merge(
					$block->context,
					[
						'newspack-blocks/author' => array_merge(
							$author,
							[ 'avatarHideDefault' => ! empty( $attributes['avatarHideDefault'] ) ]
						),
					]
				)
			);
			$author_html         .= $inner_block_instance->render();
		}

		// Post-process: wrap archive link paragraph in anchor tag.
		$author_html = newspack_blocks_wrap_author_archive_link( $author_html, $author );

		$output .= '<div class="' . esc_attr( $classes ) . '">' . $author_html . '</div>';
	}

	return $output;
}

/**
 * Post-process rendered HTML to wrap the archive link paragraph in an anchor tag.
 *
 * @param string $html Rendered HTML from inner blocks.
 * @param array  $author Author data array.
 * @return string Processed HTML with archive link wrapped.
 */
function newspack_blocks_wrap_author_archive_link( $html, $author ) {
	$url = $author['url'] ?? '';
	if ( $url ) {
		$html = preg_replace_callback(
			'/(<p[^>]*class="[^"]*author-archive-link[^"]*"[^>]*>)(.*?)(<\/p>)/s',
			function ( $matches ) use ( $url ) {
				// Avoid creating nested anchors if the paragraph already contains a link.
				if ( false !== stripos( $matches[2], '<a ' ) ) {
					return $matches[0];
				}
				return $matches[1] . '<a href="' . esc_url( $url ) . '">' . wp_kses_post( $matches[2] ) . '</a>' . $matches[3];
			},
			$html
		);
	}

	return $html;
}

add_action( 'init', 'newspack_blocks_register_author_profile' );
