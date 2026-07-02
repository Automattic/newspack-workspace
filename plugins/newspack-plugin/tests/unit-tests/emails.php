<?php
/**
 * Tests Reader Revenue Emails.
 *
 * @package Newspack\Tests
 */

use Newspack\Plugin_Manager;
use Newspack\Emails;

/**
 * Tests Reader Revenue Emails.
 */
class Newspack_Test_Emails extends WP_UnitTestCase {
	/**
	 * Setup.
	 *
	 * Intentionally does NOT call `parent::set_up()` — the existing
	 * tests in this class depend on the absence of the parent's
	 * transaction lifecycle (tested empirically: adding parent::set_up
	 * breaks `test_emails_send_by_id`). Tests that need a user
	 * factory use the static accessor on `WP_UnitTestCase` rather
	 * than `$this->factory`.
	 */
	public function set_up() {
		reset_phpmailer_instance();
		add_filter(
			'newspack_email_configs',
			function ( $types ) {
				$types['test-email-config'] = [
					'name'        => 'test-email-config',
					'label'       => __( 'Test config', 'newspack' ),
					'description' => __( 'Email sent to test things.', 'newspack' ),
					'template'    => dirname( NEWSPACK_PLUGIN_FILE ) . '/includes/templates/reader-revenue-emails/receipt.php',
					'category'    => 'test',
				];
				return $types;
			}
		);
		Emails::reset_email_configs_cache();
	}

	/**
	 * Teardown.
	 */
	public function tear_down() {
		reset_phpmailer_instance();
		Emails::reset_email_configs_cache();
		delete_option( \Newspack\Reader_Activation::OPTIONS_PREFIX . 'sender_name' );
		delete_option( \Newspack\Reader_Activation::OPTIONS_PREFIX . 'sender_email_address' );
		delete_option( \Newspack\Reader_Activation::OPTIONS_PREFIX . 'contact_email_address' );
	}

	/**
	 * NPPD-1566: the saved sender/contact overrides are honored by the
	 * send path REGARDLESS of Reader Activation state. Reader Activation
	 * is disabled in the test environment by default, so these assertions
	 * exercise exactly the previously-broken path: a publisher saves the
	 * settings via the Emails modal with RA off, and the send path must
	 * use them instead of silently falling back to derived defaults.
	 */
	public function test_sender_settings_honored_regardless_of_reader_activation() {
		$prefix = \Newspack\Reader_Activation::OPTIONS_PREFIX;
		update_option( $prefix . 'sender_name', 'Custom Sender' );
		update_option( $prefix . 'sender_email_address', 'sender@example.com' );
		update_option( $prefix . 'contact_email_address', 'contact@example.com' );

		self::assertSame( 'Custom Sender', Emails::get_from_name(), 'Saved sender_name must be honored with RA off.' );
		self::assertSame( 'sender@example.com', Emails::get_from_email(), 'Saved sender_email must be honored with RA off.' );
		self::assertSame( 'contact@example.com', Emails::get_reply_to_email(), 'Saved contact_email must be honored with RA off.' );
	}

	/**
	 * An empty (or absent) saved value is the revert-to-default signal:
	 * the send path falls back to the derived default rather than sending
	 * with a blank sender.
	 */
	public function test_sender_settings_fall_back_to_defaults_when_empty() {
		$prefix = \Newspack\Reader_Activation::OPTIONS_PREFIX;
		update_option( $prefix . 'sender_name', '' );
		delete_option( $prefix . 'sender_email_address' );

		self::assertSame( get_bloginfo( 'name' ), Emails::get_from_name(), 'Empty sender_name must fall back to the site title.' );
		self::assertStringStartsWith( 'no-reply@', Emails::get_from_email(), 'Absent sender_email must fall back to the derived no-reply default.' );
	}

	/**
	 * NPPD-1575: the option-layer guard (registered via register_setting)
	 * validates the email keys for EVERY writer — including a direct
	 * update_option() that bypasses the Emails route's inline is_email().
	 * A valid address is stored; a malformed one is coerced to the
	 * last-good stored value (never wiping a good address); empty is the
	 * revert signal and is preserved.
	 */
	public function test_email_option_sanitization_applies_to_any_writer() {
		Emails::register_sender_settings(); // Ensure the sanitize filters are attached.
		$option = \Newspack\Reader_Activation::OPTIONS_PREFIX . 'sender_email_address';

		// Valid address is stored (sanitized).
		update_option( $option, 'good@example.com' );
		self::assertSame( 'good@example.com', get_option( $option ), 'Valid address must persist.' );

		// Malformed non-empty write (bypassing the route guard) is coerced
		// to the last-good stored value, not allowed to wipe it.
		update_option( $option, 'not-an-email' );
		self::assertSame( 'good@example.com', get_option( $option ), 'Invalid address must coerce to last-good, not overwrite it.' );

		// Empty is the revert-to-default signal and is preserved.
		update_option( $option, '' );
		self::assertSame( '', get_option( $option ), 'Empty must be preserved as the revert signal.' );
	}

	/**
	 * The sender_name option strips CR/LF via sanitize_text_field at the
	 * option layer, so a From:-header injection attempt can't be stored
	 * even by a writer that skips the route.
	 */
	public function test_sender_name_option_strips_newlines() {
		Emails::register_sender_settings();
		$option = \Newspack\Reader_Activation::OPTIONS_PREFIX . 'sender_name';

		update_option( $option, "Evil\r\nBcc: victim@example.com" );
		self::assertStringNotContainsString( "\r", get_option( $option ), 'CR must be stripped from sender_name.' );
		self::assertStringNotContainsString( "\n", get_option( $option ), 'LF must be stripped from sender_name.' );
	}

	/**
	 * Get an email, by type.
	 *
	 * @param string $type Email type.
	 */
	private static function get_test_email( $type ) {
		return Emails::get_emails()[ $type ];
	}

	/**
	 * Email setup & defaults generation.
	 */
	public function test_emails_setup() {
		self::assertTrue(
			Emails::supports_emails(),
			'Emails are configured after Newspack Newsletters plugin is active.'
		);

		self::assertTrue(
			Emails::can_send_email( 'test-email-config' ),
			'Test email can now be sent.'
		);

		$emails     = Emails::get_emails( [ 'test-email-config' ] );
		$test_email = $emails['test-email-config'];
		self::assertEquals(
			'Test config',
			$test_email['label'],
			'Test email has the expected label'
		);
		self::assertEquals(
			'Thank you!',
			$test_email['subject'],
			'Test email has the expected subject'
		);
		self::assertStringContainsString(
			'<!doctype html>',
			$test_email['html_payload'],
			'Test email has the HTML payload'
		);
	}

	/**
	 * Email sending, with a template.
	 */
	public function test_emails_send_with_template() {
		$test_email = self::get_test_email( 'test-email-config' );

		$recipient    = 'tester@tests.com';
		$amount       = '$42';
		$placeholders = [
			[
				'template' => '*AMOUNT*',
				'value'    => $amount,
			],
		];
		$send_result  = Emails::send_email(
			'test-email-config',
			$recipient,
			$placeholders
		);

		self::assertTrue( $send_result, 'Email has been sent.' );

		$mailer = tests_retrieve_phpmailer_instance();

		self::assertContains(
			$recipient,
			$mailer->get_sent()->to[0],
			'Sent email has the expected recipient'
		);
		self::assertEquals(
			$test_email['subject'],
			$mailer->get_sent()->subject,
			'Sent email has the expected subject'
		);
		self::assertStringContainsString(
			'From: Test Blog <no-reply@example.org>',
			$mailer->get_sent()->header,
			'Sent email has the expected "From" header'
		);
		self::assertStringContainsString(
			$amount,
			$mailer->get_sent()->body,
			'Sent email contains the replaced placeholder content'
		);
	}

	/**
	 * Sending by email id.
	 */
	public function test_emails_send_by_id() {
		$test_email = self::get_test_email( 'test-email-config' );

		$send_result = Emails::send_email(
			$test_email['post_id'],
			'someone@example.com'
		);
		self::assertTrue( $send_result, 'Email has been sent.' );

		$send_result = Emails::send_email(
			9999,
			'someone@example.com'
		);
		self::assertFalse( $send_result, 'Non-existent email is not sent.' );
	}

	/**
	 * Email post status — auto-send vs. test-send distinction (NPPD-1547).
	 *
	 * Locks the contract that splits the two entry points:
	 *
	 * - AUTO-SEND (Emails::send_email + Emails::can_send_email) is
	 *   blocked for draft emails. Event-triggered sends (RAS, receipts,
	 *   renewal reminders, etc.) require the email to be in 'publish'
	 *   status — an inactive email must not fire on triggered events.
	 * - TEST-SEND (Emails::send_test_email) IS allowed for draft
	 *   emails. The admin's deliberate "Send test" operation is
	 *   semantically distinct from auto-trigger gating; before
	 *   NPPD-1547 the shared 'publish' === status guard incidentally
	 *   blocked test-send for inactive emails.
	 *
	 * Both assertions in one test makes the distinction explicit and
	 * locked: a future refactor that re-conflates the two paths
	 * fails this test against both contracts simultaneously.
	 */
	public function test_emails_status() {
		$test_email = self::get_test_email( 'test-email-config' );
		$original_status = get_post_status( $test_email['post_id'] );
		wp_update_post(
			[
				'ID'          => $test_email['post_id'],
				'post_status' => 'draft',
			]
		);

		try {
			// AUTO-SEND: draft is blocked. Unchanged from pre-NPPD-1547.
			self::assertFalse(
				Emails::can_send_email( 'test-email-config' ),
				'can_send_email() must return false for draft (auto-send gate).'
			);
			$auto_send_result = Emails::send_email( 'test-email-config', 'someone@example.com' );
			self::assertFalse( $auto_send_result, 'send_email() must not dispatch for draft (auto-send gate).' );

			// TEST-SEND: draft is allowed. The headline contract of NPPD-1547.
			// send_test_email requires manage_options at its entry point;
			// log in as admin for the duration of the call.
			$admin = self::login_as_admin();
			try {
				$test_send_result = Emails::send_test_email( $test_email['post_id'], 'someone@example.com' );
				self::assertTrue(
					$test_send_result,
					'send_test_email() must return true for draft — the admin "Send test" operation is decoupled from the auto-send status gate.'
				);
			} finally {
				self::logout_admin( $admin );
			}
		} finally {
			// Restore the shared post so later tests see 'publish'
			// status (this class skips parent::set_up(), so DB
			// mutations leak across tests without explicit restore).
			wp_update_post(
				[
					'ID'          => $test_email['post_id'],
					'post_status' => $original_status,
				]
			);
		}
	}

	/*
	 * ------------------------------------------------------------------
	 * NPPD-1547 — Test-send entry point + recipient validation
	 * ------------------------------------------------------------------
	 * The split between auto-send and test-send is verified at the
	 * status-gate level by `test_emails_status` above. The tests
	 * below cover the rest of the test-send contract: prerequisite
	 * failures surface specific newspack_emails_* WP_Error codes
	 * (not the previous generic "Test email was not sent." string),
	 * and capability + trash + recipient-format guards apply
	 * uniformly whether send_test_email is reached via the REST
	 * handler or via a direct PHP call.
	 */

	/**
	 * Headline contract: test-send dispatches for a draft email.
	 */
	public function test_test_send_works_for_draft() {
		$test_email      = self::get_test_email( 'test-email-config' );
		$original_status = get_post_status( $test_email['post_id'] );
		wp_update_post(
			[
				'ID'          => $test_email['post_id'],
				'post_status' => 'draft',
			]
		);

		$admin = self::login_as_admin();
		try {
			$result = Emails::send_test_email( $test_email['post_id'], 'tester@example.com' );

			self::assertTrue( $result, 'send_test_email() must return true (not WP_Error) for a draft email with all other prereqs satisfied.' );

			// Verify the email actually reached the (mocked) mailer
			// with the correct recipient — guards against accidentally
			// short-circuiting before dispatch_email is called.
			$mailer = tests_retrieve_phpmailer_instance();
			self::assertContains( 'tester@example.com', $mailer->get_sent()->to[0] );
		} finally {
			self::logout_admin( $admin );
			wp_update_post(
				[
					'ID'          => $test_email['post_id'],
					'post_status' => $original_status,
				]
			);
		}
	}

	/**
	 * Recipient format validation now lives in
	 * validate_send_prerequisites (the helper), not at the REST
	 * handler. Both the api_send_test_email REST entry AND direct
	 * send_test_email PHP calls reject typo input with the same
	 * code (`newspack_emails_invalid_recipient`) and 400 status.
	 */
	public function test_test_send_rejects_non_email_recipient_via_rest() {
		$test_email = self::get_test_email( 'test-email-config' );

		$admin = self::login_as_admin();
		try {
			$request = new WP_REST_Request( 'POST' );
			$request->set_param( 'post_id', $test_email['post_id'] );
			$request->set_param( 'recipient', 'not-an-email' );

			$response = Emails::api_send_test_email( $request );

			self::assertInstanceOf( WP_Error::class, $response );
			self::assertSame( 'newspack_emails_invalid_recipient', $response->get_error_code() );
			self::assertSame( 400, $response->get_error_data()['status'] );
		} finally {
			self::logout_admin( $admin );
		}
	}

	/**
	 * Direct PHP callers of send_test_email get the same recipient
	 * validation as REST callers — guards against the "helper does
	 * one thing, handler does another" gap that would let a future
	 * internal caller bypass is_email().
	 */
	public function test_test_send_rejects_non_email_recipient_via_direct_php() {
		$test_email = self::get_test_email( 'test-email-config' );

		$admin = self::login_as_admin();
		try {
			$result = Emails::send_test_email( $test_email['post_id'], 'not-an-email' );

			self::assertInstanceOf( WP_Error::class, $result );
			self::assertSame( 'newspack_emails_invalid_recipient', $result->get_error_code() );
			self::assertSame( 400, $result->get_error_data()['status'] );
		} finally {
			self::logout_admin( $admin );
		}
	}

	/**
	 * Supports_emails() returning false means Newspack_Newsletters is
	 * not active. Acknowledged structural test-infra gap.
	 *
	 * The supports_emails() implementation uses class_exists() on Newspack_Newsletters;
	 * the test bootstrap loads that class and PHP doesn't allow
	 * un-loading. The false branch is not exercisable without either
	 * adding a filter to supports_emails() for test seam (a
	 * production change for testability) or runkit/uopz extensions.
	 * Accepting reduced coverage on this single guard rather than
	 * paying the production-code-change cost. Tracked separately as
	 * "make supports_emails() check mockable for testing" follow-up.
	 */
	public function test_test_send_blocked_when_no_newspack_newsletters() {
		$this->markTestSkipped( 'supports_emails() false branch is not exercisable without modifying production code or runkit/uopz extensions. Test infra investment we are choosing not to make in this PR; tracked as a follow-up.' );
	}

	/**
	 * A post WITHOUT EMAIL_HTML_META cannot be dispatched.
	 * validate_send_prerequisites surfaces it as
	 * newspack_emails_html_payload_missing with 422 — distinct from
	 * "post doesn't exist" (now newspack_emails_post_missing/404) so
	 * the UI can prompt "save your draft first" vs. "the post is
	 * gone".
	 *
	 * Uses its own throwaway post (different config name) so the
	 * missing-meta state doesn't leak into other tests via the
	 * shared get_emails() lookup.
	 */
	public function test_test_send_blocked_when_missing_html_payload() {
		$post_id = wp_insert_post(
			[
				'post_type'   => Emails::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => 'NPPD-1547 missing-html test',
				'meta_input'  => [
					Emails::EMAIL_CONFIG_NAME_META => 'nppd1547-missing-html',
				],
			]
		);

		$admin = self::login_as_admin();
		try {
			$result = Emails::send_test_email( $post_id, 'tester@example.com' );

			self::assertInstanceOf( WP_Error::class, $result );
			self::assertSame( 'newspack_emails_html_payload_missing', $result->get_error_code() );
			self::assertSame( 422, $result->get_error_data()['status'] );
		} finally {
			wp_delete_post( $post_id, true );
			self::logout_admin( $admin );
		}
	}

	/**
	 * Post exists with HTML payload BUT no EMAIL_CONFIG_NAME_META.
	 * Surfaces as newspack_emails_config_name_missing with 422.
	 * Without this guard, dispatch_email would call
	 * get_email_payload('') which returns false → array-access on
	 * false → blank-bodied email sent to recipient. Now reachable
	 * because send_test_email skips the publish gate, so drafts
	 * with incomplete meta can route this far.
	 */
	public function test_test_send_blocked_when_config_name_missing() {
		$post_id = wp_insert_post(
			[
				'post_type'   => Emails::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => 'NPPD-1547 missing-config-name test',
				'meta_input'  => [
					// Intentionally no EMAIL_CONFIG_NAME_META.
					\Newspack_Newsletters::EMAIL_HTML_META => '<!doctype html><html><body>nppd-1547 stub</body></html>',
				],
			]
		);

		$admin = self::login_as_admin();
		try {
			$result = Emails::send_test_email( $post_id, 'tester@example.com' );

			self::assertInstanceOf( WP_Error::class, $result );
			self::assertSame( 'newspack_emails_config_name_missing', $result->get_error_code() );
			self::assertSame( 422, $result->get_error_data()['status'] );
		} finally {
			wp_delete_post( $post_id, true );
			self::logout_admin( $admin );
		}
	}

	/**
	 * A non-email post that happens to carry the email meta keys must not
	 * reach the dispatch path. serialize_email() keys off post meta, so the
	 * post_type guard is what keeps a matching-meta post of another type out.
	 */
	public function test_test_send_blocked_for_wrong_post_type() {
		$post_id = wp_insert_post(
			[
				'post_type'   => 'post',
				'post_status' => 'draft',
				'post_title'  => 'NPPD-1547 wrong-post-type test',
				'meta_input'  => [
					Emails::EMAIL_CONFIG_NAME_META         => 'test-email-config',
					\Newspack_Newsletters::EMAIL_HTML_META => '<!doctype html><html><body>stub</body></html>',
				],
			]
		);

		$admin = self::login_as_admin();
		try {
			$result = Emails::send_test_email( $post_id, 'tester@example.com' );

			self::assertInstanceOf( WP_Error::class, $result );
			self::assertSame( 'newspack_emails_wrong_post_type', $result->get_error_code() );
			self::assertSame( 400, $result->get_error_data()['status'] );
		} finally {
			wp_delete_post( $post_id, true );
			self::logout_admin( $admin );
		}
	}

	/**
	 * A non-empty but UNREGISTERED config name (e.g. a renamed provider type
	 * left on an old draft) is rejected before dispatch — otherwise it would
	 * re-resolve to nothing downstream and send a blank-bodied email.
	 */
	public function test_test_send_blocked_when_config_name_unknown() {
		$post_id = wp_insert_post(
			[
				'post_type'   => Emails::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => 'NPPD-1547 unknown-config-name test',
				'meta_input'  => [
					Emails::EMAIL_CONFIG_NAME_META         => 'this-config-does-not-exist',
					\Newspack_Newsletters::EMAIL_HTML_META => '<!doctype html><html><body>stub</body></html>',
				],
			]
		);

		$admin = self::login_as_admin();
		try {
			$result = Emails::send_test_email( $post_id, 'tester@example.com' );

			self::assertInstanceOf( WP_Error::class, $result );
			self::assertSame( 'newspack_emails_config_name_unknown', $result->get_error_code() );
			self::assertSame( 422, $result->get_error_data()['status'] );
		} finally {
			wp_delete_post( $post_id, true );
			self::logout_admin( $admin );
		}
	}

	/**
	 * The `wp_mail_content_type` filter is request-scoped: dispatch_email
	 * must remove it after a SUCCESSFUL send, or subsequent plain-text mail
	 * in the same request silently becomes html.
	 */
	public function test_dispatch_removes_content_type_filter_on_success() {
		$test_email = self::get_test_email( 'test-email-config' );
		$admin      = self::login_as_admin();
		try {
			$result = Emails::send_test_email( $test_email['post_id'], 'tester@example.com' );

			self::assertTrue( $result, 'Sanity: the test send should succeed.' );
			self::assertFalse(
				has_filter( 'wp_mail_content_type' ),
				'wp_mail_content_type must be removed after a successful dispatch.'
			);
		} finally {
			self::logout_admin( $admin );
		}
	}

	/**
	 * ...and removed after a FAILED send too — the try/finally in
	 * dispatch_email is what guarantees the filter never leaks past the
	 * wp_mail() call. wp_mail is forced to fail via `pre_wp_mail`.
	 */
	public function test_dispatch_removes_content_type_filter_on_failure() {
		$test_email = self::get_test_email( 'test-email-config' );
		$admin      = self::login_as_admin();
		add_filter( 'pre_wp_mail', '__return_false' );
		try {
			$result = Emails::send_test_email( $test_email['post_id'], 'tester@example.com' );

			self::assertInstanceOf( WP_Error::class, $result );
			self::assertSame( 'newspack_emails_test_dispatch_failed', $result->get_error_code() );
			self::assertFalse(
				has_filter( 'wp_mail_content_type' ),
				'wp_mail_content_type must be removed even when dispatch fails.'
			);
		} finally {
			remove_filter( 'pre_wp_mail', '__return_false' );
			self::logout_admin( $admin );
		}
	}

	/**
	 * The locale switch wraps the whole send operation. After a SUCCESSFUL
	 * send the locale must be restored. The admin is given a distinct
	 * (`fr_FR`) user locale so a real switch happens and a leak would be
	 * observable.
	 */
	public function test_send_test_email_restores_locale_on_success() {
		$test_email = self::get_test_email( 'test-email-config' );
		$admin      = self::login_as_admin();
		update_user_meta( $admin[0], 'locale', 'fr_FR' );
		$before = get_locale();
		try {
			$result = Emails::send_test_email( $test_email['post_id'], 'tester@example.com' );

			self::assertTrue( $result, 'Sanity: the test send should succeed.' );
			self::assertSame( $before, get_locale(), 'Locale must be restored after a successful test-send.' );
		} finally {
			self::logout_admin( $admin );
		}
	}

	/**
	 * ...and restored on an EARLY prerequisite failure too (here an invalid
	 * recipient, which returns before dispatch). The switch is the
	 * outermost operation, so every return path must unwind it.
	 */
	public function test_send_test_email_restores_locale_on_early_error() {
		$test_email = self::get_test_email( 'test-email-config' );
		$admin      = self::login_as_admin();
		update_user_meta( $admin[0], 'locale', 'fr_FR' );
		$before = get_locale();
		try {
			$result = Emails::send_test_email( $test_email['post_id'], 'not-an-email' );

			self::assertInstanceOf( WP_Error::class, $result );
			self::assertSame( $before, get_locale(), 'Locale must be restored even when an early prerequisite check fails.' );
		} finally {
			self::logout_admin( $admin );
		}
	}

	/**
	 * Trashed posts cannot be test-sent. The publish-status gate
	 * skip in NPPD-1547 is for the "draft / inactive but being
	 * edited" case; a trashed post is intentionally removed and
	 * shouldn't be sendable. Restoring from trash is a one-click
	 * admin operation if the publisher really wants to test the
	 * trashed content.
	 */
	public function test_test_send_blocked_for_trashed_post() {
		$test_email      = self::get_test_email( 'test-email-config' );
		$original_status = get_post_status( $test_email['post_id'] );
		wp_update_post(
			[
				'ID'          => $test_email['post_id'],
				'post_status' => 'trash',
			]
		);

		$admin = self::login_as_admin();
		try {
			$result = Emails::send_test_email( $test_email['post_id'], 'tester@example.com' );

			self::assertInstanceOf( WP_Error::class, $result );
			self::assertSame( 'newspack_emails_post_trashed', $result->get_error_code() );
			self::assertSame( 409, $result->get_error_data()['status'] );
		} finally {
			self::logout_admin( $admin );
			wp_update_post(
				[
					'ID'          => $test_email['post_id'],
					'post_status' => $original_status,
				]
			);
		}
	}

	/**
	 * Empty recipient surfaces newspack_emails_empty_recipient with
	 * 400 — same code regardless of REST vs direct entry (no more
	 * duplicate codes for the same state).
	 */
	public function test_test_send_blocked_when_recipient_empty() {
		$test_email = self::get_test_email( 'test-email-config' );

		$admin = self::login_as_admin();
		try {
			$result = Emails::send_test_email( $test_email['post_id'], '' );

			self::assertInstanceOf( WP_Error::class, $result );
			self::assertSame( 'newspack_emails_empty_recipient', $result->get_error_code() );
			self::assertSame( 400, $result->get_error_data()['status'] );
		} finally {
			self::logout_admin( $admin );
		}
	}

	/**
	 * Invalid post_id (0, negative, non-numeric) surfaces
	 * newspack_emails_invalid_post_id with 400 — distinct from
	 * "post doesn't exist" (404). 0 is the common case via
	 * `absint(missing_param)`; this guard returns a structurally
	 * correct 400 rather than the previous mis-classified 404.
	 */
	public function test_test_send_blocked_when_post_id_invalid() {
		$admin = self::login_as_admin();
		try {
			$result = Emails::send_test_email( 0, 'tester@example.com' );

			self::assertInstanceOf( WP_Error::class, $result );
			self::assertSame( 'newspack_emails_invalid_post_id', $result->get_error_code() );
			self::assertSame( 400, $result->get_error_data()['status'] );
		} finally {
			self::logout_admin( $admin );
		}
	}

	/**
	 * Tight post_id validation: is_numeric() is too loose — it
	 * accepts floats like '1.5' and scientific notation like '1e3'
	 * which then truncate-cast to ints (1, 1000) and resolve to
	 * the wrong post. Require integer-shaped input.
	 *
	 * Each value must be rejected with newspack_emails_invalid_post_id.
	 */
	public function test_test_send_rejects_non_integer_shaped_post_id() {
		$admin = self::login_as_admin();
		try {
			// Values is_numeric() accepts but ctype_digit rejects.
			// Each must surface newspack_emails_invalid_post_id (400).
			$non_integer_shapes = [
				'1.5',
				'1e3',
				'1.7e308',
				'-5',
				'abc',
				'12abc',
				' 5 ',
				'',
				null,
			];

			foreach ( $non_integer_shapes as $bad_post_id ) {
				// Friendly label for assertion-failure output. null
				// and '' don't survive sprintf %s cleanly otherwise.
				$label  = null === $bad_post_id ? 'NULL' : ( '' === $bad_post_id ? "''" : "'{$bad_post_id}'" );
				$result = Emails::send_test_email( $bad_post_id, 'tester@example.com' );
				self::assertInstanceOf(
					WP_Error::class,
					$result,
					"post_id {$label} must be rejected"
				);
				self::assertSame(
					'newspack_emails_invalid_post_id',
					$result->get_error_code(),
					"post_id {$label} wrong error code"
				);
			}
		} finally {
			self::logout_admin( $admin );
		}
	}

	/**
	 * The REST route's validate_callback rejects malformed raw post_id
	 * shapes directly. Unit-level guard for the callback itself: valid
	 * shapes return true, malformed shapes return the shared
	 * newspack_emails_invalid_post_id (400) WP_Error.
	 */
	public function test_validate_test_send_post_id_callback() {
		foreach ( [ 1, '1', '42', 999 ] as $valid ) {
			self::assertTrue(
				Emails::validate_test_send_post_id( $valid ),
				sprintf( 'post_id %s must validate', is_string( $valid ) ? "'{$valid}'" : $valid )
			);
		}
		foreach ( [ '1.5', '1e3', '-5', '0', 0, 'abc', '12abc', ' 5 ', '', null ] as $bad ) {
			$result = Emails::validate_test_send_post_id( $bad );
			$label  = null === $bad ? 'NULL' : ( '' === $bad ? "''" : ( is_string( $bad ) ? "'{$bad}'" : $bad ) );
			self::assertInstanceOf( WP_Error::class, $result, "post_id {$label} must be rejected" );
			self::assertSame( 'newspack_emails_invalid_post_id', $result->get_error_code(), "post_id {$label} wrong code" );
			self::assertSame( 400, $result->get_error_data()['status'], "post_id {$label} wrong status" );
		}
	}

	/**
	 * Full REST dispatch: the route registers post_id with
	 * `sanitize_callback => absint`, which coerces '1.5'→1, '1e3'→1,
	 * '-5'→5 BEFORE the handler runs. The added validate_callback runs
	 * first (on the raw value) so malformed input is rejected with a
	 * 400 instead of silently resolving a valid-but-wrong post. Only a
	 * full server dispatch exercises the sanitize/validate ordering —
	 * calling api_send_test_email() directly does not.
	 */
	public function test_rest_dispatch_rejects_malformed_post_id_before_coercion() {
		$admin = self::login_as_admin();
		try {
			foreach ( [ '1.5', '1e3', '-5' ] as $bad_post_id ) {
				$request = new WP_REST_Request( 'POST', '/newspack/v1/newspack-emails/test' );
				$request->set_param( 'post_id', $bad_post_id );
				$request->set_param( 'recipient', 'tester@example.com' );

				$response = rest_get_server()->dispatch( $request );

				self::assertSame(
					400,
					$response->get_status(),
					"post_id '{$bad_post_id}' must be rejected by the route before absint coercion"
				);
				// rest_invalid_param wraps the validate_callback error;
				// the nested detail carries our specific code.
				$data = $response->get_data();
				self::assertSame(
					'newspack_emails_invalid_post_id',
					$data['data']['details']['post_id']['code'] ?? null,
					"post_id '{$bad_post_id}' must surface newspack_emails_invalid_post_id"
				);
			}
		} finally {
			self::logout_admin( $admin );
		}
	}

	/**
	 * Digit-only string post_id normalizes to int and routes to the
	 * post-id branch correctly. Locks in the send_email
	 * gettype()-branching hardening: numeric-string callers (from
	 * $_POST, post meta, cast-naive integrations) no longer silently
	 * fall through to the string-name branch.
	 */
	public function test_send_test_email_accepts_numeric_string_post_id() {
		$test_email      = self::get_test_email( 'test-email-config' );
		$original_status = get_post_status( $test_email['post_id'] );
		wp_update_post(
			[
				'ID'          => $test_email['post_id'],
				'post_status' => 'draft',
			]
		);

		$admin = self::login_as_admin();
		try {
			// Cast post_id to a string to simulate what a $_POST
			// or untyped REST param would deliver pre-absint.
			$result = Emails::send_test_email( (string) $test_email['post_id'], 'tester@example.com' );
			self::assertTrue( $result, 'Numeric-string post_id must be accepted (normalized to int internally).' );
		} finally {
			self::logout_admin( $admin );
			wp_update_post(
				[
					'ID'          => $test_email['post_id'],
					'post_status' => $original_status,
				]
			);
		}
	}

	/**
	 * Subscriber-level user calling the REST route is blocked by
	 * api_permissions_check (the route's permission_callback).
	 */
	public function test_test_send_blocked_when_non_admin_via_rest() {
		$subscriber_id = wp_insert_user(
			[
				'user_login' => 'nppd1547_subscriber_' . uniqid(),
				'user_pass'  => 'test-password',
				'user_email' => 'sub-' . uniqid() . '@example.test',
				'role'       => 'subscriber',
			]
		);
		$prev_user = get_current_user_id();
		wp_set_current_user( $subscriber_id );

		try {
			$result = Emails::api_permissions_check( null );

			self::assertInstanceOf( WP_Error::class, $result );
			self::assertSame( 'newspack_rest_forbidden', $result->get_error_code() );
			self::assertSame( 403, $result->get_error_data()['status'] );
		} finally {
			wp_set_current_user( $prev_user );
			wp_delete_user( $subscriber_id );
		}
	}

	/**
	 * Subscriber-level user calling send_test_email DIRECTLY (not
	 * via REST) is blocked by the cap check at the entry point of
	 * send_test_email itself. Without the entry-point check, a
	 * future PHP caller (CLI command, plugin hook) could bypass
	 * the REST route's permission_callback and dispatch draft
	 * emails from a low-privilege context. This test locks the
	 * defense-in-depth contract.
	 */
	public function test_test_send_blocked_when_non_admin_via_direct_php() {
		$test_email = self::get_test_email( 'test-email-config' );

		$subscriber_id = wp_insert_user(
			[
				'user_login' => 'nppd1547_subscriber_direct_' . uniqid(),
				'user_pass'  => 'test-password',
				'user_email' => 'sub-direct-' . uniqid() . '@example.test',
				'role'       => 'subscriber',
			]
		);
		$prev_user = get_current_user_id();
		wp_set_current_user( $subscriber_id );

		try {
			$result = Emails::send_test_email( $test_email['post_id'], 'tester@example.com' );

			self::assertInstanceOf( WP_Error::class, $result );
			self::assertSame( 'newspack_emails_forbidden', $result->get_error_code() );
			self::assertSame( 403, $result->get_error_data()['status'] );
		} finally {
			wp_set_current_user( $prev_user );
			wp_delete_user( $subscriber_id );
		}
	}

	/**
	 * Non-existent post_id surfaces newspack_emails_post_missing
	 * with 404 — distinct from invalid-id-shape (400) and missing-
	 * content (422). The split lets the UI route by failure mode.
	 */
	public function test_test_send_blocked_when_post_missing() {
		$admin = self::login_as_admin();
		try {
			$result = Emails::send_test_email( 9999999, 'tester@example.com' );

			self::assertInstanceOf( WP_Error::class, $result );
			self::assertSame( 'newspack_emails_post_missing', $result->get_error_code() );
			self::assertSame( 404, $result->get_error_data()['status'] );
		} finally {
			self::logout_admin( $admin );
		}
	}

	/**
	 * REGRESSION LOCK: NPPD-1547's refactor extracted the send-path
	 * helper but MUST preserve auto-send's status gate. Without this
	 * lock-in, a future refactor that accidentally routes
	 * send_email's post-id branch through send_test_email's
	 * status-less path would silently start dispatching draft
	 * emails on triggered events.
	 */
	public function test_auto_send_still_blocked_for_draft() {
		$test_email      = self::get_test_email( 'test-email-config' );
		$original_status = get_post_status( $test_email['post_id'] );
		wp_update_post(
			[
				'ID'          => $test_email['post_id'],
				'post_status' => 'draft',
			]
		);

		try {
			// Post-id path of send_email — the branch that shares
			// validate_send_prerequisites with send_test_email. Auto-send
			// must still apply the status check on top.
			$post_id_result = Emails::send_email( $test_email['post_id'], 'tester@example.com' );
			self::assertFalse( $post_id_result, 'send_email() post-id branch must remain status-gated.' );

			// String path of send_email — the dominant auto-trigger
			// surface. Status check is inline (not via the helper).
			$string_result = Emails::send_email( 'test-email-config', 'tester@example.com' );
			self::assertFalse( $string_result, 'send_email() string-name branch must remain status-gated.' );
		} finally {
			wp_update_post(
				[
					'ID'          => $test_email['post_id'],
					'post_status' => $original_status,
				]
			);
		}
	}

	/**
	 * Login helper: create a fresh administrator user, set them
	 * as current, return state needed for cleanup. Tests that
	 * call send_test_email need to pair this with logout_admin()
	 * in a try/finally block.
	 *
	 * @return array{0: int, 1: int} [ admin user id, previously-current user id ]
	 */
	private static function login_as_admin() {
		$admin_id = wp_insert_user(
			[
				'user_login' => 'nppd1547_admin_' . uniqid(),
				'user_pass'  => 'test-password',
				'user_email' => 'admin-' . uniqid() . '@example.test',
				'role'       => 'administrator',
			]
		);
		$prev_user = get_current_user_id();
		wp_set_current_user( $admin_id );
		return [ $admin_id, $prev_user ];
	}

	/**
	 * Restore the previously-current user and delete the admin
	 * created by login_as_admin().
	 *
	 * @param array{0: int, 1: int} $session State returned by login_as_admin.
	 */
	private static function logout_admin( $session ) {
		[ $admin_id, $prev_user ] = $session;
		wp_set_current_user( $prev_user );
		wp_delete_user( $admin_id );
	}
}
