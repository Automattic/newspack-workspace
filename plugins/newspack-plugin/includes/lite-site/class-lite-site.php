<?php
/**
 * Lite Site functionality
 *
 * @package newspack
 */

namespace Newspack;

/**
 * Lite Site class
 */
class Lite_Site {
	/**
	 * The option name for storing settings
	 */
	const OPTION_NAME = 'newspack_lite_site_settings';

	/**
	 * Initialize the lite site functionality
	 */
	public static function init() {
		// The standalone Newspack Lite Site plugin supersedes this module, so skip initialization to avoid conflicts.
		if ( defined( 'NEWSPACK_LITE_SITE_PLUGIN_FILE' ) ) {
			return;
		}

		// Only register rewrite rules if the feature is enabled.
		if ( self::is_enabled() ) {
			add_action( 'init', [ __CLASS__, 'register_rewrite_rules' ] );
			add_filter( 'query_vars', [ __CLASS__, 'register_query_vars' ] );
			add_action( 'template_redirect', [ __CLASS__, 'handle_lite_site_templates' ] );
		}

		// Always register settings.
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_menu', [ __CLASS__, 'add_menu_page' ] );
	}

	/**
	 * Check if the lite site feature is enabled
	 */
	public static function is_enabled() {
		$settings = get_option( self::OPTION_NAME, [] );
		return ! empty( $settings['enabled'] );
	}

	/**
	 * Check if the current request is for the lite site.
	 *
	 * @return bool True if it's a lite site request, false otherwise.
	 */
	public static function is_lite_site_request() {
		$lite_site = get_query_var( 'lite_site' );
		return ! empty( $lite_site );
	}

	/**
	 * Get the lite site URL base
	 */
	public static function get_url_base() {
		$settings = get_option( self::OPTION_NAME, [] );
		return ! empty( $settings['url_base'] ) ? $settings['url_base'] : 'text';
	}

	/**
	 * Get the number of posts to display
	 */
	public static function get_number_of_posts() {
		$settings = get_option( self::OPTION_NAME, [] );
		return ! empty( $settings['number_of_posts'] ) ? intval( $settings['number_of_posts'] ) : 20;
	}

	/**
	 * Get the selected categories
	 */
	public static function get_categories() {
		$settings = get_option( self::OPTION_NAME, [] );
		return ! empty( $settings['categories'] ) ? (array) $settings['categories'] : [];
	}

	/**
	 * Get the footer HTML
	 */
	public static function get_footer_html() {
		$settings = get_option( self::OPTION_NAME, [] );
		return ! empty( $settings['footer_html'] ) ? $settings['footer_html'] : '';
	}

	/**
	 * Register settings
	 */
	public static function register_settings() {
		register_setting(
			'newspack_lite_site',
			self::OPTION_NAME,
			[
				'type'              => 'object',
				'sanitize_callback' => [ __CLASS__, 'sanitize_settings' ],
			]
		);

		add_settings_section(
			'newspack_lite_site_main',
			__( 'Settings', 'newspack-plugin' ),
			'__return_null',
			'newspack_lite_site'
		);

		add_settings_field(
			'enabled',
			__( 'Enable Lite Site', 'newspack-plugin' ),
			[ __CLASS__, 'render_enabled_field' ],
			'newspack_lite_site',
			'newspack_lite_site_main'
		);

		add_settings_field(
			'url_base',
			__( 'URL Base', 'newspack-plugin' ),
			[ __CLASS__, 'render_url_base_field' ],
			'newspack_lite_site',
			'newspack_lite_site_main'
		);

		add_settings_field(
			'number_of_posts',
			__( 'Number of posts to display', 'newspack-plugin' ),
			[ __CLASS__, 'render_number_of_posts_field' ],
			'newspack_lite_site',
			'newspack_lite_site_main'
		);

		add_settings_field(
			'categories',
			__( 'Categories', 'newspack-plugin' ),
			[ __CLASS__, 'render_categories_field' ],
			'newspack_lite_site',
			'newspack_lite_site_main'
		);

		add_settings_field(
			'footer_html',
			__( 'Footer HTML', 'newspack-plugin' ),
			[ __CLASS__, 'render_footer_html_field' ],
			'newspack_lite_site',
			'newspack_lite_site_main'
		);
	}

	/**
	 * Add menu page
	 */
	public static function add_menu_page() {
		add_options_page(
			__( 'Lite Site', 'newspack-plugin' ),
			__( 'Lite Site', 'newspack-plugin' ),
			'manage_options',
			'newspack-lite-site',
			[ __CLASS__, 'render_settings_page' ]
		);
	}

	/**
	 * Render settings page
	 */
	public static function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Lite Site', 'newspack-plugin' ); ?></h1>
			<p><?php esc_html_e( 'Lite Site is a text-only version of this website that loads faster and uses less data.', 'newspack-plugin' ); ?></p>
			<p><?php esc_html_e( 'It’s designed to allow your readers to still be able to access your content despite connectivity issues, poor network coverage, or in the event of natural disasters and emergencies.', 'newspack-plugin' ); ?></p>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'newspack_lite_site' );
				do_settings_sections( 'newspack_lite_site' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render enabled field
	 */
	public static function render_enabled_field() {
		$settings = get_option( self::OPTION_NAME, [] );
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enabled]"
				value="1"
				<?php checked( ! empty( $settings['enabled'] ) ); ?>
			>
			<?php esc_html_e( 'Enable lite site feature', 'newspack-plugin' ); ?>
		</label>
		<?php
	}

	/**
	 * Render URL base field
	 */
	public static function render_url_base_field() {
		$settings = get_option( self::OPTION_NAME, [] );
		$url_base = ! empty( $settings['url_base'] ) ? $settings['url_base'] : 'text';
		?>
		<input
			type="text"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[url_base]"
			value="<?php echo esc_attr( $url_base ); ?>"
			class="regular-text"
		>
		<p class="description">
			<?php
			printf(
				/* translators: %s: is the site URL without a trailing slash, ex: https://example.com */
				esc_html__( 'The URL base for the lite site (e.g. "text" for %s/text)', 'newspack-plugin' ),
				esc_url( untrailingslashit( home_url() ) )
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render posts per page field
	 */
	public static function render_number_of_posts_field() {
		$settings = get_option( self::OPTION_NAME, [] );
		$number_of_posts = ! empty( $settings['number_of_posts'] ) ? intval( $settings['number_of_posts'] ) : 20;
		?>
		<input
			type="number"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[number_of_posts]"
			value="<?php echo esc_attr( $number_of_posts ); ?>"
			min="1"
			max="100"
			step="1"
		>
		<?php
	}

	/**
	 * Render categories field
	 */
	public static function render_categories_field() {
		$settings = get_option( self::OPTION_NAME, [] );
		$selected_categories = ! empty( $settings['categories'] ) ? (array) $settings['categories'] : [];
		$categories = get_categories( [ 'hide_empty' => false ] );
		?>
		<select
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[categories][]"
			multiple
			class="regular-text"
			style="min-height: 100px;"
		>
			<option value="" <?php selected( empty( $selected_categories ) ); ?>>
				All categories
			</option>
			<?php foreach ( $categories as $category ) : ?>
				<option
					value="<?php echo esc_attr( $category->term_id ); ?>"
					<?php selected( in_array( $category->term_id, $selected_categories ) ); ?>
				>
					<?php echo esc_html( $category->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Select categories to include.', 'newspack-plugin' ); ?>
		</p>
		<?php
	}

	/**
	 * Render footer HTML field
	 */
	public static function render_footer_html_field() {
		$settings = get_option( self::OPTION_NAME, [] );
		$footer_html = ! empty( $settings['footer_html'] ) ? $settings['footer_html'] : '';
		?>
		<textarea
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[footer_html]"
			rows="5"
			class="large-text"
		><?php echo esc_textarea( $footer_html ); ?></textarea>
		<p class="description">
			<?php esc_html_e( 'HTML to be displayed in the footer of lite site pages.', 'newspack-plugin' ); ?>
		</p>
		<?php
	}

	/**
	 * Sanitize settings
	 *
	 * @param array $settings The settings to sanitize.
	 * @return array The sanitized settings.
	 */
	public static function sanitize_settings( $settings ) {
		$old_settings = get_option( self::OPTION_NAME, [] );

		flush_rewrite_rules(); // phpcs:ignore

		// handle All categories.
		if ( in_array( '', $settings['categories'] ) ) {
			$settings['categories'] = [];
		}

		return [
			'enabled'         => ! empty( $settings['enabled'] ),
			'url_base'        => sanitize_title( $settings['url_base'] ),
			'number_of_posts' => min( 100, max( 1, intval( $settings['number_of_posts'] ) ) ),
			'categories'      => ! empty( $settings['categories'] ) ? array_map( 'intval', $settings['categories'] ) : [],
			'footer_html'     => wp_kses_post( $settings['footer_html'] ),
		];
	}

	/**
	 * Register the rewrite rules for the lite site
	 */
	public static function register_rewrite_rules() {
		$url_base = self::get_url_base();

		add_rewrite_rule(
			'^' . $url_base . '/?$',
			'index.php?lite_site=archive',
			'top'
		);

		add_rewrite_rule(
			'^' . $url_base . '/([0-9]+)/?$',
			'index.php?lite_site=single&lite_site_id=$matches[1]',
			'top'
		);
	}

	/**
	 * Register custom query variables
	 *
	 * @param array $vars The array of query variables.
	 * @return array The modified array of query variables.
	 */
	public static function register_query_vars( $vars ) {
		$vars[] = 'lite_site';
		$vars[] = 'lite_site_id';
		return $vars;
	}

	/**
	 * Handle template routing for lite site pages
	 */
	public static function handle_lite_site_templates() {
		$lite_site = get_query_var( 'lite_site' );

		if ( ! $lite_site ) {
			return;
		}

		// Disable all other output.
		remove_all_actions( 'wp_head' );
		remove_all_actions( 'wp_footer' );

		if ( $lite_site === 'archive' ) {
			include_once __DIR__ . '/templates/archive.php';
			exit;
		}

		if ( $lite_site === 'single' ) {
			include_once __DIR__ . '/templates/single.php';
			exit;
		}
	}

	/**
	 * Get the primary color
	 *
	 * @return string The primary color.
	 */
	public static function get_primary_color() {
		if ( wp_is_block_theme() ) {
			// Guard against re-entry via wp_theme_json_data_theme — wp_get_global_settings() re-fires that filter and recurses.
			if ( doing_filter( 'wp_theme_json_data_theme' ) ) {
				return 'currentcolor';
			}
			$settings = wp_get_global_settings();
			$palettes = $settings['color']['palette'] ?? [];
			$palette  = [];
			foreach ( [ 'custom', 'theme', 'default' ] as $origin ) {
				if ( ! empty( $palettes[ $origin ] ) ) {
					$palette = $palettes[ $origin ];
					break;
				}
			}
			return $palette[0]['color'] ?? 'currentcolor';
		}

		if ( ! function_exists( 'newspack_get_primary_color' ) ) {
			return 'currentcolor';
		}

		$primary_color = newspack_get_primary_color();

		if ( 'default' !== get_theme_mod( 'theme_colors' ) ) {
			$primary_color = get_theme_mod( 'primary_color_hex', $primary_color );
		}

		return $primary_color;
	}

	/**
	 * Get the author(s) for a post
	 *
	 * @param WP_Post $post The post object.
	 * @return string The formatted author(s) string with links.
	 */
	public static function get_authors( $post ) {
		if ( function_exists( 'coauthors_posts_links' ) ) {
			$authors = get_coauthors( $post->ID );
			$author_links = array_map(
				function( $author ) {
					return sprintf(
						'<a href="%s">%s</a>',
						esc_url( get_author_posts_url( $author->ID, $author->user_nicename ) ),
						esc_html( $author->display_name )
					);
				},
				$authors
			);

			if ( count( $author_links ) > 1 ) {
				$last_author = array_pop( $author_links );
				$first_authors = implode(
					', ',
					$author_links
				);

				$author_string = sprintf(
					/* translators: %1$s: a comma separated list of authors names with links and, after the "and" %2$s: one last author link */
					__( '%1$s and %2$s', 'newspack-plugin' ),
					$first_authors,
					$last_author
				);

			} else {
				$author_string = $author_links[0];
			}
		} else {
			$author_string = sprintf(
				'<a href="%s">%s</a>',
				esc_url( get_author_posts_url( $post->post_author ) ),
				esc_html( get_the_author_meta( 'display_name', $post->post_author ) )
			);
		}

		return sprintf(
			/* translators: %s: author name(s) */
			__( 'By %s', 'newspack-plugin' ),
			$author_string
		);
	}

	/**
	 * Clean the post content for lite display
	 *
	 * @param string $content The post content.
	 * @return string The cleaned content.
	 */
	public static function clean_content( $content ) {
		// Remove HTML comments.
		$content = preg_replace( '/<!--(.|\s)*?-->/', '', $content );

		// First remove figures and their contents (including images and captions).
		$content = preg_replace( '/<figure.*?>.*?<\/figure>/is', '', $content );

		// Remove script tags.
		$content = preg_replace( '/<script.*?>.*?<\/script>/is', '', $content );

		// Define allowed HTML elements for text-only content.
		$allowed_html = [
			'p'          => [],
			'h1'         => [],
			'h2'         => [],
			'h3'         => [],
			'h4'         => [],
			'h5'         => [],
			'h6'         => [],
			'ul'         => [],
			'ol'         => [],
			'li'         => [],
			'blockquote' => [],
			'strong'     => [],
			'em'         => [],
			'b'          => [],
			'i'          => [],
			'a'          => [
				'href'  => true,
				'title' => true,
			],
			'br'         => [],
		];

		// Strip all HTML except allowed elements.
		$content = wp_kses( $content, $allowed_html );

		// Clean up any empty paragraphs.
		$content = preg_replace( '/<p>\s*<\/p>/', '', $content );

		return $content;
	}

	/**
	 * Get GA4 snippet if active and settings are present.
	 */
	public static function get_ga4_snippet() {
		if ( ! GoogleSiteKit::is_active() ) {
			return;
		}
		$settings = GoogleSiteKit::get_sitekit_ga4_settings();
		if ( ! $settings || empty( $settings['measurementID'] ) ) {
			return;
		}
		?>
		<!-- Global site tag (gtag.js) - Google Analytics -->
		<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( $settings['measurementID'] ); ?>"></script>
		<script>
			window.dataLayer = window.dataLayer || [];
			function gtag(){dataLayer.push(arguments);}
			gtag('js', new Date());
			gtag('config', '<?php echo esc_attr( $settings['measurementID'] ); ?>');
		</script>
		<?php
	}
}

add_action( 'plugins_loaded', [ Lite_Site::class, 'init' ] );
