<?php
/**
 * Class Test Advertisers List REST
 *
 * Covers the validation rails the Advertisers DataView relies on (parent
 * != self on update). The list / create / read / delete endpoints are
 * the unmodified WP defaults exposed by `show_in_rest => true` on the
 * taxonomy registration; only the parent-self update path needs custom
 * coverage because WP's own enforcement is asymmetric (insert checks,
 * update doesn't).
 *
 * @package Newspack_Newsletters
 */

use Newspack_Newsletters\Ads;

/**
 * Validation tests for the Advertiser taxonomy REST surface.
 */
class Advertisers_List_REST_Test extends WP_UnitTestCase {
	/**
	 * Administrator user — the taxonomy's `manage_terms` cap defaults
	 * to `manage_categories`, which administrators have.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up REST server and an authenticated admin user.
	 */
	public function set_up() {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Reset REST server.
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * A POST update setting `parent` to the term's own id is rejected
	 * with a 400 and a descriptive error code. WP's `wp_update_term`
	 * does not enforce this on its own, so the guard at the REST layer
	 * is what stops a self-referencing row from being persisted.
	 */
	public function test_update_rejects_parent_equals_self() {
		$term = wp_insert_term( 'Self-parent advertiser', Ads::ADVERTISER_TAX );
		$this->assertNotWPError( $term );
		$term_id = (int) $term['term_id'];

		$request = new WP_REST_Request( 'POST', '/wp/v2/' . Ads::ADVERTISER_TAX . '/' . $term_id );
		$request->set_param( 'parent', $term_id );

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'newspack_newsletters_advertiser_parent_self', $data['code'] ?? null );
	}

	/**
	 * A normal update (parent set to a different valid term) is
	 * untouched by the guard — the filter only fires when parent equals
	 * the term being updated.
	 */
	public function test_update_with_different_parent_succeeds() {
		$parent = wp_insert_term( 'Parent advertiser', Ads::ADVERTISER_TAX );
		$child  = wp_insert_term( 'Child advertiser', Ads::ADVERTISER_TAX );
		$this->assertNotWPError( $parent );
		$this->assertNotWPError( $child );

		$request = new WP_REST_Request( 'POST', '/wp/v2/' . Ads::ADVERTISER_TAX . '/' . (int) $child['term_id'] );
		$request->set_param( 'parent', (int) $parent['term_id'] );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( (int) $parent['term_id'], $data['parent'] );
	}

	/**
	 * Creating a term doesn't carry a route URL `id`, so the guard is a
	 * no-op for create — `wp_insert_term` itself prevents self-parenting
	 * (the term doesn't exist yet to be referenced). Create with parent=0
	 * (top-level) succeeds and returns the new term.
	 */
	public function test_create_top_level_term_succeeds() {
		$request = new WP_REST_Request( 'POST', '/wp/v2/' . Ads::ADVERTISER_TAX );
		$request->set_param( 'name', 'Top-level advertiser' );

		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'Top-level advertiser', $data['name'] );
		$this->assertSame( 0, $data['parent'] );
	}

	/**
	 * The advertiser count must match the Ads list, which shows non-publish
	 * ads too — so a draft ad should count, not leave the advertiser at `0`.
	 */
	public function test_advertiser_count_includes_non_publish_ads() {
		$term = wp_insert_term( 'Acme', Ads::ADVERTISER_TAX );
		$this->assertIsArray( $term );

		$ad = self::factory()->post->create(
			[
				'post_type'   => Ads::CPT,
				'post_status' => 'draft',
			]
		);
		wp_set_object_terms( $ad, [ (int) $term['term_id'] ], Ads::ADVERTISER_TAX );

		$fresh = get_term( $term['term_id'], Ads::ADVERTISER_TAX );
		$this->assertSame( 1, (int) $fresh->count );
	}

	/**
	 * The Ads list `draft` bucket also surfaces `auto-draft` ads, so the
	 * advertiser count must include them too — otherwise the count and the
	 * list disagree by any auto-draft rows.
	 */
	public function test_advertiser_count_includes_auto_draft_ads() {
		$term = wp_insert_term( 'Auto-draft Advertiser', Ads::ADVERTISER_TAX );
		$this->assertIsArray( $term );
		$term_id = (int) $term['term_id'];

		$ad = self::factory()->post->create(
			[
				'post_type'   => Ads::CPT,
				'post_status' => 'auto-draft',
			]
		);
		wp_set_object_terms( $ad, [ $term_id ], Ads::ADVERTISER_TAX );

		clean_term_cache( [ $term_id ], Ads::ADVERTISER_TAX );
		$fresh = get_term( $term_id, Ads::ADVERTISER_TAX );
		$this->assertSame( 1, (int) $fresh->count );
	}

	/**
	 * A site that already ran the prior one-time recount must still pick up
	 * the new auto-draft semantics: the bumped sentinel re-runs the recount
	 * and refreshes stale term counts.
	 */
	public function test_recount_refreshes_stale_counts_after_sentinel_bump() {
		update_option( 'newspack_nl_advertiser_count_recounted_v2', 1 );
		delete_option( 'newspack_nl_advertiser_count_recounted_v3' );

		$term = wp_insert_term( 'Stale-count Advertiser', Ads::ADVERTISER_TAX );
		$this->assertIsArray( $term );
		$term_id = (int) $term['term_id'];

		$ad = self::factory()->post->create(
			[
				'post_type'   => Ads::CPT,
				'post_status' => 'auto-draft',
			]
		);
		wp_set_object_terms( $ad, [ $term_id ], Ads::ADVERTISER_TAX );

		// Force a stale count as if it predated the auto-draft semantics.
		global $wpdb;
		$tt_id = (int) get_term( $term_id, Ads::ADVERTISER_TAX )->term_taxonomy_id;
		$wpdb->update( $wpdb->term_taxonomy, [ 'count' => 0 ], [ 'term_taxonomy_id' => $tt_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		clean_term_cache( [ $term_id ], Ads::ADVERTISER_TAX );
		$this->assertSame( 0, (int) get_term( $term_id, Ads::ADVERTISER_TAX )->count );

		Ads::maybe_recount_advertiser_terms();

		clean_term_cache( [ $term_id ], Ads::ADVERTISER_TAX );
		$this->assertSame( 1, (int) get_term( $term_id, Ads::ADVERTISER_TAX )->count );
		$this->assertEquals( 1, get_option( 'newspack_nl_advertiser_count_recounted_v3' ) );
	}

	/**
	 * A newsletter tagged with an advertiser must not inflate the count —
	 * only ads (Ads::CPT) should be counted.
	 */
	public function test_advertiser_count_excludes_newsletters() {
		$term = wp_insert_term( 'Newsletter-free Advertiser', Ads::ADVERTISER_TAX );
		$this->assertIsArray( $term );
		$term_id = (int) $term['term_id'];

		$ad = self::factory()->post->create(
			[
				'post_type'   => Ads::CPT,
				'post_status' => 'publish',
			]
		);
		wp_set_object_terms( $ad, [ $term_id ], Ads::ADVERTISER_TAX );

		$newsletter = self::factory()->post->create(
			[
				'post_type'   => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status' => 'publish',
			]
		);
		wp_set_object_terms( $newsletter, [ $term_id ], Ads::ADVERTISER_TAX );

		clean_term_cache( [ $term_id ], Ads::ADVERTISER_TAX );
		$fresh = get_term( $term_id, Ads::ADVERTISER_TAX );
		$this->assertSame( 1, (int) $fresh->count );
	}
}
