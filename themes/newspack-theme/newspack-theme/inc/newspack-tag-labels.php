<?php
/**
 * Newspack Tag Labels shim
 *
 * @package Newspack
 */

if ( ! function_exists( 'newspack_get_tag_labels' ) ) :
	/**
	 * Returns array of tag labels for the given post.
	 *
	 * @param int|WP_Post|null $post Post to check.
	 *
	 * @return array|null
	 */
	function newspack_get_tag_labels( $post = null ) {

		if ( class_exists( '\Newspack\Tag_Labels' ) && method_exists( '\Newspack\Tag_Labels', 'get_labels_for_post' ) ) {
			return \Newspack\Tag_Labels::get_labels_for_post( $post );
		}

		return null;
	}
endif;


if ( ! function_exists( 'newspack_generate_tag_labels' ) ) :
	/**
	 * Generates HTML for given tag labels.
	 *
	 * @param array $labels Labels to display.
	 * @param bool  $links  Whether to include links to tag archives.
	 * @param array $outer_classes Classes to apply to the outer container.
	 * @param array $inner_classes Classes to apply to the inner container.
	 *
	 * @return string       Tag labels as HTML.
	 */
	function newspack_generate_tag_labels( $labels = null, $links = true, $outer_classes = array( 'tag-labels' ), $inner_classes = array( 'tag-label', 'flag' ) ) {
		if ( class_exists( '\Newspack\Tag_Labels' ) && method_exists( '\Newspack\Tag_Labels', 'generate_html' ) ) {
			return \Newspack\Tag_Labels::generate_html( $labels, $links, $outer_classes, $inner_classes, 'span' );
		}

		return '';
	}
endif;

if ( ! function_exists( 'newspack_display_tag_labels' ) ) :
	/**
	 * Outputs HTML for given tag labels.
	 *
	 * @param array $labels Labels to display.
	 * @param bool  $links  Whether to include links to tag archives.
	 *
	 * @return null
	 */
	function newspack_display_tag_labels( $labels = null, $links = true ) {
		if ( class_exists( '\Newspack\Tag_Labels' ) && method_exists( '\Newspack\Tag_Labels', 'display' ) ) {
			\Newspack\Tag_Labels::display( $labels, $links, 'span' );
		}

		return null;
	}
endif;

/**
 * Enqueue styles.
 */
function newspack_tag_labels_enqueue_styles() {
	if ( ( defined( 'WP_CLI' ) && WP_CLI ) || is_admin() || ! class_exists( 'Newspack\Tag_Labels' ) ) {
		return;
	}
	wp_enqueue_style(
		'newspack-tag-labels-style',
		get_template_directory_uri() . '/styles/newspack-tag-labels.css',
		array( 'newspack-style' ),
		wp_get_theme()->get( 'Version' )
	);
	wp_style_add_data( 'newspack-tag-labels-style', 'rtl', 'replace' );
}
add_action( 'wp_enqueue_scripts', 'newspack_tag_labels_enqueue_styles' );

/**
 * Enqueue supplemental block editor styles.
 */
function newspack_tag_labels_editor_styles() {
	if ( ! class_exists( 'Newspack\Tag_Labels' ) ) {
		return;
	}
	wp_enqueue_style( 'newspack-tag-labels-editor-styles', get_theme_file_uri( '/styles/newspack-tag-labels-editor.css' ), false, wp_get_theme()->get( 'Version' ), 'all' );
	wp_style_add_data( 'newspack-tag-labels-editor-styles', 'rtl', 'replace' );
}
add_action( 'enqueue_block_editor_assets', 'newspack_tag_labels_editor_styles' );


/**
 * Adds section to customizer for Tag Labels options.
 *
 * @param WP_Customize_Manager $wp_customize Customizer object.
 */
function newspack_tag_labels_customize_register( $wp_customize ) {
	if ( ! class_exists( 'Newspack\Tag_Labels' ) ) {
		return;
	}

	$wp_customize->add_section(
		'newspack_tag_labels',
		array(
			'title' => esc_html__( 'Tag Labels', 'newspack-theme' ),
		)
	);

	$wp_customize->add_setting(
		'tag_labels_hex',
		array(
			'default'           => '#FED850',
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);

	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'tag_labels_hex',
			array(
				'label'       => esc_html__( 'Tag Label', 'newspack-theme' ),
				'description' => esc_html__( 'Changes the background of the tag label that appears on posts and blocks. It should stand out boldly against your site\'s color scheme.', 'newspack-theme' ),
				'section'     => 'newspack_tag_labels',
			)
		)
	);
}
add_action( 'customize_register', 'newspack_tag_labels_customize_register' );

/**
 * Add custom colors to tag labels.
 */
function newspack_tag_labels_styles() {
	if ( ! class_exists( 'Newspack\Tag_Labels' ) ) {
		return;
	}

	$flag_color          = get_theme_mod( 'tag_labels_hex', '#FED850' );
	$flag_color_contrast = newspack_get_color_contrast( $flag_color );
	?>
	<style>
		<?php // Match `.tag-label.flag` so this ties the base stylesheet rule (specificity 0,3,0) and wins on source order in single/archive contexts. ?>
		.tag-labels .tag-label.flag,
		amp-script .tag-labels .tag-label.flag,
		.wpnbha .tag-labels a.flag,
		.featured-image-behind .tag-labels a.flag {
			background: <?php echo esc_attr( $flag_color ); ?>;
			color: <?php echo esc_attr( $flag_color_contrast ); ?>;
		}
	</style>
	<?php
}
add_action( 'wp_head', 'newspack_tag_labels_styles' );

/**
 * Add custom colors for tag labels to editor.
 */
function newspack_tag_labels_styles_editor() {
	if ( ! class_exists( 'Newspack\Tag_Labels' ) ) {
		return;
	}

	$flag_color          = get_theme_mod( 'tag_labels_hex', '#FED850' );
	$flag_color_contrast = newspack_get_color_contrast( $flag_color );

	$tag_labels_customizations = '
		.editor-styles-wrapper .tag-labels .flag  {
			background: ' . esc_attr( $flag_color ) . ';
			color: ' . esc_attr( $flag_color_contrast ) . ';
		}
	';

	wp_add_inline_style( 'newspack-tag-labels-editor-styles', $tag_labels_customizations );
}
add_action( 'enqueue_block_editor_assets', 'newspack_tag_labels_styles_editor' );
