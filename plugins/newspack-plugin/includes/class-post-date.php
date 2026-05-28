<?php
/**
 * Post Date features: time-ago and modified date.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Post Date class.
 */
class Post_Date {

	/**
	 * Default number of days for the time-ago cutoff.
	 * Posts older than this show the full date instead.
	 *
	 * @var int
	 */
	const DEFAULT_TIME_AGO_CUTOFF_DAYS = 14;

	/**
	 * Default time-ago cutoff in days when the updated date feature is enabled.
	 *
	 * @var int
	 */
	const DEFAULT_UPDATED_DATE_TIME_AGO_CUTOFF_DAYS = 1;

	/**
	 * Theme mod keys to migrate on theme switch.
	 *
	 * @var string[]
	 */
	const THEME_MOD_KEYS = [
		'post_time_ago',
		'post_time_ago_cut_off',
		'post_updated_date',
		'post_updated_date_threshold',
	];

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'after_switch_theme', [ __CLASS__, 'migrate_date_settings' ], 10, 2 );
		add_filter( 'render_block_core/post-date', [ __CLASS__, 'filter_post_date_block' ], 10, 2 );
		add_filter( 'get_the_date', [ __CLASS__, 'filter_get_the_date' ], 10, 3 );
		add_filter( 'newspack_blocks_formatted_displayed_post_date', [ __CLASS__, 'filter_blocks_formatted_date' ], 10, 2 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_editor_assets' ] );
		add_action( 'newspack_theme_posted_on', [ __CLASS__, 'render_updated_date_classic' ] );
		add_filter( 'body_class', [ __CLASS__, 'add_body_show_updated' ] );
		add_filter( 'newspack_theme_include_hidden_updated_time', [ __CLASS__, 'suppress_theme_hidden_updated_time' ] );
	}

	/**
	 * Convert a date string to "X ago" format if within cutoff.
	 *
	 * @param string $date_string Date in 'Y-m-d H:i:s' format (GMT).
	 * @param int    $cutoff_days Number of days for cutoff.
	 * @return string|null Relative date string or null if beyond cutoff.
	 */
	public static function convert_to_time_ago( $date_string, $cutoff_days ) {
		$timestamp = strtotime( $date_string );
		if ( false === $timestamp ) {
			return null;
		}
		$now    = time();
		$diff   = $now - $timestamp;
		$cutoff = $cutoff_days * DAY_IN_SECONDS;

		if ( $diff < 0 || $diff >= $cutoff ) {
			return null;
		}

		/* translators: %s: Human-readable time difference. */
		return sprintf( __( '%s ago', 'newspack-plugin' ), human_time_diff( $timestamp, $now ) );
	}

	/**
	 * Determine whether the updated date should be displayed for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function should_display_updated_date( $post_id = 0 ) {
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		/** This filter is documented in includes/class-post-date.php */
		$post_types = apply_filters( 'newspack_updated_date_supported_post_types', [ 'post' ] );
		if ( ! in_array( $post->post_type, $post_types, true ) ) {
			return false;
		}

		$sitewide  = get_theme_mod( 'post_updated_date', false );
		$show_meta = get_post_meta( $post_id, 'newspack_show_updated_date', true );
		$hide_meta = get_post_meta( $post_id, 'newspack_hide_updated_date', true );

		// Per-post show override bypasses threshold (matches classic theme behavior).
		if ( ! $sitewide && $show_meta ) {
			return true;
		}

		if ( $sitewide ) {
			if ( $hide_meta ) {
				return false;
			}
		} else {
			return false;
		}

		// Apply threshold.
		$threshold = (int) get_theme_mod( 'post_updated_date_threshold', 24 );
		$published = strtotime( $post->post_date_gmt );
		$modified  = strtotime( $post->post_modified_gmt );

		if ( ( $modified - $published ) < $threshold * HOUR_IN_SECONDS ) {
			return false;
		}

		return true;
	}

	/**
	 * Get the effective time-ago cutoff in days. Reduced to 1 day when the
	 * updated date feature is also enabled.
	 *
	 * @return int Cutoff in days.
	 */
	public static function get_time_ago_cutoff_days() {
		$cutoff = get_theme_mod( 'post_updated_date', false )
			? self::DEFAULT_UPDATED_DATE_TIME_AGO_CUTOFF_DAYS
			: (int) get_theme_mod( 'post_time_ago_cut_off', self::DEFAULT_TIME_AGO_CUTOFF_DAYS );

		return (int) apply_filters( 'newspack_time_ago_cutoff_days', $cutoff );
	}

	/**
	 * Replace the text content of a <time> tag, preserving any <a> wrapper.
	 *
	 * @param string $block_content Block HTML containing a <time> tag.
	 * @param string $new_text      Replacement text (should already be escaped).
	 * @return string Modified block content, or original if no <time> tag found.
	 */
	private static function replace_time_text( $block_content, $new_text ) {
		return preg_replace_callback(
			'/(<time[^>]*>)(.*?)(<\/time>)/s',
			function ( $matches ) use ( $new_text ) {
				// Preserve <a> wrapper when isLink is enabled.
				if ( preg_match( '/^(\s*<a\s[^>]*>).*?(<\/a>\s*)$/s', $matches[2], $link ) ) {
					return $matches[1] . $link[1] . $new_text . $link[2] . $matches[3];
				}
				return $matches[1] . $new_text . $matches[3];
			},
			$block_content,
			1
		) ?? $block_content;
	}

	/**
	 * Apply time-ago conversion to block content if enabled and within cutoff.
	 *
	 * @param string $block_content Rendered block content containing a <time> tag.
	 * @return string Block content with time-ago applied, or unchanged.
	 */
	private static function maybe_apply_time_ago_to_block( $block_content ) {
		if ( ! get_theme_mod( 'post_time_ago', false ) ) {
			return $block_content;
		}

		if ( ! preg_match( '/datetime="([^"]+)"/', $block_content, $matches ) ) {
			return $block_content;
		}

		$cutoff_days = self::get_time_ago_cutoff_days();
		$gmt_date    = gmdate( 'Y-m-d H:i:s', strtotime( $matches[1] ) );
		$time_ago    = self::convert_to_time_ago( $gmt_date, $cutoff_days );

		if ( null === $time_ago ) {
			return $block_content;
		}

		return self::replace_time_text( $block_content, esc_html( $time_ago ) );
	}

	/**
	 * Filter the render output of core/post-date blocks.
	 * Handles time-ago conversion, modified date visibility, and "Updated" label.
	 *
	 * @param string $block_content Rendered block content.
	 * @param array  $block         Block data.
	 * @return string Filtered block content.
	 */
	public static function filter_post_date_block( $block_content, $block ) {
		// Detect modified date blocks via CSS class or block bindings attributes.
		$is_modified = str_contains( $block_content, 'wp-block-post-date__modified-date' )
			|| ( isset( $block['attrs']['metadata']['bindings']['datetime']['args']['field'] )
				&& 'modified' === $block['attrs']['metadata']['bindings']['datetime']['args']['field'] )
			|| ( isset( $block['attrs']['displayType'] ) && 'modified' === $block['attrs']['displayType'] );

		// Handle modified date visibility.
		if ( $is_modified ) {
			$post_id = get_the_ID();
			if ( ! self::should_display_updated_date( $post_id ) ) {
				return '';
			}
			if ( empty( $block_content ) ) {
				return '';
			}

			$block_content = self::maybe_apply_time_ago_to_block( $block_content );

			// Wrap the date text with a translatable "Updated %s" label.
			$date_text = wp_strip_all_tags( preg_match( '/(<time[^>]*>)(.*?)(<\/time>)/s', $block_content, $m ) ? $m[2] : '' );
			/* translators: %s: Modified date. */
			$label = sprintf( esc_html__( 'Updated %s', 'newspack-plugin' ), $date_text );
			$block_content = self::replace_time_text( $block_content, $label );

			// Mark as modified so the relative-time JS can skip text replacement,
			// even when core omits the wp-block-post-date__modified-date class (block bindings path).
			$processor = new \WP_HTML_Tag_Processor( $block_content );
			if ( $processor->next_tag() ) {
				$processor->set_attribute( 'data-newspack-modified', '' );
				$block_content = $processor->get_updated_html();
			}
			return $block_content;
		}

		// Handle time-ago for publish dates.
		return self::maybe_apply_time_ago_to_block( $block_content );
	}

	/**
	 * Filter get_the_date() for classic theme support.
	 *
	 * @param string   $the_date Formatted date.
	 * @param string   $format   Date format.
	 * @param \WP_Post $post     Post object.
	 * @return string
	 */
	public static function filter_get_the_date( $the_date, $format, $post ) {
		if ( ! get_theme_mod( 'post_time_ago', false ) ) {
			return $the_date;
		}

		// Only convert the default date format (empty string). Explicit formats must be preserved
		// because they may be machine-readable (ISO 8601, Unix) or requested by templates/blocks.
		if ( ! empty( $format ) ) {
			return $the_date;
		}

		// Only convert dates in the loop to avoid affecting archive titles
		// (e.g. "Daily Archives: 2 days ago") and other contexts.
		if ( ! in_the_loop() ) {
			return $the_date;
		}

		$time_ago = self::convert_to_time_ago( $post->post_date_gmt, self::get_time_ago_cutoff_days() );

		return null !== $time_ago ? $time_ago : $the_date;
	}

	/**
	 * Filter formatted date for Newspack Blocks Homepage Posts.
	 *
	 * @param string   $date Formatted date.
	 * @param \WP_Post $post Post object.
	 * @return string
	 */
	public static function filter_blocks_formatted_date( $date, $post ) {
		if ( ! get_theme_mod( 'post_time_ago', false ) ) {
			return $date;
		}

		$time_ago = self::convert_to_time_ago( $post->post_date_gmt, self::get_time_ago_cutoff_days() );

		return null !== $time_ago ? $time_ago : $date;
	}

	/**
	 * Render updated date for classic (non-block) themes.
	 * Hooked to `newspack_theme_posted_on` which fires inside `newspack_posted_on()`.
	 */
	public static function render_updated_date_classic() {
		if ( wp_is_block_theme() || ! is_singular() ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! self::should_display_updated_date( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$modified_date = get_the_modified_date( '', $post );
		if ( get_theme_mod( 'post_time_ago', false ) ) {
			$time_ago = self::convert_to_time_ago( $post->post_modified_gmt, self::get_time_ago_cutoff_days() );
			if ( null !== $time_ago ) {
				$modified_date = $time_ago;
			}
		}

		printf(
			'<span class="updated-label">%1$s </span><time class="updated" datetime="%2$s">%3$s</time>',
			esc_html__( 'Updated', 'newspack-plugin' ),
			esc_attr( get_the_modified_date( DATE_W3C, $post ) ),
			esc_html( $modified_date )
		);
	}

	/**
	 * Add 'show-updated' body class when the updated date should display on classic themes.
	 *
	 * @param string[] $classes Body CSS classes.
	 * @return string[]
	 */
	public static function add_body_show_updated( $classes ) {
		if ( wp_is_block_theme() || ! is_singular() ) {
			return $classes;
		}
		if ( self::should_display_updated_date() ) {
			$classes[] = 'show-updated';
		}
		return $classes;
	}

	/**
	 * Suppress the theme's hidden <time class="updated"> when the plugin handles it.
	 *
	 * @param bool $include Whether to include the hidden updated time.
	 * @return bool
	 */
	public static function suppress_theme_hidden_updated_time( $include ) {
		if ( wp_is_block_theme() || ! is_singular() ) {
			return $include;
		}
		if ( self::should_display_updated_date() ) {
			return false;
		}
		return $include;
	}

	/**
	 * Register per-post meta for updated date toggles.
	 */
	public static function register_meta() {
		/**
		 * Filters the post types that support the updated date feature.
		 *
		 * @param string[] $post_types Array of post type slugs. Default: [ 'post' ].
		 */
		$post_types = apply_filters( 'newspack_updated_date_supported_post_types', [ 'post' ] );
		foreach ( $post_types as $post_type ) {
			register_post_meta(
				$post_type,
				'newspack_hide_updated_date',
				[
					'show_in_rest'  => true,
					'single'        => true,
					'type'          => 'boolean',
					'default'       => false,
					'auth_callback' => [ __CLASS__, 'auth_callback' ],
				]
			);
			register_post_meta(
				$post_type,
				'newspack_show_updated_date',
				[
					'show_in_rest'  => true,
					'single'        => true,
					'type'          => 'boolean',
					'default'       => false,
					'auth_callback' => [ __CLASS__, 'auth_callback' ],
				]
			);
		}
	}

	/**
	 * Auth callback for post meta.
	 *
	 * @param bool   $allowed  Whether the user can add the post meta.
	 * @param string $meta_key The meta key.
	 * @param int    $post_id  Post ID.
	 * @return bool
	 */
	public static function auth_callback( $allowed, $meta_key, $post_id ) {
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Migrate date settings from old theme to new theme on theme switch.
	 *
	 * @param string    $old_name  Old theme name.
	 * @param \WP_Theme $old_theme Old theme object.
	 */
	public static function migrate_date_settings( $old_name, $old_theme ) {
		$old_stylesheet = $old_theme->get_stylesheet();
		$old_mods       = get_option( "theme_mods_$old_stylesheet", [] );

		foreach ( self::THEME_MOD_KEYS as $key ) {
			if ( isset( $old_mods[ $key ] ) && false === get_theme_mod( $key, false ) ) {
				set_theme_mod( $key, $old_mods[ $key ] );
			}
		}
	}

	/**
	 * Enqueue relative-time script on frontend.
	 */
	public static function enqueue_scripts() {
		if ( ! get_theme_mod( 'post_time_ago', false ) ) {
			return;
		}

		$handle = 'newspack-relative-time';
		$path   = NEWSPACK_ABSPATH . 'dist/other-scripts/relative-time.js';
		$url    = plugins_url( 'dist/other-scripts/relative-time.js', NEWSPACK_PLUGIN_FILE );

		if ( ! file_exists( $path ) ) {
			return;
		}

		$asset = include NEWSPACK_ABSPATH . 'dist/other-scripts/relative-time.asset.php';

		wp_enqueue_script( $handle, $url, $asset['dependencies'] ?? [], $asset['version'] ?? false, true );
		wp_localize_script(
			$handle,
			'newspackRelativeTime',
			[
				'cutoff' => self::get_time_ago_cutoff_days() * DAY_IN_SECONDS,
				'locale' => get_locale(),
			]
		);
	}

	/**
	 * Enqueue editor sidebar script for per-post toggles.
	 */
	public static function enqueue_editor_assets() {
		/** This filter is documented in includes/class-post-date.php */
		$post_types = apply_filters( 'newspack_updated_date_supported_post_types', [ 'post' ] );

		$screen = get_current_screen();
		if ( $screen && ! in_array( $screen->post_type, $post_types, true ) ) {
			return;
		}

		if ( ! get_theme_mod( 'post_updated_date', false ) ) {
			$mode = 'show';
		} else {
			$mode = 'hide';
		}

		$handle = 'newspack-post-date-editor';
		$path   = NEWSPACK_ABSPATH . 'dist/other-scripts/post-date-editor.js';
		$url    = plugins_url( 'dist/other-scripts/post-date-editor.js', NEWSPACK_PLUGIN_FILE );

		if ( ! file_exists( $path ) ) {
			return;
		}

		$asset = include NEWSPACK_ABSPATH . 'dist/other-scripts/post-date-editor.asset.php';

		wp_enqueue_script( $handle, $url, $asset['dependencies'] ?? [], $asset['version'] ?? false, true );
		wp_localize_script(
			$handle,
			'newspackPostDate',
			[
				'mode'      => $mode,
				'postTypes' => $post_types,
			]
		);
	}
}
Post_Date::init();
