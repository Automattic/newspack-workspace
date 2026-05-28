<?php
/**
 * Collection Sections Taxonomy handler.
 *
 * @package Newspack\Collections
 */

namespace Newspack\Collections;

use Newspack\Collections\Post_Type;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the Collection Sections taxonomy and related operations.
 */
class Collection_Section_Taxonomy {

	use Traits\Meta_Handler;
	use Traits\Registration_Manager;

	/**
	 * Taxonomy for Collection Sections.
	 *
	 * @var string
	 */
	private const TAXONOMY = 'newspack_collection_section';

	/**
	 * The column name for the order field.
	 *
	 * @var string
	 */
	public const ORDER_COLUMN_NAME = 'order';

	/**
	 * Get the taxonomy for Collection Sections.
	 *
	 * @return string The taxonomy name.
	 */
	public static function get_taxonomy() {
		return self::TAXONOMY;
	}

	/**
	 * Get meta definitions.
	 *
	 * @return array Array of meta definitions. See `Traits\Meta_Handler::get_meta_definitions()` for more details.
	 */
	public static function get_meta_definitions() {
		return [
			'section_order' => [
				'type'              => 'integer',
				'label'             => __( 'Order', 'newspack-plugin' ),
				'description'       => __( 'Set the order of this section within collections (lower numbers appear first).', 'newspack-plugin' ),
				'single'            => true,
				'sanitize_callback' => 'absint',
				'show_in_rest'      => true,
				'default'           => 0,
			],
		];
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
	 * Initialize the taxonomy handler.
	 */
	public static function init() {
		// Register taxonomy and menu relationships.
		add_action( 'init', [ __CLASS__, 'register_taxonomy' ] );
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'newspack_collections_before_flush_rewrites', [ __CLASS__, 'update_registration' ] );
		add_action( 'admin_menu', [ __CLASS__, 'add_to_collections_menu' ] );
		add_filter( 'parent_file', [ __CLASS__, 'set_parent_menu' ] );

		// Admin column customization.
		add_action( 'manage_post_posts_columns', [ __CLASS__, 'set_taxonomy_column_name_in_post_list' ] );
		add_filter( 'manage_edit-' . self::get_taxonomy() . '_columns', [ __CLASS__, 'add_order_column' ] );
		add_filter( 'manage_' . self::get_taxonomy() . '_custom_column', [ __CLASS__, 'display_order_column' ], 10, 3 );
		add_filter( 'manage_edit-' . self::get_taxonomy() . '_sortable_columns', [ __CLASS__, 'make_order_column_sortable' ] );
		add_action( 'parse_term_query', [ __CLASS__, 'handle_admin_sorting' ] );

		// Order field handling.
		add_action( self::get_taxonomy() . '_add_form_fields', [ __CLASS__, 'add_order_field' ] );
		add_action( self::get_taxonomy() . '_edit_form_fields', [ __CLASS__, 'edit_order_field' ] );
		add_action( 'created_' . self::get_taxonomy(), [ __CLASS__, 'save_order_meta' ] );
		add_action( 'edited_' . self::get_taxonomy(), [ __CLASS__, 'save_order_meta' ] );
		add_action( 'created_' . self::get_taxonomy(), [ __CLASS__, 'ensure_order_meta_on_create' ], 10, 3 );

		// Quick edit functionality.
		add_action( 'quick_edit_custom_box', [ __CLASS__, 'add_quick_edit_field' ], 10, 3 );
		add_action( 'edited_' . self::get_taxonomy(), [ __CLASS__, 'save_order_meta' ] );
		add_action( 'current_screen', [ __CLASS__, 'output_section_taxonomy_data_for_admin_scripts' ] );
	}

	/**
	 * Register meta fields for the collection section taxonomy.
	 */
	public static function register_meta() {
		self::register_meta_for_object( 'term', self::get_taxonomy() );
	}

	/**
	 * Register the Collection Sections taxonomy.
	 */
	public static function register_taxonomy() {
		$labels = [
			'name'              => _x( 'Collection Sections', 'collection section taxonomy general name', 'newspack-plugin' ),
			'singular_name'     => _x( 'Collection Section', 'collection section taxonomy singular name', 'newspack-plugin' ),
			'search_items'      => __( 'Search Collection Sections', 'newspack-plugin' ),
			'popular_items'     => __( 'Popular Collection Sections', 'newspack-plugin' ),
			'all_items'         => __( 'All Collection Sections', 'newspack-plugin' ),
			'parent_item'       => __( 'Parent Collection Section', 'newspack-plugin' ),
			'parent_item_colon' => __( 'Parent Collection Section:', 'newspack-plugin' ),
			'edit_item'         => __( 'Edit Collection Section', 'newspack-plugin' ),
			'view_item'         => __( 'View Collection Section', 'newspack-plugin' ),
			'update_item'       => __( 'Update Collection Section', 'newspack-plugin' ),
			'add_new_item'      => __( 'Add New Collection Section', 'newspack-plugin' ),
			'new_item_name'     => __( 'New Collection Section Name', 'newspack-plugin' ),
			'menu_name'         => _x( 'Sections', 'label for collection section menu name', 'newspack-plugin' ),
		];

		$args = [
			'labels'             => $labels,
			'description'        => __( 'Taxonomy for organizing posts into sections within collections.', 'newspack-plugin' ),
			'public'             => true,
			'show_in_menu'       => false, // Hide in the posts menu (but show in the collections menu).
			'show_admin_column'  => true,
			'show_in_quick_edit' => false,
			'show_in_rest'       => true,
			'rewrite'            => [
				'slug' => Settings::get_setting( 'custom_naming_enabled', false ) ? Settings::get_setting( 'custom_slug', 'collection' ) . '-section' : 'collection-section',
			],
		];

		register_taxonomy( self::get_taxonomy(), [ 'post' ], $args );
	}

	/**
	 * Add Collection Sections to the Collections admin menu.
	 */
	public static function add_to_collections_menu() {
		add_submenu_page(
			'edit.php?post_type=' . Post_Type::get_post_type(), // Parent menu slug.
			_x( 'Sections', 'collection section taxonomy page title', 'newspack-plugin' ), // Page title.
			_x( 'Sections', 'collection section taxonomy menu title', 'newspack-plugin' ), // Menu title.
			'manage_categories', // Capability.
			'edit-tags.php?taxonomy=' . self::get_taxonomy() // Menu slug.
		);
	}

	/**
	 * Set the Collections as the parent menu for consistency.
	 *
	 * @param string $parent_file The parent file.
	 * @return string The modified parent file.
	 */
	public static function set_parent_menu( $parent_file ) {
		global $current_screen;

		if ( $current_screen && self::get_taxonomy() === $current_screen->taxonomy ) {
			return $parent_file . '?post_type=' . Post_Type::get_post_type();
		}

		return $parent_file;
	}

	/**
	 * Set the taxonomy column name in the admin post list table.
	 * Used to simplify the column name to "Sections" instead of "Collection Sections".
	 *
	 * @param array $posts_columns An associative array of column headings.
	 * @return array The modified columns array.
	 */
	public static function set_taxonomy_column_name_in_post_list( $posts_columns ) {
		if ( isset( $posts_columns[ 'taxonomy-' . self::get_taxonomy() ] ) ) {
			$posts_columns[ 'taxonomy-' . self::get_taxonomy() ] = _x( 'Sections', 'label for collection section column name', 'newspack-plugin' );
		}

		return $posts_columns;
	}

	/**
	 * Add order column to taxonomy admin list.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public static function add_order_column( $columns ) {
		$new_columns = [];
		foreach ( $columns as $key => $column ) {
			$new_columns[ $key ] = $column;

			// Insert order column after name.
			if ( 'name' === $key ) {
				$new_columns[ self::ORDER_COLUMN_NAME ] = self::get_order_column_heading();
			}
		}
		return $new_columns;
	}

	/**
	 * Filters the displayed order column in the collection sections list table.
	 *
	 * @param string $content     Custom column output. Default empty.
	 * @param string $column_name Name of the column.
	 * @param int    $term_id     Term ID.
	 * @return string Column content.
	 */
	public static function display_order_column( $content, $column_name, $term_id ) {
		if ( self::ORDER_COLUMN_NAME === $column_name ) {
			$order = self::get( $term_id, 'section_order' );
			return $order ? esc_html( $order ) : '0';
		}

		return $content;
	}

	/**
	 * Make order column sortable.
	 *
	 * @param array $sortable_columns An array of sortable columns.
	 * @return array Modified columns.
	 */
	public static function make_order_column_sortable( $sortable_columns ) {
		$sortable_columns[ self::ORDER_COLUMN_NAME ] = 'meta_value_num';
		return $sortable_columns;
	}

	/**
	 * Handle admin sorting for the order column.
	 *
	 * @param WP_Term_Query $query The term query object.
	 */
	public static function handle_admin_sorting( $query ) {
		if ( is_admin() && function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();

			if (
				$screen &&
				'edit-tags' === $screen->base &&
				self::get_taxonomy() === $screen->taxonomy &&
				'meta_value_num' === $query->query_vars['orderby']
			) {
				$query->query_vars['meta_key'] = self::$prefix . 'section_order';
			}
		}
	}

	/**
	 * Generate the HTML for the order input field.
	 *
	 * @param string $context  The context where the field is being used ('add' or 'edit').
	 * @param int    $order    The current order value.
	 */
	private static function render_order_field_html( $context = 'add', $order = 0 ) {
		$field_id    = self::$prefix . 'section_order';
		$description = self::get_meta_definitions()['section_order']['description'];

		if ( 'add' === $context ) {
			$template = '
			<div class="form-field">
				<label for="%1$s">%2$s</label>
				<input type="number" name="%3$s" id="%1$s" value="%4$d" min="0" step="1" />
				<p class="description">%5$s</p>
			</div>';
		} else {
			$template = '
			<tr class="form-field">
				<th scope="row">
					<label for="%1$s">%2$s</label>
				</th>
				<td>
					<input type="number" name="%3$s" id="%1$s" value="%4$d" min="0" step="1" />
					<p class="description">%5$s</p>
				</td>
			</tr>';
		}

		printf(
			$template, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			/* %1$s */ esc_attr( $field_id ),
			/* %2$s */ esc_html( self::get_order_column_heading() ),
			/* %3$s */ esc_attr( $field_id ),
			/* %4$d */ esc_attr( $order ),
			/* %5$s */ esc_html( $description )
		);
	}

	/**
	 * Add order field to the add term form.
	 */
	public static function add_order_field() {
		self::render_order_field_html( 'add' );
	}

	/**
	 * Add order field to the edit term form.
	 *
	 * @param WP_Term $term Current taxonomy term object.
	 */
	public static function edit_order_field( $term ) {
		$order = self::get( $term->term_id, 'section_order' );
		self::render_order_field_html( 'edit', $order ? (int) $order : 0 );
	}

	/**
	 * Save the order meta when term is created or updated.
	 *
	 * @param int $term_id Term ID.
	 */
	public static function save_order_meta( $term_id ) {
		self::check_auth();

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$order = isset( $_POST[ self::$prefix . 'section_order' ] ) ? (int) $_POST[ self::$prefix . 'section_order' ] : false;
		if ( $order ) {
			self::set( $term_id, 'section_order', $order );
		}
	}

	/**
	 * Ensure order meta is set when term is created (e.g., from post sidebar).
	 *
	 * @param int $term_id The term ID.
	 */
	public static function ensure_order_meta_on_create( $term_id ) {
		// Check if order meta exists. If not, set it to 0.
		if ( '' === self::get( $term_id, 'section_order' ) ) {
			self::set( $term_id, 'section_order', 0 );
		}
	}

	/**
	 * Add order field to quick edit form.
	 *
	 * @param string $column_name Column name.
	 * @param string $screen Screen type.
	 * @param string $taxonomy Taxonomy name.
	 */
	public static function add_quick_edit_field( $column_name, $screen, $taxonomy ) {
		if ( self::get_taxonomy() === $taxonomy && self::ORDER_COLUMN_NAME === $column_name ) {
			?>
			<fieldset>
				<div class="inline-edit-col">
					<label>
						<span class="title"><?php echo esc_html( self::get_order_column_heading() ); ?></span>
						<span class="input-text-wrap">
							<input type="number" name="<?php echo esc_attr( self::$prefix . 'section_order' ); ?>"
									class="ptitle" value="" min="0" step="1" />
						</span>
					</label>
				</div>
			</fieldset>
			<?php
		}
	}

	/**
	 * Output section taxonomy data for admin scripts.
	 *
	 * @param WP_Screen $current_screen The current screen object.
	 */
	public static function output_section_taxonomy_data_for_admin_scripts( $current_screen ) {
		if (
			'edit-tags' === $current_screen->base &&
			self::get_taxonomy() === $current_screen->taxonomy
		) {
			Enqueuer::add_data(
				'sectionTaxonomy',
				[
					'metaDefinitions' => self::get_frontend_meta_definitions(),
					'orderColumnName' => self::ORDER_COLUMN_NAME,
				]
			);
		}
	}
}
