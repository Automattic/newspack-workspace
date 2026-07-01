<?php
/**
 * Parse.ly integration class.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Parse.ly integration: migrates existing installs off the legacy `json_ld`
 * meta_type default.
 */
class Parsely {
	/**
	 * Name of the option that records whether the meta_type migration has run.
	 *
	 * @var string
	 */
	const META_TYPE_MIGRATION_OPTION = 'newspack_parsely_meta_type_migrated';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_init', [ __CLASS__, 'migrate_meta_type' ] );
	}

	/**
	 * Migrate existing Parse.ly installs from the legacy `json_ld` meta_type
	 * default to `repeated_metas`, which avoids conflicts with Yoast SEO's own
	 * JSON-LD output.
	 *
	 * The switch is intentional and unconditional for any site still rendering
	 * `json_ld`: because Parse.ly's own default for an absent `meta_type` is
	 * also `json_ld`, a missing key is treated as the legacy default and
	 * rewritten too. This means a deliberate publisher `json_ld` choice is
	 * indistinguishable from the old default and gets switched as well — which
	 * is desired, since the Yoast conflict applies regardless of intent.
	 *
	 * Runs on every `admin_init` until wp-parsely is active, then migrates the
	 * site once and records completion. On sites that never activate Parse.ly
	 * the migration simply stays pending (two cached option reads per request).
	 */
	public static function migrate_meta_type() {
		if ( get_option( self::META_TYPE_MIGRATION_OPTION ) ) {
			return;
		}

		if ( ! is_plugin_active( 'wp-parsely/wp-parsely.php' ) ) {
			return;
		}

		$parsely_settings = get_option( 'parsely', [] );
		if ( ! is_array( $parsely_settings ) ) {
			$parsely_settings = [];
		}

		// Treat an absent key as the legacy default: wp-parsely merges its own
		// `json_ld` default at read time, so a missing `meta_type` renders the
		// same conflicting JSON-LD output we're migrating away from.
		$meta_type = $parsely_settings['meta_type'] ?? 'json_ld';
		if ( 'json_ld' === $meta_type ) {
			$parsely_settings['meta_type'] = 'repeated_metas';
			update_option( 'parsely', $parsely_settings );
		}

		update_option( self::META_TYPE_MIGRATION_OPTION, true );
	}
}
Parsely::init();
