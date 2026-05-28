<?php
/**
 * Republication Tracker Tool Article Settings.
 *
 * @since   1.0
 * @package Republication_Tracker_Tool
 */

/**
 * Republication Tracker Tool Article Settings class.
 *
 * @since 1.0
 */
class Republication_Tracker_Tool_Article_Settings {
	/**
	 * Parent plugin class.
	 *
	 * @var    Republication_Tracker_Tool
	 * @since  1.0
	 */
	protected $plugin = null;

	/**
	 * Constructor.
	 *
	 * @since  1.0
	 *
	 * @param  Republication_Tracker_Tool $plugin Main plugin object.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
		$this->hooks();
	}

	/**
	 * Initiate our hooks.
	 *
	 * @since  1.0
	 */
	public function hooks() {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_fields' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
		add_action( 'manage_edit-post_columns', array( $this, 'add_custom_columns' ) );
		add_action( 'manage_edit-post_sortable_columns', array( $this, 'add_sortable_columns' ) );
		add_action( 'manage_posts_custom_column', array( $this, 'custom_column_content' ), 10, 2 );
		add_action( 'save_post', array( $this, 'save_hide_widget_metabox' ), 10 );
		add_action( 'wp_insert_post', array( $this, 'apply_default_post_distribution' ), 10, 3 );
	}

	/**
	 * Register the hide-widget meta so the block editor can read/write it via REST.
	 *
	 * @since 2.9.0
	 */
	public function register_meta() {
		foreach ( array( 'post' ) as $post_type ) {
			register_post_meta(
				$post_type,
				'republication-tracker-tool-hide-widget',
				array(
					'show_in_rest'  => true,
					'single'        => true,
					'type'          => 'boolean',
					'auth_callback' => function( $allowed, $meta_key, $post_id, $user_id ) {
						return user_can( $user_id, 'edit_post', $post_id );
					},
				)
			);
		}
	}

	/**
	 * Expose whether the hide_republication_widget filter forces hiding,
	 * so the block editor sidebar can render a notice instead of the toggle.
	 *
	 * @since 2.9.0
	 */
	public function register_rest_fields() {
		foreach ( array( 'post' ) as $post_type ) {
			register_rest_field(
				$post_type,
				'republication_tracker_tool_filter_hides',
				array(
					'get_callback' => function( $post_array ) {
						$post = get_post( $post_array['id'] );
						return (bool) apply_filters( 'hide_republication_widget', false, $post );
					},
					'schema'       => array(
						'type'    => 'boolean',
						'context' => array( 'edit' ),
					),
				)
			);
			register_rest_field(
				$post_type,
				'republication_tracker_tool_share_data',
				array(
					'get_callback' => function( $post_array ) {
						$shares = get_post_meta( $post_array['id'], 'republication_tracker_tool_sharing', true );
						if ( ! is_array( $shares ) ) {
							$shares = array();
						}
						$total   = 0;
						$entries = array();
						foreach ( $shares as $url => $count ) {
							$total    += (int) $count;
							$entries[] = array(
								'url'   => (string) $url,
								'count' => (int) $count,
							);
						}
						return array(
							'total'   => $total,
							'entries' => $entries,
						);
					},
					'schema'       => array(
						'type'    => 'object',
						'context' => array( 'edit' ),
					),
				)
			);
		}
	}

	/**
	 * Add custom metaboxes for the Classic Editor only.
	 *
	 * In the block editor the sidebar panel registered in src/index.js handles this UI,
	 * and registering classic meta boxes here would block real-time collaboration.
	 *
	 * @since 1.0
	 */
	public function register_meta_boxes() {
		$screen = get_current_screen();
		if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
			return;
		}

		add_meta_box(
			'republication-tracker-tool',
			esc_html__( 'Republication Tracker Tool', 'republication-tracker-tool' ),
			array( $this, 'render_metabox' ),
			array( 'post' ),
			'advanced',
			'default'
		);

		add_meta_box(
			'republication-tracker-tool-hide-widget',
			esc_html__( 'Hide Republication Widget', 'republication-tracker-tool' ),
			array( $this, 'render_hide_widget_metabox' ),
			array( 'post' ),
			'side',
			'default'
		);
	}

	/**
	 * Enqueue the block editor sidebar panel.
	 *
	 * @since 2.9.0
	 */
	public function enqueue_editor_assets() {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->post_type ) {
			return;
		}

		$asset_file = REPUBLICATION_TRACKER_TOOL_PATH . 'dist/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;

		wp_enqueue_script(
			'republication-tracker-tool-editor',
			REPUBLICATION_TRACKER_TOOL_URL . 'dist/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations(
			'republication-tracker-tool-editor',
			'republication-tracker-tool'
		);
	}

	/**
	 * Save the value of the hide widget metabox checkbox
	 *
	 * @since 1.0.2
	 * @param int $post_id The post ID.
	 */
	public function save_hide_widget_metabox( $post_id ) {

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['republication-tracker-tool-hide-widget-submit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( isset( $_POST['republication-tracker-tool-hide-widget'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing

			update_post_meta( $post_id, 'republication-tracker-tool-hide-widget', true );

		} else {

			update_post_meta( $post_id, 'republication-tracker-tool-hide-widget', false );

		}
	}

	/**
	 * Render a custom metabox.
	 *
	 * @since 1.0
	 * @param WP_Post $post Post object.
	 * @param array   $args Arguments object.
	 */
	public function render_metabox( $post, $args ) {
		$shares      = get_post_meta( $post->ID, 'republication_tracker_tool_sharing', true );
		$total_count = 0;
		if ( is_array( $shares ) ) {
			foreach ( $shares as $url => $count ) {
				$total_count = $total_count + $count;
			}
		}
		echo wp_kses_post( wpautop( 'Total number of views: ' . $total_count ) );
		if ( is_array( $shares ) && ! empty( $shares ) ) {
			echo '<table class="wp-list-table widefat striped posts">';
				echo '<thead>';
					printf( '<th scope="col" id="url" class="manage-column column-primary"><span>%s</span><span class="sorting-indicator"></span></th>', esc_html__( 'Republished URL', 'republication-tracker-tool' ) );
					printf( '<th scope="col" id="views" class="manage-column ">%s</th>', esc_html__( 'Views', 'republication-tracker-tool' ) );
				echo '</thead>';
				echo '<tbody id="the-list">';
			foreach ( $shares as $url => $count ) {
				printf(
					'<tr><td class="column-primary" data-colname="URL"><a href="%1$s" target="_blank">%1$s</a></td><td class="views" data-colname="Views">%2$s</td></tr>',
					wp_kses_post( $url ),
					wp_kses_post( $count )
				);
			}
				echo '</tbody>';
				echo '<tfoot>';
					printf( '<th scope="col" id="url" class="manage-column column-primary"><span>%s</span><span class="sorting-indicator"></span></th>', esc_html__( 'Republished URL', 'republication-tracker-tool' ) );
					printf( '<th scope="col" id="views" class="manage-column">%s</th>', esc_html__( 'Views', 'republication-tracker-tool' ) );
				echo '</tfoot>';
			echo '</table>';
		} else {
			echo esc_html_e( 'There are no shares to display.', 'republication-tracker-tool' );
		}
	}

	/**
	 * Render a custom metabox to check/uncheck whether or not the sharing widget should be hidden
	 *
	 * @since 1.0.2
	 * @param obj $post Post object.
	 * @param obj $args Arguments object.
	 */
	public function render_hide_widget_metabox( $post, $args ) {

		$hide_republication_widget = get_post_meta( $post->ID, 'republication-tracker-tool-hide-widget', true );

		$checked = '';

		if ( true == $hide_republication_widget ) {

			$checked = 'checked';

		}

		$hide_republication_widget_by_filter = false;
		$hide_republication_widget_by_filter = apply_filters( 'hide_republication_widget', $hide_republication_widget_by_filter, $post );

		if ( true == $hide_republication_widget_by_filter ) {
			echo '<p>The Republication sharing widget on this post is programmatically disabled through the <code>hide_republication_widget</code> filter. <a href="https://github.com/Automattic/republication-tracker-tool/blob/trunk/docs/removing-republish-button-from-categories.md" target="_blank" rel="noopener noreferrer">Read more about this filter</a>.</p>';
		} else {

			echo '<label>';
				echo '<input type="hidden" name="republication-tracker-tool-hide-widget-submit" value="yes">';
				echo '<input type="checkbox" name="republication-tracker-tool-hide-widget" id="republication-tracker-tool-hide-widget" ' . esc_attr( $checked ) . '>';
				echo esc_html__( 'Hide the Republication sharing widget on this post?', 'republication-tracker-tool' );
			echo '</label>';

		}
	}

	/**
	 * Add custom columns to the post list table.
	 *
	 * @param array $columns The columns.
	 * @return array The columns.
	 */
	public function add_custom_columns( $columns ) {
		$columns['republication_tracker_tool'] = esc_html__( 'Total Views', 'republication-tracker-tool' );
		return $columns;
	}

	/**
	 * Add sortable columns to the post list table.
	 *
	 * @param array $columns The columns.
	 * @return array The columns.
	 */
	public function add_sortable_columns( $columns ) {
		$columns['republication_tracker_tool'] = esc_html__( 'Total Views', 'republication-tracker-tool' );
		return $columns;
	}

	/**
	 * Display the content of the custom columns in the post list table.
	 *
	 * @param string $column The column name.
	 * @param int    $post_id The post ID.
	 */
	public function custom_column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'republication_tracker_tool':
				$shares      = get_post_meta( $post_id, 'republication_tracker_tool_sharing', true );
				$total_count = 0;
				if ( $shares ) {
					foreach ( $shares as $url => $count ) {
						$total_count = $total_count + $count;
					}
				}
				printf( '%s', number_format( $total_count ) );
				break;
		}
	}

	/**
	 * Apply default post distribution settings to new posts.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an existing post being updated.
	 */
	public function apply_default_post_distribution( $post_id, $post, $update ) {
		// Only apply to new posts.
		if ( ! $update && 'post' === $post->post_type ) {
			$default_post_distribution = get_option( 'republication_tracker_tool_default_post_distribution', 'off' );

			if ( 'on' === $default_post_distribution ) {
				update_post_meta( $post_id, 'republication-tracker-tool-hide-widget', true );
			}
		}
	}
}
