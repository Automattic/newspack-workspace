<?php
/**
 * Newspack Theme: Customizer
 *
 * @package Newspack
 */

/**
 * Add postMessage support for site title and description for the Theme Customizer.
 *
 * @param WP_Customize_Manager $wp_customize Theme Customizer object.
 */
function newspack_customize_register( $wp_customize ) {
	$wp_customize->get_setting( 'blogname' )->transport         = 'postMessage';
	$wp_customize->get_setting( 'blogdescription' )->transport  = 'postMessage';
	$wp_customize->get_setting( 'header_textcolor' )->transport = 'postMessage';
	$wp_customize->get_control( 'header_text' )->label          = __( 'Display Site Title', 'newspack-theme' );

	if ( isset( $wp_customize->selective_refresh ) ) {
		$wp_customize->selective_refresh->add_partial(
			'blogname',
			array(
				'selector'        => '.site-title a',
				'render_callback' => 'newspack_customize_partial_blogname',
			)
		);
		$wp_customize->selective_refresh->add_partial(
			'blogdescription',
			array(
				'selector'        => '.site-description',
				'render_callback' => 'newspack_customize_partial_blogdescription',
			)
		);
	}

	/**
	 * Header Options
	 */
	$wp_customize->add_panel(
		'newspack_header_options',
		array(
			'title' => esc_html__( 'Header Settings', 'newspack-theme' ),
		)
	);

	/**
	 * Header Appearance
	 */
	$wp_customize->add_section(
		'header_section_appearance',
		array(
			'title' => esc_html__( 'Appearance', 'newspack-theme' ),
			'panel' => 'newspack_header_options',
		)
	);

	// Header - add option to center logo.
	$wp_customize->add_setting(
		'header_center_logo',
		array(
			'default'           => false,
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'header_center_logo',
		array(
			'type'        => 'checkbox',
			'label'       => esc_html__( 'Center Logo', 'newspack-theme' ),
			'description' => esc_html__( 'Check to center the logo in the header.', 'newspack-theme' ),
			'section'     => 'header_section_appearance',
		)
	);

	// Header - add option for solid background colour.
	$wp_customize->add_setting(
		'header_solid_background',
		array(
			'default'           => false,
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'header_solid_background',
		array(
			'type'        => 'checkbox',
			'label'       => esc_html__( 'Solid Background', 'newspack-theme' ),
			'description' => esc_html__( 'Check to use the primary color as the header background. Can be changed under "Colors".', 'newspack-theme' ),
			'section'     => 'header_section_appearance',
		)
	);

	// Header - add option for simplified short header.
	$wp_customize->add_setting(
		'header_simplified',
		array(
			'default'           => false,
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'header_simplified',
		array(
			'type'        => 'checkbox',
			'label'       => esc_html__( 'Short Header', 'newspack-theme' ),
			'description' => esc_html__( 'Displays header as a shorter, simpler version.', 'newspack-theme' ),
			'section'     => 'header_section_appearance',
		)
	);

	// Header - add option for simplified short header.
	$wp_customize->add_setting(
		'header_sticky',
		array(
			'default'           => false,
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'header_sticky',
		array(
			'type'        => 'checkbox',
			'label'       => esc_html__( 'Sticky Header', 'newspack-theme' ),
			'description' => esc_html__( 'Makes header "stick" to the top of the page on scroll. Forces a fixed height.', 'newspack-theme' ),
			'section'     => 'header_section_appearance',
		)
	);

	/**
	 * Header Slideouts
	 */
	$wp_customize->add_section(
		'header_section_slideout',
		array(
			'title' => esc_html__( 'Slide-out Sidebar', 'newspack-theme' ),
			'panel' => 'newspack_header_options',
		)
	);

	// Header - option to add slideout.
	$wp_customize->add_setting(
		'header_show_slideout',
		array(
			'default'           => false,
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'header_show_slideout',
		array(
			'type'        => 'checkbox',
			'label'       => esc_html__( 'Show Slide-out Sidebar', 'newspack-theme' ),
			'description' => sprintf(
				/* translators: %s: link to Slide Out Sidebar widget panel in Customizer. */
				esc_html__( 'Show a Slide-out sidebar in the header, which you can populate by adding widgets %1$s.', 'newspack-theme' ),
				'<a rel="goto-section" href="#sidebar-widgets-header-1">' . __( 'here', 'newspack-theme' ) . '</a>'
			),
			'section'     => 'header_section_slideout',
		)
	);

	// Header - label for slide out sidebar
	$wp_customize->add_setting(
		'slideout_label',
		array(
			'default'           => esc_html__( 'Menu', 'newspack-theme' ),
			'sanitize_callback' => 'sanitize_text_field',
		)
	);
	$wp_customize->add_control(
		'slideout_label',
		array(
			'type'        => 'text',
			'label'       => esc_html__( 'Slide-out Sidebar Text', 'newspack-theme' ),
			'description' => esc_html__( 'Use this field to change the text on the Slide-out Sidebar toggle. The text is not visible when using the short header, but can always be read by screen readers.', 'newspack-theme' ),
			'section'     => 'header_section_slideout',
		)
	);

	// Header - label for slide out menu
	$wp_customize->add_setting(
		'slideout_widget_mobile',
		array(
			'default'           => false,
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'slideout_widget_mobile',
		array(
			'type'        => 'checkbox',
			'label'       => esc_html__( 'Add slide-out widgets to mobile menu', 'newspack-theme' ),
			'description' => esc_html__( 'Adds the widgets assigned to the Slide-out Sidebar area to the mobile menu, too.', 'newspack-theme' ),
			'section'     => 'header_section_slideout',
		)
	);

	// Header - slide out menu position
	$wp_customize->add_setting(
		'slideout_sidebar_side',
		array(
			'default'           => 'left',
			'sanitize_callback' => 'newspack_sanitize_slideout_sidebar_side',
		)
	);
	$wp_customize->add_control(
		'slideout_sidebar_side',
		array(
			'type'    => 'radio',
			'label'   => esc_html__( 'Slide-out sidebar side', 'newspack-theme' ),
			'choices' => array(
				'left'  => _x( 'Left', 'slide-out menu side', 'newspack-theme' ),
				'right' => _x( 'Right', 'slide-out menu side', 'newspack-theme' ),
			),
			'section' => 'header_section_slideout',
		)
	);

	/**
	 * Header Slideouts
	 */
	$wp_customize->add_section(
		'header_section_subpages',
		array(
			'title' => esc_html__( 'Subpage Header', 'newspack-theme' ),
			'panel' => 'newspack_header_options',
		)
	);

	// Header - option for v. simplified header on subpages.
	$wp_customize->add_setting(
		'header_sub_simplified',
		array(
			'default'           => false,
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'header_sub_simplified',
		array(
			'type'        => 'checkbox',
			'label'       => esc_html__( 'Use simple header on subpages', 'newspack-theme' ),
			'description' => esc_html__( 'On posts, pages, archive and search results, use a header that only displays the site logo and search icon, with all menus hidden under a toggle.', 'newspack-theme' ),
			'section'     => 'header_section_subpages',
		)
	);

	/**
	 * Header - Mobile Donate CTA
	 */
	$wp_customize->add_section(
		'header_section_cta',
		array(
			'title' => esc_html__( 'Mobile Call-to-Action', 'newspack-theme' ),
			'panel' => 'newspack_header_options',
		)
	);

	// Mobile CTA - toggle on and off.
	$wp_customize->add_setting(
		'show_header_cta',
		array(
			'default'           => false,
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'show_header_cta',
		array(
			'type'        => 'checkbox',
			'label'       => esc_html__( 'Show Mobile CTA', 'newspack-theme' ),
			'description' => esc_html__( 'Show an essential call-to-action button in the mobile header, that is always visible.', 'newspack-theme' ),
			'section'     => 'header_section_cta',
		)
	);

	// Mobile CTA - button text.
	$wp_customize->add_setting(
		'header_cta_text',
		array(
			'default'           => esc_html__( 'Donate', 'newspack-theme' ),
			'sanitize_callback' => 'sanitize_text_field',
		)
	);
	$wp_customize->add_control(
		'header_cta_text',
		array(
			'type'    => 'text',
			'label'   => esc_html__( 'Button Text', 'newspack-theme' ),
			'section' => 'header_section_cta',
		)
	);

	// Mobile CTA - URL.
	$wp_customize->add_setting(
		'header_cta_url',
		array(
			'default'           => '',
			'sanitize_callback' => 'esc_url_raw',
		)
	);

	$wp_customize->add_control(
		'header_cta_url',
		array(
			'label'   => esc_html__( 'Button URL', 'newspack-theme' ),
			'type'    => 'text',
			'section' => 'header_section_cta',
		)
	);

	// Mobile CTA - link target.
	$wp_customize->add_setting(
		'header_cta_target',
		array(
			'default'           => false,
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);

	$wp_customize->add_control(
		'header_cta_target',
		array(
			'label'   => esc_html__( 'Open link in new window', 'newspack-theme' ),
			'type'    => 'checkbox',
			'section' => 'header_section_cta',
		)
	);


	// Mobile CTA - button color.
	$wp_customize->add_setting(
		'header_cta_hex',
		array(
			'default'           => newspack_get_mobile_cta_color(),
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);

	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'header_cta_hex',
			array(
				'label'       => esc_html__( 'Background Color', 'newspack-theme' ),
				'description' => __( 'Selecting a strong, non-palette color is recommended to ensure the CTA stands out.', 'newspack-theme' ),
				'section'     => 'header_section_cta',
			)
		)
	);

	// Mobile CTA - toggle on and off.
	$wp_customize->add_setting(
		'cta_in_simplified_header',
		array(
			'default'           => false,
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'cta_in_simplified_header',
		array(
			'type'    => 'checkbox',
			'label'   => esc_html__( 'Show Mobile CTA in Simplfied Subpage Header', 'newspack-theme' ),
			'section' => 'header_section_cta',
		)
	);

	// Add option to upload logo specifically for the footer.
	$wp_customize->add_setting(
		'newspack_alternative_logo',
		array(
			'default'           => '',
			'sanitize_callback' => 'absint',
		)
	);

	$wp_customize->add_control(
		new WP_Customize_Cropped_Image_Control(
			$wp_customize,
			'newspack_alternative_logo',
			array(
				'label'       => esc_html__( 'Alternative Logo', 'newspack-theme' ),
				'description' => esc_html__( 'Upload an alternative logo to be used on posts with the featured image behind and featured image beside settings, where the logo will be overlapping.', 'newspack-theme' ),
				'section'     => 'header_section_subpages',
				'settings'    => 'newspack_alternative_logo',
				'flex_width'  => false,
				'flex_height' => true,
				'width'       => 400,
				'height'      => 300,
			)
		)
	);

	/**
	 * Primary color.
	 */
	$wp_customize->add_setting(
		'theme_colors',
		array(
			'default'           => 'default',
			'sanitize_callback' => 'newspack_sanitize_color_option',
		)
	);

	$wp_customize->add_control(
		'theme_colors',
		array(
			'type'    => 'radio',
			'label'   => __( 'Colors', 'newspack-theme' ),
			'choices' => array(
				'default' => _x( 'Default', 'primary color', 'newspack-theme' ),
				'custom'  => _x( 'Custom', 'primary color', 'newspack-theme' ),
			),
			'section' => 'colors',
		)
	);

	// Add primary color hexidecimal setting and control.
	$wp_customize->add_setting(
		'primary_color_hex',
		array(
			'default'           => newspack_get_primary_color(),
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);

	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'primary_color_hex',
			array(
				'description' => __( 'Apply a primary custom color.', 'newspack-theme' ),
				'section'     => 'colors',
			)
		)
	);

	// Add secondary color hexidecimal setting and control.
	$wp_customize->add_setting(
		'secondary_color_hex',
		array(
			'default'           => newspack_get_secondary_color(),
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);

	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'secondary_color_hex',
			array(
				'description' => __( 'Apply a secondary custom color.', 'newspack-theme' ),
				'section'     => 'colors',
			)
		)
	);

	/**
	 * Header background_color
	 */
	$wp_customize->add_setting(
		'header_color',
		array(
			'default'           => 'default',
			'sanitize_callback' => 'newspack_sanitize_color_option',
		)
	);

	$wp_customize->add_control(
		'header_color',
		array(
			'type'    => 'radio',
			'label'   => __( 'Header Background Color', 'newspack-theme' ),
			'choices' => array(
				'default' => _x( 'Default', 'header background color', 'newspack-theme' ),
				'custom'  => _x( 'Custom', 'header background color', 'newspack-theme' ),
			),
			'section' => 'colors',
		)
	);

	// Add header color hexidecimal setting and control.
	$wp_customize->add_setting(
		'header_color_hex',
		array(
			'default'           => '#666666',
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);

	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'header_color_hex',
			array(
				'description' => __( 'Apply a background color to the header.', 'newspack-theme' ),
				'section'     => 'colors',
			)
		)
	);

	// Add primary menu color hexidecimal setting and control.
	$wp_customize->add_setting(
		'header_primary_menu_color_hex',
		array(
			'default'           => '',
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);

	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'header_primary_menu_color_hex',
			array(
				'description' => __( 'Apply a background color to the primary menu.', 'newspack-theme' ),
				'section'     => 'colors',
			)
		)
	);

	/**
	 * Footer background_color
	 */
	$wp_customize->add_setting(
		'footer_color',
		array(
			'default'           => 'default',
			'sanitize_callback' => 'newspack_sanitize_color_option',
		)
	);

	$wp_customize->add_control(
		'footer_color',
		array(
			'type'    => 'radio',
			'label'   => __( 'Footer Background Color', 'newspack-theme' ),
			'choices' => array(
				'default' => _x( 'Default', 'footer background color', 'newspack-theme' ),
				'custom'  => _x( 'Custom', 'footer background color', 'newspack-theme' ),
			),
			'section' => 'colors',
		)
	);

	// Add footer color hexidecimal setting and control.
	$wp_customize->add_setting(
		'footer_color_hex',
		array(
			'default'           => '',
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);

	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'footer_color_hex',
			array(
				'description' => __( 'Apply a background color to the footer.', 'newspack-theme' ),
				'section'     => 'colors',
			)
		)
	);

	/**
	 * Ads background_color
	 */
	$wp_customize->add_setting(
		'ads_color',
		array(
			'default'           => 'default',
			'sanitize_callback' => 'newspack_sanitize_color_option',
		)
	);

	$wp_customize->add_control(
		'ads_color',
		array(
			'type'    => 'radio',
			'label'   => __( 'Ads Background Color', 'newspack-theme' ),
			'choices' => array(
				'default' => _x( 'Default', 'primary color', 'newspack-theme' ),
				'custom'  => _x( 'Custom', 'primary color', 'newspack-theme' ),
			),
			'section' => 'colors',
		)
	);

	// Add ads color hexidecimal setting and control.
	$wp_customize->add_setting(
		'ads_color_hex',
		array(
			'default'           => '#ffffff',
			'sanitize_callback' => 'sanitize_hex_color',
		)
	);

	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'ads_color_hex',
			array(
				'description' => __( 'Apply a background color to the ads.', 'newspack-theme' ),
				'section'     => 'colors',
			)
		)
	);

	// Header - add option to hide tagline.
	$wp_customize->add_setting(
		'header_display_tagline',
		array(
			'default'           => true,
			'transport'         => 'postMessage',
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'header_display_tagline',
		array(
			'type'    => 'checkbox',
			'label'   => esc_html__( 'Display Tagline', 'newspack-theme' ),
			'section' => 'title_tagline',
		)
	);

	// Add option to hide page title on static front page.
	$wp_customize->add_setting(
		'hide_front_page_title',
		array(
			'default'           => false,
			'type'              => 'theme_mod',
			'transport'         => 'postMessage',
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);

	$wp_customize->add_control(
		'hide_front_page_title',
		array(
			'label'       => esc_html__( 'Hide Homepage Title', 'newspack-theme' ),
			'description' => esc_html__( 'Check to hide the page title, if your homepage is set to display a static page.', 'newspack-theme' ),
			'section'     => 'static_front_page',
			'priority'    => 10,
			'type'        => 'checkbox',
			'settings'    => 'hide_front_page_title',
		)
	);

	// Turn the homepage into a 'splash' page: no header and no footer.
	$wp_customize->add_setting(
		'splash_page_home',
		array(
			'default'           => false,
			'type'              => 'theme_mod',
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);

	$wp_customize->add_control(
		'splash_page_home',
		array(
			'label'       => esc_html__( 'Make the homepage a splash page', 'newspack-theme' ),
			'description' => esc_html__( 'Hide the header and footer on the homepage so it can be a splash page or placeholder for a site under development.', 'newspack-theme' ),
			'section'     => 'static_front_page',
			'priority'    => 10,
			'type'        => 'checkbox',
			'settings'    => 'splash_page_home',
		)
	);

	// Add option to upload logo specifically for the footer.
	$wp_customize->add_setting(
		'newspack_footer_logo',
		array(
			'default'           => '',
			'sanitize_callback' => 'absint',
		)
	);

	$wp_customize->add_control(
		new WP_Customize_Cropped_Image_Control(
			$wp_customize,
			'newspack_footer_logo',
			array(
				'label'       => esc_html__( 'Footer Logo', 'newspack-theme' ),
				'description' => esc_html__( 'Optional alternative logo to be displayed in the footer.', 'newspack-theme' ),
				'section'     => 'title_tagline',
				'settings'    => 'newspack_footer_logo',
				'priority'    => 9,
				'flex_width'  => true,
				'flex_height' => true,
				'width'       => 400,
				'height'      => 300,
			)
		)
	);

	$wp_customize->add_setting(
		'footer_logo_size',
		array(
			'default'           => 'medium',
			'sanitize_callback' => 'newspack_sanitize_footer_logo_size',
		)
	);

	$wp_customize->add_control(
		'footer_logo_size',
		array(
			'label'    => esc_html__( 'Footer Logo Size', 'newspack-theme' ),
			'section'  => 'title_tagline',
			'priority' => 9,
			'type'     => 'select',
			'settings' => 'footer_logo_size',
			'choices'  => array(
				'small'  => esc_html__( 'Small', 'newspack-theme' ),
				'medium' => esc_html__( 'Medium', 'newspack-theme' ),
				'large'  => esc_html__( 'Large', 'newspack-theme' ),
				'xlarge' => esc_html__( 'Extra Large', 'newspack-theme' ),
			),
		)
	);

	/**
	 * Author Bio options
	 */
	$wp_customize->add_section(
		'author_bio_options',
		array(
			'title' => esc_html__( 'Author Bio Settings', 'newspack-theme' ),
		)
	);

	// Add option to hide the whole author bio.
	$wp_customize->add_setting(
		'show_author_bio',
		array(
			'default'           => true,
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'show_author_bio',
		array(
			'type'        => 'checkbox',
			'label'       => esc_html__( 'Display Author Bio', 'newspack-theme' ),
			'description' => esc_html__( 'Display Author Bio under individual posts.', 'newspack-theme' ),
			'section'     => 'author_bio_options',
		)
	);

	// Add option to hide author email address.
	$wp_customize->add_setting(
		'show_author_email',
		array(
			'default'           => false,
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'show_author_email',
		array(
			'type'        => 'checkbox',
			'label'       => esc_html__( 'Display Author Email', 'newspack-theme' ),
			'description' => esc_html__( 'Display Author email with bio on individual posts and author archives.', 'newspack-theme' ),
			'section'     => 'author_bio_options',
		)
	);

	// Add option to hide author social links.
	$wp_customize->add_setting(
		'show_author_social',
		array(
			'default'           => false,
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'show_author_social',
		array(
			'type'        => 'checkbox',
			'label'       => esc_html__( 'Display Author Social Media links', 'newspack-theme' ),
			'description' => esc_html__( 'Display social media links with the author bio on individual posts and author archives (this option requires the Yoast plugin).', 'newspack-theme' ),
			'section'     => 'author_bio_options',
		)
	);

	// Add option to hide author email address.
	$wp_customize->add_setting(
		'author_bio_truncate',
		array(
			'default'           => true,
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'author_bio_truncate',
		array(
			'type'        => 'checkbox',
			'label'       => esc_html__( 'Truncate Author Bio', 'newspack-theme' ),
			'description' => esc_html__( 'Set a specific length for author bios displayed on single posts.', 'newspack-theme' ),
			'section'     => 'author_bio_options',
		)
	);

	// Add option to hide the whole author bio.
	$wp_customize->add_setting(
		'author_bio_length',
		array(
			'default'           => 200,
			'sanitize_callback' => 'absint',
		)
	);
	$wp_customize->add_control(
		'author_bio_length',
		array(
			'type'        => 'number',
			'label'       => esc_html__( 'Author Bio Length (in characters)', 'newspack-theme' ),
			'description' => esc_html__( 'Truncates the author bio on single posts to this approximate character length, but without breaking a word.', 'newspack-theme' ),
			'section'     => 'author_bio_options',
		)
	);

	// Shared avatar image size for author archive and author bio templates.
	$wp_customize->add_setting(
		'author_avatar_size',
		array(
			'default'           => 120,
			'sanitize_callback' => 'newspack_sanitize_author_avatar_size',
		)
	);
	$wp_customize->add_control(
		'author_avatar_size',
		array(
			'type'        => 'number',
			'label'       => esc_html__( 'Author Avatar Size', 'newspack-theme' ),
			'description' => esc_html__( 'One shared setting for author archive and author bio avatars. Values are saved in the 32-512px range. This controls the requested image size, but the final on-screen size is still controlled by CSS -- both will need to be changed to change the visual size of the avatar.', 'newspack-theme' ),
			'input_attrs' => array(
				'min'  => 32,
				'max'  => 512,
				'step' => 1,
			),
			'section'     => 'author_bio_options',
		)
	);

	/**
	 * Template Settings
	 */
	$wp_customize->add_panel(
		'newspack_template_settings',
		array(
			'title' => esc_html__( 'Template Settings', 'newspack-theme' ),
		)
	);

	/**
	 * Post Template Settings
	 */
	$wp_customize->add_section(
		'post_default_settings',
		array(
			'title' => esc_html__( 'Post Settings', 'newspack-theme' ),
			'panel' => 'newspack_template_settings',
		)
	);

	// Add option to set a featured image default.
	$wp_customize->add_setting(
		'featured_image_default',
		array(
			'default'           => 'large',
			'sanitize_callback' => 'newspack_sanitize_feature_image_position',
		)
	);
	$wp_customize->add_control(
		'featured_image_default',
		array(
			'type'        => 'radio',
			'label'       => __( 'Featured Image Default Position', 'newspack-theme' ),
			'description' => esc_html__( 'Affects all posts where the Featured Image Position is set to \'Default\'.', 'newspack-theme' ),
			'choices'     => array(
				'large'  => esc_html__( 'Large', 'newspack-theme' ),
				'small'  => esc_html__( 'Small', 'newspack-theme' ),
				'behind' => esc_html__( 'Behind article title', 'newspack-theme' ),
				'beside' => esc_html__( 'Beside article title', 'newspack-theme' ),
				'above'  => esc_html__( 'Above article title', 'newspack-theme' ),
				'hidden' => esc_html__( 'Hidden', 'newspack-theme' ),
			),
			'section'     => 'post_default_settings',
		)
	);

	// Add option to select the default post template.
	$wp_customize->add_setting(
		'post_template_default',
		array(
			'default'           => 'default',
			'sanitize_callback' => 'newspack_sanitize_post_template',
		)
	);
	$wp_customize->add_control(
		'post_template_default',
		array(
			'type'        => 'select',
			'label'       => __( 'Default Post Template', 'newspack-theme' ),
			'description' => esc_html__( 'This option changes the selected template used for newly created posts going forward. The template can still be changed on a per-post basis.', 'newspack-theme' ),
			'choices'     => array(
				'default'            => esc_html__( 'With Sidebar', 'newspack-theme' ),
				'single-feature.php' => esc_html__( 'One Column', 'newspack-theme' ),
				'single-wide.php'    => esc_html__( 'One Column Wide', 'newspack-theme' ),
			),
			'section'     => 'post_default_settings',
		)
	);
	// Add option to display previous and next links on single posts.
	$wp_customize->add_setting(
		'post_previous_next',
		array(
			'default'           => false,
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'post_previous_next',
		array(
			'type'    => 'checkbox',
			'label'   => __( 'Display previous and next links at the bottom of each post.', 'newspack-theme' ),
			'section' => 'post_default_settings',
		)
	);

	// Add option to display previous and next links on single posts.
	$wp_customize->add_setting(
		'post_excerpt_instead_of_subtitle',
		array(
			'default'           => false,
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'post_excerpt_instead_of_subtitle',
		array(
			'type'    => 'checkbox',
			'label'   => __( 'Display the custom excerpt at the top of single posts instead of the article subtitle.', 'newspack-theme' ),
			'section' => 'post_default_settings',
		)
	);

	/**
	 * Page Template Settings
	 */
	$wp_customize->add_section(
		'page_default_settings',
		array(
			'title' => esc_html__( 'Page Settings', 'newspack-theme' ),
			'panel' => 'newspack_template_settings',
		)
	);

	// Add option to set a featured image default.
	$wp_customize->add_setting(
		'page_featured_image_default',
		array(
			'default'           => 'small',
			'sanitize_callback' => 'newspack_sanitize_feature_image_position',
		)
	);
	$wp_customize->add_control(
		'page_featured_image_default',
		array(
			'type'        => 'radio',
			'label'       => __( 'Featured Image Default Position', 'newspack-theme' ),
			'description' => esc_html__( 'Affects all pages where the Featured Image Position is set to \'Default\'.', 'newspack-theme' ),
			'choices'     => array(
				'large'  => esc_html__( 'Large', 'newspack-theme' ),
				'small'  => esc_html__( 'Small', 'newspack-theme' ),
				'behind' => esc_html__( 'Behind article title', 'newspack-theme' ),
				'beside' => esc_html__( 'Beside article title', 'newspack-theme' ),
				'above'  => esc_html__( 'Above article title', 'newspack-theme' ),
				'hidden' => esc_html__( 'Hidden', 'newspack-theme' ),
			),
			'section'     => 'page_default_settings',
		)
	);

	// Add option to select the d page template.
	$wp_customize->add_setting(
		'page_template_default',
		array(
			'default'           => 'default',
			'sanitize_callback' => 'newspack_sanitize_post_template',
		)
	);
	$wp_customize->add_control(
		'page_template_default',
		array(
			'type'        => 'select',
			'label'       => __( 'Default Page Template', 'newspack-theme' ),
			'description' => esc_html__( 'This option changes the selected template used for newly created pages going forward. The template can still be changed on a per-page basis.', 'newspack-theme' ),
			'choices'     => array(
				'default'            => esc_html__( 'With Sidebar', 'newspack-theme' ),
				'single-feature.php' => esc_html__( 'One Column', 'newspack-theme' ),
				'single-wide.php'    => esc_html__( 'One Column Wide', 'newspack-theme' ),
			),
			'section'     => 'page_default_settings',
		)
	);

	/**
	 * Archive settings
	 */
	$wp_customize->add_section(
		'archive_options',
		array(
			'title'       => esc_html__( 'Archive Settings', 'newspack-theme' ),
			'panel'       => 'newspack_template_settings',
			'description' => esc_html__( 'These settings affect all archives, including the blog posts page.', 'newspack-theme' ),
		)
	);

	// Add option to show excerpts for all archives.
	$wp_customize->add_setting(
		'archive_show_excerpt',
		array(
			'default'           => false,
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'archive_show_excerpt',
		array(
			'type'    => 'checkbox',
			'label'   => esc_html__( 'Show excerpts for all archives', 'newspack-theme' ),
			'section' => 'archive_options',
		)
	);

	// Add option to show subtitles for all archives.
	$wp_customize->add_setting(
		'archive_show_subtitle',
		array(
			'default'           => false,
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'archive_show_subtitle',
		array(
			'type'    => 'checkbox',
			'label'   => esc_html__( 'Show subtitles for all archives', 'newspack-theme' ),
			'section' => 'archive_options',
		)
	);

	// Add option to enable image cropping in the archive pages.
	$wp_customize->add_setting(
		'archive_enable_cropping',
		array(
			'default'           => true,
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'archive_enable_cropping',
		array(
			'type'    => 'checkbox',
			'label'   => esc_html__( 'Crop archive images to a 4:3 aspect ratio (changes require regenerating thumbnails for existing featured images)', 'newspack-theme' ),
			'section' => 'archive_options',
		)
	);

	// Add option to show image captions in archives.
	$wp_customize->add_setting(
		'archive_show_captions',
		array(
			'default'           => false,
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'archive_show_captions',
		array(
			'type'    => 'checkbox',
			'label'   => esc_html__( 'Show image captions in archives and WordPress’s default search results', 'newspack-theme' ),
			'section' => 'archive_options',
		)
	);

	// Add option to show image credits in archives.
	$wp_customize->add_setting(
		'archive_show_credits',
		array(
			'default'           => false,
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'archive_show_credits',
		array(
			'type'    => 'checkbox',
			'label'   => esc_html__( 'Show image credits in archives and WordPress’s default search results', 'newspack-theme' ),
			'section' => 'archive_options',
		)
	);

	// Add option to change the first archive's layout.
	$wp_customize->add_setting(
		'archive_feature_latest_post',
		array(
			'default'           => true,
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);

	$wp_customize->add_control(
		'archive_feature_latest_post',
		array(
			'type'    => 'checkbox',
			'label'   => esc_html__( 'Use a large, featured display for the latest post in the archives', 'newspack-theme' ),
			'section' => 'archive_options',
		)
	);

	// Add option to change archive layouts.
	$wp_customize->add_setting(
		'archive_layout',
		array(
			'default'           => 'default',
			'sanitize_callback' => 'newspack_sanitize_radio',
		)
	);
	$wp_customize->add_control(
		'archive_layout',
		array(
			'type'    => 'radio',
			'label'   => esc_html__( 'Archive Layout', 'newspack-theme' ),
			'choices' => array(
				'default'         => esc_html__( 'With sidebar', 'newspack-theme' ),
				'one-column'      => esc_html__( 'One column', 'newspack-theme' ),
				'one-column-wide' => esc_html__( 'One column wide', 'newspack-theme' ),
			),
			'section' => 'archive_options',
		)
	);

	// Add option to customize archive titles.
	$wp_customize->add_setting(
		'archive_title_format',
		array(
			'default'           => 'default',
			'sanitize_callback' => 'newspack_sanitize_radio',
		)
	);

	$wp_customize->add_control(
		'archive_title_format',
		array(
			'type'        => 'radio',
			'label'       => esc_html__( 'Archive Title Format', 'newspack-theme' ),
			'description' => esc_html__( 'Change the format of the title used on archive pages.', 'newspack-theme' ),
			'choices'     => array(
				'default' => esc_html__( 'Default (eg. "Category: Featured", "Author: Jane Doe")', 'newspack-theme' ),
				'short'   => esc_html__( 'Only archive name (eg. "Featured", "Jane Doe")', 'newspack-theme' ),
			),
			'section'     => 'archive_options',
		)
	);

		// Add option to to switch between list and grid.
		$wp_customize->add_setting(
			'archive_list_or_grid',
			array(
				'default'           => 'list',
				'sanitize_callback' => 'newspack_sanitize_radio',
			)
		);
		$wp_customize->add_control(
			'archive_list_or_grid',
			array(
				'type'        => 'radio',
				'label'       => esc_html__( 'List or Grid Layout', 'newspack-theme' ),
				'description' => esc_html__( 'When using grid, set the number of posts per page to a number divisible by 12 under Settings > Reading and disable the "Use a large, featured display for the latest post in the archives" above to get a full bottom row of posts.', 'newspack-theme' ),
				'choices'     => array(
					'list' => esc_html__( 'List', 'newspack-theme' ),
					'grid' => esc_html__( 'Grid', 'newspack-theme' ),
				),
				'section'     => 'archive_options',
			)
		);

	/**
	 * Comments settings
	 */
	$wp_customize->add_section(
		'comments_options',
		array(
			'title' => esc_html__( 'Comments Settings', 'newspack-theme' ),
		)
	);

	// Add option to collapse the comments.
	$wp_customize->add_setting(
		'collapse_comments',
		array(
			'default'           => false,
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'collapse_comments',
		array(
			'type'        => 'checkbox',
			'label'       => esc_html__( 'Collapse Comments', 'newspack-theme' ),
			'description' => esc_html__( 'When using WordPress\'s default comments, checking this option will collapse the comments section when there is more than one comment, and display a button to expand.', 'newspack-theme' ),
			'section'     => 'comments_options',
		)
	);

	// Add option to collapse the comments.
	$wp_customize->add_setting(
		'display_comment_policy',
		array(
			'default'           => false,
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'display_comment_policy',
		array(
			'type'        => 'checkbox',
			'label'       => esc_html__( 'Display comment policy', 'newspack-theme' ),
			'description' => esc_html__( 'Allows you to add an optional comment policy above the comment form when using WordPress\'s default comments.', 'newspack-theme' ),
			'section'     => 'comments_options',
		)
	);

	// Add option to display comment policy.
	$wp_customize->add_setting(
		'comment_policy',
		array(
			'default'           => '',
			'sanitize_callback' => 'newspack_sanitize_textarea_balance',
		)
	);
	$wp_customize->add_control(
		'comment_policy',
		array(
			'type'    => 'textarea',
			'label'   => esc_html__( 'Comment policy text', 'newspack-theme' ),
			'section' => 'comments_options',
		)
	);

	/**
	 * Footer settings
	 */
	$wp_customize->add_section(
		'footer_options',
		array(
			'title' => esc_html__( 'Footer Settings', 'newspack-theme' ),
		)
	);

	// Add option to toggle off the footer branding.
	$wp_customize->add_setting(
		'footer_show_branding',
		array(
			'default'           => true,
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'footer_show_branding',
		array(
			'type'        => 'checkbox',
			'label'       => esc_html__( 'Show footer branding', 'newspack-theme' ),
			'description' => esc_html__( 'Display the site logo in the footer when the footer widget area is populated.', 'newspack-theme' ),
			'section'     => 'footer_options',
		)
	);

	// Add option to toggle off the default footer layout.
	$wp_customize->add_setting(
		'footer_widget_layout',
		array(
			'default'           => 'columns',
			'sanitize_callback' => 'newspack_sanitize_radio',
		)
	);
	$wp_customize->add_control(
		'footer_widget_layout',
		array(
			'type'        => 'radio',
			'label'       => esc_html__( 'Footer Widget Layout', 'newspack-theme' ),
			'description' => esc_html__( 'Stack the footer widgets, or have them automatically divide into even columns.', 'newspack-theme' ),
			'choices'     => array(
				'columns' => esc_html__( 'Columns', 'newspack-theme' ),
				'stacked' => esc_html__( 'Stacked', 'newspack-theme' ),
			),
			'section'     => 'footer_options',
		)
	);

	// Add option to collapse the comments.
	$wp_customize->add_setting(
		'footer_copyright',
		array(
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);
	$wp_customize->add_control(
		'footer_copyright',
		array(
			'type'        => 'text',
			'label'       => esc_html__( 'Copyright Information', 'newspack-theme' ),
			'description' => esc_html__( 'Add custom text to be displayed next to a copyright symbol and current year in the footer. By default, it will display your site title.', 'newspack-theme' ),
			'section'     => 'footer_options',
		)
	);
}
add_action( 'customize_register', 'newspack_customize_register' );

/**
 * Add custom font support in the Customizer
 *
 * @param WP_Customize_Manager $wp_customize Theme Customizer object.
 */
function newspack_customize_typography_register( $wp_customize ) {

	require_once get_parent_theme_file_path( '/inc/typography.php' );

	$wp_customize->add_section(
		'newspack_typography',
		array(
			'title'    => __( 'Typography', 'newspack-theme' ),
			'priority' => 50,
		)
	);

	$wp_customize->add_setting(
		'custom_font_import_code',
		array(
			'sanitize_callback' => 'newspack_sanitize_font_provider_url',
		)
	);
	$wp_customize->add_setting(
		'custom_font_import_code_alternate',
		array(
			'sanitize_callback' => 'newspack_sanitize_font_provider_url',
		)
	);
	$wp_customize->add_setting(
		'font_body',
		array(
			'sanitize_callback' => 'wp_filter_nohtml_kses',
		)
	);
	$wp_customize->add_setting(
		'font_header',
		array(
			'sanitize_callback' => 'wp_filter_nohtml_kses',
		)
	);
	$wp_customize->add_setting(
		'font_body_stack',
		array(
			'sanitize_callback' => 'newspack_sanitize_font_stack',
			'default'           => 'serif',
		)
	);
	$wp_customize->add_setting(
		'font_header_stack',
		array(
			'sanitize_callback' => 'newspack_sanitize_font_stack',
			'default'           => 'serif',
		)
	);

	$wp_customize->add_control(
		'custom_font_import_code',
		array(
			'label'       => __( 'Font Provider Import Code or URL', 'newspack-theme' ),
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- This is example text, not actual code.
			'description' => __( 'Example: &lt;link href="https://fonts.googleapis.com/css?family=Open+Sans&display=swap" rel="stylesheet"&gt; or https://fonts.googleapis.com/css?family=Open+Sans.', 'newspack-theme' ),
			'section'     => 'newspack_typography',
			'type'        => 'text',
		)
	);

	$wp_customize->add_control(
		'custom_font_import_code_alternate',
		array(
			'label'   => __( 'Secondary Font Provider Import Code or URL', 'newspack-theme' ),
			'section' => 'newspack_typography',
			'type'    => 'text',
		)
	);

	$wp_customize->add_control(
		'font_header',
		array(
			'label'       => __( 'Header Font', 'newspack-theme' ),
			'description' => __( 'Example: Open Sans' ),
			'section'     => 'newspack_typography',
			'type'        => 'text',
		)
	);

	$font_stacks = newspack_get_font_stacks_as_select_choices();

	foreach ( $font_stacks as &$stack ) {
		$stack = wp_kses( $stack, null );
	}

	$wp_customize->add_control(
		'font_header_stack',
		array(
			'label'   => __( 'Header Font Fallback Stack', 'newspack-theme' ),
			'section' => 'newspack_typography',
			'type'    => 'select',
			'choices' => $font_stacks,
		)
	);

	$wp_customize->add_control(
		'font_body',
		array(
			'label'   => __( 'Body Font', 'newspack-theme' ),
			'section' => 'newspack_typography',
			'type'    => 'text',
		)
	);

	$wp_customize->add_control(
		'font_body_stack',
		array(
			'label'   => __( 'Body Font Fallback Stack', 'newspack-theme' ),
			'section' => 'newspack_typography',
			'type'    => 'select',
			'choices' => $font_stacks,
		)
	);

	// Typography - use optional uppercase styles
	$wp_customize->add_setting(
		'accent_allcaps',
		array(
			'default'           => true,
			'sanitize_callback' => 'newspack_sanitize_checkbox',
		)
	);
	$wp_customize->add_control(
		'accent_allcaps',
		array(
			'type'    => 'checkbox',
			'label'   => esc_html__( 'Use all-caps for accent text.', 'newspack-theme' ),
			'section' => 'newspack_typography',
		)
	);
}

add_action( 'customize_register', 'newspack_customize_typography_register' );

/**
 * Render the site title for the selective refresh partial.
 *
 * @return void
 */
function newspack_customize_partial_blogname() {
	bloginfo( 'name' );
}

/**
 * Render the site tagline for the selective refresh partial.
 *
 * @return void
 */
function newspack_customize_partial_blogdescription() {
	bloginfo( 'description' );
}

/**
 * Bind JS handlers to instantly live-preview changes.
 */
function newspack_customize_preview_js() {
	wp_enqueue_script( 'newspack-customize-preview', get_theme_file_uri( '/js/dist/customize-preview.js' ), array( 'customize-preview' ), '20181231', true );
	wp_localize_script(
		'newspack-customize-preview',
		'_NewspackThemePreviewData',
		array(
			'default_hex' => newspack_get_primary_color(),
		)
	);
}
add_action( 'customize_preview_init', 'newspack_customize_preview_js' );

/**
 * Load dynamic logic for the customizer controls area.
 */
function newspack_panels_js() {
	wp_enqueue_script( 'newspack-customize-controls', get_theme_file_uri( '/js/dist/customize-controls.js' ), array(), '20181231', true );
}
add_action( 'customize_controls_enqueue_scripts', 'newspack_panels_js' );

/**
 * Sanitize footer logo size.
 *
 * @param string $choice Whether the footer logo is small or large.
 *
 * @return string
 */
function newspack_sanitize_footer_logo_size( $choice ) {
	$valid = array(
		'small',
		'medium',
		'large',
		'xlarge',
	);

	if ( in_array( $choice, $valid, true ) ) {
		return $choice;
	}

	return 'medium';
}

/**
 * Sanitize custom color choice.
 *
 * @param string $choice Whether image filter is active.
 *
 * @return string
 */
function newspack_sanitize_color_option( $choice ) {
	$valid = array(
		'default',
		'custom',
	);

	if ( in_array( $choice, $valid, true ) ) {
		return $choice;
	}

	return 'default';
}

/**
 * Sanitize custom color choice.
 *
 * @param string $choice Whether image filter is active.
 *
 * @return string
 */
function newspack_sanitize_feature_image_position( $choice ) {
	$valid = array(
		'large',
		'small',
		'behind',
		'beside',
		'above',
		'hidden',
	);

	if ( in_array( $choice, $valid, true ) ) {
		return $choice;
	}

	return 'large';
}

/**
 * Sanitize post template.
 *
 * @param string $choice Post template file name.
 *
 * @return string
 */
function newspack_sanitize_post_template( $choice ) {
	$valid = array(
		'single-feature.php',
		'single-wide.php',
	);

	if ( in_array( $choice, $valid, true ) ) {
		return $choice;
	}

	return 'default';
}


/**
 * Sanitize slide-out sidebar side
 *
 * @param string $choice The side to display the slide-out sidebar.
 *
 * @return string
 */
function newspack_sanitize_slideout_sidebar_side( $choice ) {
	$valid = array(
		'left',
		'right',
	);

	if ( in_array( $choice, $valid, true ) ) {
		return $choice;
	}

	return 'left';
}

/**
 * Sanitize the checkbox.
 *
 * @param bool|int|string $input Value of checkbox.
 *
 * @return boolean true if is true, 1, or '1'; false otherwise
 */
function newspack_sanitize_checkbox( $input ) {
	if ( is_scalar( $input ) && '1' === (string) $input ) {
		return true;
	} else {
		return false;
	}
}

/**
 * Sanitize author avatar image size.
 *
 * @param int|string $input Requested avatar size.
 *
 * @return int Clamped avatar size.
 */
function newspack_sanitize_author_avatar_size( $input ) {
	$size = absint( $input );

	if ( $size < 32 ) {
		return 32;
	}

	if ( $size > 512 ) {
		return 512;
	}

	return $size;
}

/**
 * Sanitize the radio buttons.
 */
function newspack_sanitize_radio( $input, $setting ) {

	// Ensure input is a slug.
	$input = sanitize_key( $input );

	// Get list of choices from the control associated with the setting.
	$choices = $setting->manager->get_control( $setting->id )->choices;

	// If the input is a valid key, return it; otherwise, return the default.
	return ( array_key_exists( $input, $choices ) ? $input : $setting->default );
}

/**
 * Sanitize and balance tags in textareas.
 *
 * @param string $input Value of textarea.
 *
 * @return string The textarea value, sanitized and with HTML tags balanced.
 */
function newspack_sanitize_textarea_balance( $input ) {
	$input = wp_kses_post( force_balance_tags( $input ) );

	return $input;
}

/**
 * Sanitize font provider embed URL.
 *
 * @param string $code Font provider embed code.
 *
 * @return string|null Return a valid font provider URL if found or null if not.
 */
function newspack_sanitize_font_provider_url( $code ) {
	if ( '' === trim( $code ) ) {
		return '';
	}
	$font_service_urls = array(
		'google'      => 'fonts.googleapis.com',
		'bunny'       => 'fonts.bunny.net',
		'coollabs'    => 'api.fonts.coollabs.io',
		'fonts'       => 'fast.fonts.net',
		'typekit'     => 'use.typekit.net',
		'typenetwork' => 'cloud.typenetwork.com',
	);

	$regex = '/\/\/[^\("\') \n]+/i';
	preg_match( $regex, $code, $matches );
	$url = isset( $matches[0] ) ? $matches[0] : null;

	$url_info = wp_parse_url( $url );
	if ( isset( $url_info['host'] ) && in_array( $url_info['host'], array_values( $font_service_urls ), true ) ) {
		return $url;
	}
	return null;
}

/**
 * Sanitize font stack ID.
 *
 * @param string $stack_id Font stack ID.
 *
 * @return string|null Return a valid font stack ID or null.
 */
function newspack_sanitize_font_stack( $stack_id ) {
	$stacks = newspack_get_font_stacks();
	if ( in_array( $stack_id, array_keys( $stacks ), true ) ) {
		return $stack_id;
	}
	return null;
}
