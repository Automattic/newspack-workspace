<?php
/**
 * Newspack Hub Woo Admin pages
 *
 * @package Newspack
 */

// phpcs:disable WordPress.Security.NonceVerification.Recommended

namespace Newspack_Network\Hub\Admin;

use Newspack_Network\Hub\Admin;
use Newspack_Network\Hub\Nodes;
use Newspack_Network\Hub\Stores\Woo_Item;

/**
 * Class to handle the Woo admin pages by customizing the Custom Post type screens
 */
abstract class Woo {

	/**
	 * The affected post types
	 *
	 * @var string
	 */
	protected static $post_types = [];

	/**
	 * Whether the global hooks have been added
	 *
	 * @var boolean
	 */
	protected static $hooks_added = false;

	/**
	 * Runs the initialization.
	 */
	public static function init() {
		$class_name         = get_called_class();
		$db_class_name      = str_replace( 'Admin', 'Database', $class_name );
		self::$post_types[] = $db_class_name::POST_TYPE_SLUG;

		// Removes the Bulk actions dropdown.
		add_filter( 'bulk_actions-edit-' . $db_class_name::POST_TYPE_SLUG, '__return_empty_array' );

		add_filter( 'manage_' . $db_class_name::POST_TYPE_SLUG . '_posts_columns', [ $class_name, 'posts_columns' ] );
		add_action( 'manage_' . $db_class_name::POST_TYPE_SLUG . '_posts_custom_column', [ $class_name, 'posts_columns_values' ], 10, 2 );
		add_action( 'parse_query', [ $class_name, 'parse_query' ] );

		add_filter( 'get_edit_post_link', [ $class_name, 'get_edit_post_link' ], 10, 2 );

		if ( ! self::$hooks_added ) {
			self::$hooks_added = true;
			add_filter( 'post_row_actions', [ __CLASS__, 'remove_row_actions' ], 10, 2 );

			add_action( 'admin_enqueue_scripts', [ __CLASS__, 'admin_enqueue_scripts' ] );

			add_filter( 'disable_months_dropdown', [ __CLASS__, 'disable_restrict_manage_posts' ], 10, 2 );
			add_filter( 'disable_categories_dropdown', [ __CLASS__, 'disable_restrict_manage_posts' ], 10, 2 );
			add_filter( 'disable_formats_dropdown', [ __CLASS__, 'disable_restrict_manage_posts' ], 10, 2 );

			add_action( 'restrict_manage_posts', [ __CLASS__, 'restrict_manage_posts' ], 10, 2 );
			add_filter( 'pre_get_posts', [ __CLASS__, 'pre_get_posts' ] );
		}
	}

	/**
	 * Enqueues the admin styles.
	 *
	 * @return void
	 */
	public static function admin_enqueue_scripts() {
		global $pagenow;
		if ( 'edit.php' !== $pagenow || ! in_array( get_post_type(), self::$post_types, true ) ) {
			return;
		}

		wp_enqueue_style(
			'newspack-network-woo-cpts',
			plugins_url( 'css/woo-cpts.css', __FILE__ ),
			[],
			filemtime( NEWSPACK_NETWORK_PLUGIN_DIR . '/includes/hub/admin/css/woo-cpts.css' )
		);
	}

	/**
	 * Removes the row actions for the Subscriptions post type.
	 *
	 * @param array   $actions An array of row action links.
	 * @param WP_Post $post    The post object.
	 * @return array
	 */
	public static function remove_row_actions( $actions, $post ) {
		if ( in_array( $post->post_type, self::$post_types, true ) ) {
			return [];
		}
		return $actions;
	}

	/**
	 * Filters the edit link for a Subscription Item.
	 *
	 * @param string $link    The edit link.
	 * @param int    $post_id The post ID.
	 * @return string
	 */
	public static function get_edit_post_link( $link, $post_id ) {
		$store_class_name = str_replace( 'Admin', 'Stores', get_called_class() );
		$item             = $store_class_name::get_item( $post_id );
		if ( ! $item ) { // Not a Woo item.
			return $link;
		}
		$edit_link = $item->get_edit_link();
		// Even if get_edit_link returns null, that's what we'll use, because we don't want to link to the post edit page.
		return (string) $edit_link;
	}

	/**
	 * Disable the restrict manage posts dropdowns
	 *
	 * @param bool   $disable Whether to disable the dropdown.
	 * @param string $post_type The post type.
	 * @return bool
	 */
	public static function disable_restrict_manage_posts( $disable, $post_type ) {
		if ( in_array( $post_type, self::$post_types, true ) ) {
			return true;
		}
		return $disable;
	}

	/**
	 * Add filter options to the nav bar
	 *
	 * @param string $post_type The post type slug.
	 * @param string $which     The location of the extra table nav markup.
	 * @return void
	 */
	public static function restrict_manage_posts( $post_type, $which ) {
		if ( 'top' !== $which || ! in_array( $post_type, self::$post_types, true ) ) {
			return;
		}

		$current_node = isset( $_GET['node_id'] ) ? sanitize_text_field( $_GET['node_id'] ) : '';

		Nodes::nodes_dropdown( $current_node );
	}

	/**
	 * Filters the main query in the admin page
	 *
	 * @param \WP_Query $query The Query object.
	 * @return NULL
	 */
	public static function pre_get_posts( $query ) {
		global $pagenow;
		$post_type = isset( $_GET['post_type'] ) ? sanitize_text_field( $_GET['post_type'] ) : '';
		if ( 'edit.php' !== $pagenow || ! in_array( $post_type, self::$post_types, true ) || ! is_admin() || ! $query->is_main_query() ) {
			return null;
		}

		if ( ! isset( $_GET['node_id'] ) || ! is_numeric( $_GET['node_id'] ) ) { // zero is a valid value.
			return null;
		}
		$query->query_vars['meta_query'][] = [
			'key'   => 'node_id',
			'value' => sanitize_text_field( $_GET['node_id'] ),
		];

		return null;
	}

	/**
	 * Filters search query to include custom fields
	 *
	 * @param \WP_Query $query  The Query object.
	 * @return void
	 */
	public static function parse_query( $query ) {
		global $pagenow;
		if ( ! is_admin() || 'edit.php' !== $pagenow || empty( $query->query_vars['s'] ) || ! in_array( $query->query_vars['post_type'], self::$post_types, true ) ) {
			return;
		}

		$search_term = $query->query_vars['s'];

		if ( ! is_numeric( $search_term ) ) {
			// Query by name and/or email meta.
			$meta_query = [
				'relation' => 'OR',
				[
					'key'     => 'user_name',
					'value'   => sanitize_text_field( $search_term ),
					'compare' => 'LIKE',
				],
				[
					'key'     => 'user_email',
					'value'   => sanitize_text_field( $search_term ),
					'compare' => 'LIKE',
				],
			];

			$query->set( 'meta_query', $meta_query );

			unset( $query->query_vars['s'] );
		}
	}

	/**
	 * Modify columns on post type table
	 *
	 * @param array $columns Registered columns.
	 * @return array
	 */
	abstract public static function posts_columns( $columns );

	/**
	 * Add content to the custom column
	 *
	 * @param string $column The current column.
	 * @param int    $post_id The current post ID.
	 * @return void
	 */
	abstract public static function posts_columns_values( $column, $post_id );
}
