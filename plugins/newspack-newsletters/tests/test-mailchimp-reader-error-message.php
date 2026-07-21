<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Tests the Mailchimp reader-facing error message customization (NPPM-2912).
 *
 * @package Newspack_Newsletters
 */

/**
 * Test the Mailchimp resubscribe (compliance-state) error message.
 *
 * @group mailchimp_reader_error
 */
class MailchimpReaderErrorMessageTest extends WP_UnitTestCase {
	const OPTION_NAME = 'newspack_newsletters_mailchimp_resubscribe_message';

	/**
	 * Set up before class.
	 */
	public static function wpSetUpBeforeClass() {
		update_option( 'newspack_mailchimp_api_key', 'test-us' );
	}

	/**
	 * Tear down after class.
	 */
	public static function wpTearDownAfterClass() {
		delete_option( 'newspack_mailchimp_api_key' );
	}

	/**
	 * Reset the option between tests.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( self::OPTION_NAME );
	}

	/**
	 * Get the provider's reader error message for a given raw error.
	 *
	 * @param WP_Error|null $raw_error The raw ESP error.
	 * @return string
	 */
	private static function get_message( $raw_error ) {
		$mailchimp = Newspack_Newsletters_Mailchimp::instance();
		return $mailchimp->get_reader_error_message( [ 'email' => 'reader@example.com' ], $raw_error );
	}

	/**
	 * Compliance-state errors show the default support-team message when no custom message is set.
	 */
	public function test_compliance_error_shows_default_message() {
		$message = self::get_message( new WP_Error( 'mc', 'Bad Request: Member In Compliance State' ) );
		self::assertSame(
			"We'll need to subscribe this email address manually. Please contact our support team.",
			$message
		);
	}

	/**
	 * A publisher-defined message replaces the default on compliance-state errors.
	 */
	public function test_compliance_error_shows_custom_message() {
		update_option( self::OPTION_NAME, 'Please <a href="https://example.com/signup">use our signup page</a> to resubscribe.' );
		$message = self::get_message( new WP_Error( 'mc', 'Bad Request: Member In Compliance State' ) );
		self::assertSame(
			'Please <a href="https://example.com/signup">use our signup page</a> to resubscribe.',
			$message,
			'The custom message, including HTML links, should be shown verbatim.'
		);
	}

	/**
	 * Stored markup is sanitized on output — scripts don't survive.
	 */
	public function test_custom_message_is_sanitized() {
		update_option( self::OPTION_NAME, 'Resubscribe <a href="https://example.com/s">here</a>.<script>alert(1)</script>' );
		$message = self::get_message( new WP_Error( 'mc', 'Member In Compliance State' ) );
		self::assertStringContainsString( '<a href="https://example.com/s">here</a>', $message );
		self::assertStringNotContainsString( '<script>', $message );
	}

	/**
	 * A whitespace-only saved message counts as empty — the default message shows.
	 */
	public function test_whitespace_only_message_falls_back_to_default() {
		update_option( self::OPTION_NAME, "  \n  " );
		$message = self::get_message( new WP_Error( 'mc', 'Member In Compliance State' ) );
		self::assertSame(
			"We'll need to subscribe this email address manually. Please contact our support team.",
			$message
		);
	}

	/**
	 * The custom message applies ONLY to compliance-state errors; other errors keep the generic default.
	 */
	public function test_custom_message_does_not_apply_to_other_errors() {
		update_option( self::OPTION_NAME, 'Custom resubscribe copy.' );
		$message = self::get_message( new WP_Error( 'mc', 'Invalid Resource' ) );
		self::assertSame(
			'Sorry, an error has occurred. Please try again later or contact us for support.',
			$message
		);
	}

	/**
	 * The filter output is sanitized at the choke point, whatever callback produced it.
	 */
	public function test_filter_output_is_sanitized_for_any_producer() {
		add_filter(
			'newspack_newsletters_add_contact_reader_error_message',
			function () {
				return 'Try <a href="https://example.com/s">this</a>.<script>alert(1)</script>';
			},
			99
		);
		$message = self::get_message( new WP_Error( 'mc', 'Invalid Resource' ) );
		self::assertStringContainsString( '<a href="https://example.com/s">this</a>', $message );
		self::assertStringNotContainsString( '<script>', $message );
	}

	/**
	 * A filter callback returning a non-string never breaks the sanitizer.
	 */
	public function test_non_string_filter_output_is_coerced() {
		add_filter( 'newspack_newsletters_add_contact_reader_error_message', '__return_null', 99 );
		$message = self::get_message( new WP_Error( 'mc', 'Invalid Resource' ) );
		remove_filter( 'newspack_newsletters_add_contact_reader_error_message', '__return_null', 99 );
		self::assertSame( '', $message );
	}

	/**
	 * The setting is registered in the settings list, scoped to Mailchimp, with a kses sanitizer.
	 */
	public function test_setting_is_registered() {
		$settings = Newspack_Newsletters_Settings::get_settings_list();
		$entry    = null;
		foreach ( $settings as $setting ) {
			if ( isset( $setting['key'] ) && self::OPTION_NAME === $setting['key'] ) {
				$entry = $setting;
				break;
			}
		}
		self::assertNotNull( $entry, 'The resubscribe message setting should be registered.' );
		self::assertSame( 'mailchimp', $entry['provider'] ?? null );
		self::assertSame( 'wp_kses_post', $entry['sanitize_callback'] ?? null );
		self::assertSame( 'textarea', $entry['type'] ?? null, 'A message with a link needs a multi-line field.' );
	}
}
