<?php
/**
 * Test Ad_Stats — dated per-ad impression/click rows.
 *
 * @package Newspack_Newsletters
 */

use Newspack_Newsletters\Tracking\Ad_Stats;
use Newspack_Newsletters\Tracking\Click;
use Newspack_Newsletters\Ads;

/**
 * Ad_Stats test.
 */
class Ad_Stats_Test extends WP_UnitTestCase {
	/**
	 * Reset the table between tests.
	 */
	public function set_up() {
		parent::set_up();
		global $wpdb;
		// Guarantee a clean, current-schema table for each test regardless of any
		// persisted version option in the test database.
		$table = Ad_Stats::get_table_name();
		$wpdb->query( "DROP TABLE IF EXISTS $table" ); // phpcs:ignore
		Ad_Stats::create_custom_table();
	}

	/**
	 * Fetch a single stats row.
	 *
	 * @param int $ad_id         Ad post ID.
	 * @param int $newsletter_id Newsletter post ID.
	 *
	 * @return object|null
	 */
	private function get_row( $ad_id, $newsletter_id ) {
		global $wpdb;
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM %i WHERE ad_id = %d AND newsletter_id = %d AND stat_date = %s',
				Ad_Stats::get_table_name(),
				$ad_id,
				$newsletter_id,
				current_time( 'Y-m-d', true )
			)
		);
	}

	/**
	 * The custom table version option is set.
	 */
	public function test_db_version() {
		self::assertEquals( Ad_Stats::TABLE_VERSION, get_option( Ad_Stats::TABLE_VERSION_OPTION ) );
	}

	/**
	 * Repeated impression records aggregate into a single daily row.
	 */
	public function test_record_impressions_aggregates_by_day() {
		Ad_Stats::record_impressions( 111, 222, 1 );
		Ad_Stats::record_impressions( 111, 222, 4 );

		$row = $this->get_row( 111, 222 );
		self::assertNotNull( $row );
		self::assertEquals( 5, (int) $row->impressions );
		self::assertEquals( 0, (int) $row->clicks );
	}

	/**
	 * Repeated click records aggregate under the unknown-newsletter sentinel.
	 */
	public function test_record_clicks_aggregates_under_sentinel() {
		Ad_Stats::record_clicks( 111 );
		Ad_Stats::record_clicks( 111 );

		$row = $this->get_row( 111, Ad_Stats::UNKNOWN_NEWSLETTER_ID );
		self::assertNotNull( $row );
		self::assertEquals( 2, (int) $row->clicks );
		self::assertEquals( 0, (int) $row->impressions );
	}

	/**
	 * A click with a known newsletter shares that newsletter's row, so
	 * impressions and clicks coexist in one (ad_id, newsletter_id, day) row.
	 */
	public function test_click_with_newsletter_shares_impression_row() {
		Ad_Stats::record_impressions( 111, 222, 3 );
		Ad_Stats::record_clicks( 111, 222 );

		$row = $this->get_row( 111, 222 );
		self::assertNotNull( $row );
		self::assertEquals( 3, (int) $row->impressions );
		self::assertEquals( 1, (int) $row->clicks );

		// No stray sentinel row was created.
		self::assertNull( $this->get_row( 111, Ad_Stats::UNKNOWN_NEWSLETTER_ID ) );
	}

	/**
	 * A click with an unknown newsletter falls back to the sentinel row and
	 * stays separate from impressions.
	 */
	public function test_click_without_newsletter_uses_sentinel() {
		Ad_Stats::record_impressions( 111, 222, 3 );
		Ad_Stats::record_clicks( 111 );

		$impression_row = $this->get_row( 111, 222 );
		$click_row      = $this->get_row( 111, Ad_Stats::UNKNOWN_NEWSLETTER_ID );

		self::assertEquals( 3, (int) $impression_row->impressions );
		self::assertEquals( 0, (int) $impression_row->clicks );
		self::assertEquals( 1, (int) $click_row->clicks );
		self::assertEquals( 0, (int) $click_row->impressions );
	}

	/**
	 * Invalid input is ignored (no row written).
	 */
	public function test_invalid_input_is_ignored() {
		Ad_Stats::record_impressions( 0, 222, 1 );  // No ad ID.
		Ad_Stats::record_impressions( 111, 222, 0 ); // Zero count.
		Ad_Stats::record_impressions( 111, 0, 1 );   // No newsletter — would collide with the click sentinel.

		global $wpdb;
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Ad_Stats::get_table_name() ); // phpcs:ignore
		self::assertEquals( 0, $count );
	}

	/**
	 * Ads::track_ad_impression writes a dated row alongside the lifetime counter.
	 */
	public function test_track_ad_impression_writes_dated_row() {
		$ad_id         = $this->factory->post->create( [ 'post_type' => Ads::CPT ] );
		$newsletter_id = $this->factory->post->create( [ 'post_type' => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT ] );
		update_post_meta( $newsletter_id, 'inserted_ads', [ $ad_id ] );

		Ads::track_ad_impression( $newsletter_id, 'fake@email.com' );
		Ads::bulk_track_ad_impression( $newsletter_id, 5 );

		$row = $this->get_row( $ad_id, $newsletter_id );
		self::assertNotNull( $row );
		self::assertEquals( 6, (int) $row->impressions );
	}

	/**
	 * A tracked ad-link click writes a dated click row keyed by the ad ID.
	 */
	public function test_handle_click_writes_dated_row() {
		$content = "<!-- wp:paragraph -->\n<p><a href=\"https://google.com\">Link</a></p>\n<!-- /wp:paragraph -->";
		$ad_id   = $this->factory->post->create(
			[
				'post_type'    => Ads::CPT,
				'post_content' => $content,
			]
		);
		update_post_meta( $ad_id, 'newspack_email_html', $content );

		$_GET['np_newsletters_click'] = 1;
		$_GET['id']                   = $ad_id;
		$_GET['nid']                  = 222;
		$_GET['em']                   = 'fake@email.com';
		$_GET['url']                  = 'https://google.com';
		Click::handle_click( false );

		// The click is attributed to the source newsletter carried by `nid`.
		$row = $this->get_row( $ad_id, 222 );
		self::assertNotNull( $row );
		self::assertEquals( 1, (int) $row->clicks );

		unset( $_GET['np_newsletters_click'], $_GET['id'], $_GET['nid'], $_GET['em'], $_GET['url'] );
	}

	/**
	 * A proxied ad link carries the current newsletter as `nid`.
	 */
	public function test_process_link_carries_newsletter_id() {
		$ad_id = $this->factory->post->create( [ 'post_type' => Ads::CPT ] );
		$ad    = get_post( $ad_id );

		\Newspack_Newsletters_Renderer::$newsletter_id = 222;
		$proxied = Click::process_link( 'https://google.com', 'https://google.com', $ad );
		\Newspack_Newsletters_Renderer::$newsletter_id = null;

		$args = [];
		wp_parse_str( (string) wp_parse_url( $proxied, PHP_URL_QUERY ), $args );
		self::assertEquals( $ad_id, (int) $args['id'] );
		self::assertEquals( 222, (int) $args['nid'] );
	}

	/**
	 * Cleanup deletes rows older than the retention window.
	 */
	public function test_cleanup_removes_old_rows() {
		global $wpdb;
		$table = Ad_Stats::get_table_name();

		// A fresh row and a row well past the retention window.
		Ad_Stats::record_impressions( 111, 222, 1 );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			[
				'ad_id'         => 999,
				'newsletter_id' => 888,
				'stat_date'     => gmdate( 'Y-m-d', strtotime( '-' . ( Ad_Stats::RETENTION_MONTHS + 1 ) . ' months' ) ),
				'impressions'   => 1,
			],
			[ '%d', '%d', '%s', '%d' ]
		);

		self::assertEquals( 2, (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) ); // phpcs:ignore

		$deleted = Ad_Stats::cleanup();

		self::assertEquals( 1, $deleted );
		self::assertEquals( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) ); // phpcs:ignore
		self::assertNotNull( $this->get_row( 111, 222 ) );
	}

	/**
	 * A backlog larger than one delete batch is fully drained in a single run
	 * (the loop keeps going until a batch clears fewer than CLEANUP_BATCH rows).
	 */
	public function test_cleanup_drains_multiple_batches() {
		global $wpdb;
		$table  = Ad_Stats::get_table_name();
		$old    = gmdate( 'Y-m-d', strtotime( '-' . ( Ad_Stats::RETENTION_MONTHS + 1 ) . ' months' ) );
		$total  = Ad_Stats::CLEANUP_BATCH + 5; // Just over one batch → forces a 2nd iteration.

		// Bulk-insert expired rows with distinct (ad_id) so each is its own PK row.
		$values = [];
		for ( $i = 1; $i <= $total; $i++ ) {
			$values[] = $wpdb->prepare( '(%d, %d, %s, %d, %d)', $i, 0, $old, 1, 0 );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "INSERT INTO $table (ad_id, newsletter_id, stat_date, impressions, clicks) VALUES " . implode( ',', $values ) );
		self::assertEquals( $total, (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) ); // phpcs:ignore

		$deleted = Ad_Stats::cleanup();

		self::assertEquals( $total, $deleted );
		self::assertEquals( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) ); // phpcs:ignore
	}
}
