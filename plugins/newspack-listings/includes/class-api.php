<?php
/**
 * Newspack Listings API.
 *
 * Custom API endpoints for Newspack Listings.
 *
 * @package Newspack_Listings
 */

namespace Newspack_Listings;

use Newspack_Listings\Core;
use Newspack_Listings\Blocks;
use Newspack_Listings\Featured;
use Newspack_Listings\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * API class.
 * Sets up API endpoints and handlers for listings.
 */
final class Api {
	/**
	 * REST route namespace.
	 *
	 * @var Api
	 */
	protected static $namespace = 'newspack-listings/v1';

	/**
	 * The single instance of the class.
	 *
	 * @var Api
	 */
	protected static $instance = null;

	/**
	 * Main Api instance.
	 * Ensures only one instance of Api is loaded or can be loaded.
	 *
	 * @return Api - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Register REST API endpoints.
	 */
	public static function register_routes() {

		// GET listings posts by ID, query args, or title search term.
		register_rest_route(
			self::$namespace,
			'listings',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'get_items' ],
					'args'                => [
						'query'      => [
							'sanitize_callback' => '\Newspack_Listings\Utils\sanitize_array',
						],
						'id'         => [
							'sanitize_callback' => 'absint',
						],
						'type'       => [
							'sanitize_callback' => '\Newspack_Listings\Utils\sanitize_array',
						],
						'attributes' => [
							'sanitize_callback' => '\Newspack_Listings\Utils\sanitize_array',
						],
						'offset'     => [
							'sanitize_callback' => 'absint',
						],
						'page'       => [
							'sanitize_callback' => 'absint',
						],
						'per_page'   => [
							'sanitize_callback' => 'absint',
						],
						'search'     => [
							'sanitize_callback' => 'sanitize_text_field',
						],
						'_fields'    => [
							'sanitize_callback' => 'sanitize_text_field',
						],

					],
					'permission_callback' => '__return_true',
				],
			]
		);

		// GET listings taxonomy terms by name search term.
		register_rest_route(
			self::$namespace,
			'terms',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'get_terms' ],
					'permission_callback' => '__return_true',
				],
			]
		);

		// Get and set listing priority level.
		register_rest_route(
			self::$namespace,
			'priority',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'get_priority' ],
					'permission_callback' => [ __CLASS__, 'api_permissions_check' ],
				],
			]
		);

		register_rest_route(
			self::$namespace,
			'priority',
			[
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ __CLASS__, 'set_priority' ],
					'permission_callback' => [ __CLASS__, 'api_permissions_check' ],
					'args'                => [
						'post_id'  => [
							'sanitize_callback' => 'absint',
						],
						'priority' => [
							'sanitize_callback' => 'absint',
						],
					],
				],
			]
		);
	}

	/**
	 * Permission callback for authenticated requests.
	 *
	 * @return boolean if user can edit stuff.
	 */
	public static function api_permissions_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'newspack_rest_forbidden',
				esc_html__( 'You cannot use this resource.', 'newspack-listings' ),
				[
					'status' => 403,
				]
			);
		}
		return true;
	}

	/**
	 * Sanitize an array of text or number values.
	 *
	 * @param array $array Array of text or float values to be sanitized.
	 * @return array Sanitized array.
	 */
	public static function sanitize_array( $array ) {
		return Utils\sanitize_array( $array );
	}

	/**
	 * Gets all listing post types.
	 *
	 * @return array Array of post type slugs.
	 */
	public static function get_listing_post_types() {
		return array_values( Core::NEWSPACK_LISTINGS_POST_TYPES );
	}

	/**
	 * Build query args for listings.
	 *
	 * @param array $query Query options given as block attributes from Curated List block.
	 * @param array $args (Optional) Args to overwrite defaults with.
	 *
	 * @return array WP_Query args array.
	 */
	public static function build_listings_query( $query, $args = [] ) {
		$default_args = [
			'is_curated_list' => 1,
			'post_type'       => [],
			'post_status'     => 'publish',
			'posts_per_page'  => 10,
			'paged'           => 1,
		];

		$args = wp_parse_args( $args, $default_args );

		if ( ! empty( $query['type'] ) && 'any' !== $query['type'] ) {
			$args['post_type'] = $query['type'];
		} else {
			// If not given a post type, look up posts of any listing type.
			$args['post_type'] = self::get_listing_post_types();
		}
		if ( ! empty( $query['maxItems'] ) ) {
			$args['posts_per_page'] = $query['maxItems'];
		}
		if ( ! empty( $query['authors'] ) ) {
			$args['author__in'] = $query['authors'];
		}
		if ( ! empty( $query['order'] ) ) {
			$args['order'] = $query['order'];
		}
		if ( ! empty( $query['sortBy'] ) ) {
			if ( 'event_date' === $query['sortBy'] ) {
				$args['orderby']  = 'meta_value';
				$args['meta_key'] = 'newspack_listings_event_start_date';
			} else {
				$args['orderby'] = $query['sortBy'];
			}
		}

		if ( ! empty( $query['post__in'] ) ) {
			$args['post__in'] = $query['post__in'];
		}

		if (
			! empty( $query['categories'] ) ||
			! empty( $query['tags'] ) ||
			! empty( $query['categoryExclusions'] ) ||
			! empty( $query['tagExclusions'] )
		) {
			$args['tax_query'] = []; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}
		if ( ! empty( $query['categories'] ) ) {
			$args['tax_query'][] = [
				'taxonomy' => 'category',
				'field'    => 'term_id',
				'terms'    => $query['categories'],
			];
		}
		if ( ! empty( $query['tags'] ) ) {
			$args['tax_query'][] = [
				'taxonomy' => 'post_tag',
				'field'    => 'term_id',
				'terms'    => $query['tags'],
			];
		}
		if ( ! empty( $query['categoryExclusions'] ) ) {
			$args['tax_query'][] = [
				'taxonomy' => 'category',
				'field'    => 'term_id',
				'terms'    => $query['categoryExclusions'],
				'operator' => 'NOT IN',
			];
		}
		if ( ! empty( $query['tagExclusions'] ) ) {
			$args['tax_query'][] = [
				'taxonomy' => 'post_tag',
				'field'    => 'term_id',
				'terms'    => $query['tagExclusions'],
				'operator' => 'NOT IN',
			];
		}
		if ( ! empty( $args['tax_query'] ) && 1 < count( $args['tax_query'] ) ) {
			$args['tax_query']['relation'] = 'AND';
		}

		return $args;
	}

	/**
	 * Lookup individual posts by title search or post ID.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response.
	 */
	public static function get_items( $request ) {
		$response   = [];
		$params     = $request->get_params();
		$fields     = explode( ',', $params['_fields'] );
		$search     = ! empty( $params['search'] ) ? $params['search'] : null;
		$query      = ! empty( $params['query'] ) ? $params['query'] : null;
		$id         = ! empty( $params['id'] ) ? $params['id'] : null;
		$post_types = ! empty( $params['type'] ) ? $params['type'] : [];
		$per_page   = ! empty( $params['per_page'] ) ? $params['per_page'] : 10;
		$page       = ! empty( $params['page'] ) ? $params['page'] : 1;
		$next_page  = $page + 1;
		$attributes = ! empty( $params['attributes'] ) ? $params['attributes'] : [];
		$is_amp     = $request->get_param( 'amp' );

		// If not given a post type, look up posts of any listing type.
		if ( empty( $post_types ) ) {
			$post_types = self::get_listing_post_types();
		}

		// Query args.
		$args = [
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
		];

		// Look up by title only if provided with a search term and not an ID or query terms.
		if ( ! empty( $search ) && empty( $id ) && empty( $query ) ) {
			$args['s'] = esc_sql( $search );
		}

		// If given query terms but not an ID, search using those terms.
		if ( ! empty( $query ) && empty( $id ) ) {
			$args = self::build_listings_query( $query, $args );
		}

		// If given an ID, just look up that post.
		if ( ! empty( $id ) ) {
			$args['p'] = esc_sql( $id );
		}

		$listings_query = new \WP_Query( $args );

		if ( $listings_query->have_posts() ) {
			$listings = array_map(
				function( $post ) use ( $attributes, $fields, $is_amp, $next_page, $query ) {
					$item = [
						'id'    => $post->ID,
						'title' => $post->post_title,
					];

					// if $fields includes html, get rendered HTML for the post.
					if ( in_array( 'html', $fields ) && ! empty( $attributes ) ) {
						$html = Utils\template_include(
							'listing',
							[
								'attributes' => $attributes,
								'post'       => $post,
							]
						);

						// If an AMP page, convert to valid AMP HTML.
						if ( $is_amp ) {
							$html = Utils\generate_amp_partial( $html );
						}

						$item['html'] = $html;
					}

					// If $fields includes category, get the post categories.
					if ( in_array( 'category', $fields ) ) {
						$item['category'] = get_the_category( $post->ID );
					}

					// If $fields includes tags, get the post tags.
					if ( in_array( 'tags', $fields ) ) {
						$item['tags'] = get_the_terms( $post->ID, 'post_tag' );
					}

					// If $fields includes excerpt, get the post excerpt.
					if ( in_array( 'excerpt', $fields ) ) {
						$item['excerpt'] = Utils\get_listing_excerpt( $post );
					}

					// If $fields includes media, get the featured image + caption.
					if ( in_array( 'media', $fields ) ) {
						$item['media'] = [
							'image'   => get_the_post_thumbnail_url( $post->ID, 'medium' ),
							'caption' => get_the_post_thumbnail_caption( $post->ID ),
						];
					}

					// If $fields includes meta, get all Newspack Listings meta fields.
					if ( in_array( 'meta', $fields ) || in_array( 'author', $fields ) ) {
						$item['meta'] = [];
						$post_meta    = Core::get_meta_values( $post->ID, $post->post_type );

						if ( ! empty( $post_meta ) ) {
							$item['meta'] = $post_meta;
						}
					}

					// If $fields includes type, get the post type.
					if ( in_array( 'type', $fields ) ) {
						$item['type'] = $post->post_type;
					}

					// If $fields includes author and the post isn't set to hide author, get the post author.
					if ( in_array( 'author', $fields ) && empty( get_post_meta( $post->ID, 'newspack_listings_hide_author', true ) ) ) {
						$item['author'] = get_the_author_meta( 'display_name', $post->post_author );
					}

					// If $fields includes sponsors include sponsors info.
					if ( in_array( 'sponsors', $fields ) ) {
						$item['sponsors'] = Utils\get_sponsors( $post->ID, 'native' );
					}

					// If the item is featured, append class names for its featured status and priority level.
					if ( in_array( 'classes', $fields ) ) {
						$item['classes'] = Featured::add_featured_classes( [], [], $post->ID );
					}

					return $item;
				},
				$listings_query->posts
			);

			$response = new \WP_REST_Response( $listings );

			// Provide next URL if there are more pages.
			if ( $next_page <= $listings_query->max_num_pages ) {
				$next_url = add_query_arg(
					[
						'attributes' => $attributes,
						'query'      => $query,
						'page'       => $next_page,
						'amp'        => $is_amp,
						'_fields'    => 'html',
					],
					rest_url( '/' . self::$namespace . '/listings' )
				);
			}

			if ( ! empty( $next_url ) ) {
				$response->header( 'next-url', $next_url );
			}
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Get all terms for a specific taxonomy.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response.
	 */
	public static function get_terms( $request ) {
		$params = $request->get_params();

		if ( empty( $params['taxonomy'] ) ) {
			$params['taxonomy'] = 'category';
		}

		$terms = get_terms( $params );

		if ( is_array( $terms ) ) {
			return new \WP_REST_Response(
				array_map(
					function( $term ) {
						return [
							'id'   => $term->term_id,
							'name' => $term->name,
						];
					},
					$terms
				),
				200
			);
		}

		return new \WP_REST_Response( [] );
	}

	/**
	 * Get featured priority by post ID.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response.
	 */
	public static function get_priority( $request ) {
		$params   = $request->get_params();
		$post_id  = $params['post_id'];
		$priority = Featured::get_priority( $post_id );
		return new \WP_REST_Response( $priority );
	}

	/**
	 * Set featured priority by post ID.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response.
	 */
	public static function set_priority( $request ) {
		$params   = $request->get_params();
		$post_id  = $params['post_id'];
		$priority = $params['priority'];
		$result   = Featured::update_priority( $post_id, $priority );
		return new \WP_REST_Response( $result );
	}
}

Api::instance();
