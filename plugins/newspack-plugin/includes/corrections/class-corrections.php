<?php
/**
 * Newspack Corrections and Clarifications
 *
 * @package Newspack
 */

namespace Newspack;

use WP_Error;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class to handle Corrections and Clarifications.
 */
class Corrections {
	/**
	 * Post type for corrections.
	 */
	const POST_TYPE = 'newspack_correction';

	/**
	 * Meta key for correction post ID meta.
	 */
	const CORRECTION_POST_ID_META = 'newspack_correction-post-id';

	/**
	 * Meta key for post corrections priority meta.
	 */
	const CORRECTIONS_PRIORITY_META = 'newspack_corrections_priority';

	/**
	 * Meta key for post corrections type meta.
	 */
	const CORRECTIONS_TYPE_META = 'newspack_corrections_type';

	/**
	 * REST route for corrections.
	 */
	const REST_ROUTE = '/corrections';

	/**
	 * Initializes the class.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_post_type' ] );
		add_filter( 'the_content', [ __CLASS__, 'output_corrections_on_post' ], 1 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'wp_enqueue_scripts' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'wp_enqueue_scripts' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_corrections_block_patterns' ] );
		add_action( 'init', [ __CLASS__, 'register_corrections_template' ] );
		add_action( 'transition_post_status', [ __CLASS__, 'update_corrections_status' ], 10, 3 );
		add_filter( 'republication_tracker_tool_republish_content', [ __CLASS__, 'append_corrections_to_republished_content' ], 10, 2 );
	}

	/**
	 * Get supported post types.
	 *
	 * @return array Array of supported post types slugs.
	 */
	public static function get_supported_post_types() {
		/**
		 * Filter to allow other post types to support Newspack Corrections and Clarifications.
		 *
		 * @param array $supported_post_types Array of supported post types slugs.
		 */
		return apply_filters( 'newspack_corrections_supported_post_types', [ 'post' ] );
	}

	/**
	 * Check if a post type is supported.
	 *
	 * @param string $post_type The post type slug.
	 * @return bool Whether the post type is supported.
	 */
	public static function is_supported_post_type( $post_type ) {
		return in_array( $post_type, self::get_supported_post_types(), true );
	}

	/**
	 * Enqueue scripts and styles.
	 */
	public static function wp_enqueue_scripts() {
		\wp_enqueue_style(
			'newspack-corrections-single',
			Newspack::plugin_url() . '/dist/other-scripts/corrections.css',
			[],
			Newspack::asset_version( 'other-scripts/corrections' )
		);

		if ( ! is_admin() || ! filter_input( INPUT_GET, 'post', FILTER_VALIDATE_INT ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( empty( $screen ) || 'post' !== $screen->base || ! self::is_supported_post_type( $screen->post_type ) ) {
			return;
		}

		\wp_enqueue_script(
			'newspack-corrections-modal',
			Newspack::plugin_url() . '/dist/other-scripts/corrections-modal.js',
			[ 'wp-edit-post', 'wp-data', 'wp-components', 'wp-element' ],
			Newspack::asset_version( 'other-scripts/corrections-modal' ),
			true
		);

		wp_localize_script(
			'newspack-corrections-modal',
			'NewspackCorrectionsData',
			[
				'corrections'  => self::get_corrections( get_the_ID() ),
				'restPath'     => sprintf( '/%s%s', NEWSPACK_API_NAMESPACE, self::REST_ROUTE ),
				'siteTimezone' => wp_timezone_string(),
			]
		);

		\wp_enqueue_style(
			'newspack-corrections-modal',
			Newspack::plugin_url() . '/dist/other-scripts/corrections-modal.css',
			[],
			Newspack::asset_version( 'other-scripts/corrections-modal' )
		);
	}

	/**
	 * Registers the corrections post type.
	 *
	 * @return void
	 */
	public static function register_post_type() {
		$supports = [
			'author',
			'editor',
			'title',
			'revisions',
			'custom-fields',
		];
		$labels = [
			'name'                     => _x( 'Corrections', 'post type general name', 'newspack-plugin' ),
			'singular_name'            => _x( 'Correction', 'post type singular name', 'newspack-plugin' ),
			'menu_name'                => _x( 'Corrections', 'admin menu', 'newspack-plugin' ),
			'name_admin_bar'           => _x( 'Correction', 'add new on admin bar', 'newspack-plugin' ),
			'add_new'                  => _x( 'Add New', 'correction', 'newspack-plugin' ),
			'add_new_item'             => __( 'Add New Correction', 'newspack-plugin' ),
			'new_item'                 => __( 'New Correction', 'newspack-plugin' ),
			'edit_item'                => __( 'Edit Correction', 'newspack-plugin' ),
			'view_item'                => __( 'View Correction', 'newspack-plugin' ),
			'view_items'               => __( 'View Correction', 'newspack-plugin' ),
			'all_items'                => __( 'All Corrections', 'newspack-plugin' ),
			'search_items'             => __( 'Search Corrections', 'newspack-plugin' ),
			'parent_item_colon'        => __( 'Parent Correction:', 'newspack-plugin' ),
			'not_found'                => __( 'No corrections found.', 'newspack-plugin' ),
			'not_found_in_trash'       => __( 'No corrections found in Trash.', 'newspack-plugin' ),
			'archives'                 => __( 'Correction Archives', 'newspack-plugin' ),
			'attributes'               => __( 'Correction Attributes', 'newspack-plugin' ),
			'insert_into_item'         => __( 'Insert into correction', 'newspack-plugin' ),
			'uploaded_to_this_item'    => __( 'Uploaded to this correction', 'newspack-plugin' ),
			'filter_items_list'        => __( 'Filter corrections list', 'newspack-plugin' ),
			'items_list_navigation'    => __( 'Corrections list navigation', 'newspack-plugin' ),
			'items_list'               => __( 'Corrections list', 'newspack-plugin' ),
			'item_published'           => __( 'Correction published.', 'newspack-plugin' ),
			'item_published_privately' => __( 'Correction published privately.', 'newspack-plugin' ),
			'item_reverted_to_draft'   => __( 'Correction reverted to draft.', 'newspack-plugin' ),
			'item_scheduled'           => __( 'Correction scheduled.', 'newspack-plugin' ),
			'item_updated'             => __( 'Correction updated.', 'newspack-plugin' ),
			'item_link'                => __( 'Correction Link', 'newspack-plugin' ),
			'item_link_description'    => __( 'A link to a correction.', 'newspack-plugin' ),
		];
		$args = array(
			'labels'              => $labels,
			'description'         => __( 'Post type used to store corrections and clarifications.', 'newspack-plugin' ),
			'has_archive'         => true,
			'public'              => false,
			'publicly_queryable'  => true,
			'exclude_from_search' => true,
			'query_var'           => true,
			'rewrite'             => [ 'slug' => 'corrections' ],
			'show_ui'             => false,
			'show_in_nav_menus'   => false,
			'show_in_rest'        => true,
			'supports'            => $supports,
			'taxonomies'          => [],
			'menu_icon'           => 'dashicons-edit',
		);
		\register_post_type( self::POST_TYPE, $args ); // phpcs:ignore WordPress.NamingConventions.ValidPostTypeSlug.NotStringLiteral

		$rewrite_rules_updated_option_name = 'newspack_corrections_rewrite_rules_updated';
		if ( false === get_option( $rewrite_rules_updated_option_name ) ) {
			flush_rewrite_rules(); //phpcs:ignore
			update_option( $rewrite_rules_updated_option_name, true );
		}
	}

	/**
	 * Register REST route for corrections.
	 */
	public static function register_rest_routes() {
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			self::REST_ROUTE . '/(?P<id>\d+)',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'rest_save_corrections' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);
	}

	/**
	 * REST endpoint to save corrections.
	 *
	 * @param WP_REST_Request $request The REST request.
	 *
	 * @return WP_REST_Response The REST response.
	 */
	public static function rest_save_corrections( WP_REST_Request $request ) {
		$post_id     = $request->get_param( 'post_id' );
		$corrections = $request->get_param( 'corrections' );

		if ( ! get_post( $post_id ) ) {
			return rest_ensure_response( new WP_Error( 'invalid_post_id', 'Invalid post ID.', [ 'status' => 400 ] ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return rest_ensure_response( new WP_Error( 'unauthorized', 'You do not have permission to edit this post.', [ 'status' => 403 ] ) );
		}

		$existing_corrections = self::get_corrections( $post_id );
		$existing_ids         = wp_list_pluck( $existing_corrections, 'ID' );

		// Track processed corrections to handle deletions.
		$processed_ids = [];

		foreach ( $corrections as $correction ) {
			$correction_id = $correction['id'];

			if ( empty( $correction['content'] ) ) {
				continue;
			}

			// ID will be null if it's a new correction.
			if ( ! empty( $correction_id ) ) {
				// Verify the correction belongs to this post.
				if ( ! in_array( $correction_id, $existing_ids, true ) ) {
					return rest_ensure_response( new WP_Error( 'invalid_correction', 'The correction does not belong to this post.', [ 'status' => 400 ] ) );
				}
				// Update existing correction.
				self::update_correction( $post_id, $correction_id, $correction );
				$processed_ids[] = $correction_id;
			} else {
				// Create new correction.
				$new_correction_id = self::add_correction( $post_id, $correction );
				if ( ! is_wp_error( $new_correction_id ) ) {
					$processed_ids[] = $new_correction_id;
				}
			}
		}

		// Delete corrections that are no longer present.
		$to_delete = array_diff( $existing_ids, $processed_ids );
		self::delete_corrections( $post_id, $to_delete );

		return rest_ensure_response(
			[
				'success'           => true,
				'corrections_saved' => $processed_ids,
				'message'           => __( 'Corrections saved successfully.', 'newspack-plugin' ),
			]
		);
	}

	/**
	 * Save corrections for post.
	 *
	 * @param int   $post_id    The post ID.
	 * @param array $correction The corrections.
	 *
	 * @return int|WP_Error The correction ID.
	 */
	public static function add_correction( $post_id, $correction ) {
		$id = wp_insert_post(
			[
				'post_title'   => sprintf( 'Correction for %s', get_the_title( $post_id ) ),
				'post_content' => sanitize_textarea_field( $correction['content'] ),
				'post_date'    => sanitize_text_field( $correction['date'] ),
				'post_type'    => self::POST_TYPE,
				'post_status'  => get_post_status( $post_id ),
				'meta_input'   => [
					self::CORRECTION_POST_ID_META   => $post_id,
					self::CORRECTIONS_TYPE_META     => $correction['type'],
					self::CORRECTIONS_PRIORITY_META => $correction['priority'],
				],
			]
		);

		return $id;
	}

	/**
	 * Get corrections for post.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return array The corrections.
	 */
	public static function get_corrections( $post_id ) {
		$corrections = get_posts( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_posts_get_posts
			[
				'posts_per_page' => -1,
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'meta_key'       => self::CORRECTION_POST_ID_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $post_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		// Attach correction type & date to each post.
		foreach ( $corrections as $correction ) {
			$correction->correction_type     = get_post_meta( $correction->ID, self::CORRECTIONS_TYPE_META, true );
			$correction->correction_date     = get_post_datetime( $correction->ID )->format( 'Y-m-d H:i:s' );
			$correction->correction_priority = get_post_meta( $correction->ID, self::CORRECTIONS_PRIORITY_META, true );
		}

		return $corrections;
	}

	/**
	 * Update correction.
	 *
	 * @param int   $post_id       the post id.
	 * @param int   $correction_id the post id.
	 * @param array $correction    the correction.
	 */
	public static function update_correction( $post_id, $correction_id, $correction ) {
		wp_update_post(
			[
				'ID'           => $correction_id,
				'post_content' => sanitize_textarea_field( $correction['content'] ),
				'post_date'    => sanitize_text_field( $correction['date'] ),
				'post_status'  => get_post_status( $post_id ),
				'meta_input'   => [
					self::CORRECTIONS_TYPE_META     => $correction['type'],
					self::CORRECTIONS_PRIORITY_META => $correction['priority'],
				],
			]
		);
	}

	/**
	 * Update corrections status when the post status changes.
	 *
	 * @param string  $new_status The new post status.
	 * @param string  $old_status The old post status.
	 * @param WP_Post $post The post object.
	 */
	public static function update_corrections_status( $new_status, $old_status, $post ) {
		if ( $new_status === $old_status || ! self::is_supported_post_type( $post->post_type ) ) {
			return;
		}

		$corrections = self::get_corrections( $post->ID );

		if ( empty( $corrections ) ) {
			return;
		}

		foreach ( $corrections as $correction ) {
			// If scheduling the post, set correction status to private to prevent core from immediately publishing it.
			// This ensures the status update isn't skipped when the correction date is in the past, while preserving the original correction date.
			wp_update_post(
				[
					'ID'          => $correction->ID,
					'post_status' => 'future' === $new_status ? 'private' : $new_status,
				]
			);
		}
	}

	/**
	 * Delete corrections for post.
	 *
	 * @param int   $post_id        the post id.
	 * @param array $correction_ids correction ids.
	 */
	public static function delete_corrections( $post_id, $correction_ids ) {
		foreach ( $correction_ids as $id ) {
			wp_delete_post( $id, true );
		}
	}

	/**
	 * Gets the Correction type label for a given post. Defaults to the current global post if none is provided.
	 *
	 * @param int $post_id The correction id.
	 * @return string The correction type label.
	 */
	public static function get_correction_type( $post_id = null ) {
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}
		return self::get_correction_type_label( get_post_meta( $post_id, self::CORRECTIONS_TYPE_META, true ) );
	}

	/**
	 * Gets the correction type label.
	 *
	 * @param string $type the correction type.
	 * @return string the correction type label.
	 */
	private static function get_correction_type_label( $type ) {
		if ( 'clarification' === $type ) {
			return __( 'Clarification', 'newspack-plugin' );
		}
		return __( 'Correction', 'newspack-plugin' );
	}

	/**
	 * Outputs corrections on the post content.
	 *
	 * @param string $content the post content.
	 *
	 * @return string the post content with corrections.
	 */
	public static function output_corrections_on_post( $content ) {
		if ( is_admin() || ! is_single() || wp_is_block_theme() ) {
			return $content;
		}

		$corrections = self::get_corrections( get_the_ID() );
		if ( empty( $corrections ) ) {
			return $content;
		}

		// Separate corrections by priority.
		$high_priority_corrections    = [];
		$low_prioirty_corrections = [];

		foreach ( $corrections as $correction ) {
			if ( 'high' === $correction->correction_priority ) {
				$high_priority_corrections[] = $correction;
			} else {
				$low_prioirty_corrections[] = $correction;
			}
		}

		$top_corrections_markup    = ! empty( $high_priority_corrections ) ? self::get_corrections_markup( $high_priority_corrections, 'high' ) : '';
		$bottom_corrections_markup = ! empty( $low_prioirty_corrections ) ? self::get_corrections_markup( $low_prioirty_corrections, 'low' ) : '';

		return $top_corrections_markup . $content . $bottom_corrections_markup;
	}

	/**
	 * Generates the corrections markup from an array of correction posts.
	 *
	 * @param array  $corrections Array of correction post objects.
	 * @param string $corrections_priority The priority of the corrections.
	 *
	 * @return string Generated markup (or an empty string if no corrections).
	 */
	private static function get_corrections_markup( $corrections, $corrections_priority = 'low' ) {
		// If no corrections, return an empty string.
		if ( empty( $corrections ) ) {
			return '';
		}

		$corrections_archive_url = get_post_type_archive_link( self::POST_TYPE );

		ob_start();
		?>
		<!-- wp:newspack/correction-box {"className":"correction-module","backgroundColor":"light-gray"} -->
		<div class="wp-block-group newspack-corrections-module corrections-<?php echo esc_attr( $corrections_priority ); ?>-module">
			<?php foreach ( $corrections as $correction ) : ?>
				<?php
				$correction_content = $correction->post_content;
				$correction_date    = \get_the_date( get_option( 'date_format' ), $correction->ID );
				$correction_time    = \get_the_time( get_option( 'time_format' ), $correction->ID );
				$correction_heading = sprintf(
					'%s, %s%s:',
					self::get_correction_type( $correction->ID ),
					$correction_date,
					$correction_time ? ' ' . $correction_time : ''
				);
				?>
				<p class="correction">
					<a class="correction-title" href="<?php echo esc_url( $corrections_archive_url ); ?>"><?php echo esc_html( $correction_heading ); ?></a>
					<span class="correction-content"><?php echo esc_html( $correction_content ); ?></span>
				</p>
			<?php endforeach; ?>
		</div>
		<!-- /wp:newspack/correction-box -->
		<?php
		return ob_get_clean();
	}

	/**
	 * Appends corrections to republished content via the Republication Tracker Tool.
	 *
	 * @param string  $content The republished content.
	 * @param WP_Post $post    The post being republished.
	 *
	 * @return string The content with corrections appended.
	 */
	public static function append_corrections_to_republished_content( $content, $post ) {
		$corrections = self::get_corrections( $post->ID );
		if ( empty( $corrections ) ) {
			return $content;
		}

		// Separate corrections by priority.
		$high_priority_corrections = [];
		$low_priority_corrections  = [];

		foreach ( $corrections as $correction ) {
			if ( 'high' === $correction->correction_priority ) {
				$high_priority_corrections[] = $correction;
			} else {
				$low_priority_corrections[] = $correction;
			}
		}

		$top_markup    = ! empty( $high_priority_corrections ) ? self::get_republish_corrections_markup( $high_priority_corrections, 'high' ) : '';
		$bottom_markup = ! empty( $low_priority_corrections ) ? self::get_republish_corrections_markup( $low_priority_corrections, 'low' ) : '';

		return $top_markup . $content . $bottom_markup;
	}

	/**
	 * Generates simplified corrections markup for republished content.
	 *
	 * Uses only basic HTML elements (<p>, <strong>) that render
	 * distinctly without CSS, since republished content is stripped of styles.
	 *
	 * @param array $corrections Array of correction post objects.
	 *
	 * @return string The simplified corrections markup.
	 */
	private static function get_republish_corrections_markup( $corrections ) {
		if ( empty( $corrections ) ) {
			return '';
		}

		$markup = '';

		foreach ( $corrections as $correction ) {
			$correction_date = \get_the_date( get_option( 'date_format' ), $correction->ID );
			$correction_time = \get_the_time( get_option( 'time_format' ), $correction->ID );
			$heading         = sprintf(
				'%s, %s%s:',
				self::get_correction_type( $correction->ID ),
				$correction_date,
				$correction_time ? ' ' . $correction_time : ''
			);

			$markup .= '<p><strong>' . esc_html( $heading ) . '</strong> ' . esc_html( $correction->post_content ) . '</p>';
		}

		return $markup;
	}

	/**
	 * Registers the block template.
	 */
	public static function register_corrections_block_patterns() {
		if ( ! class_exists( 'WP_Block_Patterns_Registry' ) || ! wp_is_block_theme() ) {
			return;
		}

		$category = 'newspack-corrections';

		\register_block_pattern_category(
			$category,
			[
				'label' => __( 'Newspack Corrections', 'newspack-plugin' ),
			]
		);

		\register_block_pattern(
			'newspack/corrections-loop',
			[
				'categories'  => [ $category ],
				'title'       => __( 'Corrections Loop', 'newspack-plugin' ),
				'description' => __( 'A block pattern for displaying an archive of corrections.', 'newspack-plugin' ),
				'content'     => self::get_corrections_pattern_content( 'corrections-loop' ),
				'keywords'    => [ __( 'corrections', 'newspack-plugin' ), __( 'archive', 'newspack-plugin' ), __( 'loop', 'newspack-plugin' ) ],
			]
		);
	}

	/**
	 * Retrieves the block template content.
	 *
	 * @param string $pattern The pattern name.
	 *
	 * @return string The template content.
	 */
	private static function get_corrections_pattern_content( $pattern ) {
		ob_start();
		include_once __DIR__ . "/../templates/block-patterns/corrections/{$pattern}.php";
		$content = ob_get_clean();
		return $content;
	}

	/**
	 * Registers the block template.
	 */
	public static function register_corrections_template() {
		if ( ! class_exists( 'WP_Block_Templates_Registry' ) || ! wp_is_block_theme() ) {
			return;
		}

		\register_block_template(
			'newspack//archive-' . self::POST_TYPE,
			[
				'title'       => __( 'Corrections Archive', 'newspack-plugin' ),
				'description' => __( 'A block template for displaying an archive of corrections.', 'newspack-plugin' ),
				'content'     => self::get_corrections_template_content( 'corrections-archive' ),
			]
		);
	}

	/**
	 * Retrieves the block template content.
	 *
	 * @param string $template The template name.
	 *
	 * @return string The template content.
	 */
	private static function get_corrections_template_content( $template ) {
		ob_start();
		include_once __DIR__ . "/../templates/corrections/{$template}.php";
		$content = ob_get_clean();
		return $content;
	}
}
Corrections::init();
