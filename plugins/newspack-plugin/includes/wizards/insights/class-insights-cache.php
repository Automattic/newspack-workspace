<?php
/**
 * Newspack Insights — Cache helper.
 *
 * Source-typed transient wrapper used by the Insights REST controllers.
 * See ~/Documents/AI/plans/2026-06-10-insights-caching-design.md.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

final class Cache {

	const SOURCE_BIGQUERY = 'bigquery';
	const SOURCE_EXTERNAL = 'external';
	const SOURCE_LOCAL    = 'local';

	const TTL_BIGQUERY        = DAY_IN_SECONDS;
	const TTL_EXTERNAL        = 10 * MINUTE_IN_SECONDS;
	const BQ_COOLDOWN_SECONDS = 10 * MINUTE_IN_SECONDS;

	const LOGGER_HEADER = 'NEWSPACK-INSIGHTS-CACHE';

	/**
	 * Disable all server-side caching when the escape-hatch constant is on.
	 */
	public static function is_disabled(): bool {
		return defined( 'NEWSPACK_INSIGHTS_CACHE_DISABLED' ) && NEWSPACK_INSIGHTS_CACHE_DISABLED;
	}
}
