<?php
/**
 * Newspack Story Budget Admin
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget;

/**
 * Admin Class.
 */
class Admin {
	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'register_admin_page' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'admin_notices', [ __CLASS__, 'remove_admin_notices' ], -PHP_INT_MAX );
		add_action( 'all_admin_notices', [ __CLASS__, 'remove_admin_notices' ], -PHP_INT_MAX );
		add_action( 'network_admin_notices', [ __CLASS__, 'remove_admin_notices' ], -PHP_INT_MAX );
		add_action( 'wp_head', [ __CLASS__, 'story_preview_css' ], 100 );
		add_filter( 'newspack_popups_should_display_prompt', [ __CLASS__, 'hide_prompts_on_preview' ] );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_editor_assets' ], 9 );

		// Add Quick Edit and Bulk Edit support for Budgets taxonomy.
		add_action( 'quick_edit_custom_box', [ __CLASS__, 'render_budgets_quick_edit' ], 10, 2 );
		add_action( 'bulk_edit_custom_box', [ __CLASS__, 'render_budgets_bulk_edit' ], 10, 2 );
		add_action( 'save_post', [ __CLASS__, 'save_budgets_quick_bulk_edit' ], 10, 2 );
		add_action( 'bulk_edit_posts', [ __CLASS__, 'save_budgets_bulk_edit' ], 10, 2 );

		// Add Budgets dropdown to the posts list table.
		add_action( 'restrict_manage_posts', [ __CLASS__, 'add_budgets_dropdown' ] );
		add_filter( 'manage_post_posts_columns', [ __CLASS__, 'add_budgets_column' ] );
		add_action( 'manage_post_posts_custom_column', [ __CLASS__, 'render_budgets_column' ], 10, 2 );
		add_action( 'pre_get_posts', [ __CLASS__, 'filter_posts_by_budgets' ] );
	}

	/**
	 * Register the admin page.
	 */
	public static function register_admin_page() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M10.5 14.5H17.5V16H10.5V14.5ZM10.5 12.5H17.5V11H10.5V12.5ZM21 6C20.9991 10 20.9991 14 21 18C21 18.5304 20.7893 19.0391 20.4142 19.4142C20.0391 19.7893 19.5304 20 19 20H5.625C5.28033 20.0001 4.93901 19.9324 4.62053 19.8005C4.30206 19.6687 4.01267 19.4755 3.76891 19.2318C3.52514 18.9881 3.33177 18.6988 3.19984 18.3804C3.0679 18.062 3 17.7207 3 17.376V8.999C3 7.895 3.895 7 4.998 6.997C5.664 6.995 6.418 6.994 7.004 6.995V6C7.004 5.46957 7.21471 4.96086 7.58979 4.58579C7.96486 4.21071 8.47357 4 9.004 4H19C19.5304 4 20.0391 4.21071 20.4142 4.58579C20.7893 4.96086 21 5.46957 21 6ZM7.004 8.495C6.428 8.494 5.677 8.495 5.002 8.497C4.86877 8.49726 4.74109 8.55038 4.64697 8.64468C4.55286 8.73898 4.5 8.86677 4.5 9V17.377C4.5 17.997 5.003 18.501 5.625 18.501C6.387 18.501 7.004 17.884 7.004 17.122V8.495ZM19.5 9.083V6C19.5 5.86739 19.4473 5.74021 19.3536 5.64645C19.2598 5.55268 19.1326 5.5 19 5.5H9.004C8.87139 5.5 8.74422 5.55268 8.65045 5.64645C8.55668 5.74021 8.504 5.86739 8.504 6V17.121C8.504 17.621 8.377 18.091 8.153 18.5H19C19.1326 18.5 19.2598 18.4473 19.3536 18.3536C19.4473 18.2598 19.5 18.1326 19.5 18V9.083ZM10.5 9H17.5V7.5H10.5V9Z" /></svg>';
		$icon = sprintf(
			'data:image/svg+xml;base64,%s',
			base64_encode( $svg )
		);
		add_menu_page(
			__( 'Story Budget', 'newspack-story-budget' ),
			__( 'Story Budget', 'newspack-story-budget' ),
			'edit_posts',
			'newspack-story-budget',
			[ __CLASS__, 'render_admin_page' ],
			$icon,
			6
		);
	}

	/**
	 * Enqueue assets.
	 */
	public static function enqueue_assets() {
		$data_asset = require NEWSPACK_STORY_BUDGET_PLUGIN_DIR . 'dist/story-budget-data.asset.php';
		wp_register_script(
			'newspack-story-budget-data',
			plugin_dir_url( __DIR__ ) . 'dist/story-budget-data.js',
			$data_asset['dependencies'],
			$data_asset['version'],
			true
		);
		wp_localize_script(
			'newspack-story-budget-data',
			'newspackStoryBudget',
			[
				'apiNamespace'       => API::NAMESPACE,
				'siteUrl'            => get_site_url(),
				'refreshCache'       => isset( $_GET['page'] ) && 'newspack-story-budget' === $_GET['page'], // phpcs:ignore WordPress.Security.NonceVerification.Recommended,
				'alwaysFetchStories' => defined( 'NEWSPACK_STORY_BUDGET_ALWAYS_FETCH_STORIES' ) && NEWSPACK_STORY_BUDGET_ALWAYS_FETCH_STORIES,
			]
		);

		global $pagenow;

		if ( 'post' === get_post_type() && 'edit.php' === $pagenow ) {
			$quick_edit_asset = require NEWSPACK_STORY_BUDGET_PLUGIN_DIR . 'dist/story-budget-quick-edit.asset.php';
			wp_enqueue_script(
				'newspack-story-budget-quick-edit',
				plugin_dir_url( __DIR__ ) . 'dist/story-budget-quick-edit.js',
				$quick_edit_asset['dependencies'],
				$quick_edit_asset['version'],
				true
			);
		}

		if ( isset( $_GET['page'] ) && 'newspack-story-budget' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$app_asset = require NEWSPACK_STORY_BUDGET_PLUGIN_DIR . 'dist/story-budget-app.asset.php';
			wp_enqueue_script(
				'newspack-story-budget-app',
				plugin_dir_url( __DIR__ ) . 'dist/story-budget-app.js',
				array_merge( $app_asset['dependencies'], [ 'newspack-story-budget-data' ] ),
				$app_asset['version'],
				true
			);
			wp_enqueue_style(
				'newspack-story-budget-app',
				plugin_dir_url( __DIR__ ) . 'dist/story-budget-app.css',
				[ 'wp-components' ],
				$app_asset['version']
			);
		}
	}

	/**
	 * Render the admin page.
	 */
	public static function render_admin_page() {
		?>
		<div id="newspack-story-budget-app"></div>
		<?php
	}

	/**
	 * Remove admin notices from the Story Budget page.
	 *
	 * @return array
	 */
	public static function remove_admin_notices() {
		if ( ! isset( $_GET['page'] ) || 'newspack-story-budget' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		remove_all_actions( current_action() );
	}

	/**
	 * Check if the story preview is enabled.
	 */
	protected static function is_story_preview() {
		$story = new Story( \get_the_ID() );
		if ( ! $story->is_valid() ) {
			return false;
		}
		return $story->can_preview() && isset( $_GET['newspack-story-preview'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Story preview CSS.
	 */
	public static function story_preview_css() {
		if ( ! self::is_story_preview() ) {
			return;
		}
		add_filter( 'show_admin_bar', '__return_false' ); // phpcs:ignore WordPressVIPMinimum.UserExperience.AdminBarRemoval.RemovalDetected
		?>
		<style>
			html {
				margin-top: 0 !important;
			}
			#page > *,
			#comments,
			#secondary,
			.edit-link {
				display: none !important;
			}
			#page > #content {
				display: block !important;
				pointer-events: none;
			}
		</style>
		<?php
	}

	/**
	 * Hide Newspack Campaign prompts on the story preview.
	 *
	 * @param bool $should_display Whether the prompt should be displayed.
	 *
	 * @return bool
	 */
	public static function hide_prompts_on_preview( $should_display ) {
		if ( self::is_story_preview() ) {
			return false;
		}
		return $should_display;
	}

	/**
	 * Enqueue editor assets.
	 */
	public static function enqueue_editor_assets() {
		$screen = get_current_screen();
		if ( ! in_array( $screen->post_type, Budgets::get_post_types(), true ) ) {
			return;
		}

		$editor_asset = require NEWSPACK_STORY_BUDGET_PLUGIN_DIR . 'dist/story-budget-editor.asset.php';
		wp_enqueue_script(
			'newspack-story-budget-editor',
			plugin_dir_url( __DIR__ ) . 'dist/story-budget-editor.js',
			array_merge( $editor_asset['dependencies'], [ 'newspack-story-budget-data' ] ),
			$editor_asset['version'],
			true
		);
		wp_enqueue_style(
			'newspack-story-budget-editor',
			plugin_dir_url( __DIR__ ) . 'dist/story-budget-editor.css',
			[ 'wp-components' ],
			$editor_asset['version']
		);
	}

	/**
	 * Render Budgets field in Quick Edit form.
	 *
	 * @param string $column_name The name of the column to edit.
	 * @param string $post_type   The post type.
	 */
	public static function render_budgets_quick_edit( $column_name, $post_type ) {
		if ( $column_name !== '_np_story_budget_budgets' ) {
			return;
		}
		$budgets = Budgets::get_budgets();
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<span class="title"><?php esc_html_e( 'Budget', 'newspack-story-budget' ); ?></span>
				<select name="newspack_story_budget_budgets[]" style="width: 100%;">
					<option value=""><?php esc_html_e( 'No budget', 'newspack-story-budget' ); ?></option>
					<?php foreach ( $budgets as $budget ) : ?>
						<option value="<?php echo esc_attr( $budget->id ); ?>"><?php echo esc_html( $budget->term->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Render Budgets field in Bulk Edit form.
	 *
	 * @param string $column_name The name of the column to edit.
	 * @param string $post_type   The post type.
	 */
	public static function render_budgets_bulk_edit( $column_name, $post_type ) {
		if ( $column_name !== '_np_story_budget_budgets' ) {
			return;
		}
		$budgets = Budgets::get_budgets();
		?>
		<fieldset class="inline-edit-col-left">
			<div class="inline-edit-col">
				<span class="title"><?php esc_html_e( 'Budget', 'newspack-story-budget' ); ?></span>
				<select name="newspack_story_budget_budget[]" style="width: 100%;">
					<option value=""><?php esc_html_e( 'No change', 'newspack-story-budget' ); ?></option>
					<option value="-1"><?php esc_html_e( 'Clear budget', 'newspack-story-budget' ); ?></option>
					<?php foreach ( $budgets as $budget ) : ?>
						<option value="<?php echo esc_attr( $budget->id ); ?>"><?php echo esc_html( $budget->term->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Save Budgets from Quick Edit and Bulk Edit.
	 *
	 * @param int   $post_id The post ID.
	 * @param mixed $post    The post object.
	 */
	public static function save_budgets_quick_bulk_edit( $post_id, $post ) {

		if ( ! isset( $_POST['newspack_story_budget_budgets'] ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		check_ajax_referer( 'inlineeditnonce', '_inline_edit' );

		$budgets = array_map( 'intval', (array) $_POST['newspack_story_budget_budgets'] );
		wp_set_object_terms( $post_id, $budgets, Budgets::TAXONOMY );
	}

	/**
	 * Adds a dropdown to filter posts by budget.
	 *
	 * @param string $post_type The post type of the current list.
	 * @return void
	 */
	public static function add_budgets_dropdown( $post_type ) {
		if ( ! in_array( $post_type, Budgets::get_post_types(), true ) ) {
			return;
		}

		$num_posts_with_budget = wp_count_terms(
			[
				'taxonomy'   => Budgets::TAXONOMY,
				'hide_empty' => false,
			]
		);

		// If we have no budgets or no posts with a budget, then don't show the dropdown.
		if ( is_wp_error( $num_posts_with_budget ) || (int) $num_posts_with_budget < 1 ) {
			return;
		}

		$taxonomy_object = get_taxonomy( Budgets::TAXONOMY );
		$selected        = isset( $_GET[ Budgets::TAXONOMY ] ) ? sanitize_text_field( wp_unslash( $_GET[ Budgets::TAXONOMY ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		wp_dropdown_categories(
			array(
				'show_option_all'  => $taxonomy_object->labels->all_items,
				'show_option_none' => $taxonomy_object->labels->no_items,
				'taxonomy'         => Budgets::TAXONOMY,
				'name'             => Budgets::TAXONOMY,
				'orderby'          => 'name',
				'value_field'      => 'slug',
				'selected'         => $selected,
				'hierarchical'     => false,
				'hide_empty'       => false,
			)
		);
	}

	/**
	 * Add Budgets column to the posts list table.
	 *
	 * @param array $columns The existing columns.
	 * @return array Modified columns.
	 */
	public static function add_budgets_column( $columns ) {
		$columns['_np_story_budget_budgets'] = __( 'Budget', 'newspack-story-budget' );
		return $columns;
	}

	/**
	 * Render Budgets column content.
	 *
	 * @param string $column_name The column name.
	 * @param int    $post_id     The post ID.
	 */
	public static function render_budgets_column( $column_name, $post_id ) {
		if ( $column_name !== '_np_story_budget_budgets' ) {
			return;
		}
		$budgets = wp_get_object_terms( $post_id, Budgets::TAXONOMY );
		if ( is_wp_error( $budgets ) || empty( $budgets ) ) {
			echo '<span class="np-story-budget-budgets" data-budgets=""></span>&mdash;';
			return;
		}
		$ids = wp_list_pluck( $budgets, 'term_id' );
		$names = wp_list_pluck( $budgets, 'name' );
		echo '<span class="np-story-budget-budgets" data-budgets="' . esc_attr( implode( ',', $ids ) ) . '">' . esc_html( implode( ', ', $names ) ) . '</span>';
	}

	/**
	 * Save Budgets from Bulk Edit.
	 *
	 * @param array $updated An array of updated post IDs.
	 * @param array $shared_post_data Associative array containing the post data.
	 */
	public static function save_budgets_bulk_edit( $updated, $shared_post_data ) {

		if ( empty( $shared_post_data['newspack_story_budget_budget'] ) ) {
			return;
		}

		$budget_id = $shared_post_data['newspack_story_budget_budget'][0];

		if ( empty( $budget_id ) ) {
			return;
		}

		foreach ( $updated as $post_id ) {
			if ( '-1' === $budget_id ) {
				wp_delete_object_term_relationships( $post_id, Budgets::TAXONOMY );
			} else {
				wp_set_object_terms( $post_id, (int) $budget_id, Budgets::TAXONOMY );
			}
		}
	}

	/**
	 * Filter posts by budgets.
	 *
	 * @param WP_Query $query The query object.
	 */
	public static function filter_posts_by_budgets( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$taxonomy = Budgets::TAXONOMY;

		if ( ! isset( $_GET[ $taxonomy ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$terms = sanitize_text_field( wp_unslash( $_GET[ $taxonomy ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $terms === '0' ) {
			return;
		}

		$tax_query = [
			'taxonomy' => $taxonomy,
			'field'    => 'slug',
		];

		if ( $terms === '-1' ) {
			$tax_query['operator'] = 'NOT EXISTS';
		} else {
			$tax_query['terms'] = $terms;
		}

		$query->set( $taxonomy, '' );
		$query->set( 'tax_query', [ $tax_query ] );
	}
}
