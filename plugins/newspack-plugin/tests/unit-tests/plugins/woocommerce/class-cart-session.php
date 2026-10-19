<?php
/**
 * Tests the reset of WooCommerce session carts that no longer unserialize.
 *
 * @package Newspack\Tests
 */

/**
 * A logged-in reader whose stored session cart can't be read must still load
 * the site, and WooCommerce must still merge their saved cart.
 *
 * WooCommerce isn't loaded in this suite, so each test stubs WC() with a
 * session that stores values the way WC_Session does, and replays the part of
 * WC_Cart_Session::get_cart_from_session() that fatals. The stub runs in a
 * separate process because a global WC() would flip the function_exists( 'WC' )
 * gates for every later test in the run.
 *
 * @group WooCommerce_Cart_Session
 */
class Newspack_Test_WooCommerce_Cart_Session extends WP_UnitTestCase {
	/**
	 * WooCommerce's user meta asking the next cart load to merge the saved cart. Every login sets it.
	 */
	const MERGE_FLAG = '_woocommerce_load_saved_cart_after_login';

	/**
	 * Codes of the newspack_log entries fired during the test.
	 *
	 * @var string[]
	 */
	private $logged = [];

	/**
	 * Capture newspack_log entries.
	 */
	public function set_up() {
		parent::set_up();
		$this->logged = [];
		add_action( 'newspack_log', [ $this, 'capture_log' ] );
	}

	/**
	 * Stop capturing newspack_log entries.
	 */
	public function tear_down() {
		remove_action( 'newspack_log', [ $this, 'capture_log' ] );
		parent::tear_down();
	}

	/**
	 * Record a newspack_log entry.
	 *
	 * @param string $code Log code.
	 */
	public function capture_log( $code ) {
		$this->logged[] = $code;
	}

	/**
	 * The cart WooCommerce keeps in user meta between logins.
	 *
	 * @return array
	 */
	private static function saved_cart() {
		return [
			'saved-item' => [
				'product_id'   => 101,
				'variation_id' => 0,
				'quantity'     => 1,
			],
		];
	}

	/**
	 * A session cart after a domain swap rewrote the URL inside it but not the
	 * string length declared before the URL. This is what a search-replace leaves
	 * when it doesn't unserialize values nested inside the session row.
	 *
	 * @return string
	 */
	private static function unreadable_cart() {
		$cart = [
			'session-item' => [
				'product_id'   => 102,
				'variation_id' => 0,
				'quantity'     => 1,
				'referer'      => 'https://news.example/support/',
			],
		];
		return str_replace( 'https://news.example', 'https://staging.news.example', maybe_serialize( $cart ) );
	}

	/**
	 * Log in a reader who has a saved cart and a pending merge, as after any login.
	 *
	 * @return int User ID.
	 */
	private function log_in_reader_with_saved_cart() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		update_user_meta( $user_id, '_woocommerce_persistent_cart_' . get_current_blog_id(), [ 'cart' => self::saved_cart() ] );
		update_user_meta( $user_id, self::MERGE_FLAG, 1 );
		return $user_id;
	}

	/**
	 * Stub WC() with a session holding $data, in this process only.
	 *
	 * The session stores values like WC_Session in WooCommerce 11.2.1: serialized
	 * on write, read through maybe_unserialize(), and removed when set to null.
	 *
	 * @param array|null $data Stored session values keyed by name, or null for no session.
	 */
	private function stub_wc_session( $data ) {
		if ( ! $this->isInIsolation() ) {
			$this->fail( 'stub_wc_session() may only be called from @runInSeparateProcess tests: defining WC() in the main suite process would flip function_exists( "WC" ) gates for every later test in the run.' );
		}
		if ( ! function_exists( 'WC' ) ) {
			/**
			 * Mock WC() function returning a controllable container.
			 *
			 * @return object
			 */
			function WC() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Mock WooCommerce global, isolated to a separate test process.
				global $newspack_test_wc;
				if ( empty( $newspack_test_wc ) ) {
					$newspack_test_wc = new class() {
						/**
						 * Session double, set by tests.
						 *
						 * @var object|null
						 */
						public $session = null;
					};
				}
				return $newspack_test_wc;
			}
		}
		if ( null === $data ) {
			WC()->session = null;
			return;
		}
		WC()->session = new class( $data ) {
			/**
			 * Stored session values.
			 *
			 * @var array
			 */
			public $data;

			/**
			 * Constructor.
			 *
			 * @param array $data Stored session values.
			 */
			public function __construct( $data ) {
				$this->data = $data;
			}

			/**
			 * Read a session value.
			 *
			 * @param string $key           Key.
			 * @param mixed  $default_value Value when the key isn't set.
			 * @return mixed
			 */
			public function get( $key, $default_value = null ) {
				return isset( $this->data[ $key ] ) ? maybe_unserialize( $this->data[ $key ] ) : $default_value;
			}

			/**
			 * Write a session value.
			 *
			 * @param string $key   Key.
			 * @param mixed  $value Value, or null to remove the key.
			 */
			public function set( $key, $value ) {
				if ( null === $value ) {
					unset( $this->data[ $key ] );
					return;
				}
				$this->data[ $key ] = maybe_serialize( $value );
			}
		};
	}

	/**
	 * Replay WC_Cart_Session::get_cart_from_session() from WooCommerce 11.2.1, up
	 * to and including the saved-cart merge where the fatal happens.
	 *
	 * @return array The cart WooCommerce goes on to load items from.
	 */
	private function load_cart_from_session() {
		do_action( 'woocommerce_load_cart_from_session' );

		$cart             = WC()->session->get( 'cart', null );
		$merge_saved_cart = (bool) get_user_meta( get_current_user_id(), self::MERGE_FLAG, true );

		if ( is_null( $cart ) || $merge_saved_cart ) {
			$saved_cart_meta = get_user_meta( get_current_user_id(), '_woocommerce_persistent_cart_' . get_current_blog_id(), true );
			$saved_cart      = is_array( $saved_cart_meta ) && isset( $saved_cart_meta['cart'] ) ? array_filter( (array) $saved_cart_meta['cart'] ) : [];
			$cart            = is_null( $cart ) ? [] : $cart;
			$cart            = array_merge( $saved_cart, $cart );

			delete_user_meta( get_current_user_id(), self::MERGE_FLAG );
		}

		return $cart;
	}

	/**
	 * Without the reset, the replay hits the same TypeError as production, so
	 * the tests below exercise the real failure.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_unreadable_cart_fatals_without_the_reset() {
		$this->log_in_reader_with_saved_cart();
		$this->stub_wc_session( [ 'cart' => self::unreadable_cart() ] );
		remove_all_actions( 'woocommerce_load_cart_from_session' );

		$this->expectException( TypeError::class );
		$this->load_cart_from_session();
	}

	/**
	 * The saved cart loads and the merge flag clears, so the next request doesn't
	 * repeat the merge. The unreadable value leaves the session, so WooCommerce
	 * doesn't save it back.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_unreadable_cart_is_reset_so_saved_cart_merges() {
		$user_id = $this->log_in_reader_with_saved_cart();
		$this->stub_wc_session( [ 'cart' => self::unreadable_cart() ] );
		$this->assertFalse( WC()->session->get( 'cart', null ), 'Precondition: the stored cart no longer unserializes.' );

		$cart = $this->load_cart_from_session();

		$this->assertSame( self::saved_cart(), $cart );
		$this->assertSame( '', get_user_meta( $user_id, self::MERGE_FLAG, true ) );
		$this->assertArrayNotHasKey( 'cart', WC()->session->data );
	}

	/**
	 * A reset is logged once, so a corrupted sessions table shows up in the logs.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_unreadable_cart_reset_is_logged() {
		$this->log_in_reader_with_saved_cart();
		$this->stub_wc_session( [ 'cart' => self::unreadable_cart() ] );

		$this->load_cart_from_session();

		$this->assertSame( [ 'newspack_woocommerce_unreadable_session_cart' ], $this->logged );
	}

	/**
	 * A readable cart merges with the saved cart as WooCommerce intends.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_readable_cart_is_left_alone() {
		$this->log_in_reader_with_saved_cart();
		$session_cart = [
			'session-item' => [
				'product_id'   => 102,
				'variation_id' => 0,
				'quantity'     => 1,
			],
		];
		$this->stub_wc_session( [ 'cart' => maybe_serialize( $session_cart ) ] );

		$cart = $this->load_cart_from_session();

		$this->assertSame( array_merge( self::saved_cart(), $session_cart ), $cart );
		$this->assertSame( [], $this->logged );
	}

	/**
	 * A session with no cart, the usual state for a visitor, logs nothing.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_session_without_cart_is_left_alone() {
		$this->log_in_reader_with_saved_cart();
		$this->stub_wc_session( [] );

		$cart = $this->load_cart_from_session();

		$this->assertSame( self::saved_cart(), $cart );
		$this->assertSame( [], $this->logged );
	}

	/**
	 * Without a WooCommerce session there is nothing to reset, and the hook stays quiet.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_missing_session_is_ignored() {
		$this->stub_wc_session( null );

		do_action( 'woocommerce_load_cart_from_session' );

		$this->assertSame( [], $this->logged );
	}
}
