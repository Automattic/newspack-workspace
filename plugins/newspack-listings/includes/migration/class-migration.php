<?php
/**
 * Migration utilities for Newspack Listings.
 *
 * @package Newspack_Listings
 */

namespace Newspack_Listings;

use WP_CLI;
use Newspack_Listings\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Importer class.
 * Sets up CLI-based importer for listings.
 */
final class Migration {
	/**
	 * Whether the script is running as a dry-run.
	 *
	 * @var Migration
	 */
	public static $is_dry_run = false;

	/**
	 * The single instance of the class.
	 *
	 * @var Migration
	 */
	protected static $instance = null;

	/**
	 * Main Migration instance.
	 * Ensures only one instance of Migration is loaded or can be loaded.
	 *
	 * @return Migration - Main instance.
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
		add_action( 'init', [ __CLASS__, 'add_cli_commands' ] );
	}

	/**
	 * Register the 'newspack-listings import' WP CLI command.
	 */
	public static function add_cli_commands() {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		WP_CLI::add_command(
			'newspack-listings taxonomies convert',
			[ __CLASS__, 'cli_taxonomy_convert' ],
			[
				'shortdesc' => 'Migrate legacy listing taxonomies to core post taxonomies.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Whether to do a dry run.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Run the 'newspack-listings taxonomy convert' WP CLI command.
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public static function cli_taxonomy_convert( $args, $assoc_args ) {
		// If a dry run, we won't persist any data.
		self::$is_dry_run = isset( $assoc_args['dry-run'] ) ? true : false;

		if ( self::$is_dry_run ) {
			WP_CLI::log( "\n===================\n=     Dry Run     =\n===================\n" );
		}

		WP_CLI::log( "Checking for legacy taxonomy terms...\n" );

		$converted_taxonomies = self::convert_legacy_taxonomies();

		if ( 0 === count( $converted_taxonomies['category'] ) && 0 === count( $converted_taxonomies['post_tag'] ) ) {
			WP_CLI::success( 'Completed! No legacy categories or tags found.' );
		} else {
			WP_CLI::success(
				sprintf(
					'Completed! Converted %1$s %2$s and %3$s %4$s.',
					count( $converted_taxonomies['category'] ),
					1 < count( $converted_taxonomies['category'] ) ? 'categories' : 'category',
					count( $converted_taxonomies['post_tag'] ),
					1 < count( $converted_taxonomies['post_tag'] ) ? 'tags' : 'tag'
				)
			);
		}
	}

	/**
	 * Convert legacy custom taxonomies to regular post categories and tags.
	 * Helpful for sites that have been using v1 of the Listings plugin.
	 *
	 * @return object Object containing converted term info.
	 */
	public static function convert_legacy_taxonomies() {
		$converted_taxonomies = [
			'category' => [],
			'post_tag' => [],
		];
		$custom_category_slug = 'newspack_lst_cat';
		$custom_tag_slug      = 'newspack_lst_tag';

		$category_args = [
			'hierarchical'  => true,
			'public'        => false,
			'rewrite'       => false,
			'show_in_menu'  => false,
			'show_in_rest'  => false,
			'show_tagcloud' => false,
			'show_ui'       => false,
		];
		$tag_args      = [
			'hierarchical'  => false,
			'public'        => false,
			'rewrite'       => false,
			'show_in_menu'  => false,
			'show_in_rest'  => false,
			'show_tagcloud' => false,
			'show_ui'       => false,
		];

		// Temporarily register the taxonomies for all Listing CPTs.
		$post_types = array_values( Core::NEWSPACK_LISTINGS_POST_TYPES );
		register_taxonomy( $custom_category_slug, $post_types, $category_args );
		register_taxonomy( $custom_tag_slug, $post_types, $tag_args );

		// Get a list of the custom terms.
		$custom_terms = get_terms(
			[
				'taxonomy'   => [ $custom_category_slug, $custom_tag_slug ],
				'hide_empty' => false,
			]
		);

		// If we don't have any terms from the legacy taxonomies, no need to proceed.
		if ( is_wp_error( $custom_terms ) || 0 === count( $custom_terms ) ) {
			unregister_taxonomy( $custom_category_slug );
			unregister_taxonomy( $custom_tag_slug );
			return $converted_taxonomies;
		}

		foreach ( $custom_terms as $term ) {
			// See if we have any corresponding terms already.
			$is_category            = $custom_category_slug === $term->taxonomy;
			$corresponding_taxonomy = $is_category ? 'category' : 'post_tag';
			$corresponding_term     = get_term_by( 'name', $term->name, $corresponding_taxonomy, ARRAY_A );

			// If not, create the term.
			if ( ! $corresponding_term && ! self::$is_dry_run ) {
				$corresponding_term = wp_insert_term(
					$term->name,
					$corresponding_taxonomy,
					[
						'description' => $term->description,
						'slug'        => $term->slug,
					]
				);
			}

			// Get any posts with the legacy term.
			$posts_with_custom_term = new \WP_Query(
				[
					'post_type' => $post_types,
					'per_page'  => 1000,
					'tax_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						[
							'taxonomy' => $term->taxonomy,
							'field'    => 'term_id',
							'terms'    => $term->term_id,
						],
					],
				]
			);

			// Apply the new term to the post.
			if ( $posts_with_custom_term->have_posts() && ! self::$is_dry_run ) {
				while ( $posts_with_custom_term->have_posts() ) {
					$posts_with_custom_term->the_post();
					wp_set_post_terms(
						get_the_ID(), // Post ID to apply the new term.
						[ $corresponding_term['term_id'] ], // Term ID of the new term.
						$corresponding_taxonomy, // Category or tag.
						true // Append the term without deleting existing terms.
					);
				}
			}

			// Finally, delete the legacy term.
			if ( ! self::$is_dry_run ) {
				wp_delete_term( $term->term_id, $term->taxonomy );
			}

			if ( $is_category ) {
				$converted_taxonomies['category'][] = $term->term_id;
			} else {
				$converted_taxonomies['post_tag'][] = $term->term_id;
			}

			WP_CLI::log(
				sprintf(
					'Converted %1$s "%2$s".',
					$is_category ? 'category' : 'tag',
					$term->name
				)
			);
		}

		// Unregister the legacy taxonomies.
		unregister_taxonomy( $custom_category_slug );
		unregister_taxonomy( $custom_tag_slug );

		return $converted_taxonomies;
	}
}

Migration::instance();
