<?php
/**
 * The stats provider an A/B Tests dashboard reads from.
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * A source of per-variant reader and conversion counts for a test.
 *
 * The dashboard asks one provider per site, chosen through the
 * `newspack_popups_ab_stats_provider` filter, for raw counts; it computes
 * rates, lift and confidence itself, so two surfaces never disagree about
 * one test. A provider returns counts and nothing else.
 */
interface Newspack_Popups_AB_Stats_Provider {
	/**
	 * A short identifier for this provider, reported with the stats.
	 *
	 * @return string
	 */
	public function get_slug(): string;

	/**
	 * Whether the provider can answer on this site right now.
	 *
	 * Called on every dashboard load and before every stats request, cached
	 * or not, so it must be cheap: read a setting, never call out. The
	 * network belongs in get_counts(), whose answer is cached for an hour.
	 *
	 * @return bool|WP_Error True, or an error whose message says what is
	 *                       missing in words a site administrator can act on.
	 *                       A plain false is reported with a generic reason.
	 */
	public function is_available();

	/**
	 * Count readers and conversions per variant for one test.
	 *
	 * @param array  $test  One entry of Newspack_Popups_AB_Tests::get_tests():
	 *                      `variants` maps a variant letter to the prompt that
	 *                      carries it, with the prompt's `id`.
	 * @param string $goal  The test's conversion goal, one of
	 *                      Newspack_Popups_AB_Tests::VALID_GOALS or ''. With no
	 *                      goal the provider counts readers only.
	 * @param string $start First day of the window, Y-m-d, UTC.
	 * @param string $end   Last day of the window, Y-m-d, UTC.
	 * @return array|WP_Error `[ 'unit' => 'readers'|'events', 'variants' => [
	 *                        '<letter>' => [ 'readers' => int, 'conversions' => int,
	 *                        'logged_in_readers' => int ] ] ]`. `unit` says what
	 *                        was counted: distinct readers, or events when the
	 *                        source could not count readers. Variants the source
	 *                        has nothing for may be left out. An empty `variants`
	 *                        means no data yet; a WP_Error means the source could
	 *                        not answer, with the reason in its message. An optional
	 *                        `note` is one sentence the page shows under the counts,
	 *                        in words a site administrator can read, such as how
	 *                        often the source refreshes.
	 */
	public function get_counts( array $test, string $goal, string $start, string $end );
}
