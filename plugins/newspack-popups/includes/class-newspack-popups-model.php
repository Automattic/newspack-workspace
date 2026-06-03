<?php
/**
 * Newspack Popups Model
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * API endpoints
 */
final class Newspack_Popups_Model {
	/**
	 * Possible placements of overlay popups.
	 *
	 * @var array
	 */
	protected static $overlay_placements = [ 'top', 'bottom', 'center', 'bottom_right', 'bottom_left', 'top_right', 'top_left', 'center_right', 'center_left' ];

	/**
	 * Possible placements of inline popups.
	 *
	 * @var array
	 */
	protected static $inline_placements = [ 'inline', 'above_header', 'archives' ];

	/**
	 * List of hooks that can be used to insert hidden inputs in forms that will be rendered inside a popup.
	 *
	 * @var array
	 */
	protected static $form_hooks = [
		'newspack_registration_before_form_fields',
		'newspack_newsletters_subscribe_block_before_form_fields',
		'newspack_blocks_donate_before_form_fields',
		'newspack_blocks_checkout_button_hidden_fields',
	];

	/**
	 * Attribute to temporarily hold the current popup ID and use it in the form_hooks.
	 *
	 * @var ?int
	 */
	protected static $form_hooks_popup_id;

	/**
	 * The current popup.
	 *
	 * @var array|null
	 */
	protected static $current_popup = null;

	/**
	 * Override for block theme detection (used in tests).
	 *
	 * @var boolean|null
	 */
	protected static $block_theme_override = null;

	/**
	 * Retrieve all Popups (first 100).
	 *
	 * @param  boolean $include_unpublished Whether to include unpublished posts.
	 * @param  boolean $include_trash Whether to include trashed posts.
	 * @return array Array of Popup objects.
	 */
	public static function retrieve_popups( $include_unpublished = false, $include_trash = false ) {
		$args = [
			'post_type'      => Newspack_Popups::NEWSPACK_POPUPS_CPT,
			'post_status'    => $include_unpublished ? [ 'draft', 'pending', 'future', 'publish' ] : [ 'publish' ],
			'posts_per_page' => 1000, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
		];
		if ( $include_trash ) {
			$args['post_status'][] = 'trash';
		}

		$popups = self::retrieve_popups_with_query( new WP_Query( $args ), true );
		return $popups;
	}

	/**
	 * Retrieve all active popups.
	 */
	public static function retrieve_active_popups() {
		return self::retrieve_popups_with_query(
			new WP_Query(
				[
					'post_type'      => Newspack_Popups::NEWSPACK_POPUPS_CPT,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
				]
			),
			true
		);
	}

	/**
	 * Set terms for a Popup.
	 *
	 * @param integer $id ID of Popup.
	 * @param array   $terms Array of term objects or IDs to be set.
	 * @param string  $taxonomy Taxonomy slug.
	 */
	public static function set_popup_terms( $id, $terms, $taxonomy ) {
		$popup = self::retrieve_popup_by_id( $id, false, true );
		if ( ! $popup ) {
			return new \WP_Error(
				'newspack_popups_popup_doesnt_exist',
				esc_html__( 'The prompt specified does not exist.', 'newspack-popups' ),
				[
					'status' => 400,
					'level'  => 'fatal',
				]
			);
		}
		if ( ! in_array( $taxonomy, [ 'category', 'post_tag', Newspack_Popups::NEWSPACK_POPUPS_TAXONOMY, Newspack_Segments_Model::TAX_SLUG ] ) ) {
			return new \WP_Error(
				'newspack_popups_invalid_taxonomy',
				esc_html__( 'Invalid taxonomy.', 'newspack-popups' ),
				[
					'status' => 400,
					'level'  => 'fatal',
				]
			);
		}
		$term_ids = array_map(
			function( $term ) use ( $taxonomy ) {
				if ( is_int( $term ) ) {
					$term       = get_term_by( 'id', $term, $taxonomy, ARRAY_A );
					$term['id'] = $term['term_id'];
				}
				return $term['id'];
			},
			$terms
		);
		return wp_set_post_terms( $id, $term_ids, $taxonomy );
	}

	/**
	 * Set options for a Popup. Can be used by other plugins to set popup's options.
	 *
	 * @param integer $id ID of Popup.
	 * @param array   $options Array of options to update.
	 */
	public static function set_popup_options( $id, $options ) {
		$popup = self::retrieve_popup_by_id( $id, false, true );
		if ( ! $popup ) {
			return new \WP_Error(
				'newspack_popups_popup_doesnt_exist',
				esc_html__( 'The prompt specified does not exist.', 'newspack-popups' ),
				[
					'status' => 400,
					'level'  => 'fatal',
				]
			);
		}
		foreach ( $options as $key => $value ) {
			switch ( $key ) {
				case 'frequency':
					if ( ! in_array( $value, [ 'once', 'weekly', 'daily', 'always', 'custom' ] ) ) {
						return new \WP_Error(
							'newspack_popups_invalid_option_value',
							esc_html__( 'Invalid frequency value.', 'newspack-popups' ),
							[
								'status' => 400,
								'level'  => 'fatal',
							]
						);
					}
					update_post_meta( $id, $key, $value );
					break;
				case 'placement':
					$valid_placements = array_merge(
						self::$overlay_placements,
						self::$inline_placements,
						Newspack_Popups_Custom_Placements::get_custom_placement_values(),
						[ 'manual' ]
					);
					if ( ! in_array( $value, $valid_placements ) ) {
						return new \WP_Error(
							'newspack_popups_invalid_option_value',
							esc_html__( 'Invalid placement value.', 'newspack-popups' ),
							[
								'status' => 400,
								'level'  => 'fatal',
							]
						);
					}
					update_post_meta( $id, $key, $value );
					break;
				case 'post_types':
				case 'archive_page_types':
				case 'excluded_categories':
				case 'excluded_tags':
					update_post_meta( $id, $key, $value );
					break;
				default:
					update_post_meta( $id, $key, esc_attr( $value ) );
			}
		}
	}

	/**
	 * Retrieve prompts by placement. If $placement is not given, get only prompts eligible to be
	 * programmatically inserted (not shortcoded, custom placement, or manual-only).
	 *
	 * @param  boolean     $include_unpublished Whether to include unpublished prompts.
	 * @param  int|boolean $campaign_id Campaign term ID, or false to ignore campaign.
	 * @param  array|null  $placements If given, find prompts matching these exact placements.
	 * @return array Eligible popup objects.
	 */
	public static function retrieve_eligible_popups( $include_unpublished = false, $campaign_id = false, $placements = null ) {
		$valid_placements = is_array( $placements ) ? $placements : array_merge(
			self::$overlay_placements,
			self::$inline_placements
		);
		$args             = [
			'post_type'      => Newspack_Popups::NEWSPACK_POPUPS_CPT,
			'post_status'    => $include_unpublished ? [ 'draft', 'pending', 'future', 'publish' ] : 'publish',
			'posts_per_page' => 100,
			'meta_key'       => 'placement',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'meta_value'     => $valid_placements,
			'meta_compare'   => 'IN',
		];

		// If previewing specific campaign.
		if ( ! empty( $campaign_id ) ) {
			$tax_query = [ 'taxonomy' => Newspack_Popups::NEWSPACK_POPUPS_TAXONOMY ];

			if ( -1 === (int) $campaign_id ) {
				$tax_query['operator'] = 'NOT EXISTS';
			} else {
				$tax_query['field'] = 'term_id';
				$tax_query['terms'] = [ $campaign_id ];
			}

			$args['tax_query'] = [ $tax_query ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		return self::retrieve_popups_with_query( new WP_Query( $args ) );
	}

	/**
	 * Get an array of options from abbreviated query parameters.
	 *
	 * @return array Array of options.
	 */
	public static function get_preview_query_options() {
		$options_filters = [
			'background_color'               => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
			'hide_border'                    => FILTER_VALIDATE_BOOLEAN,
			'large_border'                   => FILTER_VALIDATE_BOOLEAN,
			'no_padding'                     => FILTER_VALIDATE_BOOLEAN,
			'frequency'                      => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
			'frequency_max'                  => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
			'frequency_start'                => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
			'frequency_between'              => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
			'frequency_reset'                => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
			'close_button_background_color'  => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
			'enable_close_button_background' => FILTER_VALIDATE_BOOLEAN,
			'overlay_color'                  => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
			'overlay_opacity'                => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
			'overlay_size'                   => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
			'no_overlay_background'          => FILTER_VALIDATE_BOOLEAN,
			'placement'                      => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
			'trigger_type'                   => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
			'trigger_delay'                  => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
			'trigger_scroll_progress'        => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
			'trigger_blocks_count'           => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
			'archive_insertion_posts_count'  => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
			'archive_insertion_is_repeating' => FILTER_VALIDATE_BOOLEAN,
			'utm_suppression'                => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
			'additional_classes'             => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
		];

		$options = [];
		foreach ( $options_filters as $option => $filter ) {
			$options[ $option ] = filter_input( INPUT_GET, Newspack_Popups::PREVIEW_QUERY_KEYS[ $option ], $filter );
		}

		return $options;
	}

	/**
	 * Retrieve popup preview CPT post.
	 *
	 * @param int|string $post_id Post id. Often a query-parameter string.
	 * @return array|null Popup object array, or null if the post id does not resolve to a post.
	 */
	public static function retrieve_preview_popup( $post_id ) {
		// Up-to-date post data is stored in an autosave.
		$autosave    = wp_get_post_autosave( $post_id );
		$post_object = $autosave ? $autosave : get_post( $post_id );
		if ( ! $post_object ) {
			return null;
		}
		// Setting proper id for correct API calls.
		$post_object->ID = $post_id;

		return self::create_popup_object( $post_object, false, self::get_preview_query_options() );
	}

	/**
	 * Retrieve popup CPT post by ID.
	 *
	 * The query for prompts relies on the dynamic default value of the post_status parameter.
	 * In admin context, it will include the drafts, and in non-admin context it will only return published posts.
	 *
	 * @param string $post_id Post id.
	 * @param bool   $use_default_status_query Whether to rely on the default behavior of the post_status parameter. If false, only published posts will be returned.
	 * @param bool   $include_unpublished Whether to include unpublished prompts. $use_default_status_query must be false.
	 * @return object Popup object.
	 */
	public static function retrieve_popup_by_id( $post_id, $use_default_status_query = false, $include_unpublished = false ) {
		$args = [
			'post_type'      => Newspack_Popups::NEWSPACK_POPUPS_CPT,
			'posts_per_page' => 1,
			'p'              => $post_id,
		];

		if ( false === $use_default_status_query ) {
			$args['post_status'] = $include_unpublished ? [ 'draft', 'pending', 'future', 'publish' ] : 'publish';
		}

		$popups = self::retrieve_popups_with_query( new WP_Query( $args ) );
		return count( $popups ) > 0 ? $popups[0] : null;
	}

	/**
	 * Retrieve popup CPT posts.
	 *
	 * @param WP_Query $query The query to use.
	 * @param boolean  $include_taxonomies If true, returned objects will include assigned categories and tags.
	 * @return array Popup objects array
	 */
	protected static function retrieve_popups_with_query( WP_Query $query, $include_taxonomies = false ) {
		$popups = [];
		if ( $query->have_posts() ) {
			foreach ( $query->posts as $popup_post ) {
				if ( Newspack_Popups::NEWSPACK_POPUPS_CPT === $popup_post->post_type ) {
					$popup = self::create_popup_object(
						$popup_post,
						$include_taxonomies
					);
					$popup = self::deprecate_test_never_manual( $popup, 'publish' === $query->get( 'post_status', null ) );

					if ( $popup ) {
						$popups[] = $popup;
					}
				}
			}
		}
		return $popups;
	}

	/**
	 * Deprecate Test Mode and Never/Manual frequencies.
	 *
	 * @param object $popup The popup.
	 * @param bool   $published_only Whether the result must be a published post.
	 * @return object|null Popup object or null.
	 */
	protected static function deprecate_test_never_manual( $popup, $published_only ) {
		$frequency = $popup['options']['frequency'];
		$placement = $popup['options']['placement'];
		if ( in_array( $frequency, [ 'never', 'test', 'manual' ] ) ) {
			if ( in_array( $placement, [ 'inline', 'above_header' ] ) ) {
				$popup['options']['frequency'] = 'always';
			} else {
				$popup['options']['frequency'] = 'daily';
			}
			update_post_meta( $popup['id'], 'frequency', $popup['options']['frequency'] );

			$post = get_post( $popup['id'] );

			// Update 'manual' prompts to a default custom placement.
			if ( 'manual' === $frequency ) {
				$popup['options']['placement'] = 'custom1';
				update_post_meta( $popup['id'], 'placement', $popup['options']['placement'] );
			}

			// Set 'never' and 'test' prompts to draft status.
			if ( in_array( $frequency, [ 'never', 'test' ] ) ) {
				$popup['status']   = 'draft';
				$post->post_status = 'draft';
			}

			wp_update_post( $post );

			if ( $published_only && 'publish' !== $popup['status'] ) {
				$popup = null;
			}
		}
		return $popup;
	}

	/**
	 * Gets the popup's segments.
	 *
	 * @param int $id ID of the prompt.
	 * @return array Array of segments.
	 */
	public static function get_popup_segments( $id ) {
		$segments = get_the_terms( $id, Newspack_Segments_Model::TAX_SLUG );
		return $segments ? $segments : [];
	}

	/**
	 * Get options for the given prompt, with defaults.
	 *
	 * @param int         $id ID of the prompt.
	 * @param object|null $options Popup options to use instead of the options retrieved from the post. Used for popup previews.
	 * @return object Array of prompt options.
	 */
	public static function get_popup_options( $id, $options = null ) {
		$post_options = isset( $options ) ? $options : [
			'background_color'               => get_post_meta( $id, 'background_color', true ),
			'hide_border'                    => get_post_meta( $id, 'hide_border', true ),
			'large_border'                   => get_post_meta( $id, 'large_border', true ),
			'no_padding'                     => get_post_meta( $id, 'no_padding', true ),
			'frequency'                      => get_post_meta( $id, 'frequency', true ),
			'frequency_max'                  => get_post_meta( $id, 'frequency_max', true ),
			'frequency_start'                => get_post_meta( $id, 'frequency_start', true ),
			'frequency_between'              => get_post_meta( $id, 'frequency_between', true ),
			'frequency_reset'                => get_post_meta( $id, 'frequency_reset', true ),
			'close_button_background_color'  => get_post_meta( $id, 'close_button_background_color', true ),
			'enable_close_button_background' => get_post_meta( $id, 'enable_close_button_background', true ),
			'overlay_color'                  => get_post_meta( $id, 'overlay_color', true ),
			'overlay_opacity'                => get_post_meta( $id, 'overlay_opacity', true ),
			'overlay_size'                   => get_post_meta( $id, 'overlay_size', true ),
			'no_overlay_background'          => get_post_meta( $id, 'no_overlay_background', true ),
			'placement'                      => get_post_meta( $id, 'placement', true ),
			'trigger_type'                   => get_post_meta( $id, 'trigger_type', true ),
			'trigger_delay'                  => get_post_meta( $id, 'trigger_delay', true ),
			'trigger_scroll_progress'        => get_post_meta( $id, 'trigger_scroll_progress', true ),
			'trigger_blocks_count'           => get_post_meta( $id, 'trigger_blocks_count', true ),
			'archive_insertion_posts_count'  => get_post_meta( $id, 'archive_insertion_posts_count', true ),
			'archive_insertion_is_repeating' => get_post_meta( $id, 'archive_insertion_is_repeating', true ),
			'utm_suppression'                => get_post_meta( $id, 'utm_suppression', true ),
			'post_types'                     => get_post_meta( $id, 'post_types', true ),
			'archive_page_types'             => get_post_meta( $id, 'archive_page_types', true ),
			'additional_classes'             => get_post_meta( $id, 'additional_classes', true ),
			'excluded_categories'            => get_post_meta( $id, 'excluded_categories', true ),
			'excluded_tags'                  => get_post_meta( $id, 'excluded_tags', true ),
		];

		// Remove empty options, except for those whose value might actually be 0.
		$filtered_options = array_filter(
			$post_options,
			function( $value, $key ) {
				if ( 'overlay_opacity' === $key ) {
					return true;
				}

				return ! empty( $value );
			},
			ARRAY_FILTER_USE_BOTH
		);

		return wp_parse_args(
			$filtered_options,
			[
				'background_color'               => '#FFFFFF',
				'hide_border'                    => false,
				'large_border'                   => false,
				'no_padding'                     => false,
				'frequency'                      => 'always',
				'frequency_max'                  => 0,
				'frequency_start'                => 0,
				'frequency_between'              => 0,
				'frequency_reset'                => 'month',
				'close_button_background_color'  => '#00000000',
				'enable_close_button_background' => false,
				'overlay_color'                  => '#000000',
				'overlay_opacity'                => 30,
				'overlay_size'                   => 'medium',
				'no_overlay_background'          => false,
				'placement'                      => 'inline',
				'trigger_type'                   => 'time',
				'trigger_delay'                  => 0,
				'trigger_scroll_progress'        => 0,
				'trigger_blocks_count'           => 0,
				'archive_insertion_posts_count'  => 1,
				'archive_insertion_is_repeating' => false,
				'utm_suppression'                => null,
				'post_types'                     => self::get_default_popup_post_types(),
				'archive_page_types'             => self::get_archive_page_types_meta_default(),
				'additional_classes'             => '',
				'excluded_categories'            => [],
				'excluded_tags'                  => [],
			]
		);
	}

	/**
	 * Get popups placements.
	 *
	 * @return array Array of popup placements.
	 */
	public static function get_overlay_placements() {
		return self::$overlay_placements;
	}

	/**
	 * Generates the possible sizes for a popup.
	 *
	 * @return array popup possible sizes
	 */
	public static function get_popup_size_options() {
		/**
		 * Filters the list of possible popup sizes.
		 *
		 * @param array Array of possible popup sizes.
		 *     $params = [
		 *          'value' => (string) size value.
		 *          'label' => (string) size label to be displayed.
		 *     ]
		 */
		return apply_filters(
			'newspack_popups_size_options',
			[
				[
					'value' => 'x-small',
					'label' => __( 'Extra Small', 'newspack-popups' ),
				],
				[
					'value' => 'small',
					'label' => __( 'Small', 'newspack-popups' ),
				],
				[
					'value' => 'medium',
					'label' => __( 'Medium', 'newspack-popups' ),
				],
				[
					'value' => 'large',
					'label' => __( 'Large', 'newspack-popups' ),
				],
				[
					'value' => 'full-width',
					'label' => __( 'Full-Width', 'newspack-popups' ),
				],
			]
		);
	}

	/**
	 * Get available archive page types where to display prompts
	 */
	public static function get_available_archive_page_types() {
		return [
			[
				'name'  => 'category',
				/* translators: archive page */
				'label' => __( 'Categories', 'newspack-popups' ),
			],
			[
				'name'  => 'tag',
				/* translators: archive page */
				'label' => __( 'Tags', 'newspack-popups' ),
			],
			[
				'name'  => 'author',
				/* translators: archive page */
				'label' => __( 'Authors', 'newspack-popups' ),
			],
			[
				'name'  => 'date',
				/* translators: archive page */
				'label' => __( 'Date', 'newspack-popups' ),
			],
			[
				'name'  => 'post-type',
				/* translators: archive page */
				'label' => __( 'Custom Post Types', 'newspack-popups' ),
			],
			[
				'name'  => 'taxonomy',
				/* translators: archive page */
				'label' => __( 'Taxonomies', 'newspack-popups' ),
			],
			[
				'name'  => 'home',
				/* translators: the "Posts page" configured under Settings > Reading when a static front page is set. */
				'label' => __( 'Posts Page', 'newspack-popups' ),
			],
		];
	}

	/**
	 * Get the globally supported post types.
	 */
	public static function get_globally_supported_post_types() {
		return apply_filters(
			'newspack_campaigns_post_types_for_campaigns',
			self::get_default_popup_post_types()
		);
	}

	/**
	 * Get the supported archive page types.
	 */
	public static function get_supported_archive_page_types() {
		return apply_filters(
			'newspack_campaigns_archive_page_types_for_campaigns',
			self::get_default_popup_archive_page_types()
		);
	}

	/**
	 * Get the default supported post types.
	 */
	public static function get_default_popup_post_types() {
		// Any custom post type that is both public and has a post type archive.
		$public_post_types = array_values(
			get_post_types(
				[
					'has_archive' => true,
					'public'      => true,
				]
			)
		);

		// Default 'post' and 'page' post types actually have 'is_archive' => false, but we still want them.
		$public_post_types = array_merge( [ 'post', 'page' ], $public_post_types );

		return apply_filters(
			'newspack_campaigns_default_supported_post_types',
			$public_post_types
		);
	}

	/**
	 * Get the default supported archive page types.
	 */
	public static function get_default_popup_archive_page_types() {
		return [ 'home', 'category', 'tag', 'author', 'date', 'post-type', 'taxonomy' ];
	}

	/**
	 * Default value for the `archive_page_types` meta when a prompt has no
	 * stored value.
	 *
	 * Returns the full default set minus opt-in additions (like 'home') so that
	 * prompts created before a new page type was introduced keep their
	 * pre-existing behavior — adding a new page type should never retroactively
	 * widen where existing prompts render.
	 *
	 * Two call sites must stay in sync; both should keep calling this method
	 * rather than inlining a value:
	 *   - The `default` for the `archive_page_types` register_meta() call in
	 *     Newspack_Popups::register_meta() (class-newspack-popups.php).
	 *   - The wp_parse_args fallback in self::create_popup_options() above.
	 */
	public static function get_archive_page_types_meta_default() {
		return array_values( array_diff( self::get_default_popup_archive_page_types(), [ 'home' ] ) );
	}

	/**
	 * Create the popup object.
	 *
	 * @param WP_Post $campaign_post The prompt post object.
	 * @param boolean $include_taxonomies If true, returned objects will include assigned categories and tags.
	 * @param object  $options Popup options to use instead of the options retrieved from the post. Used for popup previews.
	 * @return object Popup object
	 */
	public static function create_popup_object( $campaign_post, $include_taxonomies = false, $options = null ) {
		$id                    = $campaign_post->ID;
		$campaign_post_options = self::get_popup_options( $id, $options );
		$popup                 = [
			'id'      => $id,
			'status'  => 'inherit' === $campaign_post->post_status ? 'preview' : $campaign_post->post_status,
			'title'   => $campaign_post->post_title,
			'content' => $campaign_post->post_content,
			'options' => $campaign_post_options,
		];

		$popup['segments'] = self::get_popup_segments( $id );

		if ( $include_taxonomies ) {
			$popup['categories']      = get_the_category( $id );
			$popup['tags']            = get_the_tags( $id );
			$popup['campaign_groups'] = get_the_terms( $id, Newspack_Popups::NEWSPACK_POPUPS_TAXONOMY );

			$all_taxonomies = self::get_custom_taxonomies();

			foreach ( $all_taxonomies as $custom_taxonomy ) {
				$popup[ $custom_taxonomy ] = get_the_terms( $id, $custom_taxonomy );
			}
		}

		$duplicate_of = get_post_meta( $id, 'duplicate_of', true );

		if ( $duplicate_of ) {
			$popup['duplicate_of'] = $duplicate_of;
		}

		if ( self::is_inline( $popup ) ) {
			switch ( $popup['options']['trigger_type'] ) {
				case 'blocks_count':
					$popup['options']['trigger_scroll_progress'] = 0;
					break;
				case 'scroll':
				default:
					$popup['options']['trigger_blocks_count'] = 0;
					break;
			}

			return $popup;
		}

		if ( self::is_overlay( $popup ) ) {
			switch ( $popup['options']['trigger_type'] ) {
				case 'scroll':
					$popup['options']['trigger_delay'] = 0;
					break;
				case 'blocks_count':
				case 'time':
				default:
					$popup['options']['trigger_scroll_progress'] = 0;
					break;
			}
			if ( ! in_array( $popup['options']['placement'], self::$overlay_placements, true ) ) {
				$popup['options']['placement'] = 'center';
			}
		}

		return $popup;
	}

	/**
	 * Gets custom taxonomies that are assigned to the popup post type.
	 *
	 * @return array
	 */
	public static function get_custom_taxonomies() {
		$all_taxonomies = get_object_taxonomies( Newspack_Popups::NEWSPACK_POPUPS_CPT );
		return array_diff( $all_taxonomies, [ Newspack_Popups::NEWSPACK_POPUPS_TAXONOMY, 'category', 'post_tag' ] );
	}

	/**
	 * Get the popup delay in milliseconds.
	 *
	 * @param object $popup The popup object.
	 * @return number Delay in milliseconds.
	 */
	protected static function get_delay( $popup ) {
		return intval( $popup['options']['trigger_delay'] ) * 1000 + 500;
	}

	/**
	 * Is it an inline popup or not.
	 *
	 * @param object $popup The popup object.
	 * @return boolean True if it is an inline popup.
	 */
	public static function is_inline( $popup ) {
		if ( ! isset( $popup['options'], $popup['options']['placement'] ) ) {
			return false;
		}
		return in_array(
			$popup['options']['placement'],
			array_merge( self::$inline_placements, Newspack_Popups_Custom_Placements::get_custom_placement_values() )
		);
	}

	/**
	 * Is it a manual-only popup or not.
	 *
	 * @param object $popup The popup object.
	 * @return boolean True if it is a manual-only popup.
	 */
	public static function is_manual_only( $popup ) {
		if ( ! isset( $popup['options'], $popup['options']['placement'] ) ) {
			return false;
		}

		return 'manual' === $popup['options']['placement'];
	}

	/**
	 * Get popups which should be inserted above page header.
	 *
	 * @param object $popup The popup object.
	 * @return boolean True if the popup should be inserted above page header.
	 */
	public static function should_be_inserted_above_page_header( $popup ) {
		if ( self::is_inline( $popup ) ) {
			return 'above_header' === $popup['options']['placement'];
		} else {
			// Insert time-triggered overlay popups above the header, this way they will be
			// visible before scrolling below the fold.
			return 'time' === $popup['options']['trigger_type'];
		}
	}

	/**
	 * Get popups which should be inserted above page header.
	 *
	 * @param object $popup The popup object.
	 * @return boolean True if the popup should be inserted above page header.
	 */
	public static function should_be_inserted_in_archive_pages( $popup ) {
		return self::is_inline( $popup ) && 'archives' === $popup['options']['placement'];
	}

	/**
	 * Get popups which should be inserted in page content.
	 *
	 * @param object $popup The popup object.
	 * @return boolean True if the popup should be inserted in page content.
	 */
	public static function should_be_inserted_in_page_content( $popup ) {
		return self::should_be_inserted_above_page_header( $popup ) === false
			&& self::should_be_inserted_in_archive_pages( $popup ) === false;
	}

	/**
	 * Is it an overlay popup or not.
	 *
	 * @param object $popup The popup object.
	 * @return boolean True if it is an overlay popup.
	 */
	public static function is_overlay( $popup ) {
		if ( ! $popup || ! isset( $popup['options'], $popup['options']['placement'] ) ) {
			return false;
		}
		return in_array( $popup['options']['placement'], self::$overlay_placements, true );
	}

	/**
	 * Is it an above-header popup?
	 *
	 * @param object $popup The popup object.
	 * @return boolean True if it is an above-header popup.
	 */
	public static function is_above_header( $popup ) {
		if ( ! isset( $popup['options'], $popup['options']['placement'] ) ) {
			return false;
		}
		return 'above_header' === $popup['options']['placement'];
	}

	/**
	 * Does the popup have newsletter prompt?
	 *
	 * @param object $popup The popup object.
	 * @return boolean True if popup has a newsletter prompt.
	 */
	public static function has_newsletter_prompt( $popup ) {
		return (
			false !== strpos( $popup['content'], 'wp:jetpack/mailchimp' ) ||
			false !== strpos( $popup['content'], 'wp:mailchimp-for-wp/form' )
		);
	}

	/**
	 * Get a unique id.
	 *
	 * @return string Unique id.
	 */
	private static function get_uniqid() {
		return 'c' . substr( uniqid(), 10 );
	}

	/**
	 * Get a shortest possible CSS class name for a form element.
	 *
	 * @param string $type Type of the form.
	 * @param string $element_id The ID of the enclosing element.
	 */
	private static function get_form_class( $type, $element_id ) {
		if ( 'action' === $type ) {
			return $element_id; // Just use the id, since this class will be shared.
		}
		$types      = [ 'dismiss' ];
		$type_index = array_search( $type, $types );
		return $element_id . $type_index; // Use a unique class name.
	}

	/**
	 * Canonise popups id. The id from WP will be an integer, but AMP does not play well with that and needs a string.
	 *
	 * @param int $popup_id Popup id.
	 */
	public static function canonize_popup_id( $popup_id ) {
		return 'id_' . $popup_id;
	}

	/**
	 * Get data-popup-status attribute for use in previews, if viewing as an admin.
	 *
	 * @param object $popup Popup.
	 */
	public static function get_data_status_preview_attrs( $popup ) {
		if ( ! Newspack_Popups::is_user_admin() ) {
			return '';
		}
		$status = 'future' === $popup['status'] ? __( 'scheduled', 'newspack-popups' ) : $popup['status'];
		$status = 'inherit' === $popup['status'] ? __( 'draft', 'newspack-popups' ) : $popup['status']; // Avoid "inherit" status when previewing a single prompt.
		return 'data-popup-status="' . esc_attr( $status ) . '" ';
	}

	/**
	 * Adds a hook to print a hidden field with the current popup ID in forms redendered inside the popup.
	 *
	 * @param array $popup The popup data.
	 * @return void
	 */
	protected static function add_form_hooks( $popup ) {
		self::$form_hooks_popup_id = $popup['id'];
		foreach ( self::$form_hooks as $hook ) {
			add_action( $hook, [ __CLASS__, 'print_form_hidden_fields' ] );
		}
	}

	/**
	 * Removes the hook to print a hidden field with the current popup ID in forms redendered inside the popup.
	 *
	 * @param array $popup The popup data.
	 * @return void
	 */
	protected static function remove_form_hooks( $popup ) {
		self::$form_hooks_popup_id = null;
		foreach ( self::$form_hooks as $hook ) {
			remove_action( $hook, [ __CLASS__, 'print_form_hidden_fields' ] );
		}
	}

	/**
	 * Prints a hidden field with the current popup ID.
	 *
	 * @return void
	 */
	public static function print_form_hidden_fields() {
		?>
			<input
				name="newspack_popup_id"
				type="hidden"
				value="<?php echo esc_attr( self::$form_hooks_popup_id ); ?>"
			/>
			<input
				name="prompt_title"
				type="hidden"
				value="<?php echo esc_attr( get_the_title( self::$form_hooks_popup_id ) ); ?>"
			/>
		<?php
	}

	/**
	 * Get a string representing the prompt's frequency config.
	 *
	 * @param string $popup The popup object.
	 * @return string The frequency config in the following format: n1,n2,n3,s1 where n and s = the following from popup_options:
	 *   - n1: frequency_start
	 *   - n2: frequency_between
	 *   - n3: frequency_max
	 *   - s: frequency_reset ("month", "week", "day")
	 */
	private static function get_frequency_config( $popup ) {
		$frequency   = $popup['options']['frequency'];
		$freq_config = [];

		switch ( $frequency ) {
			case 'custom':
				$freq_config = [ $popup['options']['frequency_start'], $popup['options']['frequency_between'], $popup['options']['frequency_max'], $popup['options']['frequency_reset'] ];
				break;
			case 'once':
				$freq_config = [ 0, 0, 1, 'month' ];
				break;
			case 'weekly':
				$freq_config = [ 0, 0, 1, 'week' ];
				break;
			case 'daily':
				$freq_config = [ 0, 0, 1, 'day' ];
				break;
			case 'always':
				$freq_config = [ 0, 0, 0, 'month' ];
				break;
			default:
				$freq_config = [ 0, 0, 0, 'month' ];
				break;
		}

		return implode( ',', $freq_config );
	}

	/**
	 * Check if the current theme is a block theme.
	 *
	 * @return boolean True if the current theme is a block theme.
	 */
	public static function is_block_theme() {
		if ( null !== self::$block_theme_override ) {
			return self::$block_theme_override;
		}

		return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
	}

	/**
	 * Override block theme detection (intended for tests).
	 *
	 * @param boolean|null $is_block_theme Override value; null resets.
	 */
	public static function set_block_theme_override( $is_block_theme ) {
		self::$block_theme_override = $is_block_theme;
	}

	/**
	 * Generate markup for an inline popup.
	 *
	 * @param string $popup The popup object.
	 * @return string The generated markup.
	 */
	public static function generate_inline_popup( $popup ) {
		global $wp;

		$blocks = parse_blocks( $popup['content'] );
		$body   = '';
		self::add_form_hooks( $popup );
		foreach ( $blocks as $block ) {
			$body .= render_block( $block );
		}
		self::remove_form_hooks( $popup );
		do_action( 'newspack_campaigns_after_campaign_render', $popup );

		$element_id           = self::canonize_popup_id( $popup['id'] );
		$hide_border          = $popup['options']['hide_border'];
		$large_border         = $popup['options']['large_border'];
		$no_padding           = $popup['options']['no_padding'];
		$is_newsletter_prompt = self::has_newsletter_prompt( $popup );
		$classes              = [ 'newspack-popup-container', 'newspack-popup', 'hidden' ];
		$classes[]            = 'above_header' === $popup['options']['placement'] ? 'newspack-above-header-popup' : null;
		$classes[]            = 'above_header' === $popup['options']['placement'] && self::is_block_theme() ? 'has-global-padding' : null;
		$classes[]            = ! self::is_above_header( $popup ) ? 'newspack-inline-popup' : null;
		$classes[]            = 'publish' !== $popup['status'] ? 'newspack-inactive-popup-status' : null;
		$classes[]            = $hide_border ? 'newspack-lightbox-no-border' : null;
		$classes[]            = $large_border ? 'newspack-lightbox-large-border' : null;
		$classes[]            = $no_padding ? 'newspack-lightbox-no-padding' : null;
		$classes[]            = $is_newsletter_prompt ? 'newspack-newsletter-prompt-inline' : null;
		$classes[]            = self::is_block_theme() ? 'is-layout-constrained' : null;
		$classes              = array_merge( $classes, explode( ' ', $popup['options']['additional_classes'] ) );
		$assigned_segments    = Newspack_Segments_Model::get_popup_segments_ids_string( $popup['id'] );
		$frequency_config     = self::get_frequency_config( $popup );
		$utm_suppression      = $popup['options']['utm_suppression'];

		ob_start();
		?>
			<div
				<?php echo self::get_data_status_preview_attrs( $popup ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
				role="button"
				tabindex="0"
				style="<?php echo esc_attr( self::container_style( $popup ) ); ?>"
				id="<?php echo esc_attr( $element_id ); ?>"
				data-segments="<?php echo esc_attr( $assigned_segments ); ?>"
				data-frequency="<?php echo esc_attr( $frequency_config ); ?>"
				<?php if ( ! empty( $utm_suppression ) ) : ?>
					data-suppression="<?php echo esc_attr( $utm_suppression ); ?>"
				<?php endif; ?>
			>
				<?php echo do_shortcode( $body ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		<?php
		self::$current_popup = null;

		/**
		 * Filters the popup content.
		 *
		 * @param string $popup_content The popup content.
		 * @param array  $popup The popup object.
		 * @param string $element_id The popup element ID.
		 */
		return apply_filters( 'newspack_popups_popup_content', ob_get_clean(), $popup, $element_id );
	}

	/**
	 * Return the current popup.
	 *
	 * @return array|null The current popup or null if it's not set.
	 */
	public static function get_current_popup() {
		return self::$current_popup;
	}

	/**
	 * Generate just the scroll-trigger page-position marker for an overlay popup.
	 *
	 * The marker is a `position: absolute; top: X%` element whose position is
	 * computed against its nearest `position: relative` ancestor (typically the
	 * post's `.entry-content`). An IntersectionObserver on this marker is what
	 * reveals the scroll-triggered overlay. The lightbox is portaled to
	 * wp_footer to escape ancestor stacking-context traps, but the marker
	 * stays inline at the article container so its percentage offset still
	 * encodes "scroll progress through the article".
	 *
	 * Returns the empty string for popups that aren't scroll-triggered overlays.
	 *
	 * @param array<string, mixed> $popup A fully-hydrated popup object as returned
	 *                                    by {@see create_popup_object()}.
	 * @return string Marker HTML, or '' when no marker is needed.
	 */
	public static function generate_position_marker( array $popup ): string {
		if ( ! self::is_overlay( $popup ) ) {
			return '';
		}
		$trigger_type = $popup['options']['trigger_type'] ?? '';
		if ( 'scroll' !== $trigger_type ) {
			return '';
		}
		$element_id = self::canonize_popup_id( $popup['id'] );
		$progress   = absint( $popup['options']['trigger_scroll_progress'] ?? 0 );
		return sprintf(
			'<div id="page-position-marker_%1$s" class="page-position-marker" style="position: absolute; top: %2$d%%"></div>',
			esc_attr( $element_id ),
			$progress
		);
	}

	/**
	 * Generate markup and styles for an overlay popup.
	 *
	 * @param array<string, mixed> $popup                    A fully-hydrated popup object.
	 * @param bool                 $include_position_marker  When true (default), the
	 *                                                       returned markup includes the
	 *                                                       scroll-trigger page-position
	 *                                                       marker. Pass false when the
	 *                                                       marker is emitted separately
	 *                                                       at the content position via
	 *                                                       {@see generate_position_marker()},
	 *                                                       so the marker's percentage `top`
	 *                                                       resolves against its
	 *                                                       `position: relative` ancestor
	 *                                                       (typically `.entry-content`)
	 *                                                       rather than against the portaled
	 *                                                       lightbox's footer position.
	 * @return string The generated markup.
	 */
	public static function generate_popup( $popup, bool $include_position_marker = true ) {
		$previewed_popup_id            = Newspack_Popups::previewed_popup_id();
		$is_manual_or_custom_placement = self::is_manual_only( $popup ) || Newspack_Popups_Custom_Placements::is_custom_placement_or_manual( $popup );

		// If previewing a single prompt, override saved settings with preview settings. Allow manual and custom placement prompts to be displayed as usual.
		if ( $previewed_popup_id && ( ! $is_manual_or_custom_placement || $popup['id'] === $previewed_popup_id ) ) {
			$preview_popup = self::retrieve_preview_popup( $previewed_popup_id );
			if ( $preview_popup ) {
				$popup = $preview_popup;
			}
		}
		if ( Newspack_Popups::preset_popup_id() ) {
			$popup = Newspack_Popups_Presets::retrieve_preset_popup( Newspack_Popups::preset_popup_id() );
		}

		self::$current_popup = $popup;

		if ( ! self::is_overlay( $popup ) ) {
			return self::generate_inline_popup( $popup );
		}

		$blocks = parse_blocks( $popup['content'] );
		$body   = '';
		self::add_form_hooks( $popup );
		foreach ( $blocks as $block ) {
			$body .= render_block( $block );
		}
		self::remove_form_hooks( $popup );
		do_action( 'newspack_campaigns_after_campaign_render', $popup );

		$element_id                     = self::canonize_popup_id( $popup['id'] );
		$hide_border                    = $popup['options']['hide_border'];
		$large_border                   = $popup['options']['large_border'];
		$no_padding                     = $popup['options']['no_padding'];
		$overlay_opacity                = absint( $popup['options']['overlay_opacity'] ) / 100;
		$overlay_color                  = $popup['options']['overlay_color'];
		$overlay_size                   = 'full' === $popup['options']['overlay_size'] ? 'full-width' : $popup['options']['overlay_size'];
		$no_overlay_background          = $popup['options']['no_overlay_background'];
		$has_featured_image             = has_post_thumbnail( $popup['id'] ) || ! empty( $popup['options']['featured_image_id'] );
		$enable_close_button_background = $popup['options']['enable_close_button_background'];
		$close_button_background_color  = $enable_close_button_background ? $popup['options']['close_button_background_color'] : '';
		$close_button_color             = $enable_close_button_background ? self::foreground_color_for_background( $popup['options']['close_button_background_color'] ) : self::foreground_color_for_background( $popup['options']['background_color'] );
		$hidden_fields                  = self::get_hidden_fields( $popup );
		$is_newsletter_prompt           = self::has_newsletter_prompt( $popup );
		$classes                        = [ 'newspack-popup-container', 'newspack-lightbox', 'newspack-popup', 'hidden', 'newspack-lightbox-placement-' . $popup['options']['placement'], 'newspack-lightbox-size-' . $overlay_size ];
		$classes[]                      = $hide_border ? 'newspack-lightbox-no-border' : null;
		$classes[]                      = $large_border ? 'newspack-lightbox-large-border' : null;
		$classes[]                      = $no_padding ? 'newspack-lightbox-no-padding' : null;
		$classes[]                      = $is_newsletter_prompt ? 'newspack-newsletter-prompt-overlay' : null;
		$classes[]                      = $no_overlay_background ? 'newspack-lightbox-no-overlay' : null;
		$classes[]                      = $has_featured_image ? 'newspack-lightbox-featured-image' : null;
		$classes[]                      = $enable_close_button_background ? 'newspack-lightbox-close-button-background' : null;
		$classes                        = array_merge( $classes, explode( ' ', $popup['options']['additional_classes'] ) );
		$wrapper_classes                = [ 'newspack-popup-wrapper' ];
		$wrapper_classes[]              = 'publish' !== $popup['status'] ? 'newspack-inactive-popup-status' : null;
		$is_scroll_triggered            = 'scroll' === $popup['options']['trigger_type'];
		$assigned_segments              = Newspack_Segments_Model::get_popup_segments_ids_string( $popup['id'] );
		$frequency_config               = self::get_frequency_config( $popup );
		$utm_suppression                = $popup['options']['utm_suppression'];

		$close_button_styles = 'color: ' . $close_button_color . ';';
		if ( $enable_close_button_background ) {
			$close_button_styles .= 'background-color: ' . $close_button_background_color . ';';
		}

		$animation_id = 'a_' . $element_id;

		ob_start();
		?>
		<div
			<?php echo self::get_data_status_preview_attrs( $popup ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			role="button"
			tabindex="0"
			id="<?php echo esc_attr( $element_id ); ?>"
			data-segments="<?php echo esc_attr( $assigned_segments ); ?>"
			data-frequency="<?php echo esc_attr( $frequency_config ); ?>"
			<?php if ( ! empty( $utm_suppression ) ) : ?>
				data-suppression="<?php echo esc_attr( $utm_suppression ); ?>"
			<?php endif; ?>

			<?php if ( $is_scroll_triggered ) : ?>
			data-scroll="<?php echo esc_attr( $popup['options']['trigger_scroll_progress'] ); ?>"
			<?php else : ?>
			data-delay="<?php echo esc_attr( self::get_delay( $popup ) ); ?>"
			<?php endif; ?>
		>

			<div class="<?php echo esc_attr( implode( ' ', $wrapper_classes ) ); ?>" data-popup-status="<?php echo esc_attr( $popup['status'] ); ?>" style="<?php echo ! ( $hide_border && ! self::is_overlay( $popup ) ) ? esc_attr( self::container_style( $popup ) ) : ''; ?>">
				<div class="newspack-popup__content-wrapper" style="<?php echo $hide_border || $no_padding ? esc_attr( self::container_style( $popup ) ) : ''; ?>">
					<?php if ( $has_featured_image ) : ?>
						<div class="newspack-popup__featured-image">
							<?php echo ! empty( $popup['options']['featured_image_id'] ) ? wp_get_attachment_image( $popup['options']['featured_image_id'], 'large' ) : get_the_post_thumbnail( $popup['id'], 'large' ); ?>
						</div>
					<?php endif; ?>
					<div class="newspack-popup__content <?php echo esc_attr( self::is_block_theme() ? 'is-layout-flow' : '' ); ?>">
						<?php echo do_shortcode( $body ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
					<button class="newspack-lightbox__close" style="<?php echo esc_attr( $close_button_styles ); ?>" aria-label="<?php esc_html_e( 'Close Pop-up', 'newspack-popups' ); // phpcs:ignore WordPressVIPMinimum.Security.ProperEscapingFunction.htmlAttrNotByEscHTML ?>">
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" role="img" aria-hidden="true" focusable="false">
							<path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12 19 6.41z"/>
						</svg>
					</button>
				</div>
			</div>
			<?php if ( ! $no_overlay_background ) : ?>
				<?php if ( Newspack_Popups_Settings::enable_dismiss_overlays_on_background_tap() ) : ?>
					<button style="opacity: <?php echo floatval( $overlay_opacity ); ?>;background-color:<?php echo esc_attr( $overlay_color ); ?>;" class="newspack-lightbox-overlay"></button>
				<?php else : ?>
					<div style="opacity: <?php echo floatval( $overlay_opacity ); ?>;background-color:<?php echo esc_attr( $overlay_color ); ?>;" class="newspack-lightbox-overlay"></div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
		if ( $include_position_marker ) {
			echo self::generate_position_marker( $popup ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
		<?php
		self::$current_popup = null;

		/**
		 * Filters the popup content.
		 *
		 * @param string $popup_content The popup content.
		 * @param array  $popup The popup object.
		 * @param string $element_id The popup element ID.
		 */
		return apply_filters( 'newspack_popups_popup_content', ob_get_clean(), $popup, $element_id );
	}

	/**
	 * Pick either white or black, whatever has sufficient contrast with the color being passed to it.
	 * Copied from https://github.com/Automattic/newspack-theme/blob/trunk/newspack-theme/inc/template-functions.php#L401-L431
	 *
	 * @param  string $background_color Hexidecimal value of the background color.
	 * @return string Either black or white hexidecimal values.
	 *
	 * @ref https://stackoverflow.com/questions/1331591/given-a-background-color-black-or-white-text
	 */
	public static function foreground_color_for_background( $background_color ) {
		// hex RGB.
		$r1 = hexdec( substr( $background_color, 1, 2 ) );
		$g1 = hexdec( substr( $background_color, 3, 2 ) );
		$b1 = hexdec( substr( $background_color, 5, 2 ) );
		// Black RGB.
		$black_color    = '#000';
		$r2_black_color = hexdec( substr( $black_color, 1, 2 ) );
		$g2_black_color = hexdec( substr( $black_color, 3, 2 ) );
		$b2_black_color = hexdec( substr( $black_color, 5, 2 ) );
		// Calc contrast ratio.
		$l1             = 0.2126 * pow( $r1 / 255, 2.2 ) +
			0.7152 * pow( $g1 / 255, 2.2 ) +
			0.0722 * pow( $b1 / 255, 2.2 );
		$l2             = 0.2126 * pow( $r2_black_color / 255, 2.2 ) +
			0.7152 * pow( $g2_black_color / 255, 2.2 ) +
			0.0722 * pow( $b2_black_color / 255, 2.2 );
		$contrast_ratio = 0;
		if ( $l1 > $l2 ) {
			$contrast_ratio = (int) ( ( $l1 + 0.05 ) / ( $l2 + 0.05 ) );
		} else {
			$contrast_ratio = (int) ( ( $l2 + 0.05 ) / ( $l1 + 0.05 ) );
		}
		if ( $contrast_ratio > 5 ) {
			// If contrast is more than 5, return black color.
			return '#000';
		} else {
			// if not, return white color.
			return '#fff';
		}
	}

	/**
	 * Generate inline styles for Popup element.
	 *
	 * @param  object $popup A Pop-up object.
	 * @return string Inline styles attribute.
	 */
	public static function container_style( $popup ) {
		$hide_border      = $popup['options']['hide_border'];
		$background_color = $popup['options']['background_color'];
		$foreground_color = self::foreground_color_for_background( $background_color );
		return 'background-color:' . $background_color . ';color:' . $foreground_color;
	}

	/**
	 * Generate hidden fields to be used in all forms.
	 *
	 * @param  object $popup A Pop-up object.
	 * @return string Hidden fields markup.
	 */
	public static function get_hidden_fields( $popup ) {
		ob_start();
		?>
		<input
			name="popup_id"
			type="hidden"
			value="<?php echo esc_attr( self::canonize_popup_id( $popup['id'] ) ); ?>"
		/>
		<input
			name="mailing_list_status"
			type="hidden"
			[value]="mailing_list_status"
		/>
		<input
			name="is_newsletter_popup"
			type="hidden"
			value="<?php echo esc_attr( self::has_newsletter_prompt( $popup ) ); ?>"
		/>
		<input
			name="dismiss"
			type="hidden"
			value="1"
		/>
		<?php
		return ob_get_clean();
	}
}
