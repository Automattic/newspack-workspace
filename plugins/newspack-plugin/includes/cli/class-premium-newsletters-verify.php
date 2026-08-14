<?php
/**
 * WP-CLI command to compare Access Control premium newsletter gates against the
 * ESP, and report readers whose subscription state disagrees with their
 * entitlement.
 *
 * Runs after a premium newsletter migration and after WooCommerce Memberships is
 * deactivated. Its purpose is evidence: a run with no leaks is what tells an
 * operator that cutover left nobody reading a paid newsletter they no longer pay
 * for.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Premium newsletter expected-vs-actual verification CLI command.
 */
class Premium_Newsletters_Verify {

	/**
	 * Classify one reader's state on one restricted list.
	 *
	 * The asymmetry is deliberate. A restricted reader who is subscribed is always
	 * wrong: they are receiving paid content without entitlement. An entitled
	 * reader who is not subscribed is only wrong when auto-signup is on, because
	 * with it off the site never promised to subscribe them — they opt in
	 * themselves, and reporting that as a defect would fail every such site.
	 *
	 * @param bool $is_restricted Whether the gate restricts this list for this reader.
	 * @param bool $is_subscribed Whether the ESP has the reader on the list.
	 * @param bool $auto_signup   Whether the site auto-subscribes entitled readers.
	 *
	 * @return string One of 'leak', 'gap', 'ok', 'not_asserted'.
	 */
	private static function classify_reader( bool $is_restricted, bool $is_subscribed, bool $auto_signup ): string {
		if ( $is_restricted ) {
			return $is_subscribed ? 'leak' : 'ok';
		}
		if ( $is_subscribed ) {
			return 'ok';
		}
		return $auto_signup ? 'gap' : 'not_asserted';
	}

	/**
	 * Count each outcome across a run's rows.
	 *
	 * Every bucket is present even at zero so callers can read one without first
	 * checking it exists.
	 *
	 * @param array[] $rows Result rows, each carrying a 'status' key.
	 *
	 * @return array<string,int> Counts keyed by status.
	 */
	private static function summarize_rows( array $rows ): array {
		$summary = [
			'leak'         => 0,
			'gap'          => 0,
			'ok'           => 0,
			'not_asserted' => 0,
			'unresolved'   => 0,
		];
		foreach ( $rows as $row ) {
			$status = $row['status'] ?? '';
			if ( isset( $summary[ $status ] ) ) {
				++$summary[ $status ];
			}
		}
		return $summary;
	}

	/**
	 * Whether the run should report failure.
	 *
	 * Leaks fail because they are the defect this command looks for. Unresolved
	 * rows fail because an unread contact is not evidence of safety — without
	 * this, a provider outage would report a site as ready to flip. Gaps do not
	 * fail: nothing is leaking, and this command never writes an addition.
	 *
	 * @param array<string,int> $summary Counts from summarize_rows().
	 *
	 * @return bool
	 */
	private static function verification_failed( array $summary ): bool {
		return 0 < ( $summary['leak'] ?? 0 ) || 0 < ( $summary['unresolved'] ?? 0 );
	}
}
