<?php
/**
 * Tests for the Teams for Memberships diagnostics CLI command.
 *
 * @package Newspack\Tests
 */

use Newspack\CLI\Teams_For_Memberships_Diagnostics;

/**
 * Test the duplicate-team classifier that decides which same-title/same-author teams
 * are genuine renewal-bug duplicates versus separate legitimate purchases.
 *
 * @group teams-for-memberships
 */
class Test_Teams_For_Memberships_Diagnostics extends WP_UnitTestCase {

	/**
	 * Build a lightweight team row for the classifier.
	 *
	 * @param int    $id              Team post ID.
	 * @param string $post_date       Team creation date.
	 * @param string $subscription_id Linked subscription id, empty when the team has none.
	 * @return object
	 */
	private function team_row( $id, $post_date, $subscription_id = '' ) {
		return (object) [
			'ID'              => $id,
			'post_title'      => 'Acme Team',
			'post_author'     => 42,
			'post_date'       => $post_date,
			'subscription_id' => (string) $subscription_id,
		];
	}

	/**
	 * The classic renewal-bug shape: one team owns the subscription, the others are
	 * subscription-less orphans created when SkyVerge Teams fell through to `create`.
	 * The subscribed team is the original; every orphan is a duplicate to merge in.
	 */
	public function test_subscriptionless_orphans_merge_into_the_subscribed_original() {
		$original = $this->team_row( 100, '2026-01-01 00:00:00', '555' );
		$orphan_a = $this->team_row( 200, '2026-03-01 00:00:00', '' );
		$orphan_b = $this->team_row( 300, '2026-04-01 00:00:00', '' );

		$result = Teams_For_Memberships_Diagnostics::classify_team_bucket( [ $orphan_b, $original, $orphan_a ] );

		$this->assertSame( 100, $result['original']->ID, 'The team that owns a subscription is the canonical original.' );
		$this->assertEqualSets( [ 200, 300 ], wp_list_pluck( $result['duplicates'], 'ID' ), 'Both orphans are duplicates to merge.' );
		$this->assertEmpty( $result['separate_purchases'], 'Nothing should be treated as a separate purchase here.' );
	}

	/**
	 * The false-positive this fix targets: two teams that each own their own subscription
	 * are separate purchases (e.g. a real account and a throwaway tester account), not the
	 * renewal-bug duplicate. They must be left untouched – never merged or deleted.
	 *
	 * Regression guard for https://linear.app/a8c/issue/NPPM-2741: the previous logic
	 * picked the older subscribed team as "original" and merged the newer (often still
	 * active) one into it, which would bind a live membership to a stale subscription and
	 * force-delete the live team.
	 */
	public function test_independently_subscribed_teams_are_separate_purchases_not_duplicates() {
		$older_cancelled = $this->team_row( 100, '2026-02-15 00:00:00', '990678' );
		$newer_active    = $this->team_row( 200, '2026-06-10 00:00:00', '1024679' );

		$result = Teams_For_Memberships_Diagnostics::classify_team_bucket( [ $older_cancelled, $newer_active ] );

		$this->assertNull( $result['original'], 'No team should be chosen as a merge target.' );
		$this->assertEmpty( $result['duplicates'], 'Neither team is a duplicate to merge.' );
		$this->assertEqualSets( [ 100, 200 ], wp_list_pluck( $result['separate_purchases'], 'ID' ), 'Both subscribed teams are reported as separate purchases.' );
	}

	/**
	 * A subscription-less orphan alongside two independently subscribed teams can't be
	 * attributed to a single purchase, so the whole set is left for manual review rather
	 * than merged into an arbitrary one.
	 */
	public function test_orphan_alongside_separate_purchases_is_left_for_manual_review() {
		$purchase_a = $this->team_row( 100, '2026-02-15 00:00:00', '990678' );
		$purchase_b = $this->team_row( 200, '2026-06-10 00:00:00', '1024679' );
		$orphan     = $this->team_row( 300, '2026-06-20 00:00:00', '' );

		$result = Teams_For_Memberships_Diagnostics::classify_team_bucket( [ $purchase_a, $orphan, $purchase_b ] );

		$this->assertEmpty( $result['duplicates'], 'The orphan must not be merged when attribution is ambiguous.' );
		$this->assertEqualSets( [ 100, 200 ], wp_list_pluck( $result['separate_purchases'], 'ID' ), 'Only the subscribed purchases are reported.' );
		$this->assertEqualSets( [ 300 ], wp_list_pluck( $result['unattributed_orphans'], 'ID' ), 'The unlinked orphan is surfaced for manual review.' );
	}

	/**
	 * A `_subscription_id` of "0" (a stale or never-set link, stored as int 0) is not a
	 * real subscription. It must count as an orphan, not a separate purchase – otherwise a
	 * genuine duplicate carrying a "0" link would be shielded from repair.
	 */
	public function test_zero_subscription_id_counts_as_no_subscription() {
		$subscribed = $this->team_row( 100, '2026-01-01 00:00:00', '555' );
		$zero_link  = $this->team_row( 200, '2026-02-01 00:00:00', '0' );

		$result = Teams_For_Memberships_Diagnostics::classify_team_bucket( [ $subscribed, $zero_link ] );

		$this->assertSame( 100, $result['original']->ID, 'The genuinely subscribed team is the original.' );
		$this->assertEqualSets( [ 200 ], wp_list_pluck( $result['duplicates'], 'ID' ), 'A team whose _subscription_id is "0" is an orphan duplicate.' );
		$this->assertEmpty( $result['separate_purchases'], 'A "0" subscription id must not trigger the separate-purchases branch.' );
	}

	/**
	 * When no team in the set owns a subscription, fall back to the oldest as the original
	 * and treat the rest as duplicates (unchanged behaviour for fully unlinked sets).
	 */
	public function test_fully_unlinked_set_falls_back_to_oldest_as_original() {
		$oldest = $this->team_row( 100, '2026-01-01 00:00:00', '' );
		$newer  = $this->team_row( 200, '2026-02-01 00:00:00', '' );

		$result = Teams_For_Memberships_Diagnostics::classify_team_bucket( [ $newer, $oldest ] );

		$this->assertSame( 100, $result['original']->ID, 'Oldest team is the original when none own a subscription.' );
		$this->assertEqualSets( [ 200 ], wp_list_pluck( $result['duplicates'], 'ID' ), 'The newer unlinked team is the duplicate.' );
		$this->assertEmpty( $result['separate_purchases'] );
	}
}
