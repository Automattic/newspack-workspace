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

	/**
	 * The renewal bug can regenerate the replacement team with a cosmetically different
	 * title – a different possessive apostrophe, letter case, or stray whitespace. Those
	 * variants must normalize to one bucket key, or Check 1 never groups the duplicate and
	 * the orphan escapes repair (the gap that left a paying reader's membership stranded:
	 * "John Collyns' Team" vs "John Collyns's Team").
	 */
	public function test_cosmetic_title_variants_share_one_bucket_key() {
		$apostrophe_only = Teams_For_Memberships_Diagnostics::normalize_team_title( "John Collyns' Team" );
		$apostrophe_s    = Teams_For_Memberships_Diagnostics::normalize_team_title( "John Collyns's Team" );
		$case_and_space  = Teams_For_Memberships_Diagnostics::normalize_team_title( "  JOHN   Collyns's   TEAM  " );

		$this->assertSame( $apostrophe_only, $apostrophe_s, "Collyns' and Collyns's must bucket together." );
		$this->assertSame( $apostrophe_only, $case_and_space, 'Case and whitespace must not split the bucket.' );
	}

	/**
	 * Normalization must not over-collapse: genuinely different team names keep distinct
	 * keys, so separate memberships are never grouped (and merged) into each other.
	 */
	public function test_distinct_titles_keep_distinct_bucket_keys() {
		$acme = Teams_For_Memberships_Diagnostics::normalize_team_title( "Acme Co's Team" );
		$beta = Teams_For_Memberships_Diagnostics::normalize_team_title( "Beta LLC's Team" );

		$this->assertNotSame( $acme, $beta, 'Different team names must not collapse into one bucket.' );
	}

	/**
	 * WordPress runs stored titles through wptexturize, which rewrites a straight apostrophe
	 * to a curly one (U+2019). That curly variant is the load-bearing case the real stranded
	 * membership hit, so the regex's curly branch and the `/u` modifier must collapse it to
	 * the same key as the straight form – an ASCII-only assertion would miss the actual fix.
	 */
	public function test_curly_apostrophe_normalizes_like_straight() {
		$straight        = Teams_For_Memberships_Diagnostics::normalize_team_title( "John Collyns' Team" );
		$curly           = Teams_For_Memberships_Diagnostics::normalize_team_title( 'John Collyns’ Team' );
		$curly_possessive = Teams_For_Memberships_Diagnostics::normalize_team_title( 'John Collyns’s Team' );

		$this->assertSame( $straight, $curly, 'A curly apostrophe must bucket with the straight form.' );
		$this->assertSame( $straight, $curly_possessive, 'A curly possessive-s must bucket with it too.' );
	}

	/**
	 * The `--team-id` path widens the lookup to every team owned by the same author, then
	 * restricts to the target team's bucket. An owner who has the duplicated team *and* a
	 * genuinely distinct second team must see only the duplicate's bucket – the unrelated
	 * team is never pulled into the merge set.
	 */
	public function test_team_id_scope_returns_only_the_targets_bucket() {
		$original  = (object) [
			'ID'          => 10,
			'post_author' => 7,
			'post_title'  => "Smith's Team",
		];
		$orphan    = (object) [
			'ID'          => 11,
			'post_author' => 7,
			'post_title'  => "Smith' Team",
		];
		$unrelated = (object) [
			'ID'          => 12,
			'post_author' => 7,
			'post_title'  => 'Garden Club',
		];

		$only_key = Teams_For_Memberships_Diagnostics::team_bucket_key( $original );
		$buckets  = Teams_For_Memberships_Diagnostics::bucket_teams_by_owner( [ $original, $orphan, $unrelated ], $only_key );

		$this->assertCount( 1, $buckets, "Only the target team's bucket is returned." );
		$bucketed_ids = wp_list_pluck( array_values( $buckets )[0], 'ID' );
		$this->assertEqualSets( [ 10, 11 ], $bucketed_ids, 'The apostrophe-variant orphan buckets with the original; the unrelated team is excluded.' );
	}

	/**
	 * Without a scope key, every owner+title bucket is returned (the site-wide pass), so
	 * the unrelated team forms its own single-team bucket rather than vanishing.
	 */
	public function test_unscoped_bucketing_keeps_every_owner_title_group() {
		$original  = (object) [
			'ID'          => 10,
			'post_author' => 7,
			'post_title'  => "Smith's Team",
		];
		$orphan    = (object) [
			'ID'          => 11,
			'post_author' => 7,
			'post_title'  => "Smith' Team",
		];
		$unrelated = (object) [
			'ID'          => 12,
			'post_author' => 7,
			'post_title'  => 'Garden Club',
		];

		$buckets = Teams_For_Memberships_Diagnostics::bucket_teams_by_owner( [ $original, $orphan, $unrelated ] );

		$this->assertCount( 2, $buckets, 'The duplicated pair and the unrelated team are separate buckets.' );
	}
}
