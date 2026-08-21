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
	 * @return array The stored token set.
	 */
	private function issue_tokens() {
		$method = new ReflectionMethod( WooCommerce_My_Account::class, 'create_email_change_tokens' );
		$method->setAccessible( true );
		return $method->invoke( null, $this->user_id );
	}

	/**
	 * Record a pending change to the given address, with fresh links.
	 *
	 * @param string $new_email The address the change is waiting on.
	 *
	 * @return array The stored token set.
	 */
	private function start_email_change( $new_email = 'new@example.com' ) {
		\update_user_meta( $this->user_id, WooCommerce_My_Account::PENDING_EMAIL_CHANGE_META, $new_email );
		return $this->issue_tokens();
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
}
