<?php
/**
 * Class Newsletters Test Subscription_List
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Subscription_List;
use Newspack\Newsletters\Subscription_Lists;

/**
 * Tests the Subscription_List class
 */
trait Lists_Setup {

	/**
	 * Testing posts
	 *
	 * @var array
	 */
	public static $posts;

	/**
	 * Testing a post that could conlfict with the ID of a list
	 *
	 * @var int
	 */
	public static $conflicting_post_id;

	/**
	 * Sets up testing data
	 *
	 * @return void
	 */
	public static function set_up_before_class() {

		$without_settings = self::create_post( 1 );

		$only_mailchimp = self::create_post( 2 );
		update_post_meta(
			$only_mailchimp,
			Subscription_List::META_KEY,
			[
				'mailchimp' => [
					'list'     => 'mc_list',
					'tag_id'   => 12,
					'tag_name' => 'MC Tag',
				],
			]
		);

		$two_settings = self::create_post( 3 );
		update_post_meta(
			$two_settings,
			Subscription_List::META_KEY,
			[
				'mailchimp'       => [
					'list'     => 'mc_list',
					'tag_id'   => 12,
					'tag_name' => 'MC Tag',
				],
				'active_campaign' => [
					'list'     => 'ac_list',
					'tag_id'   => 13,
					'tag_name' => 'AC Tag',
				],
			]
		);

		$mc_invalid = self::create_post( 4 );
		update_post_meta(
			$mc_invalid,
			Subscription_List::META_KEY,
			[
				'mailchimp'       => [
					'error' => 'Error',
				],
				'active_campaign' => [
					'list'     => 'ac_list',
					'tag_id'   => 13,
					'tag_name' => 'AC Tag',
				],
			]
		);

		$remote_mailchimp      = self::create_post( 5 );
		$remote_mailchimp_list = new Subscription_List( $remote_mailchimp );
		$remote_mailchimp_list->set_remote_id( 'xyz-' . $remote_mailchimp );
		$remote_mailchimp_list->set_type( 'remote' );
		$remote_mailchimp_list->set_provider( 'mailchimp' );

		$remote_mailchimp_inactive      = self::create_post( 6 );
		$remote_mailchimp_inactive_list = new Subscription_List( $remote_mailchimp_inactive );
		$remote_mailchimp_inactive_list->set_remote_id( 'xyz-' . $remote_mailchimp_inactive );
		$remote_mailchimp_inactive_list->set_type( 'remote' );
		$remote_mailchimp_inactive_list->set_provider( 'mailchimp' );
		$remote_mailchimp_inactive_list->update( [ 'active' => false ] );

		/**
		 * The list and post below make sure that the remote list ID is not confused with the post ID when it is an integer and matches with an existing post.
		 */
		self::$conflicting_post_id   = wp_insert_post(
			[
				'post_title'  => 'Simple Post',
				'post_type'   => 'post',
				'post_status' => 'publish',
			]
		);
		$remote_active_campaign      = self::create_post( 7 );
		$remote_active_campaign_list = new Subscription_List( $remote_active_campaign );
		$remote_active_campaign_list->set_remote_id( self::$conflicting_post_id ); // Active campaign has integer IDs, lets make sure they dont get messed up with post IDs.
		$remote_active_campaign_list->set_type( 'remote' );
		$remote_active_campaign_list->set_provider( 'active_campaign' );

		self::$posts = compact( 'without_settings', 'only_mailchimp', 'two_settings', 'mc_invalid', 'remote_mailchimp', 'remote_mailchimp_inactive', 'remote_active_campaign' );
	}

	/**
	 * Tear down class
	 */
	public static function tear_down_after_class() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->posts WHERE post_type = %s" , Subscription_Lists::CPT ) ); // phpcs:ignore
	}

	/**
	 * Create a test post
	 *
	 * @param string|int $index An index to identify the post title and description.
	 * @return int
	 */
	public static function create_post( $index ) {
		$data = [
			'post_title'   => 'Test List ' . $index,
			'post_content' => 'Description ' . $index,
			'post_type'    => Subscription_Lists::CPT,
			'post_status'  => 'publish',
		];
		return wp_insert_post( $data );
	}
}
