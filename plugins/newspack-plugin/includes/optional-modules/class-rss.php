<?php
/**
 * RSS.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * RSS feed enhancements.
 */
class RSS {
	const FEED_CPT = 'partner_rss_feed';
	const FEED_QUERY_ARG = 'partner-feed';
	const FEED_SETTINGS_META = 'partner_feed_settings';

	/**
	 * Initialise.
	 */
	public static function init() {
		if ( ! Optional_Modules::is_optional_module_active( 'rss' ) ) {
			return;
		}

		// Backend.
		add_action( 'init', [ __CLASS__, 'register_feed_cpt' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_autocomplete_scripts' ] );
		add_action( 'save_post_' . self::FEED_CPT, [ __CLASS__, 'save_settings' ] );
		add_filter( 'manage_' . self::FEED_CPT . '_posts_columns', [ __CLASS__, 'columns_head' ] );
		add_action( 'manage_' . self::FEED_CPT . '_posts_custom_column', [ __CLASS__, 'column_content' ], 10, 2 );
		add_action( 'wp_ajax_newspack_rss_search_terms', [ __CLASS__, 'ajax_search_terms' ] );

		// Frontend.
		add_filter( 'option_rss_use_excerpt', [ __CLASS__, 'filter_use_rss_excerpt' ] );
		add_action( 'pre_get_posts', [ __CLASS__, 'modify_feed_query' ] );
		add_action( 'rss2_item', [ __CLASS__, 'add_extra_tags' ] );
		add_action( 'atom_entry', [ __CLASS__, 'add_extra_tags' ] );
		add_filter( 'the_excerpt_rss', [ __CLASS__, 'maybe_remove_content_featured_image' ], 1 );
		add_filter( 'the_content_feed', [ __CLASS__, 'maybe_remove_content_featured_image' ], 1 );
		add_filter( 'the_content_feed', [ __CLASS__, 'maybe_remove_non_distributable_images' ], 1 );
		add_filter( 'the_content_feed', [ __CLASS__, 'maybe_add_tracking_snippets' ], 1 );
		add_filter( 'wpseo_include_rss_footer', [ __CLASS__, 'maybe_suppress_yoast' ] );
		add_action( 'rss2_ns', [ __CLASS__, 'maybe_inject_yahoo_namespace' ] );
		add_filter( 'the_title_rss', [ __CLASS__, 'maybe_wrap_titles_in_cdata' ] );

		add_filter( 'newspack_capabilities_map', [ __CLASS__, 'newspack_capabilities_map' ] );
	}

	/**
	 * Get URL for a feed.
	 *
	 * @param WP_Post $feed_post RSS feed post object.
	 * @param string  $feed_type Feed type (rss or atom).
	 *
	 * @return string Feed URL.
	 */
	public static function get_feed_url( $feed_post, $feed_type = 'rss' ) {
		$feed_slug     = is_numeric( $feed_post ) ? get_post_field( 'post_name', $feed_post ) : $feed_post->post_name;
		$base_feed_url = 'atom' === $feed_type ? get_bloginfo( 'atom_url' ) : get_bloginfo( 'rss2_url' );
		return add_query_arg( self::FEED_QUERY_ARG, $feed_slug, $base_feed_url );
	}

	/**
	 * Get feed settings array.
	 *
	 * @param WP_Post|int $feed_post A feed WP_Post object, post ID. (optional on frontend).
	 * @return array|false Feed settings or false if no feed found.
	 */
	public static function get_feed_settings( $feed_post = null ) {
		$default_settings = [
			'category_include'          => [],
			'category_exclude'          => [],
			'category_inner_relation'   => 'IN',
			'tag_include'               => [],
			'tag_inner_relation'        => 'IN',
			'taxonomy_filters_relation' => 'AND',
			'use_image_tags'            => false,
			'use_media_tags'            => false,
			'use_updated_tags'          => false,
			'use_tags_tags'             => false,
			'full_content'              => true,
			'num_items_in_feed'         => 10,
			'offset'                    => 0,
			'timeframe'                 => false,
			'content_featured_image'    => false,
			'suppress_yoast'            => false,
			'yahoo_namespace'           => false,
			'update_frequency'          => false,
			'use_post_id_as_guid'       => false,
			'cdata_titles'              => false,
			'republication_tracker'     => false,
			'only_republishable'        => false,
			'only_distributable_images' => false,
			'custom_tracking_snippet'   => '',
		];

		/**
		 * Filter the default RSS feed settings.
		 *
		 * @param array $default_settings The default settings for RSS feeds.
		 * @return array Modified default settings.
		 */
		$default_settings = apply_filters( 'newspack_rss_feed_settings', $default_settings );

		$custom_taxonomies = self::get_custom_taxonomies_for_posts();

		foreach ( $custom_taxonomies as $taxonomy ) {
			$default_settings[ $taxonomy . '_include' ] = [];
			$default_settings[ $taxonomy . '_exclude' ] = [];
			$default_settings[ $taxonomy . '_inner_relation' ] = 'IN';
		}

		if ( ! $feed_post ) {
			$query_feed = filter_input( INPUT_GET, self::FEED_QUERY_ARG, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
			if ( ! $query_feed ) {
				return false;
			}

			$feed_post = get_page_by_path( sanitize_text_field( $query_feed ), OBJECT, self::FEED_CPT ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_page_by_path_get_page_by_path
			if ( ! $feed_post ) {
				return false;
			}
		}

		$feed_post_id   = is_numeric( $feed_post ) ? $feed_post : $feed_post->ID;
		$saved_settings = get_post_meta( $feed_post_id, self::FEED_SETTINGS_META, true );

		if ( ! is_array( $saved_settings ) ) {
			return $default_settings;
		}

		/**
		 * Filter the saved RSS feed settings.
		 *
		 * @param array $saved_settings The saved settings for this feed.
		 * @param int   $feed_post_id   The post ID of the feed.
		 * @return array Modified saved settings.
		 */
		$saved_settings = apply_filters( 'newspack_rss_saved_settings', $saved_settings, $feed_post_id );

		return shortcode_atts( $default_settings, $saved_settings );
	}

	/**
	 * Register the partner feed CPT.
	 */
	public static function register_feed_cpt() {
		$labels = array(
			'name'               => _x( 'RSS Feeds', 'post type general name', 'newspack-plugin' ),
			'singular_name'      => _x( 'RSS Feed', 'post type singular name', 'newspack-plugin' ),
			'menu_name'          => _x( 'RSS Feeds', 'admin menu', 'newspack-plugin' ),
			'name_admin_bar'     => _x( 'RSS Feed', 'add new on admin bar', 'newspack-plugin' ),
			'add_new'            => _x( 'Add New', 'rss feed', 'newspack-plugin' ),
			'add_new_item'       => __( 'Add New RSS Feed', 'newspack-plugin' ),
			'new_item'           => __( 'New RSS Feed', 'newspack-plugin' ),
			'edit_item'          => __( 'Edit RSS Feed', 'newspack-plugin' ),
			'view_item'          => __( 'View RSS Feed', 'newspack-plugin' ),
			'all_items'          => __( 'All RSS Feeds', 'newspack-plugin' ),
			'search_items'       => __( 'Search RSS Feeds', 'newspack-plugin' ),
			'parent_item_colon'  => __( 'Parent RSS Feeds:', 'newspack-plugin' ),
			'not_found'          => __( 'No RSS feeds found.', 'newspack-plugin' ),
			'not_found_in_trash' => __( 'No RSS feeds found in Trash.', 'newspack-plugin' ),
			'item_published'     => __( 'RSS Feed published', 'newspack-plugin' ),
			'item_updated'       => __( 'RSS Feed updated', 'newspack-plugin' ),
		);

		$args = array(
			'labels'               => $labels,
			'description'          => __( 'RSS feeds customized for third-party services.', 'newspack-plugin' ),
			'public'               => true,
			'exclude_from_search'  => true,
			'publicly_queryable'   => false,
			'show_ui'              => true,
			'show_in_menu'         => true,
			'menu_icon'            => 'dashicons-rss',
			'query_var'            => true,
			'capability_type'      => self::FEED_CPT,
			'map_meta_cap'         => true,
			'has_archive'          => false,
			'hierarchical'         => false,
			'menu_position'        => null,
			'supports'             => array( 'title' ),
			'rewrite'              => false,
			'show_in_admin_bar'    => false,
			'register_meta_box_cb' => [ __CLASS__, 'add_metaboxes' ],
		);

		register_post_type( self::FEED_CPT, $args );
	}

	/**
	 * Add a feed URL column to the Edit RSS Feeds screen.
	 *
	 * @param array $columns Screen columns.
	 * @return array Modified $columns.
	 */
	public static function columns_head( $columns ) {
		$columns['feed_url'] = __( 'Feed URLs', 'newspack-plugin' );
		return $columns;
	}

	/**
	 * Populate feed URL column on Edit RSS Feeds screen.
	 *
	 * @param string $column_name The column identifier.
	 * @param int    $post_id The current element's post ID.
	 */
	public static function column_content( $column_name, $post_id ) {
		if ( 'feed_url' === $column_name ) {
			$rss_feed_url  = self::get_feed_url( $post_id );
			$atom_feed_url = self::get_feed_url( $post_id, 'atom' );
			?>
			<span>
				<strong><?php esc_html_e( 'RSS:', 'newspack-plugin' ); ?></strong>
				<a href='<?php echo esc_url( $rss_feed_url ); ?>' target='_blank'>
					<?php echo esc_url( $rss_feed_url ); ?>
				</a>
			</span>
			<br />
			<span>
				<strong><?php esc_html_e( 'Atom:', 'newspack-plugin' ); ?></strong>
				<a href='<?php echo esc_url( $atom_feed_url ); ?>' target='_blank'>
					<?php echo esc_url( $atom_feed_url ); ?>
				</a>
			</span>
			<?php
		}
	}

	/**
	 * Add metaboxes to CPT screen.
	 *
	 * @param WP_Post $feed_post RSS feed post object.
	 */
	public static function add_metaboxes( $feed_post ) {
		add_meta_box(
			'partner_rss_feed_url',
			__( 'Feed URLs', 'newspack-plugin' ),
			[ __CLASS__, 'render_url_metabox' ],
			self::FEED_CPT
		);
		add_meta_box(
			'partner_rss_feed_content_settings',
			__( 'Content Settings', 'newspack-plugin' ),
			[ __CLASS__, 'render_content_settings_metabox' ],
			self::FEED_CPT
		);
		add_meta_box(
			'partner_rss_feed_technical_settings',
			__( 'Technical Settings', 'newspack-plugin' ),
			[ __CLASS__, 'render_technical_settings_metabox' ],
			self::FEED_CPT
		);
	}

	/**
	 * Enqueue autocomplete scripts.
	 *
	 * @param string $hook The current screen.
	 */
	public static function enqueue_autocomplete_scripts( $hook ) {
		if ( in_array( $hook, [ 'post.php', 'post-new.php' ] ) ) {
			$screen = get_current_screen();

			if ( is_object( $screen ) && self::FEED_CPT == $screen->post_type ) {
				wp_enqueue_style(
					'newspack-select2',
					'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.12/css/select2.min.css',
					[],
					'4.0.12'
				);

				wp_enqueue_script(
					'newspack-select2',
					'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.12/js/select2.full.min.js',
					[ 'jquery' ],
					'4.0.12',
					false
				);
			}
		}
	}

	/**
	 * Render URL metabox for CPT.
	 *
	 * @param WP_Post $feed_post RSS feed post object.
	 */
	public static function render_url_metabox( $feed_post ) {
		if ( 'publish' !== $feed_post->post_status ) {
			?>
			<h3>
				<?php esc_html_e( 'A URL will be generated for this feed once published', 'newspack-plugin' ); ?>
			</h3>
			<?php
			return;
		}

		$rss_feed_url  = self::get_feed_url( $feed_post );
		$atom_feed_url = self::get_feed_url( $feed_post, 'atom' );
		?>
		<table>
			<tr>
				<td><h3><?php esc_html_e( 'RSS -', 'newspack-plugin' ); ?></h3></td>
				<td>
					<h3>
						<a href="<?php echo esc_url( $rss_feed_url ); ?>" target="_blank">
							<?php echo esc_url( $rss_feed_url ); ?>
						</a>
					</h3>
				</td>
			</tr>
			<tr>
				<td><h3><?php esc_html_e( 'Atom -', 'newspack-plugin' ); ?></h3></td>
				<td>
					<h3>
						<a href="<?php echo esc_url( $atom_feed_url ); ?>" target="_blank">
							<?php echo esc_url( $atom_feed_url ); ?>
						</a>
					</h3>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render content settings metabox for CPT.
	 *
	 * @param WP_Post $feed_post RSS feed post object.
	 */
	public static function render_content_settings_metabox( $feed_post ) {
		$settings          = self::get_feed_settings( $feed_post );
		$custom_taxonomies = self::get_custom_taxonomies_for_posts();
		wp_nonce_field( 'newspack_rss_enhancements_nonce', 'newspack_rss_enhancements_nonce' );
		?>
		<style>
			table {
				text-align: left;
			}
			table th, table td {
				padding-bottom: 10px;
			}
		</style>
		<table>
			<tr>
				<th><?php esc_html_e( 'Number of posts to display in feed:', 'newspack-plugin' ); ?></th>
				<td>
					<input name="num_items_in_feed" type="number" min="1" value="<?php echo esc_attr( $settings['num_items_in_feed'] ); ?>" />
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Offset posts by:', 'newspack-plugin' ); ?></th>
				<td>
					<input name="offset" type="number" min="0" value="<?php echo esc_attr( $settings['offset'] ); ?>" />
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Limit timeframe to last # of hours:', 'newspack-plugin' ); ?></th>
				<td>
					<input name="timeframe" type="number" value="<?php echo esc_attr( $settings['timeframe'] ); ?>" />
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Use post full content or excerpt:', 'newspack-plugin' ); ?></th>
				<td>
					<select name="full_content">
						<option value="1" <?php selected( $settings['full_content'] ); ?> ><?php esc_html_e( 'Full content', 'newspack-plugin' ); ?></option>
						<option value="0" <?php selected( ! $settings['full_content'] ); ?> ><?php esc_html_e( 'Excerpt', 'newspack-plugin' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Update frequency:', 'newspack-plugin' ); ?></th>
				<td>
					<select name="update_frequency">
						<option value="hourly-1" <?php selected( $settings['update_frequency'], 'hourly-1' ); ?> ><?php esc_html_e( 'Every hour', 'newspack-plugin' ); ?></option>
						<option value="hourly-60" <?php selected( $settings['update_frequency'], 'hourly-60' ); ?> ><?php esc_html_e( 'Every 1 minute', 'newspack-plugin' ); ?></option>
						<option value="hourly-12" <?php selected( $settings['update_frequency'], 'hourly-12' ); ?> ><?php esc_html_e( 'Every 5 minutes', 'newspack-plugin' ); ?></option>
						<option value="daily-8" <?php selected( $settings['update_frequency'], 'daily-8' ); ?> ><?php esc_html_e( 'Every 3 hours', 'newspack-plugin' ); ?></option>
						<option value="daily-1" <?php selected( $settings['update_frequency'], 'daily' ); ?> ><?php esc_html_e( 'Daily', 'newspack-plugin' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Use post ID as the guid instead of post URL:', 'newspack-plugin' ); ?></th>
				<td>
					<input type="hidden" name="use_post_id_as_guid" value="0" />
					<input type="checkbox" name="use_post_id_as_guid" value="1" <?php checked( $settings['use_post_id_as_guid'] ); ?> />
				</td>
			</tr>
			<tr>
				<th colspan="2">
					<h3><?php esc_html_e( 'Taxonomy Filters:', 'newspack-plugin' ); ?></h3>
				</th>
			</tr>
			<tr>
				<th>
					<?php esc_html_e( 'Include only posts that have', 'newspack-plugin' ); ?>
					<select name="category_inner_relation">
						<option value="IN" <?php selected( $settings['category_inner_relation'], 'IN' ); ?> ><?php esc_html_e( 'any of these categories', 'newspack-plugin' ); ?></option>
						<option value="AND" <?php selected( $settings['category_inner_relation'], 'AND' ); ?> ><?php esc_html_e( 'all of these categories', 'newspack-plugin' ); ?></option>
					</select>
				</th>
				<td>
					<select id="category_include" name="category_include[]" multiple="multiple" style="width:300px" data-taxonomy="category" class="newspack-ajax-taxonomy-select">
						<?php
						if ( ! empty( $settings['category_include'] ) ) {
							$selected_categories = get_terms(
								[
									'taxonomy'   => 'category',
									'include'    => $settings['category_include'],
									'hide_empty' => false,
									'fields'     => 'id=>name',
								]
							);
							foreach ( $selected_categories as $category_id => $category_name ) :
								?>
								<option value="<?php echo esc_attr( $category_id ); ?>" selected="selected"><?php echo esc_html( $category_name ); ?></option>
								<?php
							endforeach;
						}
						?>
					</select>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Exclude posts from these categories:', 'newspack-plugin' ); ?></th>
				<td>
					<select id="category_exclude" name="category_exclude[]" multiple="multiple" style="width:300px" data-taxonomy="category" class="newspack-ajax-taxonomy-select">
						<?php
						if ( ! empty( $settings['category_exclude'] ) ) {
							$selected_categories = get_terms(
								[
									'taxonomy'   => 'category',
									'include'    => $settings['category_exclude'],
									'hide_empty' => false,
									'fields'     => 'id=>name',
								]
							);
							foreach ( $selected_categories as $category_id => $category_name ) :
								?>
								<option value="<?php echo esc_attr( $category_id ); ?>" selected="selected"><?php echo esc_html( $category_name ); ?></option>
								<?php
							endforeach;
						}
						?>
					</select>
				</td>
			</tr>
			<tr>
				<th>
					<?php esc_html_e( 'Include only posts that have', 'newspack-plugin' ); ?>
					<select name="tag_inner_relation">
						<option value="IN" <?php selected( $settings['tag_inner_relation'], 'IN' ); ?> ><?php esc_html_e( 'any of these tags', 'newspack-plugin' ); ?></option>
						<option value="AND" <?php selected( $settings['tag_inner_relation'], 'AND' ); ?> ><?php esc_html_e( 'all of these tags', 'newspack-plugin' ); ?></option>
					</select>
				</th>
				<td>
					<select id="tag_include" name="tag_include[]" multiple="multiple" style="width:300px" data-taxonomy="post_tag" class="newspack-ajax-taxonomy-select">
						<?php
						if ( ! empty( $settings['tag_include'] ) ) {
							$selected_tags = get_terms(
								[
									'taxonomy'   => 'post_tag',
									'include'    => $settings['tag_include'],
									'hide_empty' => false,
									'fields'     => 'id=>name',
								]
							);
							foreach ( $selected_tags as $tag_id => $tag_name ) :
								?>
								<option value="<?php echo esc_attr( $tag_id ); ?>" selected="selected"><?php echo esc_html( $tag_name ); ?></option>
								<?php
							endforeach;
						}
						?>
					</select>
				</td>
			</tr>
			<?php
			foreach ( $custom_taxonomies as $taxonomy ) {
				$taxonomy_object        = get_taxonomy( $taxonomy );
				$taxonomy_include_key   = $taxonomy . '_include';
				$selected_include_terms = isset( $settings[ $taxonomy_include_key ] ) ? (array) $settings[ $taxonomy_include_key ] : [];

				$include_terms = ! empty( $selected_include_terms ) ? get_terms(
					[
						'taxonomy'   => $taxonomy,
						'include'    => $selected_include_terms,
						'hide_empty' => false,
						'fields'     => 'id=>name',
					]
				) : [];
				?>
				<tr>

					<th>
						<?php echo esc_html( __( 'Include only posts that have', 'newspack-plugin' ) ); ?>
						<select name="<?php echo esc_attr( $taxonomy ); ?>_inner_relation">
							<?php /* translators: %s is a taxonomy label. */ ?>
							<option value="IN" <?php selected( $settings[ $taxonomy . '_inner_relation' ], 'IN' ); ?> ><?php printf( esc_html__( 'any of these %s', 'newspack-plugin' ), esc_html( $taxonomy_object->label ) ); ?></option>
							<?php /* translators: %s is a taxonomy label. */ ?>
							<option value="AND" <?php selected( $settings[ $taxonomy . '_inner_relation' ], 'AND' ); ?> ><?php printf( esc_html__( 'all of these %s', 'newspack-plugin' ), esc_html( $taxonomy_object->label ) ); ?></option>
						</select>
					</th>
					<td>
						<select name="<?php echo esc_attr( $taxonomy_include_key ); ?>[]" multiple="multiple" style="width:300px" class="newspack-ajax-taxonomy-select" data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>">
							<?php
							foreach ( $include_terms as $term_id => $term_name ) :
								?>
								<option value="<?php echo esc_attr( $term_id ); ?>" selected="selected">
									<?php echo esc_html( $term_name ); ?>
								</option>
								<?php
							endforeach;
							?>
						</select>
					</td>
				</tr>
				<?php
			}
			?>
			<tr>
				<th>
					<?php esc_html_e( 'Taxonomies relationship:', 'newspack-plugin' ); ?>
					<p class="description"><?php echo esc_html_x( 'When more than one taxonomy is selected, should posts match conditions in ALL taxonomies (AND) or at least one of them (OR)?', 'newspack-plugin' ); ?></p>
				</th>
				<td>
					<select name="taxonomy_filters_relation">
						<option value="AND" <?php selected( $settings['taxonomy_filters_relation'], 'AND' ); ?> ><?php esc_html_e( 'AND - Posts must match all taxonomies filters', 'newspack-plugin' ); ?></option>
						<option value="OR" <?php selected( $settings['taxonomy_filters_relation'], 'OR' ); ?> ><?php esc_html_e( 'OR - Posts can match at least one taxonomy filter', 'newspack-plugin' ); ?></option>
					</select>
				</td>
			</tr>

			<?php
				/**
				 * Action for plugins to add their own content settings to the RSS feed settings UI.
				 *
				 * @param array $settings Current feed settings.
				 * @param WP_Post $feed_post The feed post object.
				 */
				do_action( 'newspack_rss_render_content_settings', $settings, $feed_post );
			?>

			<?php
			// Only show this new option if the Republication Tracker Tool plugin is active.
			if ( self::is_republication_tracker_plugin_active() ) :
				?>
				<tr>
					<th colspan="2">
						<h3><?php esc_html_e( 'Republication Tracker Tool Options:', 'newspack-plugin' ); ?></h3>
					</th>
				</tr>
				<tr>
					<th>
						<?php esc_html_e( 'Only include republishable posts', 'newspack-plugin' ); ?>
						<p class="description"><?php echo esc_html_x( 'When toggled on, posts which have republication disabled will be excluded from the feed.', 'help text for only republishable setting', 'newspack-plugin' ); ?></p>
					</th>
					<td>
						<input type="hidden" name="only_republishable" value="0" />
						<input type="checkbox" name="only_republishable" value="1" <?php checked( $settings['only_republishable'] ); ?> />
					</td>
				</tr>
				<tr>
					<th>
						<?php esc_html_e( 'Include only distributable images', 'newspack-plugin' ); ?>
						<p class="description">
							<?php echo esc_html_x( 'When toggled on, images not marked as distributable will be excluded from feed content.', 'help text for remove non-distributable images setting', 'newspack-plugin' ); ?>
							<br/>
							<?php esc_html_e( 'Note: this will respect the same settings for distributable images RTT uses for other distributiion purposes', 'newspack-plugin' ); ?>
						</p>
					</th>
					<td>
						<input type="hidden" name="only_distributable_images" value="0" />
						<input type="checkbox" name="only_distributable_images" value="1" <?php checked( $settings['only_distributable_images'] ); ?> />
					</td>
				</tr>
			<?php endif; ?>
		</table>

		<script>
			jQuery( document ).ready( function() {
				jQuery( '.newspack-ajax-taxonomy-select' ).select2( {
					ajax: {
						url: ajaxurl,
						dataType: 'json',
						type: 'POST',
						delay: 2000,
						data: function( params ) {
							return {
								action: 'newspack_rss_search_terms',
								taxonomy: jQuery( this ).data( 'taxonomy' ),
								search: params.term,
								nonce: '<?php echo esc_attr( wp_create_nonce( 'newspack_rss_search_terms' ) ); ?>'
							};
						},
						processResults: function( data ) {
							return {
								results: data
							};
						},
						cache: true
					},
					minimumInputLength: 1,
					placeholder: '<?php esc_html_e( 'Search and select terms...', 'newspack-plugin' ); ?>',
					allowClear: true
				} );
			} );
		</script>
		<?php
	}

	/**
	 * Render technical settings metabox for CPT.
	 *
	 * @param WP_Post $feed_post RSS feed post object.
	 */
	public static function render_technical_settings_metabox( $feed_post ) {
		$settings = self::get_feed_settings( $feed_post );
		?>
		<p><strong>Note:</strong> These settings are for modifying a feed to make it compatible with various integrations (SmartNews, Pugpig, etc.). They should only be used if a specific integration requires a non-standard RSS feed. Consult the integration's documentation or support for information about which elements are required.</p>

		<style>
			.newspack-rss-technical-settings {
				width: 100%;
				table-layout: auto;
			}
			.newspack-rss-technical-settings th {
				vertical-align: top;
				padding-right: 20px;
				padding-bottom: 10px;
				word-wrap: break-word;
				max-width: 600px;
			}
			.newspack-rss-technical-settings td {
				vertical-align: top;
				padding-bottom: 10px;
			}
			.newspack-rss-technical-settings textarea {
				width: 100%;
				max-width: 400px;
				box-sizing: border-box;
			}
		</style>
		<table class="newspack-rss-technical-settings">
			<tr>
				<th><?php esc_html_e( 'Add post featured images in <image> tags', 'newspack-plugin' ); ?></th>
				<td>
					<input type="hidden" name="use_image_tags" value="0" />
					<input type="checkbox" name="use_image_tags" value="1" <?php checked( $settings['use_image_tags'] ); ?>/>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Add post featured images in <media:> tags', 'newspack-plugin' ); ?></th>
				<td>
					<input type="hidden" name="use_media_tags" value="0" />
					<input type="checkbox" name="use_media_tags" value="1" <?php checked( $settings['use_media_tags'] ); ?> />
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Add post updated time in <updated> tags', 'newspack-plugin' ); ?></th>
				<td>
					<input type="hidden" name="use_updated_tags" value="0" />
					<input type="checkbox" name="use_updated_tags" value="1" <?php checked( $settings['use_updated_tags'] ); ?> />
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Add categories and tags in <tags> element', 'newspack-plugin' ); ?></th>
				<td>
					<input type="hidden" name="use_tags_tags" value="0" />
					<input type="checkbox" name="use_tags_tags" value="1" <?php checked( $settings['use_tags_tags'] ); ?> />
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Add featured image at the top of feed content', 'newspack-plugin' ); ?></th>
				<td>
					<input type="hidden" name="content_featured_image" value="0" />
					<input type="checkbox" name="content_featured_image" value="1" <?php checked( $settings['content_featured_image'] ); ?> />
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Add Yahoo namespace to RSS namespace: xmlns:media="http://search.yahoo.com/mrss/"', 'newspack-plugin' ); ?></th>
				<td>
					<input type="hidden" name="yahoo_namespace" value="0" />
					<input type="checkbox" name="yahoo_namespace" value="1" <?php checked( $settings['yahoo_namespace'] ); ?> />
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Wrap the content of <title> elements in CDATA tags', 'newspack-plugin' ); ?></th>
				<td>
					<input type="hidden" name="cdata_titles" value="0" />
					<input type="checkbox" name="cdata_titles" value="1" <?php checked( $settings['cdata_titles'] ); ?> />
				</td>
			</tr>
			<tr>
				<th>
					<?php esc_html_e( 'Custom tracking snippet', 'newspack-plugin' ); ?>
					<p class="description">
						<?php echo esc_html_x( 'Tracking snippet that will be appended to the end of each post in the feed. You can use {{post-id}} and {{post-url}} as dynamic variables.', 'help text for custom tracking snippet', 'newspack-plugin' ); ?>
						<br>
						<?php echo esc_html_x( 'Allowed HTML: script, img, iframe, noscript, div, span with safe attributes only.', 'help text for allowed HTML in tracking snippet', 'newspack-plugin' ); ?>
					</p>
				</th>
				<td>
					<textarea name="custom_tracking_snippet" rows="4" cols="50"><?php echo esc_textarea( $settings['custom_tracking_snippet'] ); ?></textarea>
				</td>
			</tr>
			<?php
				/**
				 * Action for plugins to add their own technical settings to the RSS feed settings UI.
				 *
				 * @param array $settings Current feed settings.
				 * @param WP_Post $feed_post The feed post object.
				 */
				do_action( 'newspack_rss_render_technical_settings', $settings, $feed_post );
			?>
			<?php if ( defined( 'WPSEO_VERSION' ) && WPSEO_VERSION ) : ?>
				<tr>
					<th>
						<?php
						printf(
						/* translators: %s: URL to Yoast settings */
							__( 'Suppress <a href="%s">Yoast RSS content at the top and bottom of feed posts</a>' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							admin_url( 'admin.php?page=wpseo_titles#top#rss' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						);
						?>
					</th>
					<td>
						<input type="hidden" name="suppress_yoast" value="0" />
						<input type="checkbox" name="suppress_yoast" value="1" <?php checked( $settings['suppress_yoast'] ); ?> />
					</td>
				</tr>
			<?php endif; ?>
			<?php
			// Only show this new option if the Republication Tracker Tool plugin is active.
			if ( self::is_republication_tracker_plugin_active() ) :
				?>
				<tr>
					<th><?php esc_html_e( 'Add republication tracker snippet to posts', 'newspack-plugin' ); ?></th>
					<td>
						<input type="hidden" name="republication_tracker" value="0" />
						<input type="checkbox" name="republication_tracker" value="1" <?php checked( $settings['republication_tracker'] ); ?> />
					</td>
				</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	/**
	 * Save CPT settings.
	 *
	 * @param int $feed_post_id The post ID of feed.
	 */
	public static function save_settings( $feed_post_id ) {
		$nonce = filter_input( INPUT_POST, 'newspack_rss_enhancements_nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'newspack_rss_enhancements_nonce' ) ) {
			return;
		}

		if ( ! Capabilities::current_user_can( 'edit_posts', self::FEED_CPT ) ) {
			return;
		}

		$settings          = self::get_feed_settings( $feed_post_id );
		$custom_taxonomies = self::get_custom_taxonomies_for_posts();

		$use_image_tags             = filter_input( INPUT_POST, 'use_image_tags', FILTER_SANITIZE_NUMBER_INT );
		$settings['use_image_tags'] = (bool) $use_image_tags;

		$use_media_tags             = filter_input( INPUT_POST, 'use_media_tags', FILTER_SANITIZE_NUMBER_INT );
		$settings['use_media_tags'] = (bool) $use_media_tags;

		$use_updated_tags             = filter_input( INPUT_POST, 'use_updated_tags', FILTER_SANITIZE_NUMBER_INT );
		$settings['use_updated_tags'] = (bool) $use_updated_tags;

		$use_updated_tags          = filter_input( INPUT_POST, 'use_tags_tags', FILTER_SANITIZE_NUMBER_INT );
		$settings['use_tags_tags'] = (bool) $use_updated_tags;

		$full_content             = filter_input( INPUT_POST, 'full_content', FILTER_SANITIZE_NUMBER_INT );
		$settings['full_content'] = (bool) $full_content;

		$content_featured_image             = filter_input( INPUT_POST, 'content_featured_image', FILTER_SANITIZE_NUMBER_INT );
		$settings['content_featured_image'] = (bool) $content_featured_image;

		$num_items_in_feed             = filter_input( INPUT_POST, 'num_items_in_feed', FILTER_SANITIZE_NUMBER_INT );
		$settings['num_items_in_feed'] = absint( $num_items_in_feed );

		$offset             = filter_input( INPUT_POST, 'offset', FILTER_SANITIZE_NUMBER_INT );
		$settings['offset'] = absint( $offset );

		$timeframe             = filter_input( INPUT_POST, 'timeframe', FILTER_SANITIZE_NUMBER_INT );
		$settings['timeframe'] = absint( $timeframe );

		$suppress_yoast             = filter_input( INPUT_POST, 'suppress_yoast', FILTER_SANITIZE_NUMBER_INT );
		$settings['suppress_yoast'] = (bool) $suppress_yoast;

		$yahoo_namespace             = filter_input( INPUT_POST, 'yahoo_namespace', FILTER_SANITIZE_NUMBER_INT );
		$settings['yahoo_namespace'] = (bool) $yahoo_namespace;

		$update_frequency             = filter_input( INPUT_POST, 'update_frequency', FILTER_SANITIZE_SPECIAL_CHARS );
		$settings['update_frequency'] = $update_frequency;

		$use_post_id_as_guid             = filter_input( INPUT_POST, 'use_post_id_as_guid', FILTER_SANITIZE_NUMBER_INT );
		$settings['use_post_id_as_guid'] = (bool) $use_post_id_as_guid;

		$cdata_titles             = filter_input( INPUT_POST, 'cdata_titles', FILTER_SANITIZE_NUMBER_INT );
		$settings['cdata_titles'] = (bool) $cdata_titles;

		$custom_tracking_snippet             = filter_input( INPUT_POST, 'custom_tracking_snippet', FILTER_DEFAULT ); // phpcs:ignore WordPressVIPMinimum.Security.PHPFilterFunctions.RestrictedFilter
		$settings['custom_tracking_snippet'] = wp_kses(
			$custom_tracking_snippet,
			[
				'script'   => [
					'id'          => true,
					'src'         => true,
					'type'        => true,
					'async'       => true,
					'defer'       => true,
					'class'       => true,
					'crossorigin' => true,
					'data-*'      => true,
				],
				'img'      => [
					'id'          => true,
					'style'       => true,
					'src'         => true,
					'alt'         => true,
					'class'       => true,
					'width'       => true,
					'height'      => true,
					'data-*'      => true,
					'crossorigin' => true,
					'loading'     => true,
				],
				'iframe'   => [
					'id'          => true,
					'style'       => true,
					'src'         => true,
					'class'       => true,
					'width'       => true,
					'height'      => true,
					'crossorigin' => true,
					'data-*'      => true,
					'loading'     => true,
				],
				'noscript' => true,
				'div'      => true,
				'span'     => true,
			]
		);

		$category_settings = filter_input_array(
			INPUT_POST,
			[
				'category_include'        => [
					'filter' => FILTER_SANITIZE_NUMBER_INT,
					'flags'  => FILTER_REQUIRE_ARRAY,
				],
				'category_exclude'        => [
					'filter' => FILTER_SANITIZE_NUMBER_INT,
					'flags'  => FILTER_REQUIRE_ARRAY,
				],
				'category_inner_relation' => [
					'filter' => FILTER_SANITIZE_SPECIAL_CHARS,
				],
			]
		);
		if ( $category_settings ) {
			if ( isset( $category_settings['category_include'] ) && is_array( $category_settings['category_include'] ) ) {
				$settings['category_include'] = array_map( 'intval', $category_settings['category_include'] );
			} else {
				$settings['category_include'] = [];
			}

			if ( isset( $category_settings['category_exclude'] ) && is_array( $category_settings['category_exclude'] ) ) {
				$settings['category_exclude'] = array_map( 'intval', $category_settings['category_exclude'] );
			} else {
				$settings['category_exclude'] = [];
			}

			if ( isset( $category_settings['category_inner_relation'] ) ) {
				$settings['category_inner_relation'] = $category_settings['category_inner_relation'];
			} else {
				$settings['category_inner_relation'] = 'IN';
			}
		}

		$tag_settings = filter_input_array(
			INPUT_POST,
			[
				'tag_include'        => [
					'filter' => FILTER_SANITIZE_NUMBER_INT,
					'flags'  => FILTER_REQUIRE_ARRAY,
				],
				'tag_inner_relation' => [
					'filter' => FILTER_SANITIZE_SPECIAL_CHARS,
				],
			]
		);
		if ( $tag_settings ) {
			if ( isset( $tag_settings['tag_include'] ) && is_array( $tag_settings['tag_include'] ) ) {
				$settings['tag_include'] = array_map( 'absint', $tag_settings['tag_include'] );
			} else {
				$settings['tag_include'] = [];
			}

			if ( isset( $tag_settings['tag_inner_relation'] ) ) {
				$settings['tag_inner_relation'] = $tag_settings['tag_inner_relation'];
			} else {
				$settings['tag_inner_relation'] = 'IN';
			}
		}

		$taxonomy_filters_relation = filter_input( INPUT_POST, 'taxonomy_filters_relation', FILTER_SANITIZE_SPECIAL_CHARS );
		if ( in_array( $taxonomy_filters_relation, [ 'AND', 'OR' ], true ) ) {
			$settings['taxonomy_filters_relation'] = $taxonomy_filters_relation;
		}

		foreach ( $custom_taxonomies as $taxonomy ) {
			$include_key              = $taxonomy . '_include';
			$include_values           = isset( $_POST[ $include_key ] ) ? array_map( 'absint', (array) $_POST[ $include_key ] ) : [];
			$settings[ $include_key ] = $include_values;

			$inner_relation_key = $taxonomy . '_inner_relation';
			if ( isset( $_POST[ $inner_relation_key ] ) ) {
				$settings[ $inner_relation_key ] = 'AND' === $_POST[ $inner_relation_key ] ? 'AND' : 'IN';
			} else {
				$settings[ $inner_relation_key ] = 'IN';
			}
		}

		$category_tag_relation = filter_input( INPUT_POST, 'category_tag_relation', FILTER_SANITIZE_SPECIAL_CHARS );
		if ( in_array( $category_tag_relation, [ 'AND', 'OR' ], true ) ) {
			$settings['category_tag_relation'] = $category_tag_relation;
		}

		// Process Republication Tracker options only if the plugin is active.
		if ( self::is_republication_tracker_plugin_active() ) {
			$republication_tracker             = filter_input( INPUT_POST, 'republication_tracker', FILTER_SANITIZE_NUMBER_INT );
			$settings['republication_tracker'] = (bool) $republication_tracker;

			$only_republishable             = filter_input( INPUT_POST, 'only_republishable', FILTER_SANITIZE_NUMBER_INT );
			$settings['only_republishable'] = (bool) $only_republishable;

			$only_distributable_images             = filter_input( INPUT_POST, 'only_distributable_images', FILTER_SANITIZE_NUMBER_INT );
			$settings['only_distributable_images'] = (bool) $only_distributable_images;
		}

		/**
		 * Filter the feed settings before they are saved.
		 *
		 * @param array $settings      The feed settings to be saved.
		 * @param int   $feed_post_id  The post ID of the feed.
		 * @return array Modified feed settings.
		 */
		$settings = apply_filters( 'newspack_rss_modify_save_settings', $settings, $feed_post_id );

		update_post_meta( $feed_post_id, self::FEED_SETTINGS_META, $settings );
		// @todo flush feed cache here.
	}

	/**
	 * Apply settings on frontend to WP query.
	 *
	 * @param WP_Query $query WP_Query object.
	 * @param bool     $force Whether to force the query modification. Used for tests.
	 */
	public static function modify_feed_query( $query, $force = false ) {
		if ( ! $force && ( ! $query->is_feed() || ! $query->is_main_query() ) ) {
			return;
		}

		$settings = self::get_feed_settings( is_numeric( $force ) ? $force : null );

		if ( ! $settings ) {
			return;
		}

		$query->set( 'posts_per_rss', absint( $settings['num_items_in_feed'] ) );

		$query->set( 'offset', absint( $settings['offset'] ) );

		if ( ! empty( $settings['timeframe'] ) ) {
			$query->set( 'date_query', [ 'after' => gmdate( 'Y-m-d H:i:s', strtotime( '- ' . $settings['timeframe'] . ' hours' ) ) ] );
		}

		// Handle category, taxonomy and tag filtering with configurable relation.
		$tax_queries = [];

		if ( ! empty( $settings['category_include'] ) ) {
			$tax_queries[] = [
				'taxonomy' => 'category',
				'field'    => 'term_id',
				'terms'    => array_map( 'absint', $settings['category_include'] ),
				'operator' => $settings['category_inner_relation'] ?? 'IN',
			];
		}

		if ( ! empty( $settings['tag_include'] ) ) {
			$tax_queries[] = [
				'taxonomy' => 'post_tag',
				'field'    => 'term_id',
				'terms'    => array_map( 'absint', $settings['tag_include'] ),
				'operator' => $settings['tag_inner_relation'] ?? 'IN',
			];
		}

		foreach ( self::get_custom_taxonomies_for_posts() as $taxonomy ) {
			$include_key = $taxonomy . '_include';
			$inner_relation_key = $taxonomy . '_inner_relation';
			$inner_relation = ! empty( $settings[ $inner_relation_key ] ) ? $settings[ $inner_relation_key ] : 'IN';
			if ( ! empty( $settings[ $include_key ] ) && is_array( $settings[ $include_key ] ) ) {
				$tax_queries[] = [
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $settings[ $include_key ],
					'operator' => $inner_relation,
				];
			}
		}

		// Handle category exclusion using tax_query.
		if ( ! empty( $settings['category_exclude'] ) ) {
			$tax_queries[] = [
				'taxonomy' => 'category',
				'field'    => 'term_id',
				'terms'    => array_map( 'absint', $settings['category_exclude'] ),
				'operator' => 'NOT IN',
			];
		}

		// Use tax_query if we have any filters.
		if ( ! empty( $tax_queries ) ) {
			// Only set relation if we have multiple queries.
			if ( count( $tax_queries ) > 1 ) {
				$tax_queries['relation'] = $settings['taxonomy_filters_relation'];
			}
			$query->set( 'tax_query', $tax_queries );
		}

		// Category exclusion remains separate as it should always exclude.
		if ( ! empty( $settings['category_exclude'] ) ) {
			$query->set( 'category__not_in', array_map( 'absint', $settings['category_exclude'] ) );
		}

		if ( ! empty( $settings['update_frequency'] ) ) {
			// Split the string on the hyphen to get the update frequency and the number of times to update.
			$settings['update_frequency'] = explode( '-', $settings['update_frequency'] );
			add_filter(
				'rss_update_period',
				function() use ( $settings ) {
					return $settings['update_frequency'][0];
				}
			);
			add_filter(
				'rss_update_frequency',
				function() use ( $settings ) {
					return $settings['update_frequency'][1];
				}
			);
		}

		if ( $settings['use_post_id_as_guid'] ) {
			add_filter(
				'the_guid',
				function( $post_guid, $post_id ) {
					return $post_id;
				},
				10,
				2
			);
		}

		if ( self::is_republication_tracker_plugin_active() && ! empty( $settings['only_republishable'] ) ) {
			$meta_query = $query->get( 'meta_query' );
			if ( ! is_array( $meta_query ) ) {
				$meta_query = [];
			}
			$meta_query[] = [
				'key'     => 'republication-tracker-tool-hide-widget',
				'value'   => '1',
				'compare' => '!=',
			];
			$query->set( 'meta_query', $meta_query );
		}

		/**
		 * Modify the RSS feed query.
		 *
		 * @param WP_Query $query    The WP_Query object for the feed.
		 * @param array    $settings The current feed settings.
		 */
		do_action( 'newspack_rss_modify_feed_query', $query, $settings );
	}

	/**
	 * Toggle full-content/excerpt display on frontend.
	 *
	 * @param bool $value Whether to use excerpt in RSS.
	 * @return bool Modified $value.
	 */
	public static function filter_use_rss_excerpt( $value ) {
		if ( ! is_feed() ) {
			return $value;
		}

		$settings = self::get_feed_settings();
		if ( ! $settings ) {
			return $value;
		}

		return ! $settings['full_content'];
	}

	/**
	 * Add extra tags to RSS items on frontend.
	 */
	public static function add_extra_tags() {
		$settings = self::get_feed_settings();
		if ( ! $settings ) {
			return;
		}

		$post = get_post();

		if ( $settings['use_image_tags'] ) {
			/**
			 * Filter the image size used for RSS feed images in <image> tags.
			 *
			 * @param string  $size     The image size slug. Default is `Newspack\RSS_Add_Image::RSS_IMAGE_SIZE`.
			 * @param array   $settings The feed settings array.
			 * @param WP_Post $post     The current post object.
			 */
			$image_size    = apply_filters( 'newspack_rss_image_size', RSS_Add_Image::RSS_IMAGE_SIZE, $settings, $post );
			$thumbnail_url = get_the_post_thumbnail_url( $post, $image_size );

			if ( $thumbnail_url ) :
				?>
				<image><?php echo esc_url( $thumbnail_url ); ?></image>
				<?php
			endif;
		}

		if ( $settings['use_updated_tags'] ) {
			?>
			<updated><?php echo esc_html( get_the_modified_date( 'Y-m-d\TH:i:s' ) ); ?></updated>
			<?php
		}

		if ( $settings['use_tags_tags'] ) {
			$cats         = get_the_terms( $post, 'category' );
			$cats         = ( ! is_array( $cats ) ) ? [] : $cats;
			$tags         = get_the_terms( $post, 'post_tag' );
			$tags         = ( ! is_array( $tags ) ) ? [] : $tags;
			$all_terms    = array_merge( $cats, $tags );
			$terms_string = implode( ',', wp_list_pluck( $all_terms, 'name' ) );

			/**
			 * Filter the tags output format for RSS feeds.
			 *
			 * Return false to skip the default <tags> wrapper and output custom format.
			 * Return a string to replace the default comma-separated format.
			 * Allowed tags: <tag> (no attributes).
			 *
			 * @param string|false $output    Default comma-separated escaped string, or false to skip wrapper.
			 * @param array        $all_terms Array of WP_Term objects (categories and tags merged).
			 * @param array        $settings  The feed settings array.
			 * @param WP_Post      $post      The current post object.
			 */
			$tags_output = apply_filters( 'newspack_rss_tags_output', esc_html( $terms_string ), $all_terms, $settings, $post );

			if ( false !== $tags_output ) {
				?>
				<tags><?php echo wp_kses( $tags_output, [ 'tag' => [] ] ); ?></tags>
				<?php
			}
		}

		if ( $settings['use_media_tags'] ) {
			$thumbnail_id = get_post_thumbnail_id();
			if ( $thumbnail_id ) {
				/** This filter is documented above in the use_image_tags block */
				$image_size     = apply_filters( 'newspack_rss_image_size', RSS_Add_Image::RSS_IMAGE_SIZE, $settings, $post );
				$thumbnail_data = wp_get_attachment_image_src( $thumbnail_id, $image_size );
				$thumbnail_position = get_post_meta( $post->ID, 'newspack_featured_image_position', true );
				if ( empty( $thumbnail_position ) ) {
					$thumbnail_position = 'default';
				}

				if ( $thumbnail_data ) {
					$caption = get_the_post_thumbnail_caption();
					/**
					 * Filter the media content URL for RSS feeds.
					 *
					 * Allows URL transformations (e.g., wp_specialchars_decode for mobile apps).
					 *
					 * @param string  $url          The thumbnail URL from wp_get_attachment_image_src.
					 * @param int     $thumbnail_id The attachment post ID.
					 * @param array   $settings     The feed settings array.
					 * @param WP_Post $post         The current post object.
					 */
					$media_url = apply_filters( 'newspack_rss_media_content_url', $thumbnail_data[0], $thumbnail_id, $settings, $post );
					?>
					<thumbnail_position><?php echo esc_html( $thumbnail_position ); ?></thumbnail_position>
					<media:content type="<?php echo esc_attr( get_post_mime_type( $thumbnail_id ) ); ?>" url="<?php echo esc_url( $media_url ); ?>">
						<?php if ( ! empty( $caption ) ) : ?>
						<media:description><![CDATA[<?php echo esc_html( $caption ); ?>]]></media:description>
						<?php endif; ?>
						<media:thumbnail url="<?php echo esc_url( $media_url ); ?>" width="<?php echo esc_attr( $thumbnail_data[1] ); ?>" height="<?php echo esc_attr( $thumbnail_data[2] ); ?>" />
						<?php
						/**
						 * Fires inside the media:content element.
						 *
						 * @param int         $thumbnail_id   The attachment post ID.
						 * @param array|false $thumbnail_data Array with [url, width, height] or false.
						 * @param string      $caption        The image caption from get_the_post_thumbnail_caption().
						 * @param array       $settings       The feed settings array.
						 * @param WP_Post     $post           The current post object.
						 */
						do_action( 'newspack_rss_media_content', $thumbnail_id, $thumbnail_data, $caption, $settings, $post );
						?>
					</media:content>
					<?php
				}
			}
		}

		/**
		 * Fires after all standard RSS extra tags have been output.
		 *
		 * Use this hook to add completely custom tags to feed items (e.g., for mobile app
		 * integrations like Pugpig, or other third-party services).
		 *
		 * Example:
		 *     add_action( 'newspack_rss_after_extra_tags', function( $settings, $post ) {
		 *         if ( my_is_special_feed() ) {
		 *             echo '<custom_field>' . esc_html( get_post_meta( $post->ID, 'custom', true ) ) . '</custom_field>';
		 *         }
		 *     }, 10, 2 );
		 *
		 * @param array   $settings The feed settings array from get_feed_settings().
		 * @param WP_Post $post     The current post object.
		 */
		do_action( 'newspack_rss_after_extra_tags', $settings, $post );
	}

	/**
	 * Add tracking pixels to feed content if settings are configured.
	 *
	 * @param string $content Feed content.
	 * @return string Modified $content.
	 */
	public static function maybe_add_tracking_snippets( $content ) {
		$settings = self::get_feed_settings();

		if ( ! $settings ) {
			return $content;
		}

		$post_id = get_the_ID();

		// Add custom tracking snippet if provided.
		$custom_tracking_content = '';
		if ( ! empty( $settings['custom_tracking_snippet'] ) ) {
			$custom_tracking_content = $settings['custom_tracking_snippet'];
			$custom_tracking_content = str_replace( '{{post-id}}', $post_id, $custom_tracking_content );
			$custom_tracking_content = str_replace( '{{post-url}}', get_permalink( $post_id ), $custom_tracking_content );
		}

		if ( empty( $settings['republication_tracker'] ) || ! method_exists( 'Republication_Tracker_Tool', 'create_tracking_pixel_markup' ) ) {
			return $content . $custom_tracking_content;
		}

		$pixel            = \Republication_Tracker_Tool::create_tracking_pixel_markup( $post_id );
		$parsely_tracking = \Republication_Tracker_Tool::create_parsely_tracking( $post_id );

		// Check if the attribution should be displayed.
		$display_attribution = get_option( 'republication_tracker_tool_display_attribution', 'on' );

		if ( 'on' !== $display_attribution ) {
			return $content . $pixel . $parsely_tracking . $custom_tracking_content;
		}

		$site_icon_markup = '';
		$site_icon_url    = get_site_icon_url( 150 );
		if ( ! empty( $site_icon_url ) ) {
			$site_icon_markup = sprintf(
				'<img src="%1$s" style="width:1em;height:1em;margin-left:10px;">',
				esc_attr( $site_icon_url )
			);
		}

		$attribution = sprintf(
			'This <a target="_blank" href="%1$s">article</a> first appeared on <a target="_blank" href="%2$s">%3$s</a> and is republished here under a Creative Commons license. %4$s %5$s',
			esc_url( get_permalink( $post_id ) ),
			esc_url( home_url() ),
			esc_html( get_bloginfo() ) . $site_icon_markup,
			$pixel,
			$parsely_tracking
		);

		$content .= $attribution . $custom_tracking_content;

		/**
		 * Filter the feed content after tracking snippets have been added.
		 *
		 * @param string $content  The feed content with tracking snippets applied.
		 * @param int    $post_id  The ID of the current post.
		 * @param array  $settings The current feed settings.
		 * @return string Modified feed content.
		 */
		$content = apply_filters( 'newspack_rss_after_tracking_snippet', $content, $post_id, $settings );

		return $content;
	}

	/**
	 * The Newspack Theme adds featured images to the top of feed content by default. This setting toggles whether to do that.
	 *
	 * @param string $content Feed content.
	 * @return string Unmodified $content.
	 */
	public static function maybe_remove_content_featured_image( $content ) {
		$settings = self::get_feed_settings();
		if ( ! $settings ) {
			return $content;
		}

		if ( ! $settings['content_featured_image'] ) {
			remove_filter( 'the_excerpt_rss', [ 'Newspack\RSS_Add_Image', 'thumbnails_in_rss' ] );
			remove_filter( 'the_content_feed', [ 'Newspack\RSS_Add_Image', 'thumbnails_in_rss' ] );
		}

		return $content;
	}

	/**
	 * Filter feed content to remove non-distributable images if needed.
	 *
	 * @param string $content Feed content.
	 * @return string Modified $content.
	 */
	public static function maybe_remove_non_distributable_images( $content ) {
		$settings = self::get_feed_settings();
		if ( ! $settings || ! self::is_republication_tracker_plugin_active() || empty( $settings['only_distributable_images'] ) ) {
			return $content;
		}

		if ( class_exists( '\Republication_Tracker_Tool_Content' ) &&
			method_exists( '\Republication_Tracker_Tool_Content', 'remove_non_distributable_images' )
		) {
			return \Republication_Tracker_Tool_Content::remove_non_distributable_images( $content );
		}

		return $content;
	}

	/**
	 * Suppress the Yoast prepended and appended content depending on setting.
	 *
	 * @param bool $include_yoast Whether to prepand and append content to the feed items.
	 * @return bool Modified $include_yoast
	 */
	public static function maybe_suppress_yoast( $include_yoast ) {
		$settings = self::get_feed_settings();
		if ( ! $settings ) {
			return $include_yoast;
		}

		return ! (bool) $settings['suppress_yoast'];
	}

	/**
	 * Add the 'xmlns:media="http://search.yahoo.com/mrss/"' namespace to feed if setting is checked.
	 */
	public static function maybe_inject_yahoo_namespace() {
		$settings = self::get_feed_settings();
		if ( ! $settings ) {
			return;
		}

		if ( $settings['yahoo_namespace'] ) {
			?>
xmlns:media="http://search.yahoo.com/mrss/"
			<?php
		}
	}

	/**
	 * Wrap titles in CDATA tags if checked e.g. "<title><![CDATA[Post title]]></title>".
	 * This is useful for certain parsers that don't support titles with special characters in them.
	 *
	 * @param string $title Post title for RSS feed.
	 * @return string Modified $title.
	 */
	public static function maybe_wrap_titles_in_cdata( $title ) {
		$settings = self::get_feed_settings();
		if ( ! $settings ) {
			return $title;
		}

		if ( $settings['cdata_titles'] && 'atom' !== get_query_var( 'feed' ) ) {
			$title = '<![CDATA[' . $title . ']]>';
		}

		return $title;
	}

	/**
	 * Check if the Republication Tracker Tool plugin is active.
	 * This is used to determine whether to show additional options in the RSS feed settings.
	 *
	 * @return bool Whether the Republication Tracker Tool plugin is active.
	 */
	private static function is_republication_tracker_plugin_active() {
		return class_exists( 'Republication_Tracker_Tool' );
	}

	/**
	 * Handle AJAX search for taxonomy terms.
	 */
	public static function ajax_search_terms() {
		check_ajax_referer( 'newspack_rss_search_terms', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( 'Insufficient permissions' );
		}

		if ( ! isset( $_POST['taxonomy'] ) || ! isset( $_POST['search'] ) ) {
			wp_die( 'Invalid request' );
		}

		$taxonomy = sanitize_text_field( $_POST['taxonomy'] );
		$search   = sanitize_text_field( $_POST['search'] );

		if ( ! taxonomy_exists( $taxonomy ) ) {
			wp_die( 'Invalid taxonomy' );
		}

		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'search'     => $search,
				'hide_empty' => false,
				'number'     => 50,
				'fields'     => 'id=>name',
			]
		);

		$results = [];
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term_id => $term_name ) {
				$results[] = [
					'id'   => $term_id,
					'text' => $term_name,
				];
			}
		}

		wp_send_json( $results );
	}

	/**
	 * Get custom taxonomies registered for posts (excluding built-in taxonomies).
	 *
	 * @return array Array of custom taxonomy names registered for posts.
	 */
	private static function get_custom_taxonomies_for_posts() {
		$custom_taxonomies = get_taxonomies(
			[
				'public'   => true,
				'_builtin' => false,
			],
			'objects'
		);

		$taxonomies = [];
		foreach ( $custom_taxonomies as $taxonomy ) {
			if ( in_array( 'post', $taxonomy->object_type, true ) ) {
				$taxonomies[] = $taxonomy->name;
			}
		}

		return $taxonomies;
	}

	/**
	 * Map the capabilities for the RSS feed custom post type.
	 *
	 * @param array $capabilities_map The existing capabilities map.
	 * @return array The modified capabilities map with RSS feed CPT capabilities.
	 */
	public static function newspack_capabilities_map( $capabilities_map ) {
		$capabilities_map[ self::FEED_CPT ] = 'post';
		return $capabilities_map;
	}
}
RSS::init();
