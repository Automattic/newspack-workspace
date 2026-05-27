<?php
/**
 * Mock Integration.
 *
 * @package Newspack\Tests\Unit\Integrations
 */

use Newspack\Reader_Activation\Integration;

/**
 * Concrete test implementation of Integration.
 */
class Sample_Integration extends Integration {
	/**
	 * Captured handler arguments from data event dispatch.
	 *
	 * @var array|null
	 */
	public static $handler_args = null;

	/**
	 * Menu item declaration returned by get_my_account_menu_item().
	 *
	 * @var array|null
	 */
	public $my_account_menu_item = null;

	/**
	 * Captured render_my_account_page() calls.
	 *
	 * @var array
	 */
	public $my_account_render_calls = [];

	/**
	 * Settings fields to return from register_settings_fields(). Tests that
	 * need declared fields set this before constructing the integration.
	 *
	 * @var array
	 */
	public static $declared_settings_fields = [];

	/**
	 * Register settings fields (test implementation).
	 */
	public function register_settings_fields() {
		return self::$declared_settings_fields;
	}

	/**
	 * Push contact data (test implementation).
	 *
	 * @param array      $contact The contact data.
	 * @param string     $context The sync context.
	 * @param array|null $existing_contact Existing contact data if available.
	 * @return true
	 */
	public function push_contact_data( $contact, $context = '', $existing_contact = null ) {
		return true;
	}

	/**
	 * Pull contact data (test implementation).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array
	 */
	public function pull_contact_data( $user_id ) {
		return [];
	}

	/**
	 * Whether contacts can be synced to the ESP.
	 *
	 * @param bool $return_errors Optional. Whether to return a WP_Error object. Default false.
	 *
	 * @return bool|WP_Error True if contacts can be synced, false otherwise. WP_Error if return_errors is true.
	 */
	public function can_sync( $return_errors = false ) {
		return $return_errors ? new \WP_Error() : true;
	}

	/**
	 * Register a data event handler (public wrapper for testing).
	 *
	 * @param string $action_name The data event action name.
	 * @param string $method      The instance method to call.
	 */
	public function test_register_handler( $action_name, $method ) {
		$this->register_handler( $action_name, $method );
	}

	/**
	 * Sanitize a settings field value (public wrapper for testing).
	 *
	 * @param array $field The field declaration.
	 * @param mixed $value The value to sanitize.
	 * @return mixed
	 */
	public function test_sanitize_settings_field_value( $field, $value ) {
		return $this->sanitize_settings_field_value( $field, $value );
	}

	/**
	 * Sample handler method for data events.
	 *
	 * @param int    $timestamp Timestamp.
	 * @param array  $data      Data.
	 * @param string $client_id Client ID.
	 */
	public function handle_test_event( $timestamp, $data, $client_id ) {
		self::$handler_args = [
			'timestamp' => $timestamp,
			'data'      => $data,
			'client_id' => $client_id,
		];
	}

	/**
	 * Reset captured state between tests.
	 */
	public static function reset() {
		self::$handler_args            = null;
		self::$declared_settings_fields = [];
	}

	/**
	 * Get incoming available contact fields from the integration.
	 *
	 * @return Integrations\Incoming_Field[]|\WP_Error Array of incoming contact field objects or WP_Error on failure.
	 */
	public function get_available_incoming_fields() {
		return [];
	}

	/**
	 * Return the configured My Account menu item (test override).
	 *
	 * @return array|null
	 */
	public function get_my_account_menu_item() {
		return $this->my_account_menu_item;
	}

	/**
	 * Capture render_my_account_page() calls (test override).
	 *
	 * @param mixed $value Endpoint query var value.
	 */
	public function render_my_account_page( $value ) {
		$this->my_account_render_calls[] = $value;
	}
}
