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
	 * Request URIs whose reflected characters break out of an unescaped href.
	 * One injects a tag; the other breaks out of the attribute with no `<` at all
	 * (an `onmouseover` handler). `esc_url()` neutralises both, leaving the anchor
	 * with only its two delimiter quotes.
	 *
	 * @return array<string,string> Label => request URI.
	 */
	private function breakout_request_uris() {
		return [
			'tag injection'      => '/wp-admin/users.php"><svg/onload=NPPM3043>',
			'attribute breakout' => '/wp-admin/users.php" onmouseover=NPPM3043 x="',
		];
	}

	/**
	 * Admin user id.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * The request URI captured before a test overrides it.
	 *
	 * @var string|null
	 */
	private $original_request_uri;

	/**
	 * Enable reader activation and act as an admin before each test.
	 */
	public function set_up() {
		parent::set_up();
		// Sample_Integration carries mutable statics; an earlier test leaving
		// $can_sync_error_codes set makes can_sync() fail and the contact-sync
		// row action absent, so this file would fail on its precondition.
		\Sample_Integration::reset();
		$this->original_request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- snapshot restored verbatim in tear_down().
		add_filter( 'newspack_reader_activation_enabled', '__return_true' );
		// The row actions render on wp-admin/users.php; get_admin_action_url()
		// short-circuits unless is_admin() is true.
		set_current_screen( 'users' );
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Restore the global state the framework does not.
	 *
	 * WP_UnitTestCase restores the hook registry from its backup and rolls
	 * back the per-test DB transaction, so removing filters or deleting
	 * options here would be a no-op. What survives both is the superglobal,
	 * the current screen and the static integrations registry.
	 */
	public function tear_down() {
		if ( null === $this->original_request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->original_request_uri;
		}
		set_current_screen( 'front' );
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
		// can_magic_link() needs only reader activation plus is_user_reader(),
		// which the np_reader meta satisfies. register_reader() would also pull
		// welcome email, data events and ESP sync into a test about esc_url().
		$reader_id = self::factory()->user->create();
		update_user_meta( $reader_id, Reader_Activation::READER, true );
		$reader = get_user_by( 'id', $reader_id );
		$this->assertTrue( Magic_Link::can_magic_link( $reader_id ), 'Precondition: the user qualifies for a magic link.' );

		foreach ( $this->breakout_request_uris() as $label => $uri ) {
			$_SERVER['REQUEST_URI'] = $uri;
			$actions                = Magic_Link::user_row_actions( [], $reader );
			$this->assertArrayHasKey( 'newspack-magic-link-send', $actions, "Precondition ($label): the magic-link row action is present." );
			$this->assert_href_not_breakable( $actions['newspack-magic-link-send'] );
		}
	}

	/**
	 * The contact-sync "Resync contact to ESP" row action must escape the URL.
	 */
	public function test_contact_sync_row_action_escapes_request_uri() {
		add_filter( 'newspack_reader_activation_is_syncing_allowed', '__return_true' );
		Integrations::register( new \Sample_Integration( 'nppm3043-syncable', 'NPPM3043 Syncable' ) );
		update_option( Integrations::OPTION_NAME, [ 'nppm3043-syncable' ] );

		$target = get_user_by( 'id', self::factory()->user->create() );

		foreach ( $this->breakout_request_uris() as $label => $uri ) {
			$_SERVER['REQUEST_URI'] = $uri;
			$actions                = Contact_Sync_Admin::user_row_actions( [], $target );
			$this->assertArrayHasKey( Contact_Sync_Admin::ADMIN_ACTION, $actions, "Precondition ($label): the contact-sync row action is present." );
			$this->assert_href_not_breakable( $actions[ Contact_Sync_Admin::ADMIN_ACTION ] );
		}
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
	 * The quote count alone would pass on an empty href, which is reachable:
	 * both get_admin_action_url() implementations return '' when is_admin()
	 * is false, while the row action is registered either way. So assert the
	 * link is still populated and functional, not merely well-formed.
	 *
	 * @param string $anchor The rendered row-action anchor markup.
	 */
	private function assert_href_not_breakable( $anchor ) {
		$this->assertSame( 2, substr_count( $anchor, '"' ), 'The row-action href must not be breakable out of: ' . $anchor );

		preg_match( '/href="([^"]*)"/', $anchor, $matches );
		$href = isset( $matches[1] ) ? $matches[1] : '';
		$this->assertNotSame( '', $href, 'An empty href satisfies the quote count without proving anything: ' . $anchor );
		$this->assertStringContainsString( 'uid=', $href, 'The escaped href must still carry the user id: ' . $anchor );
		$this->assertStringContainsString( '_wpnonce=', $href, 'The escaped href must still carry the nonce: ' . $anchor );
		$this->assertStringContainsString( '&#038;', $href, 'esc_url() must encode the query-arg separators: ' . $anchor );
	}
}
