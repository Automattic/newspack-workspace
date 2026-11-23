<?php
/**
 * Tests for Group_Subscription_Bulk_Invite.
 *
 * @package Newspack\Tests
 */

use Newspack\Group_Subscription;
use Newspack\Group_Subscription_API;
use Newspack\Group_Subscription_Bulk_Invite;
use Newspack\Group_Subscription_Invite;
use Newspack\Group_Subscription_Settings;

/**
 * Bulk invitations: how a pasted list or a CSV becomes addresses, which of them
 * are skipped and why, and the seat guard that refuses a list too long to fit.
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Test_Group_Subscription_Bulk_Invite extends WP_UnitTestCase {

	/**
	 * Group owner user ID.
	 *
	 * @var int
	 */
	private $owner_id = 0;

	/**
	 * Include WC mocks.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 4 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Reset the subscription store and log in as the group owner.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database;
		$subscriptions_database = [];
		$this->owner_id         = self::factory()->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => 'owner@example.test',
			]
		);
		wp_set_current_user( $this->owner_id );
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	/**
	 * Reset state between tests.
	 */
	public function tear_down() {
		global $subscriptions_database;
		$subscriptions_database = [];
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * An active group owned by the owner user.
	 *
	 * @param int $limit The seat limit; 0 is unlimited.
	 * @return WC_Subscription
	 */
	private function create_group( int $limit = 0 ): WC_Subscription {
		$subscription = wcs_create_subscription(
			[
				'customer_id'    => $this->owner_id,
				'status'         => 'active',
				'billing_period' => 'month',
			]
		);
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		$subscription->save();
		if ( $limit ) {
			Group_Subscription_Settings::update_subscription_settings( $subscription, [ 'limit' => $limit ] );
		}
		return $subscription;
	}

	/**
	 * A reader account with the given address.
	 *
	 * @param string $email The address.
	 * @return int User ID.
	 */
	private function create_reader( string $email ): int {
		$user_id = self::factory()->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => $email,
			]
		);
		update_user_meta( $user_id, '_newspack_reader', true );
		return $user_id;
	}

	/**
	 * The addresses holding a pending invitation, lowercased and sorted.
	 *
	 * @param WC_Subscription $subscription The group.
	 * @return string[]
	 */
	private function pending_invite_emails( WC_Subscription $subscription ): array {
		$emails = array_map( 'strtolower', wp_list_pluck( Group_Subscription_Invite::get_invites( $subscription, false ), 'email' ) );
		sort( $emails );
		return array_values( $emails );
	}

	/**
	 * Commas, semicolons, spaces, tabs and newlines all separate addresses in pasted text.
	 * A CSV contributes its first column, with the header row and blank rows dropped and
	 * a quoted newline in a later column kept inside its row.
	 */
	public function test_parse_splits_pasted_text_and_reads_the_first_csv_column() {
		$pasted = "a@example.test; b@example.test\n\nc@example.test\td@example.test  e@example.test,,";
		$csv    = "\xEF\xBB\xBFEmail,First name\nf@example.test,Fran\n\n\"g@example.test\",\"Gale, Jr\nsecond line\"\n , \nh@example.test\n";

		$this->assertSame(
			[ 'a@example.test', 'b@example.test', 'c@example.test', 'd@example.test', 'e@example.test', 'f@example.test', 'g@example.test', 'h@example.test' ],
			Group_Subscription_Bulk_Invite::parse( $pasted, $csv )
		);
	}

	/**
	 * Recipients copied from a mail client, `Name <address>` or `"Last, First" <address>`,
	 * become their addresses, and a bare address next to one is never swallowed into the
	 * name. A word that is part of no address is reported, not dropped.
	 */
	public function test_parse_unwraps_named_addresses_without_losing_neighbours() {
		$this->assertSame(
			[ 'a@example.test', 'b@example.test', 'c@example.test', 'd@example.test', 'e@example.test', 'f@example.test' ],
			Group_Subscription_Bulk_Invite::parse( 'a@example.test b@example.test <c@example.test> Dee Example <d@example.test> "Example, Eve" <e@example.test>; Fay <f@example.test>' )
		);
		$this->assertSame( [ 'Doe', 'j@example.test' ], Group_Subscription_Bulk_Invite::parse( 'Doe, Jane <j@example.test>' ) );
	}

	/**
	 * Only a first row naming an email column is a header, so a headerless CSV keeps its
	 * first row even when that entry is invalid, and it is reported rather than lost.
	 * A semicolon-delimited export, as spreadsheet apps write in some locales, reads the same.
	 */
	public function test_parse_tells_a_header_from_a_first_entry() {
		$this->assertSame( [ 'x@example.test', 'y@example.test' ], Group_Subscription_Bulk_Invite::parse( '', "x@example.test\ny@example.test" ) );
		$this->assertSame( [ 'jane.example.test', 'y@example.test' ], Group_Subscription_Bulk_Invite::parse( '', "jane.example.test\ny@example.test" ) );
		$this->assertSame( [ 'z@example.test' ], Group_Subscription_Bulk_Invite::parse( '', "E-mail address;Name\nz@example.test;Zed" ) );
		$this->assertSame( [ 'z@example.test' ], Group_Subscription_Bulk_Invite::parse( '', "Email\nz@example.test;Zed" ) );
	}

	/**
	 * Every address lands in exactly one bucket: invalid ones are listed back, repeats,
	 * people already in the group and pending invitations are counted, and an account
	 * the group can't hold is listed back.
	 */
	public function test_classify_sorts_addresses_into_skip_classes() {
		$subscription = $this->create_group();
		$member_id    = $this->create_reader( 'member@example.test' );
		Group_Subscription::update_members( $subscription, [ $member_id ] );
		Group_Subscription_Invite::generate_invite( $subscription, 'pending@example.test', false );
		self::factory()->user->create(
			[
				'role'       => 'editor',
				'user_email' => 'staff@example.test',
			]
		);

		$result = Group_Subscription_Bulk_Invite::classify(
			$subscription,
			[ 'new@example.test', 'NEW@example.test', 'nope', 'Member@example.test', 'owner@example.test', 'pending@example.test', 'staff@example.test', 'other@example.test' ]
		);

		$this->assertSame( [ 'new@example.test', 'other@example.test' ], $result['to_invite'] );
		$this->assertSame( [ 'nope' ], $result['invalid'] );
		$this->assertSame( [ 'staff@example.test' ], $result['not_eligible'] );
		$this->assertSame(
			[
				'duplicate' => 1,
				'member'    => 2,
				'invited'   => 1,
			],
			$result['skipped']
		);

		// Unless asked, a staff account is not sorted out ahead of the send, so a preview
		// can't be used to test addresses for one.
		$unchecked = Group_Subscription_Bulk_Invite::classify( $subscription, [ 'staff@example.test' ], false );
		$this->assertSame( [ 'staff@example.test' ], $unchecked['to_invite'] );
		$this->assertSame( [], $unchecked['not_eligible'] );
	}


	/**
	 * An expired invitation holds no seat, so its address is invited afresh rather than
	 * skipped as already invited.
	 */
	public function test_classify_reinvites_an_expired_invitation() {
		$subscription = $this->create_group();
		$subscription->update_meta_data(
			Group_Subscription_Invite::META,
			[
				'stale-key' => [
					'added_by'   => $this->owner_id,
					'email'      => 'lapsed@example.test',
					'expiration' => time() - 60,
				],
			]
		);
		$subscription->save();

		$result = Group_Subscription_Bulk_Invite::classify( $subscription, [ 'lapsed@example.test' ] );

		$this->assertSame( [ 'lapsed@example.test' ], $result['to_invite'] );
		$this->assertSame( 0, $result['skipped']['invited'] );
	}

	/**
	 * Free seats are the seat limit minus members minus pending invitations, and a list
	 * with more new addresses than that is refused whole: no invitation is created.
	 */
	public function test_invite_refuses_a_list_longer_than_the_free_seats() {
		// A limit of 5 includes the owner, so 4 seats; one member and one pending invite leave 2.
		$subscription = $this->create_group( 5 );
		Group_Subscription::update_members( $subscription, [ $this->create_reader( 'member@example.test' ) ] );
		Group_Subscription_Invite::generate_invite( $subscription, 'pending@example.test', false );

		$preview = Group_Subscription_Bulk_Invite::classify( $subscription, [ 'a@example.test', 'b@example.test', 'c@example.test' ] );
		$this->assertSame( 2, $preview['seats_available'] );

		$result = Group_Subscription_Bulk_Invite::invite( $subscription, [ 'a@example.test', 'b@example.test', 'c@example.test' ] );

		$this->assertWPError( $result );
		$this->assertSame( 'newspack_group_subscription_bulk_invite_over_limit', $result->get_error_code() );
		$this->assertSame(
			[
				'status'          => 409,
				'requested'       => 3,
				'seats_available' => 2,
			],
			$result->get_error_data()
		);
		$this->assertSame( [ 'pending@example.test' ], $this->pending_invite_emails( $subscription ), 'A refused list must not be partially applied.' );

		// Skipped addresses don't need a seat, so the same list minus one new address fits.
		$fits = Group_Subscription_Bulk_Invite::invite( $subscription, [ 'a@example.test', 'b@example.test', 'pending@example.test' ] );
		$this->assertNotWPError( $fits );
		$this->assertSame( [ 'a@example.test', 'b@example.test', 'pending@example.test' ], $this->pending_invite_emails( $subscription ) );
	}

	/**
	 * Each send runs inside the request, so an unlimited group still can't send more
	 * than MAX_INVITES at once; skipped addresses don't count toward it.
	 */
	public function test_invite_refuses_more_new_addresses_than_one_send_carries() {
		$subscription = $this->create_group();
		$emails       = array_map(
			function ( $n ) {
				return "reader{$n}@example.test";
			},
			range( 1, Group_Subscription_Bulk_Invite::MAX_INVITES + 1 )
		);

		$result = Group_Subscription_Bulk_Invite::invite( $subscription, $emails );

		$this->assertSame( 'newspack_group_subscription_bulk_invite_too_many', $result->get_error_code() );
		$this->assertSame( [], $this->pending_invite_emails( $subscription ) );
		$this->assertNotWPError( Group_Subscription_Bulk_Invite::invite( $subscription, array_merge( array_slice( $emails, 1 ), [ 'owner@example.test' ] ) ) );
	}

	/**
	 * N valid new addresses produce N invitations through generate_invite(), each
	 * recorded as issued by the current user, and the result names each one.
	 */
	public function test_invite_creates_one_invitation_per_new_address() {
		$subscription = $this->create_group();
		$emails       = [ 'one@example.test', 'two@example.test', 'three@example.test', 'four@example.test', 'five@example.test' ];

		$result = Group_Subscription_Bulk_Invite::invite( $subscription, array_merge( $emails, [ 'one@example.test', 'bogus' ] ) );

		$this->assertNotWPError( $result );
		$invites = Group_Subscription_Invite::get_invites( $subscription, false );
		$this->assertCount( 5, $invites );
		$this->assertSame( [ $this->owner_id ], array_values( array_unique( wp_list_pluck( $invites, 'added_by' ) ) ) );
		$this->assertEqualsCanonicalizing( $emails, array_merge( $result['invited'], $result['not_sent'] ) );
		$this->assertSame( [], $result['failed'] );
		$this->assertSame( 1, $result['skipped']['duplicate'] );
		$this->assertSame( [ 'bogus' ], $result['invalid'] );
	}

	/**
	 * The route answers a preview without writing, sends only on confirm, and is closed
	 * to a reader who doesn't manage the group.
	 */
	public function test_route_previews_then_confirms_and_rejects_non_managers() {
		$subscription = $this->create_group();
		$dispatch     = function ( bool $confirm ) use ( $subscription ) {
			$request = new WP_REST_Request( 'POST', '/' . Group_Subscription_API::NAMESPACE . '/invite-bulk' );
			$request->set_body_params(
				[
					'subscription_id' => $subscription->get_id(),
					'emails'          => "a@example.test\nb@example.test",
					'csv'             => "email\nc@example.test",
					'confirm'         => $confirm,
				]
			);
			return rest_get_server()->dispatch( $request );
		};

		$preview = $dispatch( false );
		$this->assertSame( 200, $preview->get_status() );
		$this->assertSame( [ 'a@example.test', 'b@example.test', 'c@example.test' ], $preview->get_data()['to_invite'] );
		$this->assertSame( [], $this->pending_invite_emails( $subscription ), 'A preview must not create invitations.' );

		$this->assertSame( 200, $dispatch( true )->get_status() );
		$this->assertSame( [ 'a@example.test', 'b@example.test', 'c@example.test' ], $this->pending_invite_emails( $subscription ) );

		wp_set_current_user( $this->create_reader( 'stranger@example.test' ) );
		$this->assertSame( 403, $dispatch( true )->get_status() );
	}
}
