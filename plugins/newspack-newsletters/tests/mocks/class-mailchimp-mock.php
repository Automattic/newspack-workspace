<?php // phpcs:ignore WordPress.Files.FileName

// phpcs:disable Generic.Classes.DuplicateClassName.Found

/**
 * Mocks the MailChimp class.
 *
 * Note: This works because this file is explictitly included in the bootstrap.php file.
 * When this class is invoked, it already exists, so Composer's autoload never loads the real class.
 */
class Newspack_Newsletters_Mailchimp_Api {

	/**
	 * Init.
	 */
	public static function init() {
		add_filter( 'newspack_mailchimp_merge_fields', [ __CLASS__, 'mock_merge_fields' ] );
	}

	/**
	 * Remove mock filters.
	 */
	public static function remove_filters() {
		echo 'removing filter';
		remove_filter( 'newspack_mailchimp_merge_fields', [ __CLASS__, 'mock_merge_fields' ] );
	}

	/**
	 * Mock merge fields payload so that we can test the MailChimp API.
	 */
	public static function mock_merge_fields() {
		return [
			'FNAME' => 'Contact First Name',
			'LNAME' => 'Contact Last Name',
		];
	}

	/**
	 * Whether the last request was successful.
	 *
	 * @var bool
	 */
	private static $mock_success = true;

	/**
	 * The last error message.
	 *
	 * @var string
	 */
	private static $mock_last_error = '';

	/**
	 * Constructor (no-op for mock).
	 *
	 * @param string $api_key      API key.
	 * @param string $api_endpoint Optional endpoint.
	 */
	public function __construct( $api_key = '', $api_endpoint = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, Squiz.Commenting.FunctionComment.Missing
	}

	/**
	 * Set mock success state.
	 *
	 * @param bool   $success    Whether the request was successful.
	 * @param string $last_error The error message when not successful.
	 */
	public static function set_mock_success( $success, $last_error = '' ) {
		self::$mock_success    = $success;
		self::$mock_last_error = $last_error;
	}

	/**
	 * Was the last request successful?
	 *
	 * @return bool
	 */
	public function success() {
		return self::$mock_success;
	}

	/**
	 * Get the last error.
	 *
	 * @return string|false
	 */
	public function getLastError() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return self::$mock_last_error ? self::$mock_last_error : false;
	}

	/**
	 * Can use the mock API?
	 */
	public static function is_api_configured() {
		return get_option( 'newspack_mailchimp_api_key', false );
	}

	public static function get( $endpoint, $args = [] ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		if ( ! self::is_api_configured() ) {
			return [];
		}

		return apply_filters( 'mailchimp_mock_get', [], $endpoint, $args );
	}

	public static function put( $endpoint, $args = [] ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		if ( ! self::is_api_configured() ) {
			return [];
		}

		return apply_filters( 'mailchimp_mock_put', [], $endpoint, $args );
	}

	public static function post( $endpoint, $args = [] ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		if ( ! self::is_api_configured() ) {
			return [];
		}

		return apply_filters( 'mailchimp_mock_post', [], $endpoint, $args );
	}

	/**
	 * Get the subscriber hash.
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	public static function subscriberHash( $email ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return md5( strtolower( $email ) );
	}
}

Newspack_Newsletters_Mailchimp_Api::init();
