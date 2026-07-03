<?php
/**
 * Class Products_Ui Test
 *
 * Covers the access-control checks that guard self-serve premium listing
 * creation and deletion (NPPM-2965).
 *
 * @package Newspack_Listings
 */

use Newspack_Listings\Core;
use Newspack_Listings\Products;
use Newspack_Listings\Products_Ui;

/**
 * Test the self-serve listings authorization helpers.
 */
class ProductsUiTest extends WP_UnitTestCase {
	/**
	 * Products_Ui instance under test.
	 *
	 * @var Products_Ui
	 */
	private $products_ui;

	/**
	 * Meta key marking a post as a self-serve premium listing.
	 *
	 * @var string
	 */
	private $subscription_meta_key;

	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::set_up();
		$this->products_ui           = new Products_Ui();
		$this->subscription_meta_key = Products::POST_META_KEYS['listing_subscription'];
	}

	/**
	 * Create a self-serve premium listing owned by a given user.
	 *
	 * @param int    $author_id       Author/owner of the listing.
	 * @param string $type            Listing type slug (event|marketplace).
	 * @param bool   $with_meta       Whether to attach the subscription meta.
	 * @param int    $subscription_id Subscription id to store in meta.
	 * @return int Listing post ID.
	 */
	private function create_premium_listing( $author_id, $type = 'event', $with_meta = true, $subscription_id = 1 ) {
		$listing_id = self::factory()->post->create(
			[
				'post_type'   => Core::NEWSPACK_LISTINGS_POST_TYPES[ $type ],
				'post_author' => $author_id,
				'post_status' => 'publish',
			]
		);
		if ( $with_meta ) {
			update_post_meta( $listing_id, $this->subscription_meta_key, $subscription_id );
		}
		return $listing_id;
	}

	/**
	 * The owner of a self-serve premium listing may delete it.
	 */
	public function test_owner_can_delete_own_premium_listing() {
		$owner_id   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$listing_id = $this->create_premium_listing( $owner_id );

		$this->assertTrue(
			$this->products_ui->user_can_delete_premium_listing( $listing_id, $owner_id )
		);
	}

	/**
	 * A user may not delete a premium listing owned by someone else.
	 */
	public function test_cannot_delete_another_users_premium_listing() {
		$owner_id    = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$attacker_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$listing_id  = $this->create_premium_listing( $owner_id );

		$this->assertFalse(
			$this->products_ui->user_can_delete_premium_listing( $listing_id, $attacker_id )
		);
	}

	/**
	 * A subscriber may not delete an arbitrary post (e.g. an admin's article).
	 */
	public function test_cannot_delete_arbitrary_non_listing_post() {
		$admin_id    = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$attacker_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$post_id     = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_author' => $admin_id,
				'post_status' => 'publish',
			]
		);

		$this->assertFalse(
			$this->products_ui->user_can_delete_premium_listing( $post_id, $attacker_id )
		);
	}

	/**
	 * Even if a user authors a non-listing post, this endpoint must not delete it.
	 */
	public function test_cannot_delete_own_non_listing_post() {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_author' => $user_id,
				'post_status' => 'publish',
			]
		);

		$this->assertFalse(
			$this->products_ui->user_can_delete_premium_listing( $post_id, $user_id )
		);
	}

	/**
	 * A listing CPT that is not a self-serve premium listing (no subscription
	 * meta) must not be deletable via this endpoint, even by its author.
	 */
	public function test_cannot_delete_listing_without_subscription_meta() {
		$user_id    = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$listing_id = $this->create_premium_listing( $user_id, 'event', false );

		$this->assertFalse(
			$this->products_ui->user_can_delete_premium_listing( $listing_id, $user_id )
		);
	}

	/**
	 * A logged-out request may not delete any listing.
	 */
	public function test_cannot_delete_when_logged_out() {
		$owner_id   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$listing_id = $this->create_premium_listing( $owner_id );

		wp_set_current_user( 0 );

		$this->assertFalse(
			$this->products_ui->user_can_delete_premium_listing( $listing_id )
		);
	}

	/**
	 * A non-existent post id must be rejected.
	 */
	public function test_cannot_delete_nonexistent_post() {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->assertFalse(
			$this->products_ui->user_can_delete_premium_listing( 999999, $user_id )
		);
	}

	/**
	 * When no user id is passed, the current user is used.
	 */
	public function test_defaults_to_current_user() {
		$owner_id   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$listing_id = $this->create_premium_listing( $owner_id );

		wp_set_current_user( $owner_id );

		$this->assertTrue(
			$this->products_ui->user_can_delete_premium_listing( $listing_id )
		);
	}

	/**
	 * A subscription with no listings yet has its full included allowance.
	 */
	public function test_remaining_included_listings_full_when_none_used() {
		$this->assertSame( 10, $this->products_ui->get_remaining_included_listings( 500 ) );
	}

	/**
	 * Each premium listing attributed to the subscription decrements the allowance.
	 */
	public function test_remaining_included_listings_decrements_with_usage() {
		$owner = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->create_premium_listing( $owner, 'event', true, 501 );
		}

		$this->assertSame( 7, $this->products_ui->get_remaining_included_listings( 501 ) );
	}

	/**
	 * The allowance bottoms out at zero once fully used (quota exhausted).
	 */
	public function test_remaining_included_listings_zero_when_exhausted() {
		$owner = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		for ( $i = 0; $i < 10; $i++ ) {
			$this->create_premium_listing( $owner, 'event', true, 502 );
		}

		$this->assertSame( 0, $this->products_ui->get_remaining_included_listings( 502 ) );
	}

	/**
	 * Listings belonging to other subscriptions do not count against the allowance.
	 */
	public function test_remaining_included_listings_ignores_other_subscriptions() {
		$owner = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->create_premium_listing( $owner, 'event', true, 601 );
		$this->create_premium_listing( $owner, 'event', true, 602 );

		$this->assertSame( 9, $this->products_ui->get_remaining_included_listings( 601 ) );
	}

	/**
	 * An invalid subscription id yields no allowance.
	 */
	public function test_remaining_included_listings_zero_for_invalid_subscription() {
		$this->assertSame( 0, $this->products_ui->get_remaining_included_listings( 0 ) );
	}

	/**
	 * Create authorization fails closed when WooCommerce Subscriptions is
	 * unavailable (as in this test harness) — no subscription can be verified.
	 */
	public function test_cannot_create_premium_listing_without_woocommerce() {
		$this->assertFalse( function_exists( 'wcs_get_subscription' ), 'WooCommerce Subscriptions should be absent in the listings test harness.' );
		$this->assertFalse( $this->products_ui->user_can_create_premium_listing( 500, 20 ) );
	}
}
