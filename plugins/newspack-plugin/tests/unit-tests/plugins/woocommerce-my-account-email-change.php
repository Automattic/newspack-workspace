<?php
/**
 * Tests for the My Account email change request lifecycle.
 *
 * @package Newspack\Tests
 */

use Newspack\WooCommerce_My_Account;

require_once __DIR__ . '/../../mocks/wc-mocks.php';

/**
 * Test the tokens backing the email change verification and cancellation links.
 */
class Newspack_Test_WooCommerce_My_Account_Email_Change extends WP_UnitTestCase {

	/**
	 * A reader with an account.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Set up a reader for each test.
	 */
	public function set_up() {
		parent::set_up();
		$this->user_id = self::factory()->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => 'reader@example.com',
			]
		);
	}

	/**
	 * Issue a token set for the test reader.
	 *
	 * @param string $new_email The address the request is for.
	 *
	 * @return array The stored token set.
	 */
	private function issue_tokens( $new_email = 'new@example.com' ) {
		$method = new ReflectionMethod( WooCommerce_My_Account::class, 'create_email_change_tokens' );
		$method->setAccessible( true );
		return $method->invoke( null, $this->user_id, $new_email );
	}

	/**
	 * Record a pending change to the given address, with fresh links.
	 *
	 * @param string $new_email The address the change is waiting on.
	 *
	 * @return array The stored token set.
	 */
	private function start_email_change( $new_email = 'new@example.com' ) {
		$tokens = $this->issue_tokens( $new_email );
		\update_user_meta( $this->user_id, WooCommerce_My_Account::PENDING_EMAIL_CHANGE_META, $new_email );
		return $tokens;
	}

	/**
	 * The address currently on the account.
	 *
	 * @return string
	 */
	private function account_email() {
		\clean_user_cache( $this->user_id );
		return \get_userdata( $this->user_id )->user_email;
	}

	/**
	 * Assert that no pending request remains on the account.
	 */
	private function assertNoPendingRequest() {
		$this->assertSame( '', \get_user_meta( $this->user_id, WooCommerce_My_Account::PENDING_EMAIL_CHANGE_META, true ), 'The pending address should be cleared.' );
		$this->assertSame( '', \get_user_meta( $this->user_id, WooCommerce_My_Account::PENDING_EMAIL_CHANGE_TOKENS_META, true ), 'The tokens should be cleared.' );
	}

	/**
	 * Each link gets its own token, since the two are delivered to different mailboxes.
	 */
	public function test_verification_and_cancellation_tokens_are_distinct() {
		$tokens = $this->issue_tokens();
		$this->assertNotEmpty( $tokens['verify'], 'A verification token should be issued.' );
		$this->assertNotEmpty( $tokens['cancel'], 'A cancellation token should be issued.' );
		$this->assertNotEquals( $tokens['verify'], $tokens['cancel'], 'The two links should never carry the same token.' );
	}

	/**
	 * Tokens are unguessable and specific to the request that issued them.
	 */
	public function test_tokens_are_unique_per_request() {
		$first  = $this->issue_tokens();
		$second = $this->issue_tokens();
		$this->assertNotEquals( $first['verify'], $second['verify'], 'A new request should issue a new verification token.' );
		$this->assertNotEquals( $first['cancel'], $second['cancel'], 'A new request should issue a new cancellation token.' );
		$this->assertSame( 32, strlen( $second['verify'] ), 'Tokens should be 32 characters long.' );
	}

	/**
	 * Re-requesting a change retires the tokens from the previous request.
	 */
	public function test_a_new_request_retires_the_previous_tokens() {
		$first = $this->start_email_change( 'first@example.com' );
		$this->start_email_change( 'second@example.com' );
		$this->assertStringNotContainsString(
			$first['cancel'],
			WooCommerce_My_Account::get_cancel_email_change_url( $this->user_id ),
			'The superseded cancellation link should no longer be offered.'
		);
	}

	/**
	 * The cancellation link must not leak the token that completes the change.
	 */
	public function test_cancellation_link_carries_only_the_cancellation_token() {
		$tokens = $this->start_email_change();
		$url    = WooCommerce_My_Account::get_cancel_email_change_url( $this->user_id );
		$this->assertStringContainsString( $tokens['cancel'], $url, 'The cancellation link should carry the cancellation token.' );
		$this->assertStringNotContainsString( $tokens['verify'], $url, 'The cancellation link should never carry the verification token.' );
	}

	/**
	 * With nothing pending there is no address to show and no link to offer.
	 */
	public function test_no_pending_change() {
		$this->assertSame( '', WooCommerce_My_Account::get_pending_email_change( $this->user_id ) );
		$this->assertSame( '', WooCommerce_My_Account::get_cancel_email_change_url( $this->user_id ) );
	}

	/**
	 * A pending change with live links is reported as pending.
	 */
	public function test_pending_change_is_reported() {
		$this->start_email_change( 'new@example.com' );
		$this->assertSame( 'new@example.com', WooCommerce_My_Account::get_pending_email_change( $this->user_id ) );
	}

	/**
	 * Once the links expire the request is dropped, so the account form unlocks.
	 */
	public function test_expired_request_is_dropped() {
		$tokens            = $this->start_email_change();
		$tokens['expires'] = time() - HOUR_IN_SECONDS;
		\update_user_meta( $this->user_id, WooCommerce_My_Account::PENDING_EMAIL_CHANGE_TOKENS_META, $tokens );

		$this->assertSame( '', WooCommerce_My_Account::get_pending_email_change( $this->user_id ), 'An expired request should not hold the account form.' );
		$this->assertSame( '', \get_user_meta( $this->user_id, WooCommerce_My_Account::PENDING_EMAIL_CHANGE_META, true ), 'The pending address should be cleared.' );
		$this->assertSame( '', \get_user_meta( $this->user_id, WooCommerce_My_Account::PENDING_EMAIL_CHANGE_TOKENS_META, true ), 'The spent tokens should be cleared.' );
	}

	/**
	 * A request with no usable links — one predating this flow, say — is dropped
	 * rather than left holding the account form with nothing the reader can click.
	 */
	public function test_request_without_usable_links_is_dropped() {
		\update_user_meta( $this->user_id, WooCommerce_My_Account::PENDING_EMAIL_CHANGE_META, 'new@example.com' );

		$this->assertSame( '', WooCommerce_My_Account::get_pending_email_change( $this->user_id ) );
		$this->assertSame( '', \get_user_meta( $this->user_id, WooCommerce_My_Account::PENDING_EMAIL_CHANGE_META, true ), 'The unusable request should be cleared.' );
	}

	/**
	 * An expired request offers no cancellation link.
	 */
	public function test_expired_request_offers_no_cancellation_link() {
		$tokens            = $this->start_email_change();
		$tokens['expires'] = time() - HOUR_IN_SECONDS;
		\update_user_meta( $this->user_id, WooCommerce_My_Account::PENDING_EMAIL_CHANGE_TOKENS_META, $tokens );

		$this->assertSame( '', WooCommerce_My_Account::get_cancel_email_change_url( $this->user_id ) );
	}

	/**
	 * The token is carried through to the link as-is, so what lands in the
	 * mailbox is what the handler compares against.
	 */
	public function test_link_carries_the_token_verbatim() {
		$url = WooCommerce_My_Account::get_email_change_url( WooCommerce_My_Account::VERIFY_EMAIL_CHANGE_PARAM, 'abc123' );
		$this->assertStringContainsString( WooCommerce_My_Account::VERIFY_EMAIL_CHANGE_PARAM . '=abc123', $url );
	}

	/**
	 * The verification token applies the requested address and settles the request.
	 */
	public function test_verifying_applies_the_requested_address() {
		$tokens = $this->start_email_change( 'confirmed@example.com' );

		$this->assertTrue( WooCommerce_My_Account::verify_email_change( $this->user_id, $tokens['verify'] ) );
		$this->assertSame( 'confirmed@example.com', $this->account_email() );
		$this->assertNoPendingRequest();
	}

	/**
	 * The cancellation token does not complete the change.
	 */
	public function test_cancellation_token_cannot_verify() {
		$tokens = $this->start_email_change( 'confirmed@example.com' );

		$result = WooCommerce_My_Account::verify_email_change( $this->user_id, $tokens['cancel'] );
		$this->assertWPError( $result );
		$this->assertSame( 'reader@example.com', $this->account_email(), 'The account address should be untouched.' );
		$this->assertSame( 'confirmed@example.com', WooCommerce_My_Account::get_pending_email_change( $this->user_id ), 'A rejected attempt should leave the request standing.' );
	}

	/**
	 * The verification token does not cancel the change.
	 */
	public function test_verification_token_cannot_cancel() {
		$tokens = $this->start_email_change();

		$this->assertWPError( WooCommerce_My_Account::cancel_email_change( $this->user_id, $tokens['verify'] ) );
		$this->assertSame( 'new@example.com', WooCommerce_My_Account::get_pending_email_change( $this->user_id ) );
	}

	/**
	 * A token that has expired no longer completes the change.
	 */
	public function test_expired_token_cannot_verify() {
		$tokens            = $this->start_email_change();
		$tokens['expires'] = time() - HOUR_IN_SECONDS;
		\update_user_meta( $this->user_id, WooCommerce_My_Account::PENDING_EMAIL_CHANGE_TOKENS_META, $tokens );

		$this->assertWPError( WooCommerce_My_Account::verify_email_change( $this->user_id, $tokens['verify'] ) );
		$this->assertSame( 'reader@example.com', $this->account_email() );
	}

	/**
	 * A verification token works once.
	 */
	public function test_verification_token_cannot_be_replayed() {
		$tokens = $this->start_email_change( 'confirmed@example.com' );
		WooCommerce_My_Account::verify_email_change( $this->user_id, $tokens['verify'] );

		$this->assertWPError( WooCommerce_My_Account::verify_email_change( $this->user_id, $tokens['verify'] ) );
		$this->assertSame( 'confirmed@example.com', $this->account_email(), 'The replay should not change the address again.' );
	}

	/**
	 * A cancellation token works once.
	 */
	public function test_cancellation_token_cannot_be_replayed() {
		$tokens = $this->start_email_change();
		$this->assertTrue( WooCommerce_My_Account::cancel_email_change( $this->user_id, $tokens['cancel'] ) );

		$this->assertWPError( WooCommerce_My_Account::cancel_email_change( $this->user_id, $tokens['cancel'] ) );
	}

	/**
	 * Cancelling settles the request and leaves the account address alone.
	 */
	public function test_cancelling_leaves_the_account_address() {
		$tokens = $this->start_email_change();

		$this->assertTrue( WooCommerce_My_Account::cancel_email_change( $this->user_id, $tokens['cancel'] ) );
		$this->assertSame( 'reader@example.com', $this->account_email() );
		$this->assertNoPendingRequest();
	}

	/**
	 * A token settles the address it was issued for, not whichever address was
	 * recorded last. Two overlapping requests can otherwise leave one request's
	 * token paired with the other's address.
	 */
	public function test_token_settles_the_address_it_was_issued_for() {
		$first = $this->issue_tokens( 'first@example.com' );
		\update_user_meta( $this->user_id, WooCommerce_My_Account::PENDING_EMAIL_CHANGE_META, 'second@example.com' );

		$this->assertTrue( WooCommerce_My_Account::verify_email_change( $this->user_id, $first['verify'] ) );
		$this->assertSame( 'first@example.com', $this->account_email() );
	}

	/**
	 * A request that cannot be stored is reported rather than half-started.
	 */
	public function test_unstorable_request_is_reported() {
		$fail = function () {
			return false;
		};
		\add_filter( 'update_user_metadata', $fail, 10, 1 );
		$tokens = $this->issue_tokens();
		\remove_filter( 'update_user_metadata', $fail, 10 );

		$this->assertSame( [], $tokens, 'A request that could not be stored should not hand back usable tokens.' );
	}
}
