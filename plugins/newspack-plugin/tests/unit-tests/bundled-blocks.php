<?php
/**
 * Tests the copy of newspack-blocks bundled in this plugin.
 *
 * @package Newspack\Tests
 */

use Newspack\Bundled_Blocks;

/**
 * Test the bundled newspack-blocks code.
 *
 * The test bootstrap doesn't load the newspack-blocks plugin, so the bundled
 * copy is expected to be live.
 */
class Newspack_Test_Bundled_Blocks extends WP_UnitTestCase {
	/**
	 * Blocks moved from newspack-blocks into this plugin.
	 *
	 * @var string[]
	 */
	const MOVED_BLOCKS = [
		'newspack-blocks/author-list',
		'newspack-blocks/author-profile',
		'newspack-blocks/checkout-button',
		'newspack-blocks/donate',
		'newspack-blocks/youtube-video-playlist',
		'newspack-blocks/iframe',
	];

	/**
	 * REST routes the moved blocks use.
	 *
	 * @var string[]
	 */
	const MOVED_ROUTES = [
		'/newspack-blocks/v1/author-list',
		'/newspack-blocks/v1/authors',
		'/newspack-blocks/v1/newspack-blocks-iframe-archive',
		'/newspack-blocks/v1/newspack-blocks-iframe-archive-from-media',
		'/newspack-blocks/v1/newspack-blocks-iframe-mode-from-url',
		'/newspack-blocks/v1/newspack-blocks-remove-iframe-archive',
		'/newspack-blocks/v1/video-playlist',
	];

	/**
	 * REST routes only Content Loop and Carousel use.
	 *
	 * @var string[]
	 */
	const CONTENT_LOOP_ROUTES = [
		'/newspack-blocks/v1/articles',
		'/newspack-blocks/v1/newspack-blocks-posts',
		'/newspack-blocks/v1/newspack-blocks-posts-batch',
		'/newspack-blocks/v1/newspack-blocks-specific-posts',
	];

	/**
	 * The moved blocks' REST routes are registered, Content Loop's aren't.
	 */
	public function test_moved_routes_registered() {
		$routes = rest_get_server()->get_routes();
		foreach ( self::MOVED_ROUTES as $route ) {
			$this->assertArrayHasKey( $route, $routes, "$route is not registered." );
		}
		foreach ( self::CONTENT_LOOP_ROUTES as $route ) {
			$this->assertArrayNotHasKey( $route, $routes, "$route is registered." );
		}
	}

	/**
	 * Without the newspack-blocks plugin, the bundled copy loads.
	 */
	public function test_bundled_copy_loads_without_legacy_plugin() {
		$this->assertFalse( Bundled_Blocks::is_legacy_plugin_active() );
		$this->assertTrue( Bundled_Blocks::is_loaded() );
		$this->assertTrue( class_exists( 'Newspack_Blocks' ) );
		$this->assertTrue( class_exists( 'Newspack_Blocks\Modal_Checkout' ) );
	}

	/**
	 * The bundled copy hooks in before this plugin, as newspack-blocks did.
	 *
	 * Loading it on `plugins_loaded` instead flipped the order of callbacks sharing a
	 * priority, and let a `class_exists( 'WCS_Limiter' )` check in Modal_Checkout::init()
	 * pass that newspack-blocks always failed, which made every subscription
	 * unpurchasable in modal checkout.
	 */
	public function test_bundled_copy_hooks_in_before_this_plugin() {
		$shared_hooks = [
			'woocommerce_checkout_fields'  => [ 'Newspack_Blocks\Modal_Checkout::woocommerce_checkout_fields', 'Newspack\WooCommerce_Cover_Fees::add_checkout_fields' ],
			'woocommerce_subscriptions_product_limited_for_user' => [ 'Newspack_Blocks\Modal_Checkout::subscriptions_product_limited_for_user', 'Newspack\WooCommerce_Subscriptions::maybe_limit_subscription_product_for_user' ],
			'newspack_ads_should_show_ads' => [ 'Newspack_Blocks\Modal_Checkout::is_not_modal_checkout_filter', 'Newspack\WooCommerce_My_Account::suppress_ads' ],
		];
		foreach ( $shared_hooks as $hook => list( $bundled, $own ) ) {
			$order = $this->get_callback_names( $hook, 10 );
			$this->assertContains( $bundled, $order, "$bundled is not hooked to $hook." );
			$this->assertContains( $own, $order, "$own is not hooked to $hook." );
			$this->assertLessThan( array_search( $own, $order, true ), array_search( $bundled, $order, true ), "$bundled runs after $own on $hook." );
		}
	}

	/**
	 * When newspack-blocks is active but loads after this plugin, the copy stays out of its way.
	 */
	public function test_bundled_copy_defers_to_legacy_plugin_loading_later() {
		// phpcs:disable WordPress.WP.AlternativeFunctions, WordPressVIPMinimum.Functions.RestrictedFunctions -- A throwaway plugin file in the test install.
		$legacy_dir = WP_PLUGIN_DIR . '/newspack-blocks-' . wp_generate_password( 6, false );
		wp_mkdir_p( $legacy_dir );
		file_put_contents( $legacy_dir . '/newspack-blocks.php', '<?php' );
		update_option( 'active_plugins', [ 'newspack-plugin/newspack.php', basename( $legacy_dir ) . '/newspack-blocks.php' ] );

		$will_load = new ReflectionMethod( Bundled_Blocks::class, 'will_legacy_plugin_load' );
		$will_load->setAccessible( true );
		$this->assertTrue( $will_load->invoke( null ) );

		// A listed plugin whose file is gone won't load.
		unlink( $legacy_dir . '/newspack-blocks.php' );
		rmdir( $legacy_dir );
		// phpcs:enable
		$this->assertFalse( $will_load->invoke( null ) );
	}

	/**
	 * Names of the callbacks on a hook at a priority, in the order they run.
	 *
	 * @param string $hook     Hook name.
	 * @param int    $priority Priority.
	 * @return string[]
	 */
	private function get_callback_names( $hook, $priority ) {
		global $wp_filter;
		if ( empty( $wp_filter[ $hook ]->callbacks[ $priority ] ) ) {
			return [];
		}
		return array_values(
			array_map(
				function ( $callback ) {
					$function = $callback['function'];
					if ( is_array( $function ) ) {
						return ( is_object( $function[0] ) ? get_class( $function[0] ) : $function[0] ) . '::' . $function[1];
					}
					return is_string( $function ) ? $function : 'closure';
				},
				$wp_filter[ $hook ]->callbacks[ $priority ]
			)
		);
	}

	/**
	 * Activating newspack-blocks while the bundled copy is loaded doesn't fatal.
	 *
	 * WordPress includes a plugin's main file in the request that activates it,
	 * after the bundled copy has already declared the same functions and classes.
	 */
	public function test_activating_legacy_plugin_with_bundled_copy_loaded_does_not_fatal() {
		$legacy_plugin_file = dirname( NEWSPACK_ABSPATH ) . '/newspack-blocks/newspack-blocks.php';
		if ( ! file_exists( $legacy_plugin_file ) ) {
			$this->markTestSkipped( 'The newspack-blocks plugin is not checked out next to this plugin.' );
		}
		$this->assertTrue( Bundled_Blocks::is_loaded() );

		include $legacy_plugin_file;

		$this->assertSame( NEWSPACK_ABSPATH, NEWSPACK_BLOCKS__PLUGIN_DIR );
	}

	/**
	 * Content Loop and Carousel code is not bundled.
	 */
	public function test_content_loop_not_bundled() {
		$this->assertFalse( method_exists( 'Newspack_Blocks_API', 'posts_endpoint' ) );
		$this->assertFalse( class_exists( 'Newspack_Blocks_Caching' ) );
		$this->assertFalse( class_exists( 'WP_REST_Newspack_Articles_Controller' ) );
		$this->assertFalse( method_exists( 'Newspack_Blocks', 'build_articles_query' ) );
	}

	/**
	 * The moved blocks are registered, Content Loop and Carousel aren't.
	 */
	public function test_moved_blocks_registered() {
		$registry = WP_Block_Type_Registry::get_instance();
		foreach ( self::MOVED_BLOCKS as $block_name ) {
			$this->assertTrue( $registry->is_registered( $block_name ), "$block_name is not registered." );
		}
		$this->assertFalse( $registry->is_registered( 'newspack-blocks/homepage-articles' ) );
		$this->assertFalse( $registry->is_registered( 'newspack-blocks/carousel' ) );
	}
}
