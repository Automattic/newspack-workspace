<?php
/**
 * Test Newsletter Ads.
 *
 * @package Newspack_Newsletters
 */

use Newspack_Newsletters\Ads;
use Newspack_Newsletters\Ads_Placements;

/**
 * Newsletters Ads Test.
 */
class Newsletters_Newsletter_Ads_Test extends WP_UnitTestCase {
	/**
	 * Ad ID for testing.
	 *
	 * @var int
	 */
	private static $ad_id = 0;

	/**
	 * Test creating ad.
	 */
	public function test_creating_ad() {
		self::$ad_id = self::factory()->post->create(
			[
				'post_type'    => Newspack_Newsletters\Ads::CPT,
				'post_title'   => 'A sample ad',
				'post_content' => '<!-- wp:paragraph -->\n<p>Ad content.<\/p>\n<!-- \/wp:paragraph -->',
			]
		);
		$this->assertNotEquals( 0, self::$ad_id );
	}

	/**
	 * Test active ad.
	 */
	public function test_is_active_ad() {
		$this->assertTrue( Newspack_Newsletters\Ads::is_ad_active( self::$ad_id ) );

		// Set start date to tomorrow.
		update_post_meta( self::$ad_id, 'start_date', gmdate( 'Y-m-d', strtotime( '+1 day' ) ) );
		$this->assertFalse( Newspack_Newsletters\Ads::is_ad_active( self::$ad_id ) );

		// Set start date to yesterday.
		update_post_meta( self::$ad_id, 'start_date', gmdate( 'Y-m-d', strtotime( '-1 day' ) ) );
		$this->assertTrue( Newspack_Newsletters\Ads::is_ad_active( self::$ad_id ) );

		// Set expiry date to yesterday.
		update_post_meta( self::$ad_id, 'expiry_date', gmdate( 'Y-m-d', strtotime( '-1 day' ) ) );
		$this->assertFalse( Newspack_Newsletters\Ads::is_ad_active( self::$ad_id ) );

		// Set expiry date to tomorrow.
		update_post_meta( self::$ad_id, 'expiry_date', gmdate( 'Y-m-d', strtotime( '+1 day' ) ) );
		$this->assertTrue( Newspack_Newsletters\Ads::is_ad_active( self::$ad_id ) );
	}

	/**
	 * Test ad placement.
	 */
	public function test_ad_placement() {
		$placement_id = self::factory()->term->create(
			[
				'taxonomy' => Ads_Placements::TAXONOMY,
				'name'     => 'Test Placement',
			]
		);
		$this->assertNotEmpty( $placement_id );

		$ad_id = self::factory()->post->create(
			[
				'post_type'  => Newspack_Newsletters\Ads::CPT,
				'post_title' => 'A sample ad',
			]
		);
		update_post_meta( $ad_id, 'insertion_strategy', 'placement' );
		wp_set_post_terms( $ad_id, [ $placement_id ], Ads_Placements::TAXONOMY );
		$ad = Ads_Placements::get_ad_by_placement( $placement_id );
		$this->assertNotEmpty( $ad );
		$this->assertEquals(
			$ad_id,
			$ad->ID,
			'Ad should fill for placement.'
		);

		$newsletter_id = self::factory()->post->create(
			[
				'post_type'  => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_title' => 'A sample newsletter',
			]
		);
		$ad = Ads_Placements::get_ad_by_placement( $placement_id, $newsletter_id );
		$this->assertNotEmpty( $ad );
		$this->assertEquals(
			$ad_id,
			$ad->ID,
			'Ad should fill for placement with newsletter.'
		);
	}

	/**
	 * Test ad placement by category.
	 */
	public function test_ad_placement_by_category() {
		$newsletter_id = self::factory()->post->create(
			[
				'post_type'  => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_title' => 'A sample newsletter',
			]
		);

		$category_id = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Test Category',
			]
		);

		$ad_id = self::factory()->post->create(
			[
				'post_type'  => Newspack_Newsletters\Ads::CPT,
				'post_title' => 'A sample ad',
			]
		);

		$placement_id = self::factory()->term->create(
			[
				'taxonomy' => 'newspack_nl_ad_placement',
				'name'     => 'Test Placement',
			]
		);
		update_post_meta( $ad_id, 'insertion_strategy', 'placement' );
		wp_set_post_terms( $ad_id, [ $placement_id ], Ads_Placements::TAXONOMY );

		// Add the category to the ad.
		wp_set_post_terms( $ad_id, [ $category_id ], 'category' );
		$ad = Ads_Placements::get_ad_by_placement( $placement_id, $newsletter_id );
		$this->assertEmpty(
			$ad,
			'Ad should not fill for newsletter without the category.'
		);

		// Add the category to the newsletter.
		wp_set_post_terms( $newsletter_id, [ $category_id ], 'category' );
		$ad = Ads_Placements::get_ad_by_placement( $placement_id, $newsletter_id );
		$this->assertNotEmpty( $ad );
		$this->assertEquals(
			$ad_id,
			$ad->ID,
			'Ad should fill for newsletter with the category.'
		);
	}

	/**
	 * Test ad placement by advertiser.
	 */
	public function test_ad_placement_by_advertiser() {
		$newsletter_id = self::factory()->post->create(
			[
				'post_type'  => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_title' => 'A sample newsletter',
			]
		);

		$ad_id = self::factory()->post->create(
			[
				'post_type'  => Newspack_Newsletters\Ads::CPT,
				'post_title' => 'A sample ad',
			]
		);

		$advertiser_id = self::factory()->term->create(
			[
				'taxonomy' => Ads::ADVERTISER_TAX,
				'name'     => 'Test Advertiser',
			]
		);

		$placement_id = self::factory()->term->create(
			[
				'taxonomy' => Ads_Placements::TAXONOMY,
				'name'     => 'Test Placement',
			]
		);
		update_post_meta( $ad_id, 'insertion_strategy', 'placement' );
		wp_set_post_terms( $ad_id, [ $placement_id ], Ads_Placements::TAXONOMY );

		// Add advertiser to newsletter.
		wp_set_post_terms( $newsletter_id, [ $advertiser_id ], Ads::ADVERTISER_TAX );
		$ad = Ads_Placements::get_ad_by_placement( $placement_id, $newsletter_id );
		$this->assertEmpty(
			$ad,
			'Ad should not fill for newsletter with an advertiser that doesnt match the ad.'
		);

		// Add advertiser to ad.
		wp_set_post_terms( $ad_id, [ $advertiser_id ], Ads::ADVERTISER_TAX );
		$ad = Ads_Placements::get_ad_by_placement( $placement_id, $newsletter_id );
		$this->assertNotEmpty( $ad );
		$this->assertEquals(
			$ad_id,
			$ad->ID,
			'Ad should fill for newsletter with matching advertiser.'
		);
	}
}
