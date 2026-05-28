<?php
/**
 * Query helper class.
 *
 * @package Newspack
 */

namespace Newspack\Collections;

defined( 'ABSPATH' ) || exit;

/**
 * Query helper class for Collections data operations and business logic.
 */
class Query_Helper {

	/**
	 * Cover section (virtual) slug.
	 *
	 * @var string
	 */
	public const COVER_SECTION = 'cover';

	/**
	 * Get available years from published collections for filtering.
	 *
	 * @param string $selected_category Optional category filter.
	 * @return array Array of years.
	 */
	public static function get_available_years( $selected_category = '' ) {
		// Try to get from cache first.
		$cached_data    = wp_cache_get( Cache::YEARS_CACHE_KEY, Cache::CACHE_GROUP );
		$category_years = [];

		if ( ! empty( $cached_data[ $selected_category ] ) && is_array( $cached_data[ $selected_category ] ) ) {
			$category_years = $cached_data[ $selected_category ];
		} elseif ( ! empty( $cached_data[''] ) && is_array( $cached_data[''] ) ) {
			$category_years = $cached_data[''];
		} else {
			global $wpdb;

			// Get all years with their associated categories in one query.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT YEAR(p.post_date) as year, t.slug as category
					FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id AND EXISTS (
						SELECT 1 FROM {$wpdb->term_taxonomy} tt2
						WHERE tr.term_taxonomy_id = tt2.term_taxonomy_id
						AND tt2.taxonomy = %s
					)
					LEFT JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
					LEFT JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
					WHERE p.post_type = %s
					AND p.post_status = 'publish'
					ORDER BY year DESC",
					Collection_Category_Taxonomy::get_taxonomy(),
					Post_Type::get_post_type()
				)
			);

			$years_data = [ '' => [] ]; // Initialize with empty category (all years).

			if ( is_array( $results ) ) {
				foreach ( $results as $result ) {
					$year = (int) $result->year;
					if ( ! $year ) {
						continue;
					}

					$years_data[''][] = $year;

					if ( $result->category ) {
						$years_data[ $result->category ][] = $year;
					}
				}

				// Sort all arrays in descending order and ensure uniqueness.
				foreach ( $years_data as &$years ) {
					$years = array_unique( $years );
					rsort( $years );
				}
				unset( $years );
			}

			wp_cache_set( Cache::YEARS_CACHE_KEY, $years_data, Cache::CACHE_GROUP );
			$category_years = $years_data[ $selected_category ] ?? [];
		}

		/**
		 * Filters the available years for a given collection category.
		 *
		 * @param array  $category_years    Array of years.
		 * @param string $selected_category Selected category slug.
		 */
		return apply_filters( 'newspack_collections_available_years', (array) $category_years, $selected_category );
	}

	/**
	 * Get the collection categories.
	 *
	 * @return array Array of collection categories.
	 */
	public static function get_collection_categories() {
		$categories = get_terms(
			[
				'taxonomy'   => Collection_Category_Taxonomy::get_taxonomy(),
				'hide_empty' => true,
				'orderby'    => 'name',
				'order'      => 'ASC',
			]
		);

		/**
		 * Filters the collection categories.
		 *
		 * @param array $categories Array of collection categories.
		 */
		$categories = apply_filters( 'newspack_collections_collection_categories', $categories );

		if (
			is_wp_error( $categories ) ||
			! is_array( $categories ) ||
			( ! empty( $categories ) && ! $categories[0] instanceof \WP_Term )
		) {
			return [];
		}

		return $categories;
	}

	/**
	 * Get processed CTAs from a collection post.
	 *
	 * @param int|WP_Post $post         The post ID or post object.
	 * @param int|null    $limit        Optional limit on the number of CTAs.
	 * @param bool        $hierarchical Whether to include hierarchical CTAs. Default is true.
	 * @return array Array of processed CTAs with 'url' and 'label' keys.
	 */
	public static function get_ctas( $post, $limit = null, $hierarchical = true ) {
		$post_id = $post instanceof \WP_Post ? $post->ID : $post;
		$ctas    = Collection_Meta::get( $post_id, 'ctas' );

		if ( ! is_array( $ctas ) ) {
			$ctas = [];
		}

		// Add hierarchical CTAs if enabled and there's room.
		if ( $hierarchical && ( null === $limit || count( $ctas ) < $limit ) ) {
			$hierarchical_ctas = self::get_hierarchical_ctas( $post_id );
			$ctas              = array_merge( $ctas, $hierarchical_ctas );
		}

		if ( empty( $ctas ) ) {
			return [];
		}

		// Apply limit to final result if specified.
		if ( null !== $limit ) {
			$ctas = array_slice( $ctas, 0, $limit );
		}

		// Process the CTAs.
		$ctas = array_values(
			array_filter(
				array_map(
					function ( $cta ) {
						$label = $cta['label'] ?? '';
						$url   = '';
						$type  = $cta['type'] ?? '';
						$class = $cta['class'] ?? '';

						if ( 'attachment' === $type && ! empty( $cta['id'] ) ) {
							$url = wp_get_attachment_url( $cta['id'] );
						} elseif ( ! empty( $cta['url'] ) ) {
							$url = $cta['url'];
						}

						return ( $label && $url ) ? compact( 'url', 'label', 'class', 'type' ) : null;
					},
					$ctas
				)
			)
		);

		/**
		 * Filters the collection CTAs.
		 *
		 * @param array $ctas Array of collection CTAs.
		 * @param int   $post_id The post ID.
		 */
		return apply_filters( 'newspack_collections_ctas', $ctas, $post_id );
	}

	/**
	 * Get hierarchical CTAs for a collection post.
	 *
	 * @param int $post_id The post ID.
	 * @return array Array of hierarchical CTAs with 'url' and 'label' keys.
	 */
	public static function get_hierarchical_ctas( $post_id ) {
		$cta_keys = [
			'subscribe_link' => __( 'Subscribe', 'newspack-plugin' ),
			'order_link'     => __( 'Order', 'newspack-plugin' ),
		];

		$ctas = array_values(
			array_filter(
				array_map(
					function ( $key ) use ( $post_id, $cta_keys ) {
						// Try to get the CTAs from the collection meta first.
						$url = Collection_Meta::get( $post_id, $key );

						// If not found, try to get the CTAs from the collection category meta.
						if ( empty( $url ) ) {
							$category_terms = get_the_terms( $post_id, Collection_Category_Taxonomy::get_taxonomy() );
							$category_id    = ( $category_terms && ! is_wp_error( $category_terms ) ) ? $category_terms[0]->term_id : null;
							if ( $category_id ) {
								$url = Collection_Category_Taxonomy::get( $category_id, $key );
							}
						}

						// If not found, try to get the CTAs from the global settings.
						if ( empty( $url ) ) {
							$url = Settings::get_setting( $key );
						}

						// If nothing is found, return null.
						return ! empty( $url ) ? [
							'label' => $cta_keys[ $key ],
							'type'  => 'link',
							'url'   => $url,
							'class' => 'cta--' . $key,
						] : null;
					},
					array_keys( $cta_keys )
				)
			)
		);

		/**
		 * Filters the hierarchical CTAs.
		 *
		 * @param array $ctas    Array of hierarchical CTAs.
		 * @param int   $post_id The post ID.
		 */
		return apply_filters( 'newspack_collections_hierarchical_ctas', $ctas, $post_id );
	}

	/**
	 * Get posts in a collection organized by sections.
	 *
	 * Sorting logic:
	 * 1. Posts are grouped by their collection section taxonomy
	 * 2. Posts without a section are grouped under 'no-section'
	 * 3. Sections are sorted in this order:
	 *    - 'cover'(posts marked as cover stories in the post meta)
	 *    - 'no-section' (posts without sections)
	 *    - Sections without `newspack_collection_section_order` meta (alphabetically)
	 *    - Sections with `newspack_collection_section_order meta` (by order value)
	 * 4. Within each section, posts are sorted in this order:
	 *    - Posts without `newspack_collection_post_order` meta (newest to oldest)
	 *    - Posts with `newspack_collection_post_order` meta (by ascending order value)
	 *
	 * @param int|WP_Post $collection Collection post ID or post object.
	 * @return array Array of post IDs organized by section slug.
	 */
	public static function get_collection_posts( $collection ) {
		$collection_id = $collection instanceof \WP_Post ? $collection->ID : $collection;

		// Try to get from cache first.
		$cache_key   = Cache::get_posts_cache_key( $collection_id );
		$cached_data = wp_cache_get( $cache_key, Cache::CACHE_GROUP );

		if ( false !== $cached_data ) {
			/**
			 * Filters the collection posts organized by section.
			 *
			 * @param array          $post_ids_by_section Array of post IDs organized by section slug.
			 * @param int|\WP_Post   $collection           Collection post ID or post object.
			 */
			return apply_filters( 'newspack_collections_posts_by_section', $cached_data, $collection );
		}

		// Get the linked collection term.
		$linked_term_id = Sync::get_term_linked_to_collection( $collection_id );
		if ( ! $linked_term_id ) {
			return apply_filters( 'newspack_collections_posts_by_section', [], $collection );
		}

		$term = get_term( $linked_term_id, Collection_Taxonomy::get_taxonomy() );
		if ( ! $term || is_wp_error( $term ) ) {
			return apply_filters( 'newspack_collections_posts_by_section', [], $collection );
		}

		// Get posts in this collection.
		$posts = get_posts(
			[
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					[
						'taxonomy' => Collection_Taxonomy::get_taxonomy(),
						'field'    => 'term_id',
						'terms'    => $linked_term_id,
					],
				],
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		if ( empty( $posts ) ) {
			return apply_filters( 'newspack_collections_posts_by_section', [], $collection );
		}

		// Organize posts by section.
		$sections = [];

		foreach ( $posts as $post ) {
			// Add cover stories to a special 'cover' section.
			if ( Post_Meta::get( $post->ID, 'is_cover_story' ) ) {
				$sections[ self::COVER_SECTION ][] = $post;
				continue;
			}

			$post_sections = get_the_terms( $post->ID, Collection_Section_Taxonomy::get_taxonomy() );

			// Regular posts are organized by their respective sections.
			if ( $post_sections && ! is_wp_error( $post_sections ) ) {
				foreach ( $post_sections as $section ) {
					$sections[ $section->slug ][] = $post;
				}
			} else {
				// Posts without sections are grouped under 'no-section'.
				$sections[''][] = $post;
			}
		}

		// Sort posts within each section by their order meta.
		foreach ( $sections as $section_slug => $section_posts ) {
			usort(
				$section_posts,
				function ( $a, $b ) {
					$order_a = Post_Meta::get( $a->ID, 'post_order' );
					$order_b = Post_Meta::get( $b->ID, 'post_order' );

					// If neither post has order meta, sort by date (newest to oldest).
					if ( empty( $order_a ) && empty( $order_b ) ) {
						return strtotime( $b->post_date ) - strtotime( $a->post_date );
					}

					// If no order meta is set for one post, it should be sorted before the other.
					if ( empty( $order_a ) ) {
						return -1;
					}
					if ( empty( $order_b ) ) {
						return 1;
					}

					// If both posts have order meta, sort by the order value (lower values first).
					return intval( $order_a ) - intval( $order_b );
				}
			);

			$sections[ $section_slug ] = $section_posts;
		}

		$section_order_cache = [];

		// Sort sections: cover stories first, then post with no section, then by order meta.
		uksort(
			$sections,
			function ( $a, $b ) use ( &$section_order_cache ) {
				// Cover stories first.
				if ( self::COVER_SECTION === $a ) {
					return -1;
				}
				if ( self::COVER_SECTION === $b ) {
					return 1;
				}

				// Posts with no section.
				if ( '' === $a ) {
					return -1;
				}
				if ( '' === $b ) {
					return 1;
				}

				// Build the section order cache.
				foreach ( [ $a, $b ] as $slug ) {
					if ( ! isset( $section_order_cache[ $slug ] ) ) {
						$term                         = get_term_by( 'slug', $slug, Collection_Section_Taxonomy::get_taxonomy() );
						$section_order_cache[ $slug ] = $term ? Collection_Section_Taxonomy::get( $term->term_id, 'section_order' ) : '';
					}
				}

				$order_a = $section_order_cache[ $a ];
				$order_b = $section_order_cache[ $b ];

				// If both sections have no order meta, sort alphabetically.
				if ( '' === $order_a && '' === $order_b ) {
					return strcmp( $a, $b );
				}

				// If one section has no order meta, it should be sorted before the other.
				if ( '' === $order_a ) {
					return -1;
				}
				if ( '' === $order_b ) {
					return 1;
				}

				// If both sections have order meta, sort by the order value (lower values first).
				return intval( $order_a ) - intval( $order_b );
			}
		);

		// Cache post IDs.
		$post_ids_by_section = [];

		foreach ( $sections as $section_slug => $section_posts ) {
			$post_ids_by_section[ $section_slug ] = wp_list_pluck( $section_posts, 'ID' );
		}

		wp_cache_set( $cache_key, $post_ids_by_section, Cache::CACHE_GROUP );

		return apply_filters( 'newspack_collections_posts_by_section', $post_ids_by_section, $collection );
	}

	/**
	 * Get collections that a post belongs to.
	 *
	 * @param int|WP_Post $post         Post ID or post object.
	 * @param bool        $return_posts Whether to return collection post objects or just the IDs. Default false (IDs).
	 * @param bool        $single       Whether to return only the first occurrence. Default false.
	 * @return array Array of collection terms or collection posts.
	 */
	public static function get_post_collections( $post, $return_posts = false, $single = false ) {
		$post_id = $post instanceof \WP_Post ? $post->ID : $post;

		$collection_terms = get_the_terms( $post_id, Collection_Taxonomy::get_taxonomy() );

		if ( empty( $collection_terms ) || is_wp_error( $collection_terms ) ) {
			return [];
		}

		// Slice early if we only need one result.
		if ( $single ) {
			$collection_terms = array_slice( $collection_terms, 0, 1 );
		}

		// Get collection posts linked to these terms.
		$collections = [];
		foreach ( $collection_terms as $term ) {
			$collection_id = Sync::get_collection_linked_to_term( $term->term_id );
			if ( $collection_id ) {
				$collections[] = $collection_id;
			}
		}

		// There shouldn't be duplicates, but just in case.
		$collections = array_unique( $collections );
		$result      = $return_posts ? array_map( 'get_post', $collections ) : $collections;

		/**
		 * Filters the collections for a post.
		 *
		 * @param array       $result       Array of collection post objects or IDs.
		 * @param int|WP_Post $post         Post ID or post object.
		 * @param bool        $return_posts Whether collection post objects were returned.
		 * @param bool        $single       Whether only single result was requested.
		 */
		return apply_filters( 'newspack_collections_post_collections', $result, $post, $return_posts, $single );
	}

	/**
	 * Get the section name.
	 *
	 * @param string $section_slug The section slug.
	 * @return string The section name.
	 */
	public static function get_section_name( $section_slug ) {
		$section_name = $section_slug;

		if ( ! empty( $section_slug ) ) {
			$section_term = get_term_by( 'slug', $section_slug, Collection_Section_Taxonomy::get_taxonomy() );
			if ( $section_term && ! is_wp_error( $section_term ) ) {
				$section_name = $section_term->name;
			}
		}

		/**
		 * Filters the section name.
		 *
		 * @param string $section_name The section name.
		 * @param string $section_slug The section slug.
		 */
		return apply_filters( 'newspack_collections_section_name', $section_name, $section_slug );
	}

	/**
	 * Get recent collections, excluding specified IDs.
	 *
	 * Query more posts than needed to account for exclusions as this is more efficient than using post__not_in.
	 *
	 * @param array $exclude Array of collection IDs to exclude.
	 * @param int   $limit Number of collections to return.
	 * @return array Array of collection posts.
	 */
	public static function get_recent( $exclude = [], $limit = 6 ) {
		$collections = get_posts(
			[
				'post_type'      => Post_Type::get_post_type(),
				'post_status'    => 'publish',
				'posts_per_page' => $limit + ( is_array( $exclude ) ? count( $exclude ) : 0 ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		if ( empty( $collections ) ) {
			return [];
		}

		$filtered = empty( $exclude ) ? $collections : array_filter(
			$collections,
			function ( $post ) use ( $exclude ) {
				return ! in_array( $post->ID, $exclude, true );
			}
		);

		/**
		 * Filters the recent collections.
		 *
		 * @param array $recent  Array of recent collection posts.
		 * @param array $exclude Array of excluded collection IDs.
		 * @param int   $limit   Number of items returned.
		 */
		return apply_filters( 'newspack_collections_recent', array_slice( $filtered, 0, $limit ), $exclude, $limit );
	}

	/**
	 * Build and run the collections query based on block attributes.
	 *
	 * @param array $attributes Block attributes (sanitized).
	 * @return array Array of WP_Post collection objects.
	 */
	public static function get_collections_by_attributes( $attributes ) {
		$query_args = [
			'post_type'      => Post_Type::get_post_type(),
			'post_status'    => 'publish',
			'posts_per_page' => $attributes['numberOfItems'],
			'orderby'        => 'date',
			'order'          => 'DESC',
			'offset'         => $attributes['offset'],
		];

		// Handle specific collections selection.
		if ( 'specific' === ( $attributes['queryType'] ?? '' ) && ! empty( $attributes['selectedCollections'] ) ) {
			$query_args['post__in'] = $attributes['selectedCollections'];
			$query_args['orderby']  = 'post__in';
			unset( $query_args['offset'] ); // Offset doesn't apply to specific post selections.
		}

		// Handle category filtering.
		if ( ! empty( $attributes['includeCategories'] ) || ! empty( $attributes['excludeCategories'] ) ) {
			$tax_query = [];

			if ( ! empty( $attributes['includeCategories'] ) ) {
				$tax_query[] = [
					'taxonomy' => Collection_Category_Taxonomy::get_taxonomy(),
					'field'    => 'term_id',
					'terms'    => $attributes['includeCategories'],
					'operator' => 'IN',
				];
			}

			if ( ! empty( $attributes['excludeCategories'] ) ) {
				$tax_query[] = [
					'taxonomy' => Collection_Category_Taxonomy::get_taxonomy(),
					'field'    => 'term_id',
					'terms'    => $attributes['excludeCategories'],
					'operator' => 'NOT IN',
				];
			}

			if ( count( $tax_query ) > 1 ) {
				$tax_query['relation'] = 'AND';
			}

			$query_args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		/**
		 * Generic filter for collections query args.
		 *
		 * @param array $query_args Query args.
		 * @param array $attributes Block attributes.
		 */
		$query_args = apply_filters( 'newspack_collections_query_args', $query_args, $attributes );

		$query = new \WP_Query( $query_args );
		$posts = $query->posts;

		/**
		 * Filter the collections posts returned by the query.
		 *
		 * @param array $posts      Array of WP_Post objects.
		 * @param array $query_args Final query args.
		 * @param array $attributes Block attributes.
		 */
		return apply_filters( 'newspack_collections_query_posts', $posts, $query_args, $attributes );
	}
}
