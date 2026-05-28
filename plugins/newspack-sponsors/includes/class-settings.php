<?php
/**
 * Newspack Sponsors Settings Page
 *
 * @package Newspack
 */

namespace Newspack_Sponsors;

use Newspack_Sponsors\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Manages Settings page.
 */
final class Settings {
	/**
	 * Set up hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_plugin_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'page_init' ] );
	}

	/**
	 * Default values for site-wide settings.
	 *
	 * @return array Array of default settings.
	 */
	public static function get_default_settings() {
		$defaults = [
			'byline'     => __( 'Sponsored by', 'newspack-sponsors' ),
			'flag'       => __( 'Sponsored', 'newspack-sponsors' ),
			'disclaimer' => sprintf(
				// Translators: Default value for Sponsor Disclaimer field.
				__(
					'Advertiser content: This content was commissioned and paid for by [sponsor name]. The news and editorial staff of %s had no role in the creation or production of this story.',
					'newspack-sponsors'
				),
				get_bloginfo( 'name' )
			),
			'suppress'   => false,
		];

		return $defaults;
	}

	/**
	 * Get current site-wide settings, or defaults if not set.
	 *
	 * @param string $setting Key name of a setting to retrieve. If given, only the matching setting's value will be returned.
	 *
	 * @return array Array of current site-wide settings.
	 */
	public static function get_settings( $setting = null ) {
		$defaults = self::get_default_settings();
		$settings = [
			'byline'     => get_option( 'newspack_sponsors_default_byline', $defaults['byline'] ),
			'flag'       => get_option( 'newspack_sponsors_default_flag', $defaults['flag'] ),
			'disclaimer' => get_option( 'newspack_sponsors_default_disclaimer', $defaults['disclaimer'] ),
			'suppress'   => get_option( 'newspack_sponsors_suppress_ads', $defaults['suppress'] ),
		];

		// Guard against empty strings, which can happen if an option is set and then unset.
		foreach ( $settings as $key => $value ) {
			if ( empty( $value ) ) {
				$settings[ $key ] = $defaults[ $key ];
			}
		}

		if ( $setting && isset( $settings[ $setting ] ) ) {
			return $settings[ $setting ];
		}

		return $settings;
	}

	/**
	 * Get list of settings fields.
	 *
	 * @return array Settings list.
	 */
	public static function get_settings_list() {
		$defaults = self::get_default_settings();

		return [
			[
				'label' => __( 'Default Sponsor Byline Prefix', 'newspack-sponsors' ),
				'value' => $defaults['byline'],
				'key'   => 'newspack_sponsors_default_byline',
				'type'  => 'input',
			],
			[
				'label' => __( 'Default Sponsored Flag Label', 'newspack-sponsors' ),
				'value' => $defaults['flag'],
				'key'   => 'newspack_sponsors_default_flag',
				'type'  => 'input',
			],
			[
				'label' => __( 'Default Sponsorship Disclaimer', 'newspack-sponsors' ),
				'value' => $defaults['disclaimer'],
				'key'   => 'newspack_sponsors_default_disclaimer',
				'type'  => 'textarea',
			],
			[
				'label' => __( 'Suppress Ads for All Sponsored Posts', 'newspack-sponsors' ),
				'value' => $defaults['suppress'],
				'key'   => 'newspack_sponsors_suppress_ads',
				'type'  => 'checkbox',
			],
		];
	}

	/**
	 * Add options page
	 */
	public static function add_plugin_page() {
		add_submenu_page(
			'edit.php?post_type=' . Core::NEWSPACK_SPONSORS_CPT,
			__( 'Newspack Sponsors: Site-Wide Settings', 'newspack-sponsors' ),
			__( 'Settings', 'newspack-sponsors' ),
			'manage_options',
			'newspack-sponsors-settings-admin',
			[ __CLASS__, 'create_admin_page' ]
		);
	}

	/**
	 * Options page callback
	 */
	public static function create_admin_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Newspack Sponsors: Site-Wide Settings', 'newspack-sponsors' ); ?></h1>
			<form method="post" action="options.php">
			<?php
				settings_fields( 'newspack_sponsors_options_group' );
				do_settings_sections( 'newspack-sponsors-settings-admin' );
				submit_button();
			?>
			</form>
		</div>
		<?php
	}

	/**
	 * Register and add settings
	 */
	public static function page_init() {
		add_settings_section(
			'newspack_sponsors_options_group',
			null,
			null,
			'newspack-sponsors-settings-admin'
		);
		foreach ( self::get_settings_list() as $setting ) {
			register_setting(
				'newspack_sponsors_options_group',
				$setting['key']
			);
			add_settings_field(
				$setting['key'],
				$setting['label'],
				[ __CLASS__, 'newspack_sponsors_settings_callback' ],
				'newspack-sponsors-settings-admin',
				'newspack_sponsors_options_group',
				$setting
			);
		}
	}

	/**
	 * Render settings fields.
	 *
	 * @param array $setting Settings array.
	 */
	public static function newspack_sponsors_settings_callback( $setting ) {
		$key   = $setting['key'];
		$type  = $setting['type'];
		$value = ( '' !== get_option( $key, false ) ) ? get_option( $key, false ) : $setting['value'];

		if ( 'checkbox' === $type ) {
			printf(
				'<input type="checkbox" id="%s" name="%s" %s />',
				esc_attr( $key ),
				esc_attr( $key ),
				! empty( $value ) ? 'checked' : '',
				esc_attr( $key )
			);
		} elseif ( 'textarea' === $type ) {
			printf(
				'<textarea id="%s" name="%s" class="widefat" rows="4">%s</textarea>',
				esc_attr( $key ),
				esc_attr( $key ),
				esc_attr( $value )
			);
		} else {
			printf(
				'<input type="text" id="%s" name="%s" value="%s" class="widefat" />',
				esc_attr( $key ),
				esc_attr( $key ),
				esc_attr( $value )
			);
		}
	}
}

if ( is_admin() ) {
	Settings::init();
}
