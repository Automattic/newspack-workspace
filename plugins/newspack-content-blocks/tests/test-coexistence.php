<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride
/**
 * Coexistence with the newspack-blocks plugin and newspack-plugin's bundled copy.
 *
 * @package Newspack_Content_Blocks
 */

/**
 * Coexistence tests. Each loads other plugins' files, so each runs in its own process.
 */
class Newspack_Content_Blocks_Test_Coexistence extends WP_UnitTestCase {
	const OWN_BLOCKS = [ 'newspack-blocks/homepage-articles', 'newspack-blocks/carousel' ];

	const BUNDLED_BLOCKS = [
		'newspack-blocks/author-list',
		'newspack-blocks/author-profile',
		'newspack-blocks/checkout-button',
		'newspack-blocks/donate',
		'newspack-blocks/youtube-video-playlist', // The video-playlist directory.
		'newspack-blocks/iframe',
	];

	/**
	 * Count how many times each newspack-blocks/* block is registered, by name.
	 * The registry is keyed by name, so a second registration replaces the first;
	 * the check that matters is the absence of _doing_it_wrong, which the base class
	 * enforces, plus the expected set of names.
	 *
	 * @return string[] Registered newspack-blocks/* block names.
	 */
	private function registered_newspack_blocks() {
		$names = array_filter(
			array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() ),
			function( $name ) {
				return 0 === strpos( $name, 'newspack-blocks/' );
			}
		);
		sort( $names );
		return array_values( $names );
	}

	/**
	 * The newspack-blocks plugin's main file loads after this plugin and after `init`
	 * (the request that activates it). It must not register anything a second time.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_no_duplicate_block_registration_when_legacy_loads_late() {
		$this->assertTrue( did_action( 'init' ) > 0, 'init has fired before the legacy plugin loads.' );
		$before = $this->registered_newspack_blocks();
		$own = array_values( array_intersect( $before, self::OWN_BLOCKS ) );
		sort( $own );
		$this->assertSame( [ 'newspack-blocks/carousel', 'newspack-blocks/homepage-articles' ], $own );

		require_once dirname( __DIR__, 2 ) . '/newspack-blocks/newspack-blocks.php';

		// Loading it late must not fatal on duplicate symbols; init has already fired, so
		// it registers no blocks of its own on this request.
		$this->assertSame( $before, $this->registered_newspack_blocks() );

		// Routes registered by both sides must coexist without notices.
		do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/newspack-blocks/v1/articles', $routes );
		$this->assertArrayHasKey( '/newspack-content-blocks/v1/authors', $routes );
	}

	/**
	 * Load newspack-plugin's bundled copy of the other blocks, then fire `init` again for
	 * the callbacks that belong to the two copies, as a request with both active would.
	 */
	private function load_bundled_copy_and_refire_init() {
		global $wp_filter;
		$plugin_dir = dirname( __DIR__, 2 ) . '/newspack-plugin/';
		define( 'NEWSPACK_PLUGIN_FILE', $plugin_dir . 'newspack.php' ); // phpcs:ignore phpcsSniffs.Constants.ConstantDocblock.Missing
		define( 'NEWSPACK_ABSPATH', $plugin_dir ); // phpcs:ignore phpcsSniffs.Constants.ConstantDocblock.Missing
		define( 'NEWSPACK_PLUGIN_VERSION', '0.0.0-test' ); // phpcs:ignore phpcsSniffs.Constants.ConstantDocblock.Missing
		require $plugin_dir . 'includes/class-bundled-blocks.php';
		$this->assertTrue( \Newspack\Bundled_Blocks::is_loaded() );
		$this->assertTrue( \Newspack_Content_Blocks\Plugin::is_loaded() );

		// Unregister our blocks, then run only init callbacks defined by the two copies.
		foreach ( self::OWN_BLOCKS as $name ) {
			unregister_block_type( $name );
		}
		$own_dir = dirname( __DIR__ ) . '/';
		$kept    = new WP_Hook();
		$current = $wp_filter['init'];
		foreach ( $current->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn = $callback['function'];
				if ( is_array( $fn ) ) {
					$file = ( new ReflectionMethod( $fn[0], $fn[1] ) )->getFileName();
				} elseif ( is_string( $fn ) && function_exists( $fn ) ) {
					$file = ( new ReflectionFunction( $fn ) )->getFileName();
				} elseif ( $fn instanceof Closure ) {
					$file = ( new ReflectionFunction( $fn ) )->getFileName();
				} else {
					continue;
				}
				if ( 0 === strpos( $file, $own_dir ) || 0 === strpos( $file, $plugin_dir ) ) {
					$kept->add_filter( 'init', $fn, $priority, $callback['accepted_args'] );
				}
			}
		}
		$kept->do_action( [] );
	}

	/**
	 * This plugin with newspack-plugin's bundled copy: all eight blocks, once each.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_bundled_copy_and_plugin_together() {
		$this->load_bundled_copy_and_refire_init();

		$expected = array_merge( self::OWN_BLOCKS, self::BUNDLED_BLOCKS );
		sort( $expected );
		$this->assertSame( $expected, $this->registered_newspack_blocks() );

		do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/newspack-blocks/v1/articles', $routes );
		$this->assertArrayHasKey( '/newspack-blocks/v1/authors', $routes );
		$this->assertArrayHasKey( '/newspack-content-blocks/v1/authors', $routes );
	}
}
