<?php
/**
 * Newsletter_Ads_Metric subclass simulating an old newsletters build
 * without the Ad_Stats class (NPPD-1861).
 *
 * A defined class can't be undefined, so the "Ad_Stats absent" readiness leg
 * is exercised through the orchestrator's protected `ad_stats_class()` seam:
 * this subclass returns null, making is_report_ready() false regardless of
 * whether the stats table exists.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\Newsletter_Ads_Metric;

/**
 * Subclass with the Ad_Stats seam forced absent.
 */
class Newsletter_Ads_Metric_No_Stats extends Newsletter_Ads_Metric {
	/**
	 * Simulate the Ad_Stats class being absent.
	 *
	 * @return string|null
	 */
	protected static function ad_stats_class(): ?string {
		return null;
	}
}
