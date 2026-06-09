<?php
/**
 * Newspack Insights — Storage backend detector (NPPD-1616).
 *
 * Decides whether the publisher is on WooCommerce HPOS or legacy CPT
 * order storage, so the metric layer can pick the correct
 * {@see Storage_Interface} implementation.
 *
 * The decision rarely changes (HPOS migration is a one-way event) so we
 * cache it for 24h. Callers that need a fresh check (e.g. a publisher
 * just toggled HPOS during a migration window) can call
 * {@see self::force_refresh()}.
 *
 * Schema reference:
 * `~/Sites/insights-docs/formulas/subscription-donation-schema.md`
 * — `woocommerce_custom_orders_table_enabled` option; `yes` = HPOS,
 * anything else = legacy. The `_data_sync_enabled` flag affects which
 * backend reads are trustworthy but not which one is the source of
 * truth for orders.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

/**
 * Storage backend detector.
 */
class Storage_Detector {

	/**
	 * Cache key for the detected backend.
	 *
	 * @var string
	 */
	const TRANSIENT_KEY = 'newspack_insights_storage_backend';

	/**
	 * Cache TTL in seconds (24 hours).
	 *
	 * @var int
	 */
	const TRANSIENT_TTL = DAY_IN_SECONDS;

	/**
	 * Backend identifier for WooCommerce HPOS.
	 *
	 * @var string
	 */
	const BACKEND_HPOS = 'hpos';

	/**
	 * Backend identifier for legacy CPT order storage.
	 *
	 * @var string
	 */
	const BACKEND_LEGACY = 'legacy';

	/**
	 * Detect the active order storage backend. Cached for 24h.
	 *
	 * @return string Either self::BACKEND_HPOS or self::BACKEND_LEGACY.
	 */
	public static function detect(): string {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( self::BACKEND_HPOS === $cached || self::BACKEND_LEGACY === $cached ) {
			return $cached;
		}

		$backend = self::compute();
		set_transient( self::TRANSIENT_KEY, $backend, self::TRANSIENT_TTL );
		return $backend;
	}

	/**
	 * Force-recompute the backend, bypassing and refreshing the cache.
	 *
	 * Useful for tests, for the HPOS migration window when the option may
	 * flip during a single admin session, and for the future cache
	 * invalidation system in NPPD-1605.
	 *
	 * @return string The freshly computed backend.
	 */
	public static function force_refresh(): string {
		delete_transient( self::TRANSIENT_KEY );
		$backend = self::compute();
		set_transient( self::TRANSIENT_KEY, $backend, self::TRANSIENT_TTL );
		return $backend;
	}

	/**
	 * Compute the backend from the live WooCommerce option.
	 *
	 * `yes` -> HPOS. Anything else (including a missing option on sites
	 * that have never enabled the feature) -> legacy.
	 *
	 * @return string
	 */
	private static function compute(): string {
		return 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' )
			? self::BACKEND_HPOS
			: self::BACKEND_LEGACY;
	}
}
