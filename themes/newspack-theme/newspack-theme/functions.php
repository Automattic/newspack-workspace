<?php
/**
 * Newspack Theme functions and definitions
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package Newspack
 */

/**
 * Newspack Theme only works in WordPress 4.7 or later.
 */
if ( version_compare( $GLOBALS['wp_version'], '4.7', '<' ) ) {
	require get_template_directory() . '/inc/back-compat.php';
	return;
}

if ( ! function_exists( 'newspack_is_amp' ) ) {
	/**
	 * Determine whether it is an AMP response.
	 *
	 * @return bool Whether AMP.
	 */
	function newspack_is_amp() {
		return function_exists( 'is_amp_endpoint' ) && is_amp_endpoint();
	}
}

if ( ! function_exists( 'newspack_setup' ) ) :
	/**
	 * Sets up theme defaults and registers support for various WordPress features.
	 *
	 * Note that this function is hooked into the after_setup_theme hook, which
	 * runs before the init hook. The init hook is too late for some features, such
	 * as indicating support for post thumbnails.
	 */
	function newspack_setup() {
		/*
		 * Make theme available for translation.
		 * Translations can be filed in the /languages/ directory.
		 * If you're building a theme based on Newspack Theme, use a find and replace
		 * to change 'newspack' to the name of your theme in all the template files.
		 */
		load_theme_textdomain( 'newspack-theme', get_template_directory() . '/languages' );

		// Add default posts and comments RSS feed links to head.
		add_theme_support( 'automatic-feed-links' );

		/*
		 * Let WordPress manage the document title.
		 * By adding theme support, we declare that this theme does not use a
		 * hard-coded <title> tag in the document head, and expect WordPress to
		 * provide it for us.
		 */
		add_theme_support( 'title-tag' );

		/*
		 * Enable support for Post Thumbnails on posts and pages.
		 *
		 * @link https://developer.wordpress.org/themes/functionality/featured-images-post-thumbnails/
		 */
		add_theme_support( 'post-thumbnails' );
		set_post_thumbnail_size( 1200, 9999 );

		add_image_size( 'newspack-featured-image', 1200, 9999 );
		add_image_size( 'newspack-featured-image-large', 2000, 9999 );
		add_image_size( 'newspack-featured-image-small', 780, 9999 );
		add_image_size( 'newspack-archive-image', 800, 600, true );
		add_image_size( 'newspack-archive-image-large', 1200, 900, true );
		add_image_size( 'newspack-footer-logo', 400, 9999 );

		if ( ! get_theme_mod( 'archive_enable_cropping', true ) ) {
			add_image_size( 'newspack-archive-image', 800, 9999, false );
			add_image_size( 'newspack-archive-image-large', 1200, 9999, false );
		}

		/**
		 * Enable feature support for specific post types.
		 */
		add_post_type_support( 'page', 'excerpt' ); // Custom excerpts for pages, normally restricted to posts.

		// This theme uses wp_nav_menu() in two locations.
		register_nav_menus(
			array(
				'primary-menu'   => __( 'Primary Menu', 'newspack-theme' ),
				'secondary-menu' => __( 'Secondary Menu', 'newspack-theme' ),
				'tertiary-menu'  => __( 'Tertiary Menu', 'newspack-theme' ),
				'highlight-menu' => __( 'Topic Highlight Menu', 'newspack-theme' ),
				'social'         => __( 'Social Links Menu', 'newspack-theme' ),
			)
		);

		/*
		 * Switch default core markup for search form, comment form, and comments
		 * to output valid HTML5.
		 */
		add_theme_support(
			'html5',
			array(
				'caption',
				'comment-form',
				'comment-list',
				'gallery',
				'script',
				'search-form',
				'style',
			)
		);

		/**
		 * Add support for core custom logo.
		 *
		 * @link https://codex.wordpress.org/Theme_Logo
		 */
		add_theme_support(
			'custom-logo',
			array(
				'flex-width'  => true,
				'flex-height' => true,
				'header-text' => array( 'site-title' ),
			)
		);

		// Add theme support for selective refresh for widgets.
		add_theme_support( 'customize-selective-refresh-widgets' );

		// Add support for Block Styles.
		add_theme_support( 'wp-block-styles' );

		// Add support for full and wide align images.
		add_theme_support( 'align-wide' );

		// Add support for editor styles.
		add_theme_support( 'editor-styles' );

		// Enqueue editor styles.
		add_editor_style( 'style-editor.css' );

		// Add custom editor font sizes.
		add_theme_support(
			'editor-font-sizes',
			array(
				array(
					'name'      => __( 'Small', 'newspack-theme' ),
					'shortName' => __( 'S', 'newspack-theme' ),
					'size'      => 16,
					'slug'      => 'small',
				),
				array(
					'name'      => __( 'Normal', 'newspack-theme' ),
					'shortName' => __( 'M', 'newspack-theme' ),
					'size'      => 20,
					'slug'      => 'normal',
				),
				array(
					'name'      => __( 'Large', 'newspack-theme' ),
					'shortName' => __( 'L', 'newspack-theme' ),
					'size'      => 36,
					'slug'      => 'large',
				),
				array(
					'name'      => __( 'Huge', 'newspack-theme' ),
					'shortName' => __( 'XL', 'newspack-theme' ),
					'size'      => 44,
					'slug'      => 'huge',
				),
			)
		);

		$primary_color   = newspack_get_primary_color();
		$secondary_color = newspack_get_secondary_color();

		if ( 'default' !== get_theme_mod( 'theme_colors' ) ) {
			$primary_color   = get_theme_mod( 'primary_color_hex', $primary_color );
			$secondary_color = get_theme_mod( 'secondary_color_hex', $secondary_color );
		}

		$primary_color_variation   = newspack_adjust_brightness( $primary_color, -40 );
		$secondary_color_variation = newspack_adjust_brightness( $secondary_color, -40 );

		// Editor color palette.
		add_theme_support(
			'editor-color-palette',
			array(
				array(
					'name'  => __( 'Primary', 'newspack-theme' ),
					'slug'  => 'primary',
					'color' => $primary_color,
				),
				array(
					'name'  => __( 'Primary Variation', 'newspack-theme' ),
					'slug'  => 'primary-variation',
					'color' => $primary_color_variation,
				),
				array(
					'name'  => __( 'Secondary', 'newspack-theme' ),
					'slug'  => 'secondary',
					'color' => $secondary_color,
				),
				array(
					'name'  => __( 'Secondary Variation', 'newspack-theme' ),
					'slug'  => 'secondary-variation',
					'color' => $secondary_color_variation,
				),
				array(
					'name'  => __( 'Dark Gray', 'newspack-theme' ),
					'slug'  => 'dark-gray',
					'color' => '#111111', // color__text-main
				),
				array(
					'name'  => __( 'Medium Gray', 'newspack-theme' ),
					'slug'  => 'medium-gray',
					'color' => '#767676', // color__text-light
				),
				array(
					'name'  => __( 'Light Gray', 'newspack-theme' ),
					'slug'  => 'light-gray',
					'color' => '#EEEEEE', // color__background-pre
				),
				array(
					'name'  => __( 'White', 'newspack-theme' ),
					'slug'  => 'white',
					'color' => '#FFFFFF',
				),
			)
		);

		add_theme_support(
			'editor-gradient-presets',
			array(
				array(
					'name'     => __( 'Primary to primary variation', 'newspack-theme' ),
					'gradient' => 'linear-gradient( 135deg, ' . esc_attr( newspack_hex_to_rgb( $primary_color ) ) . ' 0%, ' . esc_attr( newspack_hex_to_rgb( $primary_color_variation ) ) . ' 100% )',
					'slug'     => 'grad-1',
				),
				array(
					'name'     => __( 'Secondary to secondary variation', 'newspack-theme' ),
					'gradient' => 'linear-gradient( 135deg, ' . esc_attr( newspack_hex_to_rgb( $secondary_color ) ) . ' 0%, ' . esc_attr( newspack_hex_to_rgb( $secondary_color_variation ) ) . ' 100% )',
					'slug'     => 'grad-2',
				),
				array(
					'name'     => __( 'Black to medium gray', 'newspack-theme' ),
					'gradient' => 'linear-gradient( 135deg, rgb( 17, 17, 17 ) 0%, rgb( 85, 85, 85 ) 100% )',
					'slug'     => 'grad-3',
				),
				array(
					'name'     => __( 'Dark gray to medium gray', 'newspack-theme' ),
					'gradient' => 'linear-gradient( 135deg, rgb( 68, 68, 68 ) 0%, rgb( 136, 136, 136 ) 100% )',
					'slug'     => 'grad-4',
				),
				array(
					'name'     => __( 'Medium gray to light gray', 'newspack-theme' ),
					'gradient' => 'linear-gradient( 135deg, rgb( 119, 119, 119 ) 0%, rgb( 221, 221, 221 ) 100% )',
					'slug'     => 'grad-5',
				),
				array(
					'name'     => __( 'Light gray to white', 'newspack-theme' ),
					'gradient' => 'linear-gradient( 135deg, rgb( 221, 221, 221 ) 0%, rgb( 255, 255, 255 ) 100% )',
					'slug'     => 'grad-6',
				),
			)
		);

		// Add block custom spacing support.
		add_theme_support( 'custom-spacing' );

		// Add support for responsive embedded content.
		add_theme_support( 'responsive-embeds' );

		// Make our theme AMP/PWA Native
		add_theme_support(
			'amp',
			array(
				'service_worker' => array(
					'cdn_script_caching'   => true,
					'google_fonts_caching' => true,
				),
			)
		);

		// Add custom theme support - post subtitle
		add_theme_support( 'post-subtitle' );
	}
endif;
add_action( 'after_setup_theme', 'newspack_setup' );

/**
 * Register widget area.
 *
 * @link https://developer.wordpress.org/themes/functionality/sidebars/#registering-a-sidebar
 */
function newspack_widgets_init() {

	register_sidebar(
		array(
			'name'          => __( 'Sidebar', 'newspack-theme' ),
			'id'            => 'sidebar-1',
			'description'   => __( 'Add widgets here to appear in your sidebar.', 'newspack-theme' ),
			'before_widget' => '<section id="%1$s" class="widget %2$s">',
			'after_widget'  => '</section>',
			'before_title'  => '<h2 class="widget-title accent-header"><span>',
			'after_title'   => '</span></h2>',
		)
	);

	register_sidebar(
		array(
			'name'          => __( 'Slide-out Sidebar', 'newspack-theme' ),
			'id'            => 'header-1',
			'description'   => esc_html__( 'Add widgets here to appear in an off-screen sidebar when it is enabled under the Customizer Header Settings.', 'newspack-theme' ),
			'before_widget' => '<section id="%1$s" class="below-content widget %2$s">',
			'after_widget'  => '</section>',
			'before_title'  => '<h2 class="widget-title">',
			'after_title'   => '</h2>',
		)
	);

	register_sidebar(
		array(
			'name'          => __( 'Above Header', 'newspack-theme' ),
			'id'            => 'header-2',
			'description'   => esc_html__( 'Add widgets here to appear above the site header.', 'newspack-theme' ),
			'before_widget' => '<section id="%1$s" class="below-content widget %2$s">',
			'after_widget'  => '</section>',
			'before_title'  => '<h2 class="widget-title">',
			'after_title'   => '</h2>',
		)
	);

	register_sidebar(
		array(
			'name'          => __( 'Below Header', 'newspack-theme' ),
			'id'            => 'header-3',
			'description'   => esc_html__( 'Add widgets here to appear below the site header.', 'newspack-theme' ),
			'before_widget' => '<section id="%1$s" class="below-content widget %2$s">',
			'after_widget'  => '</section>',
			'before_title'  => '<h2 class="widget-title">',
			'after_title'   => '</h2>',
		)
	);

	register_sidebar(
		array(
			'name'          => __( 'Above Footer', 'newspack-theme' ),
			'id'            => 'footer-3',
			'description'   => esc_html__( 'Add widgets here to appear above the site footer.', 'newspack-theme' ),
			'before_widget' => '<section id="%1$s" class="above-footer widget %2$s">',
			'after_widget'  => '</section>',
			'before_title'  => '<h2 class="widget-title">',
			'after_title'   => '</h2>',
		)
	);

	register_sidebar(
		array(
			'name'          => __( 'Footer', 'newspack-theme' ),
			'id'            => 'footer-1',
			'description'   => __( 'Add widgets here to appear in your footer.', 'newspack-theme' ),
			'before_widget' => '<section id="%1$s" class="widget %2$s">',
			'after_widget'  => '</section>',
			'before_title'  => '<h2 class="widget-title">',
			'after_title'   => '</h2>',
		)
	);

	register_sidebar(
		array(
			'name'          => __( 'Above Copyright', 'newspack-theme' ),
			'id'            => 'footer-2',
			'description'   => __( 'Add widgets here to appear below the footer, above the copyright information.', 'newspack-theme' ),
			'before_widget' => '<section id="%1$s" class="widget %2$s">',
			'after_widget'  => '</section>',
			'before_title'  => '<h2 class="widget-title">',
			'after_title'   => '</h2>',
		)
	);

	register_sidebar(
		array(
			'name'          => __( 'Article above content', 'newspack-theme' ),
			'id'            => 'article-1',
			'description'   => __( 'Add widgets here to appear above article content.', 'newspack-theme' ),
			'before_widget' => '<section id="%1$s" class="above-content widget %2$s">',
			'after_widget'  => '</section>',
			'before_title'  => '<h2 class="widget-title">',
			'after_title'   => '</h2>',
		)
	);

	register_sidebar(
		array(
			'name'          => __( 'Article below content', 'newspack-theme' ),
			'id'            => 'article-2',
			'description'   => __( 'Add widgets here to appear below article content.', 'newspack-theme' ),
			'before_widget' => '<section id="%1$s" class="below-content widget %2$s">',
			'after_widget'  => '</section>',
			'before_title'  => '<h2 class="widget-title">',
			'after_title'   => '</h2>',
		)
	);
}
add_action( 'widgets_init', 'newspack_widgets_init' );

/**
 * Set the content width in pixels, based on the theme's design and stylesheet.
 *
 * Priority 0 to make it available to lower priority callbacks.
 *
 * @global int $content_width Content width.
 */
function newspack_content_width() {
	$content_width = 780;

	// Check if front page or using One-Column Wide template
	if ( ( is_front_page() && 'posts' !== get_option( 'show_on_front' ) ) || is_page_template( 'single-wide.php' ) ) {
		$content_width = 1200;
	}
	// This variable is intended to be overruled from themes.
	// Open WPCS issue: {@link https://github.com/WordPress-Coding-Standards/WordPress-Coding-Standards/issues/1043}.
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	$GLOBALS['content_width'] = apply_filters( 'newspack_content_width', $content_width );
}
add_action( 'template_redirect', 'newspack_content_width', 0 );

/**
 * Return the list of custom fonts in use.
 */
function newspack_get_used_custom_fonts(): array {
	return array_values( array_filter( [ get_theme_mod( 'font_header', '' ), get_theme_mod( 'font_body', '' ) ] ) );
}

/**
 * Enqueue scripts and styles.
 */
function newspack_scripts() {
	wp_enqueue_style( 'newspack-style', get_stylesheet_uri(), array(), wp_get_theme()->get( 'Version' ) );

	wp_style_add_data( 'newspack-style', 'rtl', 'replace' );

	/**
	 * Filters whether to enqueue print styles.
	 *
	 * @param bool $should_enqueue_print_styles Whether to enqueue print styles.
	 */
	if ( apply_filters( 'newspack_theme_enqueue_print_styles', true ) ) {
		wp_enqueue_style( 'newspack-print-style', get_template_directory_uri() . '/styles/print.css', array(), wp_get_theme()->get( 'Version' ), 'print' );
	}

	// Load custom fonts, if any. Version is null to prevent query string on external URLs.
	if ( get_theme_mod( 'custom_font_import_code', '' ) ) {
		wp_enqueue_style( 'newspack-font-import', newspack_custom_typography_link( 'custom_font_import_code' ), array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	}

	if ( get_theme_mod( 'custom_font_import_code_alternate', '' ) ) {
		wp_enqueue_style( 'newspack-font-alternative-import', newspack_custom_typography_link( 'custom_font_import_code_alternate' ), array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	}

	/**
	 * Filters whether to enqueue JS.
	 *
	 * @param bool $should_enqueue_js Whether to enqueue JS.
	 */
	if ( ! apply_filters( 'newspack_theme_enqueue_js', true ) ) {
		return;
	}

	$newspack_l10n = array(
		'open_search'         => esc_html__( 'Open Search', 'newspack-theme' ),
		'close_search'        => esc_html__( 'Close Search', 'newspack-theme' ),
		'expand_comments'     => esc_html__( 'Expand Comments', 'newspack-theme' ),
		'collapse_comments'   => esc_html__( 'Collapse Comments', 'newspack-theme' ),
		'show_order_details'  => esc_html__( 'Show details', 'newspack-theme' ),
		'hide_order_details'  => esc_html__( 'Hide details', 'newspack-theme' ),
		'open_dropdown_menu'  => esc_html__( 'Open dropdown menu', 'newspack-theme' ),
		'close_dropdown_menu' => esc_html__( 'Close dropdown menu', 'newspack-theme' ),
		'is_amp'              => newspack_is_amp(),
	);

	if ( ! newspack_is_amp() ) {
		if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
			wp_enqueue_script( 'comment-reply' );
		}

		wp_enqueue_script( 'newspack-amp-fallback', get_theme_file_uri( '/js/dist/amp-fallback.js' ), array(), wp_get_theme()->get( 'Version' ), true );
		wp_localize_script( 'newspack-amp-fallback', 'newspackScreenReaderText', $newspack_l10n );
	}

	wp_enqueue_script( 'newspack-menu-accessibility', get_theme_file_uri( '/js/dist/menu-accessibility.js' ), array(), wp_get_theme()->get( 'Version' ), true );
	wp_localize_script( 'newspack-menu-accessibility', 'newspackScreenReaderText', $newspack_l10n );

	if ( newspack_is_sticky_animated_header() ) {
		wp_enqueue_script( 'amp-animation', 'https://cdn.ampproject.org/v0/amp-animation-0.1.js', array(), '0.1', true );
		wp_enqueue_script( 'amp-position-observer', 'https://cdn.ampproject.org/v0/amp-position-observer-0.1.js', array(), '0.1', true );
	}

	wp_enqueue_script( 'newspack-font-loading', get_theme_file_uri( '/js/dist/font-loading.js' ), array(), wp_get_theme()->get( 'Version' ), true );
	wp_localize_script(
		'newspack-font-loading',
		'newspackFontLoading',
		array(
			'fonts' => newspack_get_used_custom_fonts(),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'newspack_scripts' );

/**
 * Add AMP-related attributes to the script tags.
 *
 * @param string $tag Enqueued script tag.
 * @param string $handle Handle of enqueued of script.
 *
 * @return string Modified $tag.
 */
function newspack_add_amp_attributes( $tag, $handle ) {

	if ( newspack_is_sticky_animated_header() ) {
		$scripts_to_defer = array( 'amp-animation', 'amp-position-observer' );
		foreach ( $scripts_to_defer as $defer_script ) {
			if ( $defer_script === $handle ) {
				return str_replace( ' src', ' async custom-element="true" src', $tag );
			}
		}
	}
	return $tag;
}
add_filter( 'script_loader_tag', 'newspack_add_amp_attributes', 10, 3 );

/**
 * Add AMP animation for when:
 * - A site has a sticky header, and a simplified subpage header
 * - When viewing a page or post with a featured image behind or beside.
 */
function newspack_sticky_header_amp_animation() {
	if ( newspack_is_sticky_animated_header() ) {
		$sticky_header_fade_out = array(
			'duration'   => '500ms',
			'fill'       => 'both',
			'iterations' => '1',
			'animations' => array(
				array(
					'selector'  => '#masthead .sticky-bg',
					'keyframes' => array(
						'opacity' => 0,
					),
				),
			),
		);

		$sticky_header_fade_in = array(
			'duration'   => '500ms',
			'fill'       => 'both',
			'iterations' => '1',
			'animations' => array(
				array(
					'selector'  => '#masthead .sticky-bg',
					'keyframes' => array(
						'opacity' => 0.7,
					),
				),
			),
		);

		echo '<amp-animation id="headerFadeOut" layout="nodisplay"><script type="application/json">' . wp_json_encode( $sticky_header_fade_out ) . '</script></amp-animation>';
		echo '<amp-animation id="headerFadeIn" layout="nodisplay"><script type="application/json">' . wp_json_encode( $sticky_header_fade_in ) . '</script></amp-animation>';
	}
}
add_action( 'wp_body_open', 'newspack_sticky_header_amp_animation' );

/**
 * Enqueue scripts for:
 * - Featured Image position option
 * - Article Subtitle
 */
function newspack_enqueue_scripts() {
	$languages_path = get_parent_theme_file_path( '/languages' );
	$theme_version  = wp_get_theme()->get( 'Version' );
	$post_type      = get_post_type();
	$current_screen = get_current_screen();

	// Add check to see if currently on the widgets screen; none of these files are needed there, but are loaded as of WP 5.8.
	// See: https://github.com/WordPress/gutenberg/issues/28538.
	if ( 'widgets' === $current_screen->id ) {
		return;
	}

	// Featured Image options.
	wp_register_script(
		'newspack-extend-featured-image-script',
		get_theme_file_uri( '/js/dist/extend-featured-image-editor.js' ),
		array( 'wp-blocks', 'wp-components' ),
		$theme_version,
		true
	);
	wp_set_script_translations( 'newspack-extend-featured-image-script', 'newspack-theme', $languages_path );
	wp_localize_script(
		'newspack-extend-featured-image-script',
		'newspack_theme_featured_image_post_types',
		newspack_get_featured_image_post_types()
	);
	wp_enqueue_script( 'newspack-extend-featured-image-script' );

	// Article subtitle.
	if ( 'post' === $post_type ) {
		wp_enqueue_script( 'newspack-post-subtitle', get_theme_file_uri( '/js/dist/post-subtitle.js' ), array(), $theme_version, true );
		wp_set_script_translations( 'newspack-post-subtitle', 'newspack-theme', $languages_path );

		wp_enqueue_script( 'newspack-post-summary', get_theme_file_uri( '/js/dist/post-summary.js' ), array(), $theme_version, true );
		wp_set_script_translations( 'newspack-post-summary', 'newspack-theme', $languages_path );

	}

	// Post meta options.
	wp_register_script(
		'newspack-post-meta-toggles',
		get_theme_file_uri( '/js/dist/post-meta-toggles.js' ),
		array(),
		$theme_version,
		true
	);
	wp_set_script_translations( 'newspack-post-meta-toggles', 'newspack-theme', $languages_path );
	wp_localize_script(
		'newspack-post-meta-toggles',
		'newspack_post_meta_post_types',
		newspack_get_post_toggle_post_types()
	);
	wp_enqueue_script( 'newspack-post-meta-toggles' );

	// Remove FSE-related Gutenberg blocks.
	$allowed_fse_blocks = newspack_fse_blocks_to_remove();
	wp_register_script( 'newspack-hide-fse-blocks', get_theme_file_uri( '/js/dist/editor-remove-blocks.js' ), array( 'wp-blocks', 'wp-dom-ready', 'wp-edit-post' ), $theme_version, true );
	wp_localize_script( 'newspack-hide-fse-blocks', 'updateAllowedBlocks', $allowed_fse_blocks );
	wp_enqueue_script( 'newspack-hide-fse-blocks' );
}
add_action( 'enqueue_block_editor_assets', 'newspack_enqueue_scripts' );


/**
 * Check for additional allowed blocks
 *
 * Add this flag to the wp-config.php to allow more blocks, formatted in a array --
 * for example: `define( 'NEWSPACK_FSE_BLOCKS_ALLOWED', ['core/avatar', 'core/loginout'] );`
 *
 * @return array List of allowed FSE blocks.
 */
function newspack_is_fse_blocks_allowed() {
	/**
	 * Specifies additional Full Site Editing (FSE) blocks to allow in the
	 * editor. By default, Newspack theme removes certain FSE blocks for
	 * compatibility. Use this constant to re-enable specific blocks.
	 *
	 * @constant NEWSPACK_FSE_BLOCKS_ALLOWED
	 * @type     array
	 * @default  No additional FSE blocks allowed
	 * @status   draft
	 *
	 * @example define( 'NEWSPACK_FSE_BLOCKS_ALLOWED', ['core/avatar', 'core/loginout'] );
	 */
	if ( defined( 'NEWSPACK_FSE_BLOCKS_ALLOWED' ) ) {
		return NEWSPACK_FSE_BLOCKS_ALLOWED;
	}
}

/**
 * Put together list of FSE blocks to remove
 *
 * @return array FSE blocks to remove from editor.
 */
function newspack_fse_blocks_to_remove() {

	// List of all FSE blocks to remove from the editor.
	$fse_blocks = array(
		'core/loginout',
		'core/comments',
		'core/post-comments-form',
		'core/comments-query-loop',
		'core/query',
		// 'core/post-title', Temporarily allow this block. Ref. https://github.com/woocommerce/woocommerce/pull/52209
		'core/post-featured-image',
		'core/post-excerpt',
		'core/post-content',
		'core/post-terms',
		'core/post-date',
		'core/post-author',
		'core/post-author-name',
		'core/post-navigation-link',
		'core/read-more',
		'core/avatar',
		'core/post-author-biography',
		'core/query-title',
		'core/term-description',
	);

	// Get site-specific allowed FSE blocks.
	if ( is_array( newspack_is_fse_blocks_allowed() ) ) {
		$get_allowed_blocks = array_map( 'esc_html', newspack_is_fse_blocks_allowed() );

		// Remove the allowed FSE blocks from the list of blocks to remove.
		if ( $get_allowed_blocks ) {
			$fse_blocks = array_diff( $fse_blocks, $get_allowed_blocks );
		}
	}

	// Turn FSE blocks array into a string.
	$fse_blocks = implode( ',', $fse_blocks );

	// Format FSE block list so it can be passed to the JavaScript file as a translation.
	$blocks_to_remove = array(
		'removeblocks' => $fse_blocks,
	);

	// Return the list of blocks to remove.
	return $blocks_to_remove;
}

/**
 * Fix skip link focus in IE11.
 *
 * This does not enqueue the script because it is tiny and because it is only for IE11,
 * thus it does not warrant having an entire dedicated blocking script being loaded.
 *
 * @link https://git.io/vWdr2
 */
function newspack_skip_link_focus_fix() {
	// Bail if this is an AMP page, because AMP already handles this bug.
	if ( newspack_is_amp() ) {
		return;
	}

	// The following is minified via `terser --compress --mangle -- js/skip-link-focus-fix.js`.
	?>
	<script>
	/(trident|msie)/i.test(navigator.userAgent)&&document.getElementById&&window.addEventListener&&window.addEventListener("hashchange",function(){var t,e=location.hash.substring(1);/^[A-z0-9_-]+$/.test(e)&&(t=document.getElementById(e))&&(/^(?:a|select|input|button|textarea)$/i.test(t.tagName)||(t.tabIndex=-1),t.focus())},!1);
	</script>
	<?php
}
add_action( 'wp_print_footer_scripts', 'newspack_skip_link_focus_fix' );

/**
 * Checks whether the amp animation files need to be loaded for the sticky header.
 */
function newspack_is_sticky_animated_header() {
	$header_sticky          = get_theme_mod( 'header_sticky', false );
	$header_sub_simplified  = get_theme_mod( 'header_sub_simplified', false );
	$feat_img_behind_beside = in_array( newspack_featured_image_position(), array( 'behind', 'beside' ), true );

	if ( $header_sticky && $header_sub_simplified && $feat_img_behind_beside && newspack_is_amp() ) {
		return true;
	} else {
		return false;
	}
}

/**
 * Enqueue supplemental block editor styles.
 */
function newspack_editor_customizer_styles() {
	// In the iframe editor, canvas styles are loaded via block assets.
	if ( ! is_admin() ) {
		return;
	}

	wp_enqueue_style( 'newspack-editor-customizer-styles', get_theme_file_uri( '/styles/style-editor-customizer.css' ), false, wp_get_theme()->get( 'Version' ), 'all' );

	// Check for color or font customizations.
	$theme_customizations = '';
	if ( 'custom' === get_theme_mod( 'theme_colors' ) ) {
		// Include color patterns.
		require_once get_parent_theme_file_path( '/inc/color-patterns.php' );
		$theme_customizations .= newspack_custom_colors_css();
	}

	if ( get_theme_mod( 'font_body', '' ) || get_theme_mod( 'font_header', '' ) || get_theme_mod( 'accent_allcaps', true ) ) {
		$theme_customizations .= newspack_custom_typography_css();
	}

	// If there are any, add those styles inline.
	if ( $theme_customizations ) {
		wp_add_inline_style( 'newspack-editor-customizer-styles', $theme_customizations );
	}

	// If custom fonts are assigned, enqueue them as well. Version is null to prevent query string on external URLs.
	if ( get_theme_mod( 'custom_font_import_code', '' ) ) {
		wp_enqueue_style( 'newspack-font-import', newspack_custom_typography_link( 'custom_font_import_code' ), array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	}
	if ( get_theme_mod( 'custom_font_import_code_alternate', '' ) ) {
		wp_enqueue_style( 'newspack-font-alternative-import', newspack_custom_typography_link( 'custom_font_import_code_alternate' ), array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	}
}
add_action( 'enqueue_block_assets', 'newspack_editor_customizer_styles' );

/**
 * Determine if current editor page is the static front page.
 */
function newspack_is_static_front_page() {
	global $post;
	$page_on_front = intval( get_option( 'page_on_front' ) );
	return isset( $post->ID ) && intval( $post->ID ) === $page_on_front;
}

/**
 * Check for specific templates.
 */
function newspack_check_current_template() {
	global $post;

	$template_file = ( $post && $post->ID ) ? get_post_meta( $post->ID, '_wp_page_template', true ) : '';

	return $template_file;
}

/**
 * Add body class on editor pages if editing the static front page.
 *
 * The 'admin-color-' prefix is used to make sure the classes get moved to the <body> tag in the iframed editor as a work-around.
 * See https://github.com/WordPress/gutenberg/issues/28538 for more details.
 */
function newspack_filter_admin_body_class( $classes ) {
	if ( ! function_exists( 'get_current_screen' ) ) {
		return $classes;
	}

	$current_screen = get_current_screen();
	if ( ! $current_screen || ! method_exists( $current_screen, 'is_block_editor' ) || ! $current_screen->is_block_editor() ) {
		return $classes;
	}

	global $post;
	if ( ! ( $post && isset( $post->ID ) ) ) {
		return $classes;
	}

	if ( newspack_is_static_front_page() ) {
		$classes .= ' admin-color-newspack-static-front-page';
	}

	$template = newspack_check_current_template();
	if (
		'single-feature.php' === $template
		|| 'no-header-footer.php' === $template
	) {
		$classes .= ' admin-color-newspack-single-column-template';
	} elseif ( 'single-wide.php' === $template ) {
		$classes .= ' admin-color-newspack-single-wide-template';
	} else {
		$classes .= ' admin-color-newspack-default-template';
	}

	return $classes;
}
add_filter( 'admin_body_class', 'newspack_filter_admin_body_class', 10, 1 );


/**
 * Enqueue CSS styles for the editor that use the <body> tag.
 */
function newspack_enqueue_editor_override_assets() {
	// In the iframe editor, canvas styles are loaded via block assets.
	if ( ! is_admin() ) {
		return;
	}

	wp_enqueue_style( 'newspack-editor-overrides', get_theme_file_uri( '/styles/style-editor-overrides.css' ), false, wp_get_theme()->get( 'Version' ), 'all' );
}
add_action( 'enqueue_block_assets', 'newspack_enqueue_editor_override_assets' );

/**
 * Use front-page.php when Front page displays is set to a static page.
 *
 * @param string $template front-page.php.
 *
 * @return string The template to be used: blank if is_home() is true (defaults to index.php), else $template.
 */
function newspack_front_page_template( $template ) {
	return is_home() ? '' : $template;
}
add_filter( 'frontpage_template', 'newspack_front_page_template' );

/**
 * Override Jetpack Image Accelerator (Photon) downsizing of avatars. If an image has a square aspect ratio and the width is between 1-120px, assume it is an avatar and block downsizing.
 * https://developer.jetpack.com/hooks/jetpack_photon_override_image_downsize/
 *
 * @param boolean $default_value The default value, generally false.
 * @param array   $args Array of image details.
 *
 * @return boolean Should Photon be stopped from downsizing.
 */
function newspack_override_avatar_downsizing( $default_value, $args ) {
	if ( is_array( $args['size'] ) && 2 === count( $args['size'] ) ) {
		list( $width, $height ) = $args['size'];
		if ( $width === $height && $width <= 120 & $width > 0 ) {
			return true;
		}
	}
	return $default_value;
}
add_filter( 'jetpack_photon_override_image_downsize', 'newspack_override_avatar_downsizing', 10, 2 );

/**
 * Register the theme's editor-managed post meta:
 * - Featured image position
 * - Article subtitle
 * - Article summary title
 * - Article summary
 * - Hide page title (pages)
 * - Show share buttons (pages)
 */
function newspack_register_meta() {
	$featured_image_post_types = newspack_get_featured_image_post_types();

	foreach ( $featured_image_post_types as $post_type ) {
		register_post_meta(
			$post_type,
			'newspack_featured_image_position',
			array(
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
			)
		);
	}

	register_post_meta(
		'post',
		'newspack_post_subtitle',
		array(
			'show_in_rest' => true,
			'single'       => true,
			'type'         => 'string',
		)
	);

	register_post_meta(
		'post',
		'newspack_article_summary_title',
		array(
			'default'      => esc_html__( 'Overview:', 'newspack-theme' ),
			'show_in_rest' => true,
			'single'       => true,
			'type'         => 'string',
		)
	);

	register_post_meta(
		'post',
		'newspack_article_summary',
		array(
			'show_in_rest' => true,
			'single'       => true,
			'type'         => 'string',
		)
	);

	register_post_meta(
		'page',
		'newspack_hide_page_title',
		array(
			'show_in_rest' => true,
			'single'       => true,
			'type'         => 'boolean',
		)
	);

	register_post_meta(
		'page',
		'newspack_show_share_buttons',
		array(
			'show_in_rest' => true,
			'single'       => true,
			'type'         => 'boolean',
			'default'      => false,
		)
	);
}
add_action( 'init', 'newspack_register_meta' );

/**
 * Editor-managed post meta keys the classic "Custom Fields" box must not write.
 *
 * These keys are edited through dedicated block-editor panels and saved via the
 * REST API. See newspack_prevent_classic_metabox_meta_clobber().
 *
 * @return string[] Meta keys.
 */
function newspack_get_editor_managed_meta_keys() {
	return array(
		'newspack_featured_image_position',
		'newspack_post_subtitle',
		'newspack_article_summary_title',
		'newspack_article_summary',
		'newspack_hide_page_title',
		'newspack_show_share_buttons',
	);
}

/**
 * Stop the classic "Custom Fields" box from clobbering editor-managed meta.
 *
 * The block editor saves these keys via the REST API. When the "Custom Fields"
 * panel is enabled, the editor also fires a separate classic meta-box save (the
 * `meta-box-loader` request) that resubmits the box's page-load value for every
 * existing meta row and writes it through edit_post(). That stale write lands a
 * moment after the REST save and silently overwrites it.
 *
 * Rather than protecting these keys (which would remove them from the Custom
 * Fields box entirely and block publishers who manage subtitles there, e.g. on
 * custom post types that have no dedicated panel), we drop our managed keys from
 * the meta-box-loader payload only. Intentional edits made with the box's own
 * Add/Update buttons save through a separate admin-ajax request and are
 * unaffected.
 *
 * @return void
 */
function newspack_prevent_classic_metabox_meta_clobber() {
	// Only the block editor's auxiliary meta-box save carries this flag; a genuine
	// classic-editor save does not, and must keep writing Custom Fields normally.
	if ( ! isset( $_REQUEST['meta-box-loader'], $_POST['post_ID'], $_POST['_wpnonce'], $_POST['meta'] ) ) {
		return;
	}

	// edit_post() processes $_POST['meta'] only after core verifies this nonce for
	// the 'editpost' action (wp-admin/post.php). This runs earlier (admin_init), so
	// verify the same nonce here before touching the payload.
	$post_id = (int) $_POST['post_ID'];
	$nonce   = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );
	if ( ! $post_id || ! wp_verify_nonce( $nonce, 'update-post_' . $post_id ) ) {
		return;
	}

	$managed = newspack_get_editor_managed_meta_keys();
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only the meta-row key is read and sanitized below; the row value is never used, and the nonce is verified above.
	foreach ( array_keys( (array) $_POST['meta'] ) as $mid ) {
		$key = isset( $_POST['meta'][ $mid ]['key'] )
			? sanitize_text_field( wp_unslash( $_POST['meta'][ $mid ]['key'] ) )
			: '';
		if ( in_array( $key, $managed, true ) ) {
			unset( $_POST['meta'][ $mid ] );
		}
	}
}
add_action( 'admin_init', 'newspack_prevent_classic_metabox_meta_clobber' );


/**
 * Migrate theme settings when switching within the family of Newspack themes.
 *
 * @since Newspack Theme 1.0.0
 */
function newspack_migrate_settings( $old_name, $old_theme = false ) {
	$theme           = wp_get_theme();
	$old_stylesheet  = is_a( $old_theme, 'WP_Theme' ) ? $old_theme->get_stylesheet() : null;
	$new_stylesheet  = $theme->get_stylesheet();
	$newspack_prefix = 'newspack-';

	if ( 0 === strrpos( $old_stylesheet, $newspack_prefix ) && 0 === strrpos( $new_stylesheet, $newspack_prefix ) ) {
		$mods = get_option( 'theme_mods_' . $old_stylesheet, null );
		if ( $mods ) {
			update_option( 'theme_mods_' . $new_stylesheet, $mods );
		}
	}
}
add_action( 'after_switch_theme', 'newspack_migrate_settings', 10, 2 );

/**
 * Display custom color CSS in customizer and on frontend.
 */
function newspack_colors_css_wrap() {

	// Only bother if we haven't customized the color.
	if ( ( ! is_customize_preview() && ( 'default' === get_theme_mod( 'theme_colors', 'default' ) && newspack_get_mobile_cta_color() === get_theme_mod( 'header_cta_hex', newspack_get_mobile_cta_color() ) && 'default' === get_theme_mod( 'ads_color', 'default' ) ) ) || is_admin() ) {
		return;
	}

	require_once get_parent_theme_file_path( '/inc/color-patterns.php' );
	?>

	<style type="text/css" id="custom-theme-colors">
		<?php echo newspack_custom_colors_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</style>
	<?php
}
add_action( 'wp_head', 'newspack_colors_css_wrap' );

/**
 * Get theme colors' values.
 *
 * @return string[] Array of colors.
 */
function newspack_get_colors() {
	$colors              = array();
	$colors['primary']   = newspack_get_primary_color();
	$colors['secondary'] = newspack_get_secondary_color();
	$colors['cta']       = get_theme_mod( 'header_cta_hex', newspack_get_mobile_cta_color() );

	if ( true === get_theme_mod( 'header_solid_background', false ) ) {
		$colors['header'] = $colors['primary'];
	}

	if ( 'default' !== get_theme_mod( 'theme_colors', 'default' ) ) {
		$colors['primary']   = get_theme_mod( 'primary_color_hex', $colors['primary'] );
		$colors['secondary'] = get_theme_mod( 'secondary_color_hex', $colors['secondary'] );

		if ( 'default' !== get_theme_mod( 'header_color', 'default' ) ) {
			$colors['header']       = get_theme_mod( 'header_color_hex', '#666666' );
			$colors['primary_menu'] = get_theme_mod( 'header_primary_menu_color_hex', '' );
		} else {
			$colors['header'] = $colors['primary'];
		}

		if ( 'default' !== get_theme_mod( 'footer_color', 'default' ) ) {
			$colors['footer'] = get_theme_mod( 'footer_color_hex', '' );
		}
	}

	// Set color contrasts.
	foreach ( $colors as $color_key => $color_value ) {
		$colors[ $color_key . '_contrast' ] = newspack_get_color_contrast( $color_value );
	}

	return $colors;
}

/**
 * Add CSS variables to theme's colors.
 */
function newspack_colors_css_variables() {
	$colors = newspack_get_colors();
	?>
	<style type="text/css" id="newspack-theme-colors-variables">
		:root {
			<?php foreach ( $colors as $color_key => $color_value ) : ?>
				--newspack-<?php echo esc_attr( str_replace( '_', '-', $color_key ) ); ?>-color: <?php echo esc_attr( $color_value ); ?>;
			<?php endforeach; ?>
		}
	</style>
	<?php
}
add_action( 'wp_head', 'newspack_colors_css_variables' );

/**
 * Display custom font CSS in customizer and on frontend.
 */
function newspack_typography_css_wrap() {

	if ( is_admin() || ( ! get_theme_mod( 'font_body', '' ) && ! get_theme_mod( 'font_header', '' ) && ! get_theme_mod( 'accent_allcaps', true ) ) ) {
		return;
	}
	?>

	<style type="text/css" id="custom-theme-fonts">
		<?php echo newspack_custom_typography_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</style>

	<?php
}
add_action( 'wp_head', 'newspack_typography_css_wrap' );

/**
 * Returns an array of 'acceptable' SVG tags to use with wp_kses().
 */
function newspack_sanitize_svgs() {
	$svg_args = array(
		'svg'      => array(
			'class'           => true,
			'aria-hidden'     => true,
			'aria-labelledby' => true,
			'role'            => true,
			'xmlns'           => true,
			'width'           => true,
			'height'          => true,
			'viewbox'         => true,
		),
		'g'        => array(
			'fill'      => true,
			'fill-rule' => true,
		),
		'title'    => array(
			'title' => true,
		),
		'path'     => array(
			'd'    => true,
			'fill' => true,
		),
		'defs'     => true,
		'clipPath' => true,
		'polygon'  => array(
			'points' => true,
		),
	);

	return $svg_args;
}

/**
 * Truncates text to a specific character length, without breaking a character.
 */
function newspack_truncate_text( $content, $length, $after = '...' ) {
	// If content is already shorter than the truncate length, return it.
	if ( strlen( $content ) <= $length ) {
		return $content;
	}

	// Find the first space after the desired length:
	$breakpoint = strpos( $content, ' ', $length );

	// Make sure $breakpoint isn't returning false, and is less than length of content:
	if ( false !== $breakpoint && $breakpoint < strlen( $content ) - 1 ) {
		$content = substr( $content, 0, $breakpoint ) . $after;
	}
	return $content;
}

/**
 * Returns an array of 'acceptable' avatar tags, to use with wp_kses().
 */
function newspack_sanitize_avatars() {
	$avatar_args = array(
		'img'      => array(
			'class'  => true,
			'src'    => true,
			'alt'    => true,
			'width'  => true,
			'height' => true,
			'data-*' => true,
			'srcset' => true,
		),
		'noscript' => array(),
	);

	return $avatar_args;
}

/**
 * Get post types that support featured image settings.
 *
 * @return array Array of post type slugs.
 */
function newspack_get_featured_image_post_types() {
	return apply_filters( 'newspack_theme_featured_image_post_types', array( 'post', 'page' ) );
}

/**
 * Get post types that support the page title and share button toggle settings.
 *
 * @return array Array of post type slugs.
 */
function newspack_get_post_toggle_post_types() {
	return array(
		'hide_title'         => array( 'page' ),
		'show_share_buttons' => function_exists( 'sharing_display' ) ? array( 'page' ) : array(),
	);
}

/**
 * Should a particular Ad deployment use responsive placement.
 *
 * @param boolean $responsive Default value of whether to use responsive placement.
 * @param string  $placement ID of the ad placement.
 * @param string  $context Optional second string describing the ad placement. For Widget placements, the ID of the Widget.
 * @return boolean Whether to use responsive placement.
 */
function newspack_theme_newspack_ads_maybe_use_responsive_placement( $responsive, $placement, $context ) {
	// Apply Responsive placement to widgets using Super Cool Ad Inserter.
	if ( 'newspack_ads_widget' === $placement && strpos( $context, 'scaip' ) === 0 ) {
		return true;
	}
	return $responsive;
}
add_filter( 'newspack_ads_maybe_use_responsive_placement', 'newspack_theme_newspack_ads_maybe_use_responsive_placement', 10, 3 );

/**
 * Add a extra span and class to the_archive_title, for easier styling.
 */
function newspack_update_the_archive_title( $title ) {
	// Split the title into parts so we can wrap them with spans:
	$title_parts  = explode( '<span class="page-description">', $title, 2 );
	$title_format = get_theme_mod( 'archive_title_format', 'default' );

	// Glue it back together again.
	if ( ! empty( $title_parts[1] ) ) {
		$title = wp_kses(
			$title_parts[1],
			array(
				'span' => array(
					'class' => array(),
				),
			)
		);
		if ( 'default' === $title_format ) {
			$title = '<span class="page-subtitle">' . esc_html( $title_parts[0] ) . '</span><span class="page-description">' . $title;
		} else {
			$title = '<span class="page-description">' . $title;
		}
	}
	return $title;
}
add_filter( 'get_the_archive_title', 'newspack_update_the_archive_title', 11, 1 );

/**
 * When new post is created, maybe set the post template.
 *
 * @param integer $post_ID The post ID.
 * @param WP_Post $post Post object.
 * @param boolean $update Whether this is an existing post being updated or not.
 */
function newspack_maybe_set_default_post_template( $post_ID, $post, $update ) {
	if ( ! $update ) {
		if ( 'post' === $post->post_type ) {
			$post_template_default = get_theme_mod( 'post_template_default' );
			if ( 'default' !== $post_template_default ) {
				update_post_meta( $post_ID, '_wp_page_template', $post_template_default );
			}
		} elseif ( 'page' === $post->post_type ) {
			$page_template_default = get_theme_mod( 'page_template_default' );
			if ( 'default' !== $page_template_default ) {
				update_post_meta( $post_ID, '_wp_page_template', $page_template_default );
			}
		}
	}
}
add_action( 'wp_insert_post', 'newspack_maybe_set_default_post_template', 10, 3 );

/**
 * Dequeue Media Element styles if AMP is enabled.
 */
function newspack_dequeue_mediaelement() {
	if ( newspack_is_amp() ) {
		wp_deregister_script( 'wp-mediaelement' );
		wp_deregister_style( 'wp-mediaelement' );
	}
}
add_action( 'wp_enqueue_scripts', 'newspack_dequeue_mediaelement', 20 );

/**
 * SVG Icons class.
 */
require get_template_directory() . '/classes/class-newspack-svg-icons.php';

/**
 * Custom Comment Walker template.
 */
require get_template_directory() . '/classes/class-newspack-walker-comment.php';

/**
 * Enhance the theme by hooking into WordPress.
 */
require get_template_directory() . '/inc/template-functions.php';

/**
 * Custom typography functions.
 */
require get_template_directory() . '/inc/typography.php';

/**
 * SVG Icons related functions.
 */
require get_template_directory() . '/inc/icon-functions.php';

/**
 * Custom template tags for the theme.
 */
require get_template_directory() . '/inc/template-tags.php';

/**
 * Customizer additions.
 */
require get_template_directory() . '/inc/customizer.php';

/**
 * Logo Resizer.
 */
require get_template_directory() . '/inc/logo-resizer.php';

/**
 * Custom Login Screen.
 */
require get_template_directory() . '/inc/login-screen.php';

/**
 * Load Jetpack compatibility file.
 */
if ( defined( 'JETPACK__VERSION' ) ) {
	require get_template_directory() . '/inc/jetpack.php';
}

/**
 * Load WooCommerce compatibility file.
 */
if ( class_exists( 'WooCommerce' ) ) {
	require get_template_directory() . '/inc/woocommerce.php';
}

/**
 * Load Trust Indicators compatibility file.
 */
if ( class_exists( 'Trust_Indicators' ) ) {
	require get_template_directory() . '/inc/trust-indicators.php';
}

/**
 * Load Sponsored Content compatibility file.
 */
if ( function_exists( '\Newspack_Sponsors\get_sponsors_for_post' ) ) {
	require get_template_directory() . '/inc/newspack-sponsors.php';
}

/**
 * Load Newsletters compatibility file.
 */
if ( class_exists( '\Newspack_Newsletters' ) ) {
	require get_template_directory() . '/inc/newspack-newsletters.php';
}

/**
 * Load Web Stories compatibility file.
 */
require get_template_directory() . '/inc/web-stories.php';

/**
 * Load The Events Calendar compatibility file.
 */
if ( class_exists( 'Tribe__Events__Main' ) ) {
	require get_template_directory() . '/inc/the-events-calendar.php';
}

/**
 * Multi-branded plugin support
 */
if ( class_exists( 'Newspack_Multibranded_Site\Customizations\Theme_Colors' ) ) {
	require get_template_directory() . '/inc/newspack-multibranded-site-plugin.php';
}

/**
 * Load Block Editor Colors compatibility file.
 */
if ( defined( 'BEC_PLUGIN_VERSION' ) ) {
	require get_template_directory() . '/inc/block-editor-colors.php';
}

/**
 * Woo Templates cache handling
 */
require get_template_directory() . '/woocommerce/templates.php';

