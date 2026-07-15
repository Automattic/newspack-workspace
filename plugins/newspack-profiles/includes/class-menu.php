<?php
/**
 * Admin menu for the Newspack Profiles plugin.
 *
 * @package NewspackProfiles
 */

declare( strict_types=1 );

namespace NewspackProfiles;

use NewspackProfiles\Configs\Pattern_Config;
use NewspackProfiles\Page_Template_Manager;
use NewspackProfiles\Registrars\Rewrite_Rule_Registrar;
use NewspackProfiles\Traits\Singleton;
use WP_Block_Editor_Context;
use WP_Block_Pattern_Categories_Registry;
use WP_Block_Patterns_Registry;

/**
 * Menu class to handle admin menu.
 */
class Menu {

	use Singleton;

	/**
	 * Page slug for the profiles list page.
	 */
	const PROFILE_COLLECTIONS_LIST_SLUG = 'newspack-profiles-settings';

	/**
	 * Page slug for creating a new profile.
	 */
	const PROFILE_COLLECTIONS_CREATE_SLUG = 'newspack-profiles-add';

	/**
	 * Constructor for the Assets class.
	 */
	protected function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Register admin menu.
	 */
	public function register_admin_menu(): void {
		$postauthor_icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path fill="white" fill-rule="evenodd" clip-rule="evenodd" d="M10 4.5a1 1 0 11-2 0 1 1 0 012 0zm1.5 0a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0zm2.25 7.5v-1A2.75 2.75 0 0011 8.25H7A2.75 2.75 0 004.25 11v1h1.5v-1c0-.69.56-1.25 1.25-1.25h4c.69 0 1.25.56 1.25 1.25v1h1.5zM4 20h9v-1.5H4V20zm16-4H4v-1.5h16V16z"></path></svg>' );

		add_menu_page(
			esc_html__( 'Profiles', 'newspack-profiles' ),
			esc_html__( 'Profiles', 'newspack-profiles' ),
			'manage_options',
			self::PROFILE_COLLECTIONS_LIST_SLUG,
			array( $this, 'render_admin_page' ),
			$postauthor_icon,
			25
		);

		add_submenu_page(
			self::PROFILE_COLLECTIONS_LIST_SLUG,
			esc_html__( 'All Profiles', 'newspack-profiles' ),
			esc_html__( 'All Profiles', 'newspack-profiles' ),
			'manage_options',
			self::PROFILE_COLLECTIONS_LIST_SLUG,
			array( $this, 'render_admin_page' ),
		);

		add_submenu_page(
			self::PROFILE_COLLECTIONS_LIST_SLUG,
			esc_html__( 'Add New Profile', 'newspack-profiles' ),
			esc_html__( 'Add New Profile', 'newspack-profiles' ),
			'manage_options',
			self::PROFILE_COLLECTIONS_CREATE_SLUG,
			array( $this, 'render_admin_page' ),
		);

		add_submenu_page(
			self::PROFILE_COLLECTIONS_LIST_SLUG,
			esc_html__( 'Templates', 'newspack-profiles' ),
			esc_html__( 'Templates', 'newspack-profiles' ),
			'manage_options',
			'edit.php?post_type=' . Page_Template_Manager::POST_TYPE,
		);
	}

	/**
	 * Render admin page content.
	 */
	public function render_admin_page(): void {
		?>
			<div id="newspack-profiles-settings-root">
				<?php esc_html_e( 'Loading...', 'newspack-profiles' ); ?>
				<noscript>
					<?php esc_html_e( 'JavaScript is required to view this page.', 'newspack-profiles' ); ?>
				</noscript>
			</div>
		<?php
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $admin_page The current page slug.
	 */
	public function enqueue_admin_assets( string $admin_page ): void {
		if ( false === strpos( $admin_page, 'newspack-profiles' ) ) {
			return;
		}

		$assets_file = include BUILD_DIR . 'index.asset.php';

		wp_enqueue_script(
			'newspack-profiles-admin-script',
			plugins_url( 'dist/index.js', NEWSPACK_PROFILES_PLUGIN_FILE ),
			$assets_file['dependencies'],
			$assets_file['version'],
			true
		);

		wp_localize_script(
			'newspack-profiles-admin-script',
			'NewspackProfilesSettingsConfig',
			array(
				'availableDataSources'            => Data_Sources::get_all(),
				'patterns'                        => Pattern_Config::get_all(),
				'editPageURL'                     => admin_url( 'post.php?action=edit&post=' ),
				'remoteDataBlocksSettingsPageURL' => admin_url( 'options-general.php?page=remote-data-blocks-settings' ),
				'placeholderImageURL'             => plugins_url( 'assets/profile-placeholder.webp', NEWSPACK_PROFILES_PLUGIN_FILE ),
				'basePath'                        => Rewrite_Rule_Registrar::get_instance()->get_base_path(),
				'initialView'                     => ( self::PROFILE_COLLECTIONS_CREATE_SLUG === filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) ) ? 'add' : 'list',
				'profileCollectionsListURL'       => admin_url( 'admin.php?page=' . self::PROFILE_COLLECTIONS_LIST_SLUG ),
				'profileCollectionsCreateURL'     => admin_url( 'admin.php?page=' . self::PROFILE_COLLECTIONS_CREATE_SLUG ),
			)
		);

		wp_enqueue_style(
			'newspack-profiles-admin-style',
			plugins_url( 'dist/style-index.css', NEWSPACK_PROFILES_PLUGIN_FILE ),
			array(),
			$assets_file['version']
		);

		wp_enqueue_style(
			'newspack-profiles-admin-components-style',
			plugins_url( 'dist/index.css', NEWSPACK_PROFILES_PLUGIN_FILE ),
			array( 'newspack-profiles-admin-style' ),
			$assets_file['version']
		);

		self::setup_editor_settings();
	}

	/**
	 * Setup editor settings to render patterns in the Pattern Selection - Step 4.
	 *
	 * This function is inspired by the WordPress Core Site Editor setup.
	 * It prepares and localizes the necessary block editor settings for the admin page.
	 *
	 * @see https://github.com/WordPress/wordpress-develop/blob/c4c0ea9722f99d1aa95da574ab126b7fe1d4e259/src/wp-admin/site-editor.php
	 */
	private function setup_editor_settings(): void {
		global $editor_styles;

		$indexed_template_types = array();
		foreach ( get_default_block_template_types() as $slug => $template_type ) {
			$template_type['slug']    = (string) $slug;
			$indexed_template_types[] = $template_type;
		}

		$context_settings = array( 'name' => 'core/edit-site' );

		$current_screen = get_current_screen();
		$current_screen->is_block_editor( true );

		$block_editor_context = new WP_Block_Editor_Context( $context_settings );
		$custom_settings      = array(
			'siteUrl'                   => site_url(),
			'postsPerPage'              => get_option( 'posts_per_page' ),
			'styles'                    => get_block_editor_theme_styles(),
			'defaultTemplateTypes'      => $indexed_template_types,
			'defaultTemplatePartAreas'  => get_allowed_block_template_part_areas(),
			'supportsLayout'            => wp_theme_has_theme_json(),
			'supportsTemplatePartsMode' => ! wp_is_block_theme() && current_theme_supports( 'block-template-parts' ),
		);

		$custom_settings['__experimentalAdditionalBlockPatterns']          = WP_Block_Patterns_Registry::get_instance()->get_all_registered( true );
		$custom_settings['__experimentalAdditionalBlockPatternCategories'] = WP_Block_Pattern_Categories_Registry::get_instance()->get_all_registered( true );

		$editor_settings = get_block_editor_settings( $custom_settings, $block_editor_context );

		wp_localize_script(
			'newspack-profiles-admin-script',
			'NewspackProfilesSettingsEditor',
			array(
				'data' => $editor_settings,
			)
		);

		// Preload server-registered block schemas.
		wp_add_inline_script(
			'wp-blocks',
			'wp.blocks.unstable__bootstrapServerSideBlockDefinitions(' . wp_json_encode( get_block_editor_server_block_settings(), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) . ');'
		);

		// Preload server-registered block bindings sources.
		$registered_sources = get_all_registered_block_bindings_sources();
		if ( ! empty( $registered_sources ) ) {
			$filtered_sources = array();
			foreach ( $registered_sources as $source ) {
				$filtered_sources[] = array(
					'name'        => $source->name,
					'label'       => $source->label,
					'usesContext' => $source->uses_context,
				);
			}
			$script = sprintf( 'for ( const source of %s ) { wp.blocks.registerBlockBindingsSource( source ); }', wp_json_encode( $filtered_sources, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) );
			wp_add_inline_script(
				'wp-blocks',
				$script
			);
		}

		wp_add_inline_script(
			'wp-blocks',
			sprintf( 'wp.blocks.setCategories( %s );', wp_json_encode( isset( $editor_settings['blockCategories'] ) ? $editor_settings['blockCategories'] : array(), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) ),
			'after'
		);

		if (
			current_theme_supports( 'wp-block-styles' ) &&
			( ! is_array( $editor_styles ) || count( $editor_styles ) === 0 )
		) {
			wp_enqueue_style( 'wp-block-library-theme' );
		}

		do_action( 'enqueue_block_editor_assets' );
	}
}
