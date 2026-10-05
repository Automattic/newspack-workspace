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

		// Same for a value that is only separators: it parses to zero
		// recipient tokens, so it must not defeat the short-circuit.
		reset_phpmailer_instance();
		$result = wp_mail( 'someuser@example.com', 'Test subject', 'Test body', [ 'Cc: ,' ] ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
		$this->assertTrue( $result, 'Suppressed mail must still report success despite a separators-only Cc header.' );
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

	/**
	 * Creating a Guest Contributor with an empty email must clear the
	 * invalid_email error WordPress 7.0.3+ adds before the
	 * user_profile_update_errors action fires, and assign the placeholder.
	 */
	public function test_user_profile_update_errors_clears_invalid_email_for_empty_email() {
		$_POST['user_login'] = 'Empty Email GC';
		$_POST['email']      = '';

		// WordPress 7.0.3+ adds both errors for an empty submission: invalid_email
		// at assignment, empty_email later because user_email is never set.
		$errors = new WP_Error();
		$errors->add( 'invalid_email', 'The email address is not correct.', [ 'form-field' => 'email' ] );
		$errors->add( 'empty_email', 'Please enter an email address.', [ 'form-field' => 'email' ] );

		$user             = new stdClass();
		$user->role       = Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME;
		$user->user_login = 'Empty Email GC';

		Guest_Contributor_Role::user_profile_update_errors( $errors, false, $user );

		$this->assertEmpty( $errors->get_error_messages( 'invalid_email' ), 'The invalid_email error must be cleared for an empty submission.' );
		$this->assertEmpty( $errors->get_error_messages( 'empty_email' ), 'The empty_email error must be cleared for an empty submission.' );
		$this->assertSame( 'empty-email-gc@' . Guest_Contributor_Role::get_dummy_email_domain(), $user->user_email );
	}

	/**
	 * A whitespace-only email submission is an empty submission: the
	 * invalid_email error must be cleared and the placeholder assigned.
	 */
	public function test_user_profile_update_errors_clears_invalid_email_for_whitespace_email() {
		$_POST['user_login'] = 'Whitespace Email GC';
		$_POST['email']      = '   ';

		$errors = new WP_Error();
		$errors->add( 'invalid_email', 'The email address is not correct.', [ 'form-field' => 'email' ] );
		$errors->add( 'empty_email', 'Please enter an email address.', [ 'form-field' => 'email' ] );

		$user             = new stdClass();
		$user->role       = Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME;
		$user->user_login = 'Whitespace Email GC';

		Guest_Contributor_Role::user_profile_update_errors( $errors, false, $user );

		$this->assertEmpty( $errors->get_error_messages( 'invalid_email' ), 'The invalid_email error must be cleared for a whitespace-only submission.' );
		$this->assertEmpty( $errors->get_error_messages( 'empty_email' ), 'The empty_email error must be cleared for a whitespace-only submission.' );
		$this->assertSame( 'whitespace-email-gc@' . Guest_Contributor_Role::get_dummy_email_domain(), $user->user_email );
	}

	/**
	 * A malformed, non-empty email submission must keep the invalid_email
	 * error: the clearing is scoped to empty submissions only.
	 */
	public function test_user_profile_update_errors_keeps_invalid_email_for_malformed_email() {
		$_POST['user_login'] = 'Malformed Email GC';
		$_POST['email']      = 'not-an-address';

		// WordPress 7.0.3+ adds both errors for a malformed submission too:
		// the assignment fails, so user_email stays unset and the later
		// empty-check also fires. Seed both to mirror the production state.
		$errors = new WP_Error();
		$errors->add( 'invalid_email', 'The email address is not correct.', [ 'form-field' => 'email' ] );
		$errors->add( 'empty_email', 'Please enter an email address.', [ 'form-field' => 'email' ] );

		$user             = new stdClass();
		$user->role       = Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME;
		$user->user_login = 'Malformed Email GC';

		Guest_Contributor_Role::user_profile_update_errors( $errors, false, $user );

		$this->assertNotEmpty( $errors->get_error_messages( 'invalid_email' ), 'A malformed, non-empty submission must still fail validation.' );
	}

	/**
	 * The invalid_email clearing is scoped to the Guest Contributor role:
	 * other roles keep core validation untouched.
	 */
	public function test_user_profile_update_errors_keeps_invalid_email_for_other_roles() {
		$_POST['user_login'] = 'Regular Author';
		$_POST['email']      = '';

		$errors = new WP_Error();
		$errors->add( 'invalid_email', 'The email address is not correct.', [ 'form-field' => 'email' ] );

		$user             = new stdClass();
		$user->role       = 'author';
		$user->user_login = 'Regular Author';

		Guest_Contributor_Role::user_profile_update_errors( $errors, false, $user );

		$this->assertNotEmpty( $errors->get_error_messages( 'invalid_email' ), 'Non-Guest-Contributor roles must keep core email validation.' );
	}

	/**
	 * Blanking the email on an existing Guest Contributor (the update path)
	 * must clear the invalid_email error and assign the placeholder from the
	 * existing login, without regenerating the login.
	 */
	public function test_user_profile_update_errors_clears_invalid_email_on_update() {
		$_POST['email'] = '';

		$errors = new WP_Error();
		$errors->add( 'invalid_email', 'The email address is not correct.', [ 'form-field' => 'email' ] );
		$errors->add( 'empty_email', 'Please enter an email address.', [ 'form-field' => 'email' ] );

		$user             = new stdClass();
		$user->role       = Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME;
		$user->user_login = 'existing-contributor';

		Guest_Contributor_Role::user_profile_update_errors( $errors, true, $user );

		$this->assertEmpty( $errors->get_error_messages( 'invalid_email' ), 'The invalid_email error must be cleared when blanking an existing Guest Contributor email.' );
		$this->assertSame( 'existing-contributor', $user->user_login, 'The update path must not regenerate the login.' );
		$this->assertSame( 'existing-contributor@' . Guest_Contributor_Role::get_dummy_email_domain(), $user->user_email );
	}

	/**
	 * A submission that only sanitization would empty — stray markup like
	 * <b></b>, or an address pasted with angle brackets — is not an empty
	 * submission. Emptiness is judged on the raw value, so these keep
	 * failing validation instead of silently becoming a placeholder.
	 */
	public function test_user_profile_update_errors_keeps_invalid_email_for_markup_only_email() {
		$_POST['user_login'] = 'Markup Email GC';
		$_POST['email']      = '<b></b>';

		$errors = new WP_Error();
		$errors->add( 'invalid_email', 'The email address is not correct.', [ 'form-field' => 'email' ] );
		$errors->add( 'empty_email', 'Please enter an email address.', [ 'form-field' => 'email' ] );

		$user             = new stdClass();
		$user->role       = Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME;
		$user->user_login = 'Markup Email GC';

		Guest_Contributor_Role::user_profile_update_errors( $errors, false, $user );

		$this->assertNotEmpty( $errors->get_error_messages( 'invalid_email' ), 'A submission that only sanitization empties must still fail validation.' );
	}

	/**
	 * A non-string email payload (e.g. email[]=… from a mis-built form) is
	 * rejected by WordPress 7.0.3+ with invalid_email; it must not be
	 * treated as an empty submission.
	 */
	public function test_user_profile_update_errors_keeps_invalid_email_for_array_email() {
		$_POST['user_login'] = 'Array Email GC';
		$_POST['email']      = [ 'jane@paper.com' ];

		$errors = new WP_Error();
		$errors->add( 'invalid_email', 'The email address is not correct.', [ 'form-field' => 'email' ] );

		$user             = new stdClass();
		$user->role       = Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME;
		$user->user_login = 'Array Email GC';

		Guest_Contributor_Role::user_profile_update_errors( $errors, false, $user );

		$this->assertNotEmpty( $errors->get_error_messages( 'invalid_email' ), 'A non-string email payload must still fail validation.' );
	}

	/**
	 * A Guest Contributor submitted with a valid email keeps it: no errors
	 * are added and the address is not replaced by a placeholder.
	 */
	public function test_user_profile_update_errors_leaves_valid_email_untouched() {
		$_POST['user_login'] = 'Valid Email GC';
		$_POST['email']      = 'jane@paper.com';

		$errors = new WP_Error();

		$user             = new stdClass();
		$user->role       = Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME;
		$user->user_login = 'Valid Email GC';
		$user->user_email = 'jane@paper.com';

		Guest_Contributor_Role::user_profile_update_errors( $errors, false, $user );

		$this->assertEmpty( $errors->get_error_codes(), 'No errors may be introduced for a valid submission.' );
		$this->assertSame( 'jane@paper.com', $user->user_email, 'A valid email must not be replaced by a placeholder.' );
	}

	/**
	 * End-to-end through core: edit_user() on the running WordPress version
	 * creates a Guest Contributor with an empty email and assigns the
	 * placeholder, while a control role still fails. Guards against core
	 * changing this validation surface again (the NPPM-3124 class of change).
	 */
	public function test_edit_user_creates_guest_contributor_with_empty_email() {
		Guest_Contributor_Role::setup_custom_role_and_capability();
		$admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );
		require_once ABSPATH . 'wp-admin/includes/user.php';

		// The test framework clears $_POST in set_up(), so assign directly —
		// reading the superglobal (e.g. via array_merge) trips the
		// nonce-verification sniff, and there is nothing to merge with.
		$_POST = [
			'action'     => 'createuser',
			'user_login' => 'Integration GC',
			'email'      => '',
			'first_name' => '',
			'last_name'  => '',
			'url'        => '',
			'pass1'      => 'S0me-Str0ng-Pass!',
			'pass2'      => 'S0me-Str0ng-Pass!',
			'role'       => Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME,
		];

		$result = edit_user();
		$this->assertIsInt( $result, 'edit_user() must create a Guest Contributor with an empty email on the running core version.' );
		$user = get_user_by( 'id', $result );
		$this->assertSame( 'integration-gc@' . Guest_Contributor_Role::get_dummy_email_domain(), $user->user_email );

		// Control: a non-Guest-Contributor role with an empty email must still fail.
		$_POST['user_login'] = 'Integration Control';
		$_POST['role']       = 'subscriber';
		$control             = edit_user();
		$this->assertWPError( $control, 'A subscriber with an empty email must still fail core validation.' );
	}
}
