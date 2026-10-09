<?php
/**
 * Tests for the site-wide comment restriction (NPPD-2327).
 *
 * @package Newspack
 */

use Newspack\Comment_Restriction;
use Newspack\Content_Gate;
use Newspack\Content_Gate_Advanced_Settings;
use Newspack\Group_Subscription;

/**
 * A publisher can make commenting a subscriber benefit on every post, not only on
 * the posts a gate restricts, by naming a gate in the Access Control advanced
 * settings. Readers who pass that gate comment; everyone else sees the subscribe
 * message, and a direct submit from them is refused. With the setting unset,
 * nothing changes.
 *
 * @group Content_Gate_Comment_Restriction
 */
class Test_Comment_Restriction extends WP_UnitTestCase {

	/**
	 * Product the comment gate's subscription rule names.
	 *
	 * @var int
	 */
	private const PRODUCT_ID = 50;

	/**
	 * Gate the restriction names.
	 *
	 * @var int
	 */
	private $gate_id;

	/**
	 * A post no gate restricts.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * Load the WooCommerce mocks the subscription rule reads.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Setup.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database, $products_database;
		$subscriptions_database = [];
		$products_database      = [];
		add_filter( 'newspack_reader_activation_enabled', '__return_true' );
		// Each test submits several comments within a second.
		remove_filter( 'check_comment_flood', 'check_comment_flood_db' );
		// Answers the display-name prompt the readers below get, as the form would.
		$_POST['comment_display_name'] = 'Test Reader';

		// The gate restricts pages only, so the post below is one no gate restricts.
		$this->gate_id = Content_Gate::create_gate( [ 'title' => 'Subscribers' ] );
		Content_Gate::update_gate_settings(
			$this->gate_id,
			[
				'status'        => 'publish',
				'content_rules' => [
					[
						'slug'  => 'post_types',
						'value' => [ 'page' ],
					],
				],
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => 'subscription',
								'value' => [ self::PRODUCT_ID ],
							],
						],
					],
				],
			]
		);
		Content_Gate::flush_gates_cache();
		$this->post_id = self::factory()->post->create( [ 'comment_status' => 'open' ] );
		Comment_Restriction::reset_cache();
	}

	/**
	 * Teardown.
	 */
	public function tear_down() {
		Content_Gate_Advanced_Settings::update_settings(
			[
				'comment_restriction_gate_id'      => 0,
				'comment_restriction_message'      => '',
				'comment_restriction_purchase_url' => '',
			]
		);
		remove_filter( 'newspack_reader_activation_enabled', '__return_true' );
		unset( $_POST['comment_display_name'] );
		wp_delete_post( $this->gate_id, true );
		Content_Gate::flush_gates_cache();
		Comment_Restriction::reset_cache();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Configure the restriction as the Advanced Settings modal saves it.
	 *
	 * @param int|null $gate_id Gate to name; defaults to the fixture gate.
	 */
	private function restrict_comments_to_gate( $gate_id = null ) {
		Content_Gate_Advanced_Settings::update_settings(
			[
				'comment_restriction_gate_id'      => $gate_id ?? $this->gate_id,
				'comment_restriction_message'      => 'Only subscribers may comment.',
				'comment_restriction_purchase_url' => 'https://example.test/subscribe/',
			]
		);
		Comment_Restriction::reset_cache();
	}

	/**
	 * Create a reader with an email-derived display name, the kind Reader
	 * Activation prompts to replace with a field of its own in the comment form.
	 *
	 * @return int User ID.
	 */
	private function create_reader() {
		$local_part = 'reader' . wp_generate_password( 6, false, false );
		$user_id    = self::factory()->user->create(
			[
				'role'         => 'subscriber',
				'user_email'   => $local_part . '@example.test',
				'display_name' => $local_part,
			]
		);
		update_user_meta( $user_id, 'np_reader', true );
		return $user_id;
	}

	/**
	 * Create a reader who owns an active subscription to the gate's product.
	 *
	 * @return array { owner: int, subscription: WC_Subscription }
	 */
	private function create_subscriber() {
		$owner_id     = $this->create_reader();
		$subscription = wcs_create_subscription(
			[
				'customer_id' => $owner_id,
				'status'      => 'active',
				'products'    => [ self::PRODUCT_ID ],
			]
		);
		return [
			'owner'        => $owner_id,
			'subscription' => $subscription,
		];
	}

	/**
	 * Create a reader holding a seat in a group subscription to the gate's product.
	 *
	 * @return int User ID.
	 */
	private function create_group_member() {
		$subscription = $this->create_subscriber()['subscription'];
		$subscription->update_meta_data( '_newspack_group_subscription_enabled', 'yes' );
		$subscription->update_meta_data( '_newspack_group_subscription_limit', 10 );
		$member_id = $this->create_reader();
		add_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $subscription->get_id() );
		return $member_id;
	}

	/**
	 * Render the post's comment form for a user.
	 *
	 * @param int $user_id User to render as.
	 *
	 * @return string Form markup.
	 */
	private function render_comment_form_as( $user_id ) {
		wp_set_current_user( $user_id );
		Comment_Restriction::reset_cache();
		ob_start();
		comment_form( [], $this->post_id );
		return ob_get_clean();
	}

	/**
	 * Submit a comment through wp-comments-post.php's handler.
	 *
	 * @param int $user_id User to submit as.
	 *
	 * @return WP_Comment|WP_Error
	 */
	private function submit_comment_as( $user_id ) {
		wp_set_current_user( $user_id );
		Comment_Restriction::reset_cache();
		return wp_handle_comment_submission(
			[
				'comment_post_ID' => $this->post_id,
				'comment'         => 'A comment ' . wp_generate_password( 8, false ),
			]
		);
	}

	/**
	 * Create a comment through the REST API.
	 *
	 * @param int $user_id User to submit as.
	 *
	 * @return int Response status.
	 */
	private function rest_create_comment_as( $user_id ) {
		wp_set_current_user( $user_id );
		Comment_Restriction::reset_cache();
		$request = new WP_REST_Request( 'POST', '/wp/v2/comments' );
		$request->set_param( 'post', $this->post_id );
		$request->set_param( 'content', 'A comment ' . wp_generate_password( 8, false ) );
		return rest_get_server()->dispatch( $request )->get_status();
	}

	/**
	 * With the setting unset, a reader with no subscription keeps the form and can
	 * submit, the same as before this rule existed.
	 */
	public function test_unconfigured_leaves_commenting_unchanged() {
		$reader_id = $this->create_reader();

		$this->assertStringContainsString( '<textarea', $this->render_comment_form_as( $reader_id ) );
		$this->assertInstanceOf( WP_Comment::class, $this->submit_comment_as( $reader_id ) );
		$this->assertSame( 201, $this->rest_create_comment_as( $reader_id ) );
	}

	/**
	 * Subscribers and group seat-holders pass the gate and comment; a reader with
	 * no subscription, and a logged-out visitor, do not. Moderators always do.
	 */
	public function test_only_readers_who_pass_the_gate_may_comment() {
		$this->restrict_comments_to_gate();
		$reader_id    = $this->create_reader();
		$owner_id     = $this->create_subscriber()['owner'];
		$member_id    = $this->create_group_member();
		$moderator_id = self::factory()->user->create( [ 'role' => 'editor' ] );

		$this->assertTrue( Comment_Restriction::is_restricted_for_user( $reader_id ), 'No subscription.' );
		$this->assertTrue( Comment_Restriction::is_restricted_for_user( 0 ), 'Logged out.' );
		$this->assertFalse( Comment_Restriction::is_restricted_for_user( $owner_id ), 'Subscription owner.' );
		$this->assertFalse( Comment_Restriction::is_restricted_for_user( $member_id ), 'Group seat-holder.' );
		$this->assertFalse( Comment_Restriction::is_restricted_for_user( $moderator_id ), 'Moderator.' );
	}

	/**
	 * A refused reader sees the configured message and purchase link where the
	 * form fields were; a subscriber sees the form.
	 */
	public function test_comment_form_shows_the_message_to_a_refused_reader() {
		$this->restrict_comments_to_gate();

		$refused_form = $this->render_comment_form_as( $this->create_reader() );
		$this->assertStringNotContainsString( '<textarea', $refused_form );
		$this->assertStringNotContainsString( 'type="submit"', $refused_form );
		$this->assertStringNotContainsString( '<input', $refused_form, 'No field survives, including the display-name prompt.' );
		$this->assertStringNotContainsString( 'Logged in as', $refused_form, 'No line introduces the missing fields.' );
		$this->assertStringContainsString( 'Only subscribers may comment.', $refused_form );
		$this->assertStringContainsString( 'href="https://example.test/subscribe/"', $refused_form );

		$this->assertStringContainsString( '<textarea', $this->render_comment_form_as( $this->create_subscriber()['owner'] ) );
	}

	/**
	 * Hiding the form is not enough: a refused reader posting straight to
	 * wp-comments-post.php or the REST API is turned away, and a subscriber is not.
	 */
	public function test_direct_submits_are_refused_server_side() {
		$this->restrict_comments_to_gate();
		$reader_id = $this->create_reader();
		$owner_id  = $this->create_subscriber()['owner'];

		$refused = $this->submit_comment_as( $reader_id );
		$this->assertWPError( $refused );
		$this->assertSame( 'newspack_comment_restricted', $refused->get_error_code() );
		$this->assertSame( 403, $this->rest_create_comment_as( $reader_id ) );

		$this->assertInstanceOf( WP_Comment::class, $this->submit_comment_as( $owner_id ) );
		$this->assertSame( 201, $this->rest_create_comment_as( $owner_id ) );
	}

	/**
	 * A comment created on a reader's behalf is judged as its author, not as
	 * whoever is logged in, unless that is staff posting it.
	 */
	public function test_a_submission_is_judged_by_its_author() {
		$this->restrict_comments_to_gate();
		$reader_id    = $this->create_reader();
		$owner_id     = $this->create_subscriber()['owner'];
		$moderator_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		$new_comment  = function ( $author_id, $actor_id ) {
			wp_set_current_user( $actor_id );
			Comment_Restriction::reset_cache();
			return wp_new_comment(
				[
					'comment_post_ID'      => $this->post_id,
					'comment_content'      => 'A comment ' . wp_generate_password( 8, false ),
					'comment_author'       => 'Reader',
					'comment_author_email' => 'reader@example.test',
					'comment_author_url'   => '',
					'user_id'              => $author_id,
				],
				true
			);
		};

		$this->assertWPError( $new_comment( $reader_id, 0 ), 'Refused reader, posted by an anonymous request.' );
		$this->assertIsInt( $new_comment( $owner_id, 0 ), 'Subscriber, posted by an anonymous request.' );
		$this->assertIsInt( $new_comment( $reader_id, $moderator_id ), 'Refused reader, posted by staff.' );
	}

	/**
	 * A setting naming a gate that is trashed or deleted refuses every reader,
	 * even one the gate's rules would admit, rather than reopening commenting
	 * to all of them.
	 */
	public function test_an_unpublished_or_missing_gate_refuses_readers() {
		$owner_id = $this->create_subscriber()['owner'];
		$this->restrict_comments_to_gate();
		$this->assertFalse( Comment_Restriction::is_restricted_for_user( $owner_id ), 'Premise: the published gate admits the subscriber.' );

		wp_trash_post( $this->gate_id );
		Comment_Restriction::reset_cache();
		$this->assertTrue( Comment_Restriction::is_restricted_for_user( $owner_id ), 'Trashed gate.' );

		$this->restrict_comments_to_gate( PHP_INT_MAX );
		$this->assertTrue( Comment_Restriction::is_restricted_for_user( $owner_id ), 'Deleted gate.' );
	}

	/**
	 * While Access Control is not the gating engine (here, Reader Activation is
	 * off), a saved setting restricts nobody.
	 */
	public function test_stands_down_while_gating_is_inactive() {
		$this->restrict_comments_to_gate();
		remove_filter( 'newspack_reader_activation_enabled', '__return_true' );
		add_filter( 'newspack_reader_activation_enabled', '__return_false' );
		Comment_Restriction::reset_cache();

		$this->assertFalse( Comment_Restriction::is_restricted_for_user( $this->create_reader() ) );
	}

	/**
	 * Only reader comments are restricted. A product review from the same reader,
	 * and the review form on a product page, are left alone.
	 */
	public function test_product_reviews_are_not_restricted() {
		$this->restrict_comments_to_gate();
		$reader_id  = $this->create_reader();
		$product_id = self::factory()->post->create(
			[
				'post_type'      => 'product',
				'comment_status' => 'open',
			]
		);
		wp_set_current_user( $reader_id );
		Comment_Restriction::reset_cache();
		// WooCommerce types a product's comments as reviews the same way (WC_Comments::update_comment_type()).
		add_filter(
			'preprocess_comment',
			function ( $comment_data ) use ( $product_id ) {
				if ( (int) $comment_data['comment_post_ID'] === $product_id ) {
					$comment_data['comment_type'] = 'review';
				}
				return $comment_data;
			},
			1
		);

		$review = wp_handle_comment_submission(
			[
				'comment_post_ID' => $product_id,
				'comment'         => 'A review ' . wp_generate_password( 8, false ),
			]
		);
		$this->assertInstanceOf( WP_Comment::class, $review );

		// The form is rendered inside the product page's loop.
		$GLOBALS['post'] = get_post( $product_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		ob_start();
		comment_form( [], $product_id );
		$this->assertStringContainsString( '<textarea', ob_get_clean() );
	}
}
