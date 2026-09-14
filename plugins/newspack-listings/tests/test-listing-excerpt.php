<?php
/**
 * Class Listing Excerpt Test
 *
 * @package Newspack_Listings
 */

use Newspack_Listings\Core;
use Newspack_Listings\Utils;

/**
 * Listing excerpt test case.
 */
class ListingExcerptTest extends WP_UnitTestCase {

	/**
	 * A protected listing keeps its body out of the card, in markup the card can
	 * carry.
	 *
	 * The excerpt stands in for the body of a listing with no hand-written excerpt,
	 * so the tag strip below it would otherwise publish the opening words of a post
	 * core meant to show nothing of. It is the one branch that returns markup of its
	 * own, and the card echoes the result through wp_kses_post() — which drops a
	 * password form, leaving a prompt with nothing to type into.
	 */
	public function test_a_password_protected_listing_shows_a_notice_not_its_body() {
		$listing_id = self::factory()->post->create(
			[
				'post_type'     => Core::NEWSPACK_LISTINGS_POST_TYPES['place'],
				'post_status'   => 'publish',
				'post_password' => 'letmein',
				'post_excerpt'  => '',
				'post_content'  => 'Opening words of a protected listing.',
			]
		);

		$excerpt = Utils\get_listing_excerpt( get_post( $listing_id ) );

		$this->assertStringNotContainsString( 'Opening words', $excerpt, 'Not even the opening words of a protected listing are published.' );
		$this->assertStringContainsString( 'password-protected', $excerpt, 'The card says why it is showing nothing.' );
		$this->assertSame( $excerpt, wp_kses_post( $excerpt ), 'The card renders what this returns: markup wp_kses_post strips never reaches the reader.' );
	}
}
