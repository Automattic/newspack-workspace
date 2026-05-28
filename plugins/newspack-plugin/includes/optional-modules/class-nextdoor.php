<?php
/**
 * Nextdoor integration for Newspack.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Nextdoor\API;

defined( 'ABSPATH' ) || exit;

/**
 * Nextdoor integration class.
 */
class Nextdoor {

	/**
	 * Settings slug.
	 *
	 * @var string
	 */
	const SETTINGS_SLUG = 'newspack_nextdoor_settings';

	/**
	 * Capability slug for publishing to Nextdoor.
	 *
	 * @var string
	 */
	const CAPABILITY_SLUG = 'np_nextdoor_publish_posts';

	/**
	 * Initialize the module.
	 */
	public static function init() {
		// Only initialize if the module is active.
		if ( ! Optional_Modules::is_optional_module_active( 'nextdoor' ) ) {
			return;
		}

		// Add custom capability.
		add_action( 'admin_init', [ __CLASS__, 'add_nextdoor_capability' ] );

		// Enqueue post editor scripts.
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_post_editor_assets' ] );

		// Include required files.
		require_once NEWSPACK_ABSPATH . 'includes/optional-modules/nextdoor/class-api.php';
		require_once NEWSPACK_ABSPATH . 'includes/optional-modules/nextdoor/class-auth.php';
		require_once NEWSPACK_ABSPATH . 'includes/optional-modules/nextdoor/class-controller.php';
	}

	/**
	 * Enqueue post editor assets.
	 */
	public static function enqueue_post_editor_assets() {
		// Only load on post editor for users who can publish to Nextdoor.
		$screen     = get_current_screen();
		$post_types = apply_filters( 'newspack_nextdoor_publish_cap_post_types', [ 'post' ] );
		if ( ! $screen || ! in_array( $screen->post_type, $post_types, true ) || ! self::can_user_publish() ) {
			return;
		}

		$handle = 'newspack-nextdoor-post-editor-plugin';
		wp_enqueue_script(
			$handle,
			\Newspack\Newspack::plugin_url() . '/dist/other-scripts/nextdoor.js',
			[ 'wp-edit-post', 'wp-data', 'wp-components', 'wp-element' ],
			NEWSPACK_PLUGIN_VERSION,
			true
		);

		wp_enqueue_style(
			$handle,
			\Newspack\Newspack::plugin_url() . '/dist/other-scripts/nextdoor.css',
			[],
			NEWSPACK_PLUGIN_VERSION
		);
	}

	/**
	 * Add custom Nextdoor capability to appropriate roles.
	 */
	public static function add_nextdoor_capability() {
		$allowed_roles = self::get_nextdoor_capability_roles();
		$all_roles     = wp_roles()->roles;

		foreach ( $all_roles as $role_name => $role_info ) {
			$role = get_role( $role_name );

			if ( ! $role ) {
				continue;
			}

			$is_allowed = in_array( $role_name, $allowed_roles, true );

			// Add capability to allowed roles.
			if ( $is_allowed && ! $role->has_cap( self::CAPABILITY_SLUG ) ) {
				$role->add_cap( self::CAPABILITY_SLUG );
			} elseif ( ! $is_allowed && $role->has_cap( self::CAPABILITY_SLUG ) ) {
				// Remove capability from disallowed roles.
				$role->remove_cap( self::CAPABILITY_SLUG );
			}
		}
	}

	/**
	 * Get roles that have Nextdoor publishing capability.
	 *
	 * @return array
	 */
	public static function get_nextdoor_capability_roles() {
		$settings       = self::get_settings();
		$roles_with_cap = isset( $settings['allowed_roles'] ) ? (array) $settings['allowed_roles'] : [ 'administrator' ];

		/**
		 * Filter for roles that should have Nextdoor capabilities.
		 *
		 * @param array $roles_with_cap Array of role names.
		 */
		$roles_with_cap = apply_filters( 'newspack_nextdoor_publish_cap_roles', $roles_with_cap );

		// Ensure administrator always has capability.
		if ( ! in_array( 'administrator', $roles_with_cap, true ) ) {
			$roles_with_cap[] = 'administrator';
		}

		return $roles_with_cap;
	}

	/**
	 * Get centralized API credentials from environment variables or constants.
	 *
	 * This method checks for credentials in the following order:
	 * 1. PHP constants
	 * 2. Environment variables
	 *
	 * @return array credentials.
	 */
	public static function get_centralized_credentials() {
		$client_id     = '';
		$client_secret = '';

		/**
		 * OAuth Client ID for Nextdoor integration. Can also be set
		 * via environment variable.
		 *
		 * @constant NEWSPACK_NEXTDOOR_CLIENT_ID
		 * @type     string
		 * @default  Nextdoor integration unavailable
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_NEXTDOOR_CLIENT_ID', 'your-client-id' );
		 */
		if ( defined( 'NEWSPACK_NEXTDOOR_CLIENT_ID' ) ) {
			$client_id = NEWSPACK_NEXTDOOR_CLIENT_ID;
		} elseif ( getenv( 'NEWSPACK_NEXTDOOR_CLIENT_ID' ) ) {
			$client_id = getenv( 'NEWSPACK_NEXTDOOR_CLIENT_ID' );
		}

		/**
		 * OAuth Client Secret for Nextdoor integration. Can also be set
		 * via environment variable.
		 *
		 * @constant NEWSPACK_NEXTDOOR_CLIENT_SECRET
		 * @type     string
		 * @default  Nextdoor integration unavailable
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_NEXTDOOR_CLIENT_SECRET', 'your-client-secret' );
		 */
		if ( defined( 'NEWSPACK_NEXTDOOR_CLIENT_SECRET' ) ) {
			$client_secret = NEWSPACK_NEXTDOOR_CLIENT_SECRET;
		} elseif ( getenv( 'NEWSPACK_NEXTDOOR_CLIENT_SECRET' ) ) {
			$client_secret = getenv( 'NEWSPACK_NEXTDOOR_CLIENT_SECRET' );
		}

		return [
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
		];
	}

	/**
	 * Check if centralized credentials are configured.
	 *
	 * @return bool True credentials are set, false otherwise.
	 */
	public static function has_centralized_credentials() {
		$credentials = self::get_centralized_credentials();
		return ! empty( $credentials['client_id'] ) && ! empty( $credentials['client_secret'] );
	}

	/**
	 * Get Nextdoor settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$default_settings = [
			// OAuth credentials.
			'client_id'        => '',
			'client_secret'    => '',

			// OAuth token.
			'refresh_token'    => '',
			'access_token'     => '',
			'token_expires_at' => 0,

			// Nextdoor page info.
			'page_id'          => '',
			'publication_url'  => '',

			// User configs.
			'allowed_roles'    => [ 'administrator' ],
		];

		/**
		 * Filter the default Nextdoor settings.
		 *
		 * @param array $default_settings The default settings for Nextdoor integration.
		 * @return array Modified default settings.
		 */
		$default_settings = apply_filters( 'newspack_nextdoor_default_settings', $default_settings );

		$saved_settings = get_option( self::SETTINGS_SLUG, [] );

		if ( ! is_array( $saved_settings ) ) {
			$saved_settings = [];
		}

		/**
		 * Filter the saved Nextdoor settings.
		 *
		 * @param array $saved_settings The saved settings for Nextdoor integration.
		 * @return array Modified saved settings.
		 */
		$saved_settings = apply_filters( 'newspack_nextdoor_settings', $saved_settings );

		$settings = shortcode_atts( $default_settings, $saved_settings );

		$centralized_credentials = self::get_centralized_credentials();
		if ( ! empty( $centralized_credentials['client_id'] ) ) {
			$settings['client_id'] = $centralized_credentials['client_id'];
		}
		if ( ! empty( $centralized_credentials['client_secret'] ) ) {
			$settings['client_secret'] = $centralized_credentials['client_secret'];
		}

		return $settings;
	}

	/**
	 * Update Nextdoor settings.
	 *
	 * @param array $settings Settings array.
	 * @return bool
	 */
	public static function update_settings( $settings ) {
		return update_option( self::SETTINGS_SLUG, $settings );
	}

	/**
	 * Delete Nextdoor settings.
	 *
	 * @return bool
	 */
	public static function delete_settings() {
		return delete_option( self::SETTINGS_SLUG );
	}

	/**
	 * Check if user can publish to Nextdoor.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function can_user_publish( $user_id = null ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		return user_can( $user_id, self::CAPABILITY_SLUG );
	}

	/**
	 * Check if Nextdoor is connected.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$settings = self::get_settings();
		return ! empty( $settings['access_token'] ) && ! empty( $settings['page_id'] );
	}

	/**
	 * Get the OAuth redirect URI for Nextdoor.
	 *
	 * @return string
	 */
	public static function get_redirect_uri() {
		return admin_url( 'admin.php?page=newspack-settings&nextdoor_oauth_callback=1' );
	}

	/**
	 * Get available WordPress roles for Nextdoor publishing.
	 *
	 * @return array Array of role data with label and value.
	 */
	public static function get_available_roles() {
		$wp_roles = wp_roles();
		$roles = [];

		foreach ( $wp_roles->roles as $role_name => $role_info ) {
			$roles[] = [
				'label' => translate_user_role( $role_info['name'], 'newspack-plugin' ),
				'value' => $role_name,
			];
		}

		return $roles;
	}

	/**
	 * Get available countries for Nextdoor publishing with their COUNTRY_ISO_3166-1_ALPHA-2 format values.
	 *
	 * @return array Array of country data with label and value.
	 */
	public static function get_available_countries() {
		$countries = [
			[
				'label' => __( 'United States', 'newspack-plugin' ),
				'value' => 'US',
			],
			[
				'label' => __( 'Canada', 'newspack-plugin' ),
				'value' => 'CA',
			],
			[
				'label' => __( 'Australia', 'newspack-plugin' ),
				'value' => 'AU',
			],
		];

		/**
		 * Filter available countries for Nextdoor integration.
		 *
		 * @param array $countries Array of country data with label and value.
		 * @see https://developer.nextdoor.com/reference/displaying-availability
		 */
		return apply_filters( 'newspack_nextdoor_available_countries', $countries );
	}
}

Nextdoor::init();
