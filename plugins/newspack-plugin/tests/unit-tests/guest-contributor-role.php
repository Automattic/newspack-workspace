<?php
/**
 * Tests the Guest_Contributor_Role.
 *
 * @package Newspack\Tests
 */

use Newspack\Guest_Contributor_Role;

/**
 * Tests the Guest_Contributor_Role.
 */
class Newspack_Test_Guest_Contributor_Role extends WP_UnitTestCase {

	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::set_up();
		wp_reset_postdata();
		// Pin the outbound-mail guard active so the mail tests are independent
		// of ambient environment state (constant or env var). The gate itself
		// is covered directly by test_mail_guard_environment_gate, and
		// parent::set_up() arms per-test hook restoration, so this pin cannot
		// leak beyond each test.
		add_filter( 'newspack_guest_author_mail_guard_active', '__return_true' );
	}

	/**
	 * On a post with author.
	 */
	public function test_guest_contributor_role_get_dummy_email() {
		$email_domain = Guest_Contributor_Role::get_dummy_email_domain();
		$user = get_userdata( 1 );

		// Mirror the sanitization in get_dummy_email_address() — user_login could contain @.
		$expected = str_replace( '@', '', $user->user_login ) . '@' . $email_domain;

		$dummy_email = Guest_Contributor_Role::get_dummy_email_address( $user );
		$this->assertSame( $expected, $dummy_email );

		$dummy_email = Guest_Contributor_Role::get_dummy_email_address( $user->user_login );
		$this->assertSame( $expected, $dummy_email );
	}

	/**
	 * Test that @ in user_login is stripped when generating dummy email.
	 */
	public function test_guest_contributor_role_get_dummy_email_with_at_in_login() {
		$email_domain = Guest_Contributor_Role::get_dummy_email_domain();

		$user             = new stdClass();
		$user->user_login = 'legacy-author@old-domain.com';

		$expected = 'legacy-authorold-domain.com@' . $email_domain;

		$dummy_email = Guest_Contributor_Role::get_dummy_email_address( $user );
		$this->assertSame( $expected, $dummy_email );

		$dummy_email = Guest_Contributor_Role::get_dummy_email_address( $user->user_login );
		$this->assertSame( $expected, $dummy_email );
	}

	/**
	 * On a post with author.
	 */
	public function test_guest_contributor_role_dummy_email_hiding_default() {
		$email_domain = Guest_Contributor_Role::get_dummy_email_domain();
		$user_id = \wp_insert_user(
			[
				'user_login' => 'guest-contributor',
				'user_pass'  => '123',
				'user_email' => 'guest-contributor@' . $email_domain,
				'role'       => Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME,
			]
		);
		$post_id = \wp_insert_post(
			[
				'post_title'  => 'Title',
				'post_status' => 'publish',
				'post_author' => $user_id,
			]
		);
		global $wp_query;
		$wp_query = new WP_Query(
			[
				'p' => $post_id,
			]
		);
		$post = get_post( $post_id );
		setup_postdata( $post );

		self::assertEquals(
			Guest_Contributor_Role::should_display_author_email( true ),
			false,
			'Email should be hidden for a Guest Contributor with a dummy email.'
		);

		// Update the user's email address.
		\wp_update_user(
			[
				'ID'         => $user_id,
				'user_email' => 'guest-contributor@legit-domain.com',
			]
		);
		self::assertEquals(
			Guest_Contributor_Role::should_display_author_email( true ),
			true,
			'Email should be displayed for a Guest Contributor with a regular email.'
		);
	}

	/**
	 * On a post with no author.
	 */
	public function test_guest_contributor_role_dummy_email_hiding_no_author() {
		global $wp_query;
		$wp_query->is_singular = true;
		$should_hide = Guest_Contributor_Role::should_display_author_email( true );
		self::assertEquals( null, get_the_author_meta( 'ID' ) );
		self::assertEquals(
			true,
			$should_hide,
			'Function should run successfully even if post apparently has no author. This can happen with co-authors-plus Guest Authors.'
		);
	}

	/**
	 * Test should_display_coauthor_email returns false for guest contributors with dummy emails.
	 */
	public function test_should_display_coauthor_email_with_dummy_email() {
		$email_domain = Guest_Contributor_Role::get_dummy_email_domain();
		$user_id = \wp_insert_user(
			[
				'user_login' => 'guest-coauthor-1',
				'user_pass'  => '123',
				'user_email' => 'guest-coauthor-1@' . $email_domain,
				'role'       => Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME,
			]
		);

		self::assertEquals(
			false,
			Guest_Contributor_Role::should_display_coauthor_email( true, $user_id ),
			'Email should be hidden for a Guest Contributor with a dummy email.'
		);
	}

	/**
	 * Test should_display_coauthor_email returns true for guest contributors with real emails.
	 */
	public function test_should_display_coauthor_email_with_real_email() {
		$user_id = \wp_insert_user(
			[
				'user_login' => 'guest-coauthor-2',
				'user_pass'  => '123',
				'user_email' => 'guest-coauthor-2@real-domain.com',
				'role'       => Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME,
			]
		);

		self::assertEquals(
			true,
			Guest_Contributor_Role::should_display_coauthor_email( true, $user_id ),
			'Email should be displayed for a Guest Contributor with a real email.'
		);
	}

	/**
	 * Test should_display_coauthor_email returns false when value is already false.
	 */
	public function test_should_display_coauthor_email_respects_false_value() {
		$email_domain = Guest_Contributor_Role::get_dummy_email_domain();
		$user_id = \wp_insert_user(
			[
				'user_login' => 'guest-coauthor-3',
				'user_pass'  => '123',
				'user_email' => 'guest-coauthor-3@real-domain.com',
				'role'       => Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME,
			]
		);

		self::assertEquals(
			false,
			Guest_Contributor_Role::should_display_coauthor_email( false, $user_id ),
			'Email should remain hidden when value is already false, even with real email.'
		);
	}

	/**
	 * Test should_display_coauthor_email returns true for regular users.
	 */
	public function test_should_display_coauthor_email_for_regular_user() {
		$user_id = \wp_insert_user(
			[
				'user_login' => 'regular-author',
				'user_pass'  => '123',
				'user_email' => 'regular-author@domain.com',
				'role'       => 'author',
			]
		);

		self::assertEquals(
			true,
			Guest_Contributor_Role::should_display_coauthor_email( true, $user_id ),
			'Email should be displayed for regular users without the guest contributor role.'
		);
	}

	/**
	 * Test should_display_coauthor_email with user having multiple roles including guest contributor.
	 */
	public function test_should_display_coauthor_email_with_multiple_roles() {
		$email_domain = Guest_Contributor_Role::get_dummy_email_domain();
		$user_id = \wp_insert_user(
			[
				'user_login' => 'multi-role-user',
				'user_pass'  => '123',
				'user_email' => 'multi-role@' . $email_domain,
				'role'       => 'author',
			]
		);

		$user = get_userdata( $user_id );
		$user->add_role( Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME );

		self::assertEquals(
			false,
			Guest_Contributor_Role::should_display_coauthor_email( true, $user_id ),
			'Email should be hidden for users with guest contributor role and dummy email, even if they have other roles.'
		);
	}

	/**
	 * Test should_display_author_email respects false value.
	 */
	public function test_should_display_author_email_respects_false_value() {
		$email_domain = Guest_Contributor_Role::get_dummy_email_domain();
		$user_id = \wp_insert_user(
			[
				'user_login' => 'guest-author-false',
				'user_pass'  => '123',
				'user_email' => 'guest-author-false@' . $email_domain,
				'role'       => Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME,
			]
		);
		$post_id = \wp_insert_post(
			[
				'post_title'  => 'Test Post',
				'post_status' => 'publish',
				'post_author' => $user_id,
			]
		);
		global $wp_query;
		$wp_query = new WP_Query(
			[
				'p' => $post_id,
			]
		);
		$post = get_post( $post_id );
		setup_postdata( $post );

		self::assertEquals(
			false,
			Guest_Contributor_Role::should_display_author_email( false ),
			'should_display_author_email should return false when value is already false.'
		);
	}

	/**
	 * Test should_display_author_email with user having multiple roles including guest contributor.
	 */
	public function test_should_display_author_email_with_multiple_roles() {
		$email_domain = Guest_Contributor_Role::get_dummy_email_domain();
		$user_id = \wp_insert_user(
			[
				'user_login' => 'multi-role-author',
				'user_pass'  => '123',
				'user_email' => 'multi-role-author@' . $email_domain,
				'role'       => 'author',
			]
		);

		$user = get_userdata( $user_id );
		$user->add_role( Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME );

		$post_id = \wp_insert_post(
			[
				'post_title'  => 'Multi Role Post',
				'post_status' => 'publish',
				'post_author' => $user_id,
			]
		);
		global $wp_query;
		$wp_query = new WP_Query(
			[
				'p' => $post_id,
			]
		);
		$post = get_post( $post_id );
		setup_postdata( $post );

		self::assertEquals(
			false,
			Guest_Contributor_Role::should_display_author_email( true ),
			'Email should be hidden for users with guest contributor role and dummy email, even if they have other roles.'
		);
	}

	/**
	 * Test should_display_author_email returns true when not on author or singular page.
	 */
	public function test_should_display_author_email_not_on_author_or_singular() {
		$email_domain = Guest_Contributor_Role::get_dummy_email_domain();
		$user_id = \wp_insert_user(
			[
				'user_login' => 'guest-home-page',
				'user_pass'  => '123',
				'user_email' => 'guest-home@' . $email_domain,
				'role'       => Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME,
			]
		);

		global $wp_query;
		$wp_query = new WP_Query();
		$wp_query->is_home = true;

		self::assertEquals(
			true,
			Guest_Contributor_Role::should_display_author_email( true ),
			'Email should not be filtered when not on author or singular pages.'
		);
	}

	/**
	 * Test is_dummy_email_address identifies dummy emails correctly.
	 */
	public function test_is_dummy_email_address() {
		$email_domain = Guest_Contributor_Role::get_dummy_email_domain();

		self::assertTrue(
			Guest_Contributor_Role::is_dummy_email_address( 'test@' . $email_domain ),
			'Should identify dummy email with default domain.'
		);

		self::assertFalse(
			Guest_Contributor_Role::is_dummy_email_address( 'test@real-domain.com' ),
			'Should not identify real email as dummy.'
		);
	}

	/**
	 * Outbound mail to generated dummy addresses must be suppressed entirely.
	 */
	public function test_mail_to_dummy_address_is_blocked() {
		reset_phpmailer_instance();
		$result = wp_mail( 'someuser@example.com', 'Test subject', 'Test body' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
		$this->assertTrue( $result, 'Suppressed mail must still report success to callers.' );
		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertEmpty( $mailer->get_sent(), 'No email should be dispatched to a dummy address.' );
	}

	/**
	 * Mixed recipient lists keep real addresses and drop dummy ones.
	 */
	public function test_mail_to_mixed_recipients_drops_only_dummy() {
		reset_phpmailer_instance();
		wp_mail( [ 'real@realdomain.org', 'fake@example.com' ], 'Test subject', 'Test body' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
		$mailer = tests_retrieve_phpmailer_instance();
		$sent   = $mailer->get_sent();
		$this->assertNotEmpty( $sent, 'Mail with at least one real recipient must still send.' );
		$recipients = array_map(
			function ( $recipient ) {
				return $recipient[0];
			},
			$sent->to
		);
		$this->assertContains( 'real@realdomain.org', $recipients );
		$this->assertNotContains( 'fake@example.com', $recipients );
	}

	/**
	 * Ordinary mail is untouched by the guard.
	 */
	public function test_mail_to_real_address_is_sent() {
		reset_phpmailer_instance();
		wp_mail( 'reader@realdomain.org', 'Test subject', 'Test body' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertNotEmpty( $mailer->get_sent() );
	}

	/**
	 * The dummy-domain match must be end-anchored: an address on a domain that
	 * merely starts with "example.com" is not a dummy address.
	 */
	public function test_is_dummy_email_address_end_anchored() {
		$this->assertTrue( Guest_Contributor_Role::is_dummy_email_address( 'someone@example.com' ) );
		$this->assertFalse( Guest_Contributor_Role::is_dummy_email_address( 'user@example.company.com' ) );
	}

	/**
	 * A trailing comma (empty list entry) must not defeat the suppression.
	 */
	public function test_mail_to_dummy_with_trailing_comma_is_blocked() {
		reset_phpmailer_instance();
		$result = wp_mail( 'someuser@example.com,', 'Test subject', 'Test body' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
		$this->assertTrue( $result, 'Suppressed mail must still report success despite empty list entries.' );
		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertEmpty( $mailer->get_sent() );
	}

	/**
	 * Mail with an all-dummy "to" but a real Cc header must NOT be suppressed —
	 * the Cc recipient's delivery is legitimate.
	 */
	public function test_all_dummy_to_with_cc_header_still_sends() {
		reset_phpmailer_instance();
		wp_mail( 'someuser@example.com', 'Test subject', 'Test body', [ 'Cc: real-cc@realdomain.org' ] ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
		$mailer = tests_retrieve_phpmailer_instance();
		$sent   = $mailer->get_sent();
		$this->assertNotEmpty( $sent, 'Mail with a Cc header must not be short-circuited.' );
		$cc = array_map(
			function ( $recipient ) {
				return $recipient[0];
			},
			$sent->cc
		);
		$this->assertContains( 'real-cc@realdomain.org', $cc );
		$to = array_map(
			function ( $recipient ) {
				return $recipient[0];
			},
			$sent->to
		);
		$this->assertNotContains( 'someuser@example.com', $to, 'The dummy to-recipient must still be stripped.' );
	}

	/**
	 * The guard's filter registrations are a contract: the pre_wp_mail
	 * short-circuit runs at priority 1, before mailer plugins' own callbacks
	 * (typically priority 10) could dispatch an all-dummy send. Registration
	 * is unconditional, so these hold regardless of environment; suppression
	 * behavior is pinned active for this file's mail tests in set_up().
	 */
	public function test_mail_guard_filter_priorities() {
		$this->assertSame( 1, has_filter( 'pre_wp_mail', [ Guest_Contributor_Role::class, 'short_circuit_dummy_only_email' ] ) );
		$this->assertSame( 10, has_filter( 'wp_mail', [ Guest_Contributor_Role::class, 'remove_dummy_email_recipients' ] ) );
	}

	/**
	 * The activity filter controls suppression at send time: forced inactive,
	 * placeholder mail delivers; with the set_up() pin back in effect, it is
	 * suppressed. try/finally keeps a mid-test failure from leaking the
	 * override into later tests when this file runs on its own.
	 */
	public function test_mail_guard_activity_filter_controls_suppression() {
		add_filter( 'newspack_guest_author_mail_guard_active', '__return_false', 20 );
		try {
			reset_phpmailer_instance();
			wp_mail( 'someuser@example.com', 'Test subject', 'Test body' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
			$this->assertNotEmpty( tests_retrieve_phpmailer_instance()->get_sent(), 'With the guard inactive, placeholder mail must deliver.' );
		} finally {
			remove_filter( 'newspack_guest_author_mail_guard_active', '__return_false', 20 );
		}

		reset_phpmailer_instance();
		$result = wp_mail( 'someuser@example.com', 'Test subject', 'Test body' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
		$this->assertTrue( $result, 'With the guard active again, suppressed mail must report success.' );
		$this->assertEmpty( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	/**
	 * The guard is active everywhere except local and development
	 * environments, where mail terminates in a capture tool instead of
	 * bouncing.
	 */
	public function test_mail_guard_environment_gate() {
		$this->assertFalse( Guest_Contributor_Role::is_mail_guard_active_for_environment( 'local' ) );
		$this->assertFalse( Guest_Contributor_Role::is_mail_guard_active_for_environment( 'development' ) );
		$this->assertTrue( Guest_Contributor_Role::is_mail_guard_active_for_environment( 'staging' ) );
		$this->assertTrue( Guest_Contributor_Role::is_mail_guard_active_for_environment( 'production' ) );
	}

	/**
	 * Recipients in "Name <address>" form are matched on the address, both
	 * for the all-dummy short-circuit and for mixed-list stripping.
	 */
	public function test_mail_guard_handles_display_name_recipients() {
		reset_phpmailer_instance();
		$result = wp_mail( 'Guest Author <someuser@example.com>', 'Test subject', 'Test body' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
		$this->assertTrue( $result, 'Suppressed mail must still report success to callers.' );
		$this->assertEmpty( tests_retrieve_phpmailer_instance()->get_sent(), 'A display-name placeholder recipient must be suppressed.' );

		reset_phpmailer_instance();
		wp_mail( [ 'Real Person <real@realdomain.org>', 'Guest Author <fake@example.com>' ], 'Test subject', 'Test body' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
		$sent = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertNotEmpty( $sent, 'Mail with at least one real recipient must still send.' );
		$recipients = array_map(
			function ( $recipient ) {
				return $recipient[0];
			},
			$sent->to
		);
		$this->assertContains( 'real@realdomain.org', $recipients );
		$this->assertNotContains( 'fake@example.com', $recipients );

		// Parity with core's greedy recipient parse: a quoted display name
		// containing angle brackets still resolves to the dispatched address.
		reset_phpmailer_instance();
		$result = wp_mail( '"a<b>c" <someuser@example.com>', 'Test subject', 'Test body' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
		$this->assertTrue( $result, 'Suppressed mail must still report success to callers.' );
		$this->assertEmpty( tests_retrieve_phpmailer_instance()->get_sent(), 'The address core dispatches to is the one the guard judges.' );
	}

	/**
	 * A bare Cc/Bcc header with no value carries no recipients, so an
	 * all-placeholder To must still be suppressed and reported as sent — not
	 * passed through to fail on an empty recipient list.
	 */
	public function test_all_dummy_to_with_empty_cc_header_is_suppressed() {
		reset_phpmailer_instance();
		$result = wp_mail( 'someuser@example.com', 'Test subject', 'Test body', [ 'Cc:' ] ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
		$this->assertTrue( $result, 'Suppressed mail must still report success despite an empty Cc header.' );
		$this->assertEmpty( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	/**
	 * Mail with an all-dummy "to" but a real Bcc header must NOT be
	 * suppressed — the Bcc recipient's delivery is legitimate.
	 */
	public function test_all_dummy_to_with_bcc_header_still_sends() {
		reset_phpmailer_instance();
		wp_mail( 'someuser@example.com', 'Test subject', 'Test body', [ 'Bcc: real-bcc@realdomain.org' ] ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
		$mailer = tests_retrieve_phpmailer_instance();
		$sent   = $mailer->get_sent();
		$this->assertNotEmpty( $sent, 'Mail with a Bcc header must not be short-circuited.' );
		$bcc = array_map(
			function ( $recipient ) {
				return $recipient[0];
			},
			$sent->bcc
		);
		$this->assertContains( 'real-bcc@realdomain.org', $bcc );
		$to = array_map(
			function ( $recipient ) {
				return $recipient[0];
			},
			$sent->to
		);
		$this->assertNotContains( 'someuser@example.com', $to, 'The dummy to-recipient must still be stripped.' );
	}

	/**
	 * The placeholder match is case-insensitive — a widening introduced with
	 * the end-anchored match, pinned here as intended behavior.
	 */
	public function test_is_dummy_email_address_case_insensitive() {
		$this->assertTrue( Guest_Contributor_Role::is_dummy_email_address( 'Foo@EXAMPLE.COM' ) );

		reset_phpmailer_instance();
		$result = wp_mail( 'Foo@EXAMPLE.COM', 'Test subject', 'Test body' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
		$this->assertTrue( $result, 'Suppressed mail must still report success to callers.' );
		$this->assertEmpty( tests_retrieve_phpmailer_instance()->get_sent(), 'An uppercase placeholder recipient must be suppressed.' );
	}

	/**
	 * Suppression follows the filterable dummy domain: with a custom domain
	 * set, mail to it is suppressed and mail to the default domain flows.
	 * The other tests hard-code the default domain deliberately, pinning the
	 * publisher-facing default; this one exercises the filter axis.
	 */
	public function test_mail_guard_follows_filtered_dummy_domain() {
		$set_domain = function () {
			return 'placeholder.invalid';
		};
		add_filter( 'newspack_guest_author_email_domain', $set_domain );

		try {
			$this->assertTrue( Guest_Contributor_Role::is_dummy_email_address( 'someuser@placeholder.invalid' ) );
			$this->assertFalse( Guest_Contributor_Role::is_dummy_email_address( 'someuser@example.com' ) );

			reset_phpmailer_instance();
			$result = wp_mail( 'someuser@placeholder.invalid', 'Test subject', 'Test body' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
			$this->assertTrue( $result, 'Mail to the filtered placeholder domain must be suppressed.' );
			$this->assertEmpty( tests_retrieve_phpmailer_instance()->get_sent() );

			reset_phpmailer_instance();
			wp_mail( 'someuser@example.com', 'Test subject', 'Test body' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
			$this->assertNotEmpty( tests_retrieve_phpmailer_instance()->get_sent(), 'With a custom placeholder domain, default-domain mail must flow.' );
		} finally {
			remove_filter( 'newspack_guest_author_email_domain', $set_domain );
		}
	}
}
