<?php
/**
 * Collections Enqueuer.
 *
 * @package Newspack\Collections
 */

namespace Newspack\Collections;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the enqueuing of collections scripts and styles.
 */
class Enqueuer {
	/**
	 * The name of the admin script.
	 *
	 * @var string
	 */
	public const SCRIPT_NAME_ADMIN = 'collections-admin';

	/**
	 * The name of the frontend script.
	 *
	 * @var string
	 */
	public const SCRIPT_NAME_FRONTEND = 'collections-frontend';

	/**
	 * The name of the global JavaScript object.
	 *
	 * @var string
	 */
	public const JS_OBJECT_NAME = 'newspackCollections';

	/**
	 * The current data structure.
	 *
	 * @var array
	 */
	private static $data = [];

	/**
	 * Initialize the enqueuer hooks.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'maybe_enqueue_admin_assets' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'maybe_enqueue_frontend_assets' ] );
	}

	/**
	 * Add data to the collections object.
	 *
	 * @param string $key   The key to store the data under.
	 * @param array  $data  The data to store.
	 */
	public static function add_data( $key, $data ) {
		self::$data[ $key ] = $data;
	}

	/**
	 * Get the current data structure.
	 *
	 * @return array The current data.
	 */
	public static function get_data() {
		return self::$data;
	}

	/**
	 * Conditionally enqueue admin assets only if data was added.
	 */
	public static function maybe_enqueue_admin_assets() {
		if ( empty( self::$data ) ) {
			return;
		}

		// Enqueue admin assets.
		\Newspack\Newspack::load_common_assets();
		self::enqueue_script(
			self::SCRIPT_NAME_ADMIN,
			[ 'jquery', 'wp-i18n', 'wp-plugins', 'wp-edit-post', 'wp-components', 'wp-element', 'wp-data', 'wp-editor', 'wp-api-fetch' ]
		);
		self::enqueue_style( self::SCRIPT_NAME_ADMIN );
		self::localize_data( self::SCRIPT_NAME_ADMIN );
	}

	/**
	 * Conditionally enqueue frontend assets only if data was added.
	 */
	public static function maybe_enqueue_frontend_assets() {
		if ( empty( self::$data ) ) {
			return;
		}

		// Enqueue frontend assets.
		self::enqueue_script( self::SCRIPT_NAME_FRONTEND, [ 'wp-dom-ready' ] );
		self::enqueue_style( self::SCRIPT_NAME_FRONTEND );
		self::localize_data( self::SCRIPT_NAME_FRONTEND );
	}

	/**
	 * Enqueue a script.
	 *
	 * @param string $handle       Script handle.
	 * @param array  $dependencies Script dependencies. Default is empty array.
	 */
	private static function enqueue_script( $handle, $dependencies = [] ) {
		wp_enqueue_script(
			$handle,
			\Newspack\Newspack::plugin_url() . '/dist/' . $handle . '.js',
			$dependencies,
			NEWSPACK_PLUGIN_VERSION,
			true
		);
	}

	/**
	 * Enqueue a style.
	 *
	 * @param string $handle   Style handle.
	 * @param array  $dependencies Style dependencies. Default is empty array.
	 */
	private static function enqueue_style( $handle, $dependencies = [] ) {
		wp_enqueue_style(
			$handle,
			\Newspack\Newspack::plugin_url() . '/dist/' . $handle . '.css',
			$dependencies,
			NEWSPACK_PLUGIN_VERSION
		);

		wp_style_add_data( $handle, 'rtl', 'replace' );
	}

	/**
	 * Localize data to the specified script.
	 *
	 * @param string $script_handle The script handle to localize data to.
	 */
	private static function localize_data( $script_handle ) {
		if ( wp_script_is( $script_handle, 'registered' ) ) {
			wp_localize_script(
				$script_handle,
				self::JS_OBJECT_NAME,
				self::$data
			);
		}
	}
}
