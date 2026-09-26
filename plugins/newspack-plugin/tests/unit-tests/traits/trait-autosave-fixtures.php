<?php
/**
 * Fixture helpers for autosave cleanup tests.
 *
 * @package Newspack\Tests
 */

/**
 * Creates posts, autosaves and revisions with controlled dates.
 */
trait Autosave_Fixtures {

	/**
	 * GMT datetime a number of days in the past.
	 *
	 * @param int $days Days ago.
	 * @return string
	 */
	protected function days_ago( $days ) {
		return gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
	}

	/**
	 * Overwrite date columns directly, since WordPress sets them to now on insert.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $fields  Columns to set.
	 */
	protected function set_dates( $post_id, $fields ) {
		global $wpdb;
		$wpdb->update( $wpdb->posts, $fields, [ 'ID' => $post_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		clean_post_cache( $post_id );
	}

	/**
	 * Create a post last saved $days ago, with no revisions.
	 *
	 * @param int $days Days since the post was last saved.
	 * @return int Post ID.
	 */
	protected function create_post( $days ) {
		$post_id = self::factory()->post->create();
		foreach ( wp_get_post_revisions( $post_id ) as $revision ) {
			wp_delete_post_revision( $revision );
		}
		$date = $this->days_ago( $days );
		$this->set_dates(
			$post_id,
			[
				'post_modified_gmt' => $date,
				'post_modified'     => $date,
			]
		);
		return $post_id;
	}

	/**
	 * Create an autosave or regular revision saved $days ago.
	 *
	 * @param int  $post_id  Parent post ID.
	 * @param int  $days     Days since it was saved.
	 * @param bool $autosave Whether to create an autosave.
	 * @return int Revision ID.
	 */
	protected function create_revision( $post_id, $days, $autosave = false ) {
		$revision_id = _wp_put_post_revision( get_post( $post_id ), $autosave );
		$date        = $this->days_ago( $days );
		$this->set_dates(
			$revision_id,
			[
				'post_date_gmt'     => $date,
				'post_date'         => $date,
				'post_modified_gmt' => $date,
				'post_modified'     => $date,
			]
		);
		return $revision_id;
	}

	/**
	 * Create a post whose autosave has been stale for 20 days.
	 *
	 * @return array [ post ID, autosave ID ].
	 */
	protected function create_eligible_autosave() {
		$post_id     = $this->create_post( 20 );
		$autosave_id = $this->create_revision( $post_id, 30, true );
		$this->create_revision( $post_id, 20 );
		return [ $post_id, $autosave_id ];
	}
}
