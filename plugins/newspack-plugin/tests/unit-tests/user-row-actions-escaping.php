<?php
/**
 * Tests that the Users-list row actions escape the reflected request URL.
 *
 * @package Newspack\Tests
 */

use Newspack\Magic_Link;
use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Contact_Sync_Admin;
use Newspack\Reader_Activation\Integrations;

require_once __DIR__ . '/integrations/class-sample-integration.php';

/**
 * Verify the magic-link and contact-sync row-action links escape
 * $_SERVER['REQUEST_URI'] before echoing it into an href.
 */
class Newspack_Test_User_Row_Actions_Escaping extends WP_UnitTestCase {

	/**
	 * A request URI whose literal double-quote breaks out of an unescaped href.
	 * `esc_url()` strips the `"><` sequence; the raw value keeps it.
	 */
	const PAYLOAD_URI = '/wp-admin/users.php"><svg/onload=NPPM3043>';

	/**
	 * Admin user id.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Enable reader activation and act as an admin before each test.
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'newspack_reader_activation_enabled', '__return_true' );
		// The row actions render on wp-admin/users.php; get_admin_action_url()
		// short-circuits unless is_admin() is true.
		set_current_screen( 'users' );
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Restore global state touched by these tests.
	 */
	public function tear_down() {
		$_SERVER['REQUEST_URI'] = '/';
		remove_filter( 'newspack_reader_activation_enabled', '__return_true' );
		remove_filter( 'newspack_reader_activation_is_syncing_allowed', '__return_true' );
		delete_option( Integrations::OPTION_NAME );
		$this->reset_integrations();
		Integrations::register_integrations();
		parent::tear_down();
	}

	/**
	 * Reset the static integrations registry so a test's fake integration does
	 * not leak into later tests.
	 */
	private function reset_integrations() {
		$reflection = new \ReflectionClass( Integrations::class );
		$property   = $reflection->getProperty( 'integrations' );
		$property->setAccessible( true );
		$property->setValue( null, [] );
	}

	/**
	 * The magic-link "Send authentication link" row action must escape the URL.
	 */
	public function test_magic_link_row_action_escapes_request_uri() {
		// register_reader() must run for a logged-out visitor; then act as the admin.
		wp_set_current_user( 0 );
		$reader_id = Reader_Activation::register_reader( 'reader-nppm3043@example.com', 'Reader NPPM3043' );
		wp_set_current_user( $this->admin_id );
		$reader = get_user_by( 'id', $reader_id );
		$this->assertInstanceOf( \WP_User::class, $reader, 'Precondition: the reader account was created.' );

		$_SERVER['REQUEST_URI'] = self::PAYLOAD_URI;
		$actions                = Magic_Link::user_row_actions( [], $reader );

		$this->assertArrayHasKey( 'newspack-magic-link-send', $actions, 'Precondition: the magic-link row action is present.' );
		$this->assert_href_not_breakable( $actions['newspack-magic-link-send'] );
	}

	/**
	 * The contact-sync "Resync contact to ESP" row action must escape the URL.
	 */
	public function test_contact_sync_row_action_escapes_request_uri() {
		add_filter( 'newspack_reader_activation_is_syncing_allowed', '__return_true' );
		Integrations::register( new \Sample_Integration( 'nppm3043-syncable', 'NPPM3043 Syncable' ) );
		update_option( Integrations::OPTION_NAME, [ 'nppm3043-syncable' ] );

		$target = get_user_by( 'id', self::factory()->user->create() );

		$_SERVER['REQUEST_URI'] = self::PAYLOAD_URI;
		$actions                = Contact_Sync_Admin::user_row_actions( [], $target );

		$this->assertArrayHasKey( Contact_Sync_Admin::ADMIN_ACTION, $actions, 'Precondition: the contact-sync row action is present.' );
		$this->assert_href_not_breakable( $actions[ Contact_Sync_Admin::ADMIN_ACTION ] );
	}

	/**
	 * Assert a row-action anchor cannot be broken out of at the href attribute.
	 *
	 * A well-formed `<a href="…">…</a>` carries exactly the two href-delimiter
	 * quotes. Any reflected value that escapes the attribute introduces a third,
	 * so the quote count is the escaping invariant — robust against a partial
	 * filter that perturbs `<svg` but leaves the attribute-closing `"` intact,
	 * and against breakout vectors that use no `<` (e.g. `" onmouseover=…`).
	 *
	 * @param string $anchor The rendered row-action anchor markup.
	 */
	private function assert_href_not_breakable( $anchor ) {
		$this->assertSame( 2, substr_count( $anchor, '"' ), 'The row-action href must not be breakable out of: ' . $anchor );
	}
}
