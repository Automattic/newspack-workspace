<?php
/**
 * Collections Post Type handler.
 *
 * @package Newspack
 */

namespace Newspack\Collections;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-collection-meta.php';

/**
 * Handles the Collections custom post type and related operations.
 */
class Post_Type {
	use Traits\Hook_Manager;
	use Traits\Registration_Manager;

	/**
	 * Post type for Collections.
	 *
	 * @var string
	 */
	private const POST_TYPE = 'newspack_collection';

	/**
	 * Order column name (using default WP menu order column).
	 *
	 * @var string
	 */
	private const ORDER_COLUMN_NAME = 'menu_order';

	/**
	 * Get the hooks for collection custom post type operations.
	 * Same structure as the add_action() parameters.
	 *
	 * @return array {
	 *     Array of hooks with the same structure as the add_action() parameters.
	 *
	 *     @type string   $hook
	 *     @type callable $callback
	 *     @type int      $priority
	 *     @type int      $accepted_args
	 * }
	 */
	protected static function get_hooks() {
		return [
			[ 'save_post_' . self::get_post_type(), [ Sync::class, 'handle_post_save' ], 10, 3 ],
			[ 'before_delete_post', [ Sync::class, 'handle_post_deleted' ] ],
			[ 'wp_trash_post', [ Sync::class, 'handle_post_trashed' ] ],
			[ 'untrashed_post', [ Sync::class, 'handle_post_untrashed' ] ],
			[ 'pre_post_update', [ __CLASS__, 'validate_collection_title' ], 10, 2 ],
		];
	}

	/**
	 * Get the post type for the Collections.
	 *
	 * @return string The post type.
	 */
	public static function get_post_type() {
		return self::POST_TYPE;
	}

	/**
	 * Get the translated column heading.
	 *
	 * @return string The translated column heading.
	 */
	public static function get_order_column_heading() {
		return __( 'Order', 'newspack-plugin' );
	}

	/**
	 * Initialize the post type handler.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_post_type' ] );
		add_action( 'newspack_collections_before_flush_rewrites', [ __CLASS__, 'update_registration' ] );
		add_action( 'current_screen', [ __CLASS__, 'output_collection_meta_data_for_admin_scripts' ] );
		add_action( 'manage_' . self::get_post_type() . '_posts_columns', [ __CLASS__, 'add_order_column' ] );
		add_action( 'manage_' . self::get_post_type() . '_posts_custom_column', [ __CLASS__, 'display_order_column' ], 10, 2 );
		add_filter( 'manage_edit-' . self::get_post_type() . '_sortable_columns', [ __CLASS__, 'make_order_column_sortable' ] );
		self::register_hooks();
		Collection_Meta::init();
	}

	/**
	 * Register the Collections custom post type.
	 */
	public static function register_post_type() {
		$labels = [
			'name'               => _x( 'Collections', 'collection post type general name', 'newspack-plugin' ),
			'singular_name'      => _x( 'Collection', 'collection post type singular name', 'newspack-plugin' ),
			'add_new'            => _x( 'Add New', 'label for add new collection', 'newspack-plugin' ),
			'add_new_item'       => __( 'Add New Collection', 'newspack-plugin' ),
			'edit_item'          => __( 'Edit Collection', 'newspack-plugin' ),
			'new_item'           => __( 'New Collection', 'newspack-plugin' ),
			'view_item'          => __( 'View Collection', 'newspack-plugin' ),
			'view_items'         => __( 'View Collections', 'newspack-plugin' ),
			'search_items'       => __( 'Search Collections', 'newspack-plugin' ),
			'not_found'          => __( 'No collections found.', 'newspack-plugin' ),
			'not_found_in_trash' => __( 'No collections found in Trash.', 'newspack-plugin' ),
			'all_items'          => __( 'All Collections', 'newspack-plugin' ),
			'item_published'     => __( 'Collection published', 'newspack-plugin' ),
			'item_updated'       => __( 'Collection updated', 'newspack-plugin' ),
		];

		$icon = sprintf(
			'data:image/svg+xml;base64,%s',
			base64_encode( \Newspack\Newspack_UI_Icons::get_svg( 'collections' ) )
		);

		$args = [
			'label'         => __( 'Collection', 'newspack-plugin' ),
			'labels'        => $labels,
			'description'   => __( 'Grouped content for custom classification.', 'newspack-plugin' ),
			'public'        => true,
			'show_in_rest'  => true,
			'rewrite'       => [
				'slug' => Settings::get_collection_slug(),
			],
			'menu_icon'     => $icon,
			'supports'      => [ 'title', 'editor', 'thumbnail', 'custom-fields', 'page-attributes', 'newspack_blocks' ],
			'has_archive'   => true,
			'menu_position' => 6,
		];

		register_post_type( self::get_post_type(), $args );
	}

	/**
	 * Add menu order column to the admin list view.
	 *
	 * @param array $columns The existing columns.
	 * @return array Modified columns array.
	 */
	public static function add_order_column( $columns ) {
		$columns[ self::ORDER_COLUMN_NAME ] = self::get_order_column_heading();
		return $columns;
	}

	/**
	 * Display the menu order value in the custom column.
	 *
	 * @param string $column_name The name of the column.
	 * @param int    $post_id     The post ID.
	 */
	public static function display_order_column( $column_name, $post_id ) {
		if ( self::ORDER_COLUMN_NAME === $column_name ) {
			echo esc_html( get_post_field( self::ORDER_COLUMN_NAME, $post_id ) );
		}
	}

	/**
	 * Make the menu order column sortable.
	 *
	 * @param array $columns The sortable columns.
	 * @return array Modified sortable columns array.
	 */
	public static function make_order_column_sortable( $columns ) {
		$columns[ self::ORDER_COLUMN_NAME ] = self::ORDER_COLUMN_NAME;
		return $columns;
	}

	/**
	 * Output collection meta data for admin scripts.
	 *
	 * @param WP_Screen $current_screen The current screen object.
	 */
	public static function output_collection_meta_data_for_admin_scripts( $current_screen ) {
		if (
			'post' === $current_screen->base &&
			self::get_post_type() === $current_screen->post_type
		) {
			Enqueuer::add_data(
				'collectionPostType',
				[
					'postType'        => self::get_post_type(),
					'metaDefinitions' => Collection_Meta::get_frontend_meta_definitions(),
					'panelTitle'      => __( 'Collection Details', 'newspack-plugin' ),
				]
			);
		}
	}

	/**
	 * Check if a collection title already exists.
	 *
	 * @param string $title     The title to check.
	 * @param int    $exclude_id Post ID to exclude from the check.
	 * @return bool True if a collection with this title exists, false otherwise.
	 */
	public static function title_exists( $title, $exclude_id = 0 ) {
		$args = [
			'post_type'      => self::get_post_type(),
			'post_status'    => [ 'publish', 'private', 'future', 'pending', 'draft' ],
			's'              => $title,
			'posts_per_page' => 100,
			'fields'         => 'ids',
		];

		if ( ! empty( $exclude_id ) ) {
			$args['post__not_in'] = [ $exclude_id ]; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
		}

		$posts = get_posts( $args );

		// Check for exact title match.
		foreach ( $posts as $post_id ) {
			$post = get_post( $post_id );
			if ( $post && trim( $post->post_title ) === trim( $title ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validate collection title to ensure it's unique.
	 *
	 * @param int   $post_id The post ID.
	 * @param array $data Array of unslashed post data.
	 */
	public static function validate_collection_title( $post_id, $data ) {
		// Only validate for collection post type.
		if ( self::get_post_type() !== $data['post_type'] ) {
			return $data;
		}

		// Skip validation for auto-drafts and revisions.
		if ( 'auto-draft' === $data['post_status'] || ! empty( $data['post_parent'] ) ) {
			return $data;
		}

		$title = trim( $data['post_title'] );
		if ( empty( $title ) ) {
			return $data;
		}

		$exclude_id = ! empty( $post_id ) ? $post_id : 0;
		$title_exists = self::title_exists( $title, $exclude_id );

		if ( $title_exists ) {
			wp_die( __( 'This collection could not be saved because a collection with the same title already exists. Please choose a different title and try again.', 'newspack-plugin' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}
