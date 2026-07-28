<?php
/**
 * Newspack Popups API
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * API endpoints
 */
final class Newspack_Popups_API {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_api_endpoints' ] );
		// Whether the site has a prompt to preview changes when content does, and
		// the answer is cached — including the "nothing yet" answer, which is what
		// a site looks like right up until its first prompt is published. With the
		// feature off the transient can never exist, so nothing hooks.
		if ( Newspack_Popups::is_contextual_prompts_enabled() ) {
			add_action( 'save_post', [ __CLASS__, 'flush_styling_preview_cache' ], 10, 2 );
			add_action( 'delete_post', [ __CLASS__, 'flush_styling_preview_cache' ], 10, 2 );
		}
	}

	/**
	 * Drop the remembered styling preview post when a story that could change the
	 * answer is written or removed.
	 *
	 * @param int     $post_id The post id.
	 * @param WP_Post $post    The post.
	 */
	public static function flush_styling_preview_cache( $post_id, $post = null ) {
		$post_type = $post instanceof WP_Post ? $post->post_type : get_post_type( $post_id );
		if ( in_array( $post_type, [ 'post', 'page' ], true ) ) {
			delete_transient( self::PREVIEW_POST_TRANSIENT );
		}
	}

	/**
	 * Register REST API endpoints.
	 */
	public function register_api_endpoints() {
		\register_rest_route(
			'newspack-popups/v1',
			'settings',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'update_settings_standalone' ],
				'permission_callback' => [ $this, 'permission_callback' ],
				'args'                => [
					'settingsToUpdate' => [
						'validate_callback' => [ __CLASS__, 'validate_settings' ],
						'sanitize_callback' => [ __CLASS__, 'sanitize_array' ],
					],
				],
			]
		);

		\register_rest_route(
			'newspack-popups/v1',
			'prompts',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_inline_and_manual_prompts' ],
				'permission_callback' => [ $this, 'permission_callback' ],
				'args'                => [
					'search'   => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'_fields'  => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'include'  => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'per_page' => [
						'type'              => 'integer',
						'default'           => 10,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		\register_rest_route(
			'newspack-popups/v1',
			'/(?P<original_id>\d+)/(?P<id>\d+)/duplicate',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_duplicate_title' ],
				'permission_callback' => [ $this, 'permission_callback' ],
				'args'                => [
					'original_id' => [
						'sanitize_callback' => 'absint',
					],
					'id'          => [
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		\register_rest_route(
			'newspack-popups/v1',
			'/(?P<id>\d+)/duplicate',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_duplicate_popup' ],
				'permission_callback' => [ $this, 'permission_callback' ],
				'args'                => [
					'id'    => [
						'sanitize_callback' => 'absint',
					],
					'title' => [
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// API endpoints for RAS presets.
		register_rest_route(
			'newspack-popups/v1',
			'/audience-management/campaign',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_reader_activation_campaign_settings' ],
				'permission_callback' => [ $this, 'permission_callback' ],
			]
		);
		register_rest_route(
			'newspack-popups/v1',
			'/audience-management/campaign',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_reader_activation_campaign_settings' ],
				'permission_callback' => [ $this, 'permission_callback' ],
			]
		);
		if ( Newspack_Popups::is_contextual_prompts_enabled() ) {
			register_rest_route(
				'newspack-popups/v1',
				'/contextual-prompt/status',
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'api_get_contextual_prompt_status' ],
					'permission_callback' => function () {
						return current_user_can( 'edit_posts' );
					},
				]
			);
			register_rest_route(
				'newspack-popups/v1',
				'/contextual-prompt/enable',
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					// Opting into AI use is an administrator decision.
					'callback'            => [ __CLASS__, 'api_set_contextual_prompt_enabled' ],
					'permission_callback' => [ $this, 'permission_callback' ],
					'args'                => [
						'enabled' => [
							'required'          => true,
							'sanitize_callback' => 'rest_sanitize_boolean',
						],
					],
				]
			);
			register_rest_route(
				'newspack-popups/v1',
				'/contextual-prompt/profile',
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ __CLASS__, 'api_save_contextual_prompt_profile' ],
					'permission_callback' => [ $this, 'permission_callback' ],
					'args'                => [
						'fields' => [
							'required' => true,
							'type'     => 'object',
						],
						'styles' => [
							'required' => false,
							'type'     => 'object',
						],
					],
				]
			);
		}
	}

	/**
	 * Report whether Contextual Prompts is opted into, and whether the current
	 * user is allowed to change that.
	 *
	 * @return WP_REST_Response
	 */
	public static function api_get_contextual_prompt_status() {
		return rest_ensure_response( self::contextual_prompt_status() );
	}

	/**
	 * The Contextual Prompts status payload: opt-in state, whether the user can
	 * manage it, the publisher-profile fields, and the block's style data. The
	 * route is open to `edit_posts`, but the profile and style data is wizard
	 * configuration only an administrator may see: anyone else gets the opt-in
	 * state alone.
	 *
	 * @return array
	 */
	private static function contextual_prompt_status() {
		$can_manage = current_user_can( 'manage_options' );
		if ( ! $can_manage ) {
			return [
				'enabled'    => Newspack_Popups_Settings::is_ai_copy_assistant_enabled(),
				'can_manage' => false,
			];
		}
		// Styles are a keyed object to every client, so no overrides has to travel
		// as `{}`: an empty PHP array would serialize as `[]` and a client
		// comparing it against an object it built would never see them as equal.
		$styles = Newspack_Popups_Contextual_Prompt_Styles::get_styles();
		// Font sizes take the highest origin holding anything, which is how the
		// editor's picker resolves the single set it offers; spacing sizes are one
		// scale built from every origin, as core's own spacing control builds it.
		$font_sizes    = self::flatten_global_settings_presets( self::get_style_setting( [ 'typography', 'fontSizes' ] ) );
		$spacing_sizes = self::get_spacing_size_presets();
		return [
			'enabled'                => Newspack_Popups_Settings::is_ai_copy_assistant_enabled(),
			'can_manage'             => $can_manage,
			'fields'                 => Newspack_Popups_Settings::get_ai_copy_assistant_fields(),
			'override_active'        => Newspack_Popups_Settings::is_override_active(),
			'is_block_theme'         => wp_is_block_theme(),
			'styles'                 => empty( $styles ) ? (object) [] : $styles,
			'style_defaults'         => Newspack_Popups_Contextual_Prompt_Styles::get_defaults(),
			'style_palette'          => self::get_palette_presets(),
			'style_font_sizes'       => self::normalize_preset_sizes( $font_sizes ),
			'style_spacing_sizes'    => self::normalize_preset_sizes( $spacing_sizes ),
			'style_spacing_custom'   => self::is_custom_spacing_size_allowed(),
			'style_spacing_units'    => self::get_spacing_units(),
			'site_editor_styles_url' => self::get_site_editor_styles_url(),
		];
	}

	/**
	 * Transient holding the styling preview post id, or 0 when the site has no
	 * prompt to preview. Content-wide LIKE searches are not indexed, so the
	 * answer is remembered rather than recomputed on every wizard load.
	 */
	const PREVIEW_POST_TRANSIENT = 'newspack_contextual_prompt_preview_post';

	/**
	 * The block-theme style handoff: the Site Editor, opened on the Contextual
	 * Prompt block's own styles section.
	 *
	 * Where the site already has a prompt, the canvas is pointed at that story so
	 * the publisher styles the block while looking at a real one. Otherwise the
	 * canvas keeps its default, which is the homepage. Only block themes take the
	 * handoff, so the lookup never runs on a classic theme.
	 *
	 * @return string
	 */
	private static function get_site_editor_styles_url() {
		$url = 'site-editor.php?p=%2Fstyles&section=' . rawurlencode( '/blocks/' . rawurlencode( Newspack_Popups_Contextual_Prompt_Block::BLOCK_NAME ) );

		if ( wp_is_block_theme() ) {
			$preview_id = self::get_styling_preview_post_id();
			if ( $preview_id ) {
				$url .= '&postType=' . rawurlencode( (string) get_post_type( $preview_id ) ) . '&postId=' . $preview_id;
			}
		}

		return admin_url( $url );
	}

	/**
	 * How many prompt-bearing stories to examine before giving up and previewing
	 * the homepage. A publisher whose whole recent run of prompts is restyled is
	 * previewing an unrepresentative card either way, so the search is bounded
	 * rather than walking the archive.
	 */
	const PREVIEW_CANDIDATE_LIMIT = 20;

	/**
	 * Block attributes that set, at instance level, a property the block's global
	 * styles also set. Any of them means the instance wins over Styles > Blocks,
	 * so the canvas would not move when those controls do.
	 */
	const PREVIEW_STYLING_ATTRIBUTES = [
		'style',
		'className',
		'backgroundColor',
		'textColor',
		'gradient',
		'fontSize',
		'fontFamily',
		'borderColor',
	];

	/**
	 * The newest published story carrying an unstyled Contextual Prompt, for the
	 * Site Editor canvas to render while the block's styles are edited.
	 *
	 * Unstyled matters: a per-instance override beats the block's global styles,
	 * so previewing a restyled prompt would leave the Styles > Blocks controls
	 * looking broken, moving nothing on screen. Rather than the newest prompt,
	 * this is the newest prompt that will actually answer those controls.
	 *
	 * Limited to `post` and `page`: the Site Editor drives its canvas from the
	 * built-in types alone, and silently falls back to the homepage for anything
	 * else. A remembered id is re-checked before it is trusted, so a story that
	 * has since been deleted or unpublished sends the lookup around again.
	 *
	 * @return int Post id, or 0 when the site has no prompt worth previewing.
	 */
	private static function get_styling_preview_post_id() {
		$cached = get_transient( self::PREVIEW_POST_TRANSIENT );
		if ( false !== $cached ) {
			$cached = (int) $cached;
			if ( ! $cached || 'publish' === get_post_status( $cached ) ) {
				return $cached;
			}
		}

		$post_types = array_values( array_intersect( Newspack_Popups_Model::get_default_popup_post_types(), [ 'post', 'page' ] ) );
		$preview_id = 0;

		if ( $post_types ) {
			$query = new WP_Query(
				[
					'post_type'              => $post_types,
					'post_status'            => 'publish',
					// The block's own delimiter, trailing space included, so the
					// match is the serialized block itself rather than a mention of
					// it in prose or a longer block name sharing the prefix.
					's'                      => '<!-- wp:' . Newspack_Popups_Contextual_Prompt_Block::BLOCK_NAME . ' ',
					'sentence'               => true,
					// Block delimiters are HTML comments: they live in post_content
					// and are stripped from search indexes, so this has to be a
					// plain content match in the database.
					'search_columns'         => [ 'post_content' ],
					'ep_integrate'           => false,
					'posts_per_page'         => self::PREVIEW_CANDIDATE_LIMIT,
					'orderby'                => 'date',
					'order'                  => 'DESC',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				]
			);

			foreach ( $query->posts as $candidate_id ) {
				$candidate = get_post( $candidate_id );
				if ( $candidate && self::has_unstyled_prompt( $candidate->post_content ) ) {
					$preview_id = (int) $candidate_id;
					break;
				}
			}
		}

		set_transient( self::PREVIEW_POST_TRANSIENT, $preview_id, HOUR_IN_SECONDS );
		return $preview_id;
	}

	/**
	 * Whether a story's prompt still answers the block's global styles.
	 *
	 * The copy paragraph counts as well as the card: typography and text colour
	 * reach the copy by inheritance, so a paragraph carrying its own would hide
	 * a global change just as the card's own background would. The CTA child is
	 * deliberately not examined, since a donate block always carries its
	 * configuration and judging that as styling would reject every prompt.
	 *
	 * @param string $content The story's content.
	 * @return bool
	 */
	private static function has_unstyled_prompt( $content ) {
		return self::find_unstyled_prompt( parse_blocks( $content ) );
	}

	/**
	 * Walk a block tree for an unstyled prompt. Recursive because a prompt sitting
	 * inside a group or columns still renders, and still answers global styles.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return bool
	 */
	private static function find_unstyled_prompt( $blocks ) {
		foreach ( $blocks as $block ) {
			if ( Newspack_Popups_Contextual_Prompt_Block::BLOCK_NAME === ( $block['blockName'] ?? '' ) ) {
				if ( self::is_prompt_unstyled( $block ) ) {
					return true;
				}
				continue;
			}
			if ( ! empty( $block['innerBlocks'] ) && self::find_unstyled_prompt( $block['innerBlocks'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a prompt and its copy are both free of instance-level styling.
	 *
	 * @param array $prompt A parsed prompt block.
	 * @return bool
	 */
	private static function is_prompt_unstyled( $prompt ) {
		if ( self::is_styled( $prompt ) ) {
			return false;
		}
		foreach ( $prompt['innerBlocks'] ?? [] as $child ) {
			if ( 'core/paragraph' === ( $child['blockName'] ?? '' ) && self::is_styled( $child ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a parsed block carries any instance-level styling.
	 *
	 * @param array $block A parsed block.
	 * @return bool
	 */
	private static function is_styled( $block ) {
		foreach ( self::PREVIEW_STYLING_ATTRIBUTES as $attribute ) {
			if ( ! empty( $block['attrs'][ $attribute ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A style setting resolved the way the editor resolves it for a block: the
	 * block's own `settings.blocks.<block>` value when it has one, the global value
	 * otherwise. Core's `wp_get_global_settings()` does not do that fallback — given
	 * a block context it reads the block path alone, and a missing path hands back
	 * the whole settings tree — so the block path is read from the settings tree and
	 * only stands in when it is set. Block settings keep the same origin-keyed preset
	 * shape as global ones, so the flatten/merge helpers read either.
	 *
	 * @param array $path Settings path, e.g. `[ 'color', 'palette' ]`.
	 * @return mixed The setting, or whatever the global lookup hands back.
	 */
	private static function get_style_setting( $path ) {
		$block_path  = array_merge( [ 'blocks', Newspack_Popups_Contextual_Prompt_Block::BLOCK_NAME ], $path );
		$block_value = self::settings_path_value( wp_get_global_settings(), $block_path );
		// A block setting of `false` is a setting, as the editor treats it: only an
		// unset one falls back to the global scope.
		return null === $block_value ? wp_get_global_settings( $path ) : $block_value;
	}

	/**
	 * The value a settings tree holds at a path, or null when the path is not there.
	 * `wp_get_global_settings()` hands the whole tree back for a missing path, which
	 * cannot answer whether the path is set at all; this can.
	 *
	 * @param mixed $settings Settings tree.
	 * @param array $path     Settings path.
	 * @return mixed|null The value, or null when the path is unset.
	 */
	private static function settings_path_value( $settings, $path ) {
		foreach ( $path as $key ) {
			if ( ! is_array( $settings ) || ! isset( $settings[ $key ] ) ) {
				return null;
			}
			$settings = $settings[ $key ];
		}
		return $settings;
	}

	/**
	 * The color presets the wizard's pickers offer, merged across origins. Core's
	 * `color.defaultPalette` setting decides whether the editor shows the default
	 * origin at all — it is false whenever a classic theme registers an
	 * `editor-color-palette`, as newspack-theme does — so the wizard drops that
	 * origin when the editor would, rather than offering colors the editor does not.
	 *
	 * @return array Flat preset list.
	 */
	private static function get_palette_presets() {
		$palette = self::get_style_setting( [ 'color', 'palette' ] );
		// A missing settings path hands back the whole settings tree, so an absent
		// `defaultPalette` reads as truthy and the defaults stay, as core's own
		// default for the setting has them.
		if ( is_array( $palette ) && isset( $palette['default'] ) && ! self::get_style_setting( [ 'color', 'defaultPalette' ] ) ) {
			unset( $palette['default'] );
		}
		return self::merge_global_settings_presets( $palette );
	}

	/**
	 * The spacing presets the wizard's padding rows step through. Core's
	 * `SpacingSizesControl` builds one scale out of the custom, theme and default
	 * origins rather than picking one, and `spacing.defaultSpacingSizes` decides
	 * whether the default origin belongs in it — core turns that off for a theme
	 * registering its own scale — so the wizard offers the steps the editor offers.
	 *
	 * @return array Flat preset list.
	 */
	private static function get_spacing_size_presets() {
		$sizes = self::get_style_setting( [ 'spacing', 'spacingSizes' ] );
		// As with the palette, a missing settings path hands back the whole settings
		// tree, so an absent `defaultSpacingSizes` reads as truthy and the defaults
		// stay, as core's own default for the setting has them.
		if ( is_array( $sizes ) && isset( $sizes['default'] ) && ! self::get_style_setting( [ 'spacing', 'defaultSpacingSizes' ] ) ) {
			unset( $sizes['default'] );
		}
		return self::sort_spacing_size_presets( self::merge_global_settings_presets( $sizes ) );
	}

	/**
	 * Whether the padding rows may offer a custom value at all. `spacing.customSpacingSize`
	 * is the setting core turns into the editor's `disableCustomSpacingSizes`, which
	 * leaves its spacing rows preset-only, so the wizard's rows honor the same policy.
	 *
	 * @return bool
	 */
	private static function is_custom_spacing_size_allowed() {
		// As with the palette, a missing settings path hands back the whole settings
		// tree, so an absent `customSpacingSize` reads as truthy and custom values
		// stay, as core's own default for the setting allows them.
		return (bool) self::get_style_setting( [ 'spacing', 'customSpacingSize' ] );
	}

	/**
	 * The units a custom padding value may be given in. `spacing.units` is what
	 * core's own spacing control filters its unit list by, and a classic theme
	 * declaring `custom-units` support narrows it, so the wizard offers no unit the
	 * editor would refuse.
	 *
	 * @return array List of unit strings.
	 */
	private static function get_spacing_units() {
		$units = self::get_style_setting( [ 'spacing', 'units' ] );
		// A missing settings path hands back the whole settings tree, so anything but
		// a flat list falls back to core's own default for the setting.
		if ( ! is_array( $units ) || ! wp_is_numeric_array( $units ) ) {
			return [ 'px', 'em', 'rem', 'vh', 'vw', '%' ];
		}
		return array_values( array_filter( $units, 'is_string' ) );
	}

	/**
	 * Spacing presets in the order core's control shows them: by slug, compared as
	 * numbers, and only while every slug starts with a digit — core leaves a scale
	 * holding a named step in its origin order rather than sorting it. The step core
	 * adds for no padding carries the `0` slug, so leaving it out of the list asks
	 * the same question of it.
	 *
	 * Public so the ordering can be exercised directly in tests.
	 *
	 * @param array $presets Flat spacing size presets.
	 * @return array
	 */
	public static function sort_spacing_size_presets( $presets ) {
		foreach ( $presets as $preset ) {
			$slug = is_array( $preset ) && isset( $preset['slug'] ) ? (string) $preset['slug'] : '';
			if ( ! preg_match( '/^[0-9]/', $slug ) ) {
				return $presets;
			}
		}
		// usort is stable, so two slugs comparing equal keep their origin order.
		usort(
			$presets,
			function ( $a, $b ) {
				return strnatcmp( (string) $a['slug'], (string) $b['slug'] );
			}
		);
		return $presets;
	}

	/**
	 * The preset lists that hold something, highest precedence first. Global
	 * settings key presets by origin (custom > theme > default), each origin holding
	 * its own list; an already-flat list stands in as its own single origin. Neither
	 * shape means it is not a preset list at all — a missing settings path hands back
	 * the whole settings tree — so it offers nothing.
	 *
	 * @param mixed $presets Origin-keyed presets, or already-flat array.
	 * @return array List of preset lists, empty when there is nothing to offer.
	 */
	private static function preset_origin_lists( $presets ) {
		if ( ! is_array( $presets ) ) {
			return [];
		}
		// Not origin-keyed: already a flat preset list.
		if ( wp_is_numeric_array( $presets ) ) {
			return empty( $presets ) ? [] : [ array_values( $presets ) ];
		}
		$lists = [];
		foreach ( [ 'custom', 'theme', 'default' ] as $origin ) {
			if ( ! empty( $presets[ $origin ] ) && is_array( $presets[ $origin ] ) ) {
				$lists[] = array_values( $presets[ $origin ] );
			}
		}
		return $lists;
	}

	/**
	 * One flat preset list, taking the highest origin that holds anything. This is
	 * how the editor resolves a preset list it offers as a single set — font sizes,
	 * where `custom ?? theme ?? default` decides — so the wizard offers the same
	 * sizes its picker does.
	 *
	 * Public so the flattening can be exercised directly in tests.
	 *
	 * @param mixed $presets Origin-keyed presets, or already-flat array.
	 * @return array Flat preset list, empty when there is nothing to offer.
	 */
	public static function flatten_global_settings_presets( $presets ) {
		$lists = self::preset_origin_lists( $presets );
		return empty( $lists ) ? [] : $lists[0];
	}

	/**
	 * One flat preset list holding every origin, custom beating theme beating
	 * default on a shared slug. The editor's color panel shows each origin as its
	 * own section rather than picking one, and every origin gets a CSS custom
	 * property, so dropping the lower ones would hide usable colors.
	 *
	 * Public so the merge can be exercised directly in tests.
	 *
	 * @param mixed $presets Origin-keyed presets, or already-flat array.
	 * @return array Flat preset list, empty when there is nothing to offer.
	 */
	public static function merge_global_settings_presets( $presets ) {
		$flat  = [];
		$slugs = [];
		foreach ( self::preset_origin_lists( $presets ) as $list ) {
			foreach ( $list as $preset ) {
				if ( ! is_array( $preset ) ) {
					continue;
				}
				$slug = isset( $preset['slug'] ) ? (string) $preset['slug'] : '';
				if ( '' !== $slug ) {
					if ( isset( $slugs[ $slug ] ) ) {
						continue;
					}
					$slugs[ $slug ] = true;
				}
				$flat[] = $preset;
			}
		}
		return $flat;
	}

	/**
	 * Size presets can carry a plain number: core unit-izes the font sizes a theme
	 * registers with `add_theme_support( 'editor-font-sizes' )`, but not the ones a
	 * theme.json declares. The wizard stores CSS strings and the style sanitizer
	 * keeps string leaves only, so a numeric size would be offered in a control and
	 * dropped on save. Deliver every size in px, as core's own back-compat does.
	 *
	 * @param array $presets Flat font size or spacing size presets.
	 * @return array
	 */
	private static function normalize_preset_sizes( $presets ) {
		foreach ( $presets as $index => $preset ) {
			if ( is_array( $preset ) && isset( $preset['size'] ) && is_numeric( $preset['size'] ) ) {
				$presets[ $index ]['size'] = $preset['size'] . 'px';
			}
		}
		return $presets;
	}

	/**
	 * Save the Contextual Prompts publisher profile. Administrator-only.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function api_save_contextual_prompt_profile( $request ) {
		$enabled = self::require_contextual_prompts_enabled();
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}

		// Validate the whole payload before any option write: a part that fails
		// must not leave earlier fields already persisted.
		$styles = null;
		if ( isset( $request['styles'] ) ) {
			$styles = Newspack_Popups_Contextual_Prompt_Styles::validate( (array) $request['styles'] );
			if ( is_wp_error( $styles ) ) {
				return $styles;
			}
		}

		Newspack_Popups_Settings::save_ai_copy_assistant_fields( (array) $request['fields'] );
		if ( null !== $styles ) {
			Newspack_Popups_Contextual_Prompt_Styles::save_styles( $styles );
		}
		return rest_ensure_response( self::contextual_prompt_status() );
	}

	/**
	 * Guard for endpoints that must stay inert until an administrator opts the site
	 * into AI use. Some newsrooms are contractually barred from using AI, so the
	 * feature reads and writes nothing before opt-in — see NPPD-2095. The opt-in
	 * status/enable endpoints are deliberately exempt; they are how opt-in happens.
	 *
	 * @return true|\WP_Error True when enabled, WP_Error otherwise.
	 */
	private static function require_contextual_prompts_enabled() {
		if ( Newspack_Popups_Settings::is_ai_copy_assistant_enabled() ) {
			return true;
		}

		return new \WP_Error(
			'newspack_contextual_prompts_disabled',
			esc_html__( 'Contextual Prompts is not enabled for this site.', 'newspack-popups' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Opt this site into (or out of) Contextual Prompts. Administrator-only.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function api_set_contextual_prompt_enabled( $request ) {
		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, (bool) $request['enabled'] );
		return rest_ensure_response( self::contextual_prompt_status() );
	}

	/**
	 * Recursively sanitize an array of arbitrary values.
	 *
	 * @param array $array Array to be sanitized.
	 * @return array Sanitized array.
	 */
	public static function sanitize_array( $array ) {
		foreach ( $array as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = self::sanitize_array( $value );
			} elseif ( is_string( $value ) ) {
					$value = sanitize_text_field( $value );
			} elseif ( is_numeric( $value ) ) {
				$value = intval( $value );
			} else {
				$value = boolval( $value );
			}

			$array[ $key ] = $value;
		}

		return $array;
	}

	/**
	 * Validate settings to be updated.
	 *
	 * @param String $settings_to_update Associative array of settings to be updated.
	 */
	public static function validate_settings( $settings_to_update ) {
		$valid = true;

		foreach ( $settings_to_update as $key => $value ) {
			if ( ! self::validate_settings_option_name( $key ) ) {
				$valid = false;
			}
		}

		return $valid;
	}

	/**
	 * Validate settings option key.
	 *
	 * @param String $key Meta key.
	 */
	public static function validate_settings_option_name( $key ) {
		return in_array(
			$key,
			array_map(
				function ( $setting ) {
					return $setting['key'];
				},
				\Newspack_Popups_Settings::get_settings()
			)
		);
	}

	/**
	 * Permission callback for authenticated requests.
	 *
	 * @return boolean if user can edit stuff.
	 */
	public static function permission_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'newspack_rest_forbidden',
				esc_html__( 'You cannot use this resource.', 'newspack' ),
				[
					'status' => 403,
				]
			);
		}
		return true;
	}

	/**
	 * Handler for API settings update endpoint.
	 * This endpoint is used by the standlone Settings page, which
	 * is only used if the main Newspack plugin UI isn't available.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public static function update_settings_standalone( $request ) {
		$settings_to_update = $request['settingsToUpdate'];

		foreach ( $settings_to_update as $key => $value ) {
			$result = \Newspack_Popups_Settings::set_settings_standalone(
				[
					'option_value' => $value,
					'option_name'  => $key,
				]
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return \rest_ensure_response( \Newspack_Popups_Settings::get_settings() );
	}

	/**
	 * Get inline prompts with the given params.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public static function get_inline_and_manual_prompts( $request ) {
		$params   = $request->get_params();
		$search   = ! empty( $params['search'] ) ? $params['search'] : null;
		$include  = ! empty( $params['include'] ) ? explode( ',', $params['include'] ) : null;
		$per_page = ! empty( $params['per_page'] ) ? $params['per_page'] : 10;

		// Query args.
		$args = [
			'post_type'      => Newspack_Popups::NEWSPACK_POPUPS_CPT,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'meta_key'       => 'placement',
			'meta_compare'   => 'IN',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'meta_value'     => [ 'inline', 'manual' ],
		];

		// Look up by title only if provided with a search term and not post IDs.
		if ( ! empty( $search ) && empty( $include ) ) {
			$args['s'] = esc_sql( $search );
		}

		// If given post IDs to include, just get those.
		if ( ! empty( $include ) && count( $include ) && empty( $search ) ) {
			$args['post__in'] = $include;
			$args['orderby']  = 'post__in';
			$args['order']    = 'ASC';
		}

		$query = new \WP_Query( $args );

		if ( $query->have_posts() ) {
			return new \WP_REST_Response(
				array_map(
					function( $post ) {
						$item = [
							'id'      => $post->ID,
							'title'   => $post->post_title,
							'content' => apply_filters( 'the_content', $post->post_content ),
						];

						return $item;
					},
					$query->posts
				),
				200
			);
		}

		return new \WP_REST_Response( [] );
	}

	/**
	 * Get default title for a duplicated prompt.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response with complete info to render the Engagement Wizard.
	 */
	public function api_get_duplicate_title( $request ) {
		$response = Newspack_Popups::get_duplicate_title( $request['original_id'], $request['id'] );
		return rest_ensure_response( $response );
	}

	/**
	 * Duplicate a prompt.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response with complete info to render the Engagement Wizard.
	 */
	public function api_duplicate_popup( $request ) {
		$response = Newspack_Popups::duplicate_popup( $request['id'], $request['title'] );
		return rest_ensure_response( $response );
	}

	/**
	 * Get reader activation campaign settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function api_get_reader_activation_campaign_settings( $request ) {
		$response = Newspack_Popups_Presets::get_ras_presets();

		if ( \is_wp_error( $response ) ) {
			return new \WP_REST_Response( [ 'message' => $response->get_error_message() ], 400 );
		}

		return rest_ensure_response( $response['prompts'] );
	}

	/**
	 * Update reader activation campaign settings.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function api_update_reader_activation_campaign_settings( $request ) {
		$slug = $request['slug'];
		$data = $request['data'];

		$response = Newspack_Popups_Presets::update_preset_prompt( $slug, $data );

		if ( \is_wp_error( $response ) ) {
			return new \WP_REST_Response( [ 'message' => $response->get_error_message() ], 400 );
		}

		return rest_ensure_response( $response['prompts'] );
	}
}
$newspack_popups_api = new Newspack_Popups_API();
