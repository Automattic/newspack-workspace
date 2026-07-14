<?php
/**
 * Newspack Settings Admin Page
 *
 * @package Newspack
 */

namespace Newspack\Wizards\Newspack;

use Newspack\OAuth;
use Newspack\Wizard;
use Newspack\Everlit_Configuration_Manager;
use Newspack\Nextdoor;
use Newspack\Complianz;
use function Newspack\google_site_kit_available;

defined( 'ABSPATH' ) || exit;

/**
 * Common functionality for admin wizards. Override this class.
 */
class Newspack_Settings extends Wizard {

	/**
	 * The slug of this wizard.
	 *
	 * @var string
	 */
	protected $slug = 'newspack-settings';

	/**
	 * The capability required to access this.
	 *
	 * @var string
	 */
	protected $capability = 'manage_options';

	/**
	 * Get Settings local data
	 *
	 * @return []
	 */
	public function get_local_data() {
		$google_site_kit_url = google_site_kit_available() ? admin_url( 'admin.php?page=googlesitekit-settings#/connected-services/analytics-4' ) : admin_url( 'admin.php?page=googlesitekit-splash' );
		$newspack_settings = [
			'connections'       => [
				'label'    => __( 'Connections', 'newspack-plugin' ),
				'path'     => '/',
				'sections' => [
					'plugins'      => [
						'editLink' => [
							'everlit'         => 'admin.php?page=everlit_settings',
							'jetpack'         => 'admin.php?page=jetpack#/settings',
							'google-site-kit' => $google_site_kit_url,
						],
						'enabled'  => [
							'everlit' => Everlit_Configuration_Manager::is_enabled(),
						],
					],
					'apis'         => [
						'dependencies' => [
							'googleOAuth' => OAuth::is_proxy_configured( 'google' ),
						],
					],
					'jetpack_sso'  => [
						'dependencies' => [
							'jetpack_sso' => class_exists( 'Jetpack' ) && defined( 'NEWSPACK_MANAGER_FILE' ),
						],
					],
					'recaptcha'    => [],
					'analytics'    => [
						'editLink'                    => $google_site_kit_url,
						'measurement_id'              => get_option( 'ga4_measurement_id', '' ),
						'measurement_protocol_secret' => get_option( 'ga4_measurement_protocol_secret', '' ),
					],
					'customEvents' => $this->sections['custom-events']->get_data(),
				],
			],
			'social'            => [
				'label'    => __( 'Social', 'newspack-plugin' ),
				'nextdoor' => [
					'available_roles' => Nextdoor::get_available_roles(),
					'country_options' => Nextdoor::get_available_countries(),
					'redirect_uri'    => Nextdoor::get_redirect_uri(),
				],
			],
			'syndication'       => [
				'label' => __( 'Syndication', 'newspack-plugin' ),
			],
			'seo'               => [
				'label' => __( 'SEO', 'newspack-plugin' ),
			],
			'theme-and-brand'   => [
				'label' => __( 'Theme and Brand', 'newspack-plugin' ),
			],
			'advanced-settings' => [
				'label' => __( 'Advanced Settings', 'newspack-plugin' ),
			],
		];
		if ( Complianz::is_complianz_active() ) {
			$newspack_settings['privacy'] = [
				'label' => __( 'Privacy', 'newspack-plugin' ),
			];
		}
		if ( \Newspack\Optional_Modules\Collections::is_feature_enabled() ) {
			$newspack_settings['collections'] = [
				'label' => __( 'Collections', 'newspack-plugin' ),
			];
		}
		if ( \Newspack\Optional_Modules\InDesign_Exporter::is_feature_enabled() ) {
			$newspack_settings['print'] = [
				'label' => __( 'Print', 'newspack-plugin' ),
			];

		}
		if ( defined( 'NEWSPACK_MULTIBRANDED_SITE_PLUGIN_FILE' ) ) {
			$newspack_settings['additional-brands'] = [
				'label'          => __( 'Additional Brands', 'newspack-plugin' ),
				'activeTabPaths' => [
					'/additional-brands/*',
				],
				'sections'       => [
					'additionalBrands' => [
						'themeColors'   => \Newspack_Multibranded_Site\Customizations\Theme_Colors::get_registered_theme_colors(),
						'menuLocations' => get_registered_nav_menus(),
						'menus'         => array_map(
							function( $menu ) {
								return array(
									'value' => $menu->term_id,
									'label' => $menu->name,
								);
							},
							wp_get_nav_menus()
						),
					],
				],
			];
		}
		$experimental_tools = \Newspack\Experimental_Tools::get_tools();
		if ( ! empty( $experimental_tools ) ) {
			$newspack_settings['experimental-tools'] = [
				'label'          => __( 'Experimental tools', 'newspack-plugin' ),
				'activeTabPaths' => [ '/experimental-tools/*' ],
				'sections'       => [
					'tools' => $experimental_tools,
				],
			];
		}

		return $newspack_settings;
	}

	/**
	 * Get the name for this wizard.
	 *
	 * @return string The wizard name.
	 */
	public function get_name() {
		return esc_html__( 'Newspack', 'newspack' );
	}

	/**
	 * Add an admin page for the wizard to live on.
	 */
	public function add_page() {
		add_submenu_page(
			'newspack-dashboard',
			__( 'Newspack / Settings', 'newspack-plugin' ),
			__( 'Settings', 'newspack-plugin' ),
			$this->capability,
			$this->slug,
			[ $this, 'render_wizard' ]
		);
	}

	/**
	 * Load up JS/CSS.
	 */
	public function enqueue_scripts_and_styles() {
		parent::enqueue_scripts_and_styles();

		if ( filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) !== $this->slug ) {
			return;
		}

		/**
		 * JavaScript
		 */
		wp_localize_script(
			'newspack-wizards',
			'newspackSettings',
			$this->get_local_data()
		);
		wp_enqueue_script( 'newspack-wizards' );
	}
}
