<?php
/**
 * Collection Categories Taxonomy handler.
 *
 * @package Newspack
 */

namespace Newspack\Collections;

use Newspack\Collections\Post_Type;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the Collection Categories taxonomy and related operations.
 */
class Collection_Category_Taxonomy {

	use Traits\Meta_Handler;
	use Traits\Registration_Manager;

	/**
	 * Get the taxonomy for Collection Categories.
	 *
	 * @return string The taxonomy name.
	 */
	public static function get_taxonomy() {
		return self::$prefix . 'category';
	}

	/**
	 * Get meta definitions.
	 *
	 * @return array Array of meta definitions. See `Traits\Meta_Handler::get_meta_definitions()` for more details.
	 */
	public static function get_meta_definitions() {
		return [
			'subscribe_link' => [
				'type'              => 'string',
				'label'             => __( 'Subscription URL', 'newspack-plugin' ),
				'description'       => __( 'Override the global subscription link for this category.', 'newspack-plugin' ),
				'single'            => true,
				'sanitize_callback' => 'esc_url_raw',
				'show_in_rest'      => [
					'schema' => [
						'format' => 'uri',
					],
				],
			],
			'order_link'     => [
				'type'              => 'string',
				'label'             => __( 'Order URL', 'newspack-plugin' ),
				'description'       => __( 'Override the global order link for this category.', 'newspack-plugin' ),
				'single'            => true,
				'sanitize_callback' => 'esc_url_raw',
				'show_in_rest'      => [
					'schema' => [
						'format' => 'uri',
					],
				],
			],
		];
	}

	/**
	 * Initialize the taxonomy handler.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_taxonomy' ] );
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'newspack_collections_before_flush_rewrites', [ __CLASS__, 'update_registration' ] );
		add_action( 'manage_' . Post_Type::get_post_type() . '_posts_columns', [ __CLASS__, 'set_taxonomy_column_name' ] );

		// Term meta field handling.
		add_action( self::get_taxonomy() . '_add_form_fields', [ __CLASS__, 'add_term_meta_fields' ] );
		add_action( self::get_taxonomy() . '_edit_form_fields', [ __CLASS__, 'edit_term_meta_fields' ] );
		add_action( 'created_' . self::get_taxonomy(), [ __CLASS__, 'save_term_meta' ] );
		add_action( 'edited_' . self::get_taxonomy(), [ __CLASS__, 'save_term_meta' ] );
		add_filter( 'newspack_content_gate_supported_taxonomies', [ __CLASS__, 'add_support_for_content_gates' ] );
	}

	/**
	 * Register the Collection Categories taxonomy.
	 */
	public static function register_taxonomy() {
		$labels = [
			'name'              => _x( 'Collection Categories', 'collection category taxonomy general name', 'newspack-plugin' ),
			'singular_name'     => _x( 'Collection Category', 'collection category taxonomy singular name', 'newspack-plugin' ),
			'search_items'      => __( 'Search Collection Categories', 'newspack-plugin' ),
			'popular_items'     => __( 'Popular Collection Categories', 'newspack-plugin' ),
			'all_items'         => __( 'All Collection Categories', 'newspack-plugin' ),
			'parent_item'       => __( 'Parent Collection Category', 'newspack-plugin' ),
			'parent_item_colon' => __( 'Parent Collection Category:', 'newspack-plugin' ),
			'edit_item'         => __( 'Edit Collection Category', 'newspack-plugin' ),
			'view_item'         => __( 'View Collection Category', 'newspack-plugin' ),
			'update_item'       => __( 'Update Collection Category', 'newspack-plugin' ),
			'add_new_item'      => __( 'Add New Collection Category', 'newspack-plugin' ),
			'new_item_name'     => __( 'New Collection Category Name', 'newspack-plugin' ),
			'menu_name'         => __( 'Categories', 'newspack-plugin' ),
		];

		$args = [
			'labels'            => $labels,
			'description'       => __( 'Taxonomy for categorizing collections.', 'newspack-plugin' ),
			'public'            => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => [
				'slug' => Settings::get_setting( 'custom_naming_enabled', false ) ? Settings::get_setting( 'custom_slug', 'collection' ) . '-category' : 'collection-category',
			],
		];

		register_taxonomy( self::get_taxonomy(), [ Post_Type::get_post_type() ], $args );
	}

	/**
	 * Register meta fields for the collection category taxonomy.
	 */
	public static function register_meta() {
		self::register_meta_for_object( 'term', self::get_taxonomy(), 'manage_categories' );
	}

	/**
	 * Set the taxonomy column name in the admin post list table.
	 * Used to simplify the column name to "Categories" instead of "Collection Categories".
	 *
	 * @param array $posts_columns An associative array of column headings.
	 * @return array The modified columns array.
	 */
	public static function set_taxonomy_column_name( $posts_columns ) {
		if ( isset( $posts_columns[ 'taxonomy-' . self::get_taxonomy() ] ) ) {
			$posts_columns[ 'taxonomy-' . self::get_taxonomy() ] = _x( 'Categories', 'label for collection category column name', 'newspack-plugin' );
		}

		return $posts_columns;
	}

	/**
	 * Add meta columns to the taxonomy edit screen.
	 *
	 * @param array $columns An associative array of column headings.
	 * @return array The modified columns array.
	 */
	public static function add_meta_columns( $columns ) {
		foreach ( self::get_meta_definitions() as $key => $meta ) {
			$columns[ self::$prefix . $key ] = $meta['label'];
		}
		return $columns;
	}

	/**
	 * Add term meta fields to the add term form.
	 */
	public static function add_term_meta_fields() {
		foreach ( self::get_meta_definitions() as $key => $meta ) {
			$meta_key = self::$prefix . $key;
			?>
			<div class="form-field">
				<label for="<?php echo esc_attr( $meta_key ); ?>"><?php echo esc_html( $meta['label'] ); ?></label>
				<input type="url" name="<?php echo esc_attr( $meta_key ); ?>" id="<?php echo esc_attr( $meta_key ); ?>" value="" />
				<p class="description"><?php echo esc_html( $meta['description'] ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Add term meta fields to the edit term form.
	 *
	 * @param WP_Term $term Current taxonomy term object.
	 */
	public static function edit_term_meta_fields( $term ) {
		foreach ( self::get_meta_definitions() as $key => $meta ) {
			$meta_key = self::$prefix . $key;
			$value    = get_term_meta( $term->term_id, $meta_key, true );
			?>
			<tr class="form-field">
				<th scope="row">
					<label for="<?php echo esc_attr( $meta_key ); ?>"><?php echo esc_html( $meta['label'] ); ?></label>
				</th>
				<td>
					<input type="url" name="<?php echo esc_attr( $meta_key ); ?>" id="<?php echo esc_attr( $meta_key ); ?>" value="<?php echo esc_attr( $value ); ?>" />
					<p class="description"><?php echo esc_html( $meta['description'] ); ?></p>
				</td>
			</tr>
			<?php
		}
	}

	/**
	 * Save the term meta when term is created or updated.
	 *
	 * @param int $term_id Term ID.
	 */
	public static function save_term_meta( $term_id ) {
		self::check_auth();

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		foreach ( self::get_meta_definitions() as $key => $meta ) {
			$meta_key = self::$prefix . $key;
			if ( isset( $_POST[ $meta_key ] ) ) {
				$value = $meta['sanitize_callback']( $_POST[ $meta_key ] );
				if ( $value ) {
					update_term_meta( $term_id, $meta_key, $value );
				} else {
					delete_term_meta( $term_id, $meta_key );
				}
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * Add support for content gates.
	 *
	 * @param array $taxonomies Taxonomies.
	 * @return array Taxonomies.
	 */
	public static function add_support_for_content_gates( $taxonomies ) {
		$taxonomies[] = [
			'slug'        => self::get_taxonomy(),
			'label'       => __( 'Collection categories', 'newspack-plugin' ),
			'description' => __( 'Categories specific to your collections.', 'newspack-plugin' ),
		];
		return $taxonomies;
	}
}
