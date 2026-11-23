<?php
/**
 * Tests for the web app manifest Newspack serves once the PWA plugin is gone.
 *
 * @package Newspack\Tests
 */

namespace Newspack\Tests;

use Newspack\Web_App_Manifest;

/**
 * Readers can install the site from the browser menu with only a manifest.
 *
 * @group web-app-manifest
 */
class Test_Web_App_Manifest extends \WP_UnitTestCase {

	/**
	 * Site icon attachment ID.
	 *
	 * @var int
	 */
	private $icon_id;

	/**
	 * A site with an icon and a name that needs decoding.
	 */
	public function set_up() {
		parent::set_up();
		$this->icon_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		update_option( 'site_icon', $this->icon_id );
		update_option( 'blogname', 'Smith &amp; Co' );
		delete_option( 'newspack_pwa_display_mode' );
		delete_option( 'short_name' );
		delete_option( 'site_icon_maskable' );
	}

	/**
	 * Undo theme support added by individual tests.
	 */
	public function tear_down() {
		// remove_theme_support() for custom-background reads a global that only exists on admin requests.
		global $_wp_theme_features;
		unset( $_wp_theme_features['custom-background'] );
		remove_theme_mod( 'background_color' );
		parent::tear_down();
	}

	/**
	 * The manifest carries what a browser needs to install the site.
	 */
	public function test_manifest_has_what_a_browser_needs_to_install() {
		$manifest = Web_App_Manifest::get_manifest();
		$this->assertSame( 'Smith & Co', $manifest['name'] );
		$this->assertSame( 'Smith & Co', $manifest['short_name'] );
		$this->assertSame( home_url( '/' ), $manifest['start_url'] );
		$this->assertSame( 'minimal-ui', $manifest['display'] );
		$this->assertSame( 'ltr', $manifest['dir'] );
		$this->assertSame( get_bloginfo( 'language' ), $manifest['lang'] );
		$this->assertCount( 2, $manifest['icons'] );
		$type = get_post_mime_type( $this->icon_id );
		foreach ( [ 192, 512 ] as $index => $size ) {
			$this->assertSame( "{$size}x{$size}", $manifest['icons'][ $index ]['sizes'] );
			$this->assertSame( get_site_icon_url( $size ), $manifest['icons'][ $index ]['src'] );
			$this->assertSame( $type, $manifest['icons'][ $index ]['type'] );
			$this->assertSame( 'any', $manifest['icons'][ $index ]['purpose'] );
		}
	}

	/**
	 * A short name a publisher set in PWA's setting is kept.
	 */
	public function test_short_name_option_wins() {
		update_option( 'short_name', 'S&C' );
		$this->assertSame( 'S&C', Web_App_Manifest::get_manifest()['short_name'] );
	}

	/**
	 * A name too long for a home screen label gets no short name.
	 */
	public function test_long_name_has_no_short_name() {
		update_option( 'blogname', 'A Much Longer Site Name' );
		$this->assertArrayNotHasKey( 'short_name', Web_App_Manifest::get_manifest() );
	}

	/**
	 * Maskable icons get their own entries, so the plain icon isn't cropped where it's shown as is.
	 */
	public function test_maskable_icons() {
		update_option( 'site_icon_maskable', true );
		$icons = Web_App_Manifest::get_manifest()['icons'];
		$this->assertSame( [ 'any', 'maskable', 'any', 'maskable' ], wp_list_pluck( $icons, 'purpose' ) );
		$this->assertSame( [ '192x192', '192x192', '512x512', '512x512' ], wp_list_pluck( $icons, 'sizes' ) );
	}

	/**
	 * Stored display modes, including ones that aren't valid.
	 *
	 * @return array
	 */
	public function display_mode_provider() {
		return [
			'valid'   => [ 'standalone', 'standalone' ],
			'empty'   => [ '', 'minimal-ui' ],
			'unknown' => [ 'bogus', 'minimal-ui' ],
		];
	}

	/**
	 * The display mode comes from the saved setting, falling back to minimal-ui.
	 *
	 * @dataProvider display_mode_provider
	 *
	 * @param string $stored   Stored option value.
	 * @param string $expected Display mode in the manifest.
	 */
	public function test_display_mode_from_option( $stored, $expected ) {
		update_option( 'newspack_pwa_display_mode', $stored );
		$this->assertSame( $expected, Web_App_Manifest::get_manifest()['display'] );
	}

	/**
	 * Only a mode a manifest can declare is saved, since PWA passes the stored value straight into its manifest.
	 */
	public function test_settings_drop_invalid_display_modes() {
		$wizard = new \Newspack\Setup_Wizard();
		$this->assertSame(
			[
				'pwa_display_mode' => 'standalone',
				'show_author_bio'  => true,
			],
			$wizard->sanitize_theme_mods(
				[
					'pwa_display_mode' => 'standalone',
					'show_author_bio'  => true,
				]
			)
		);
		$this->assertSame(
			[ 'show_author_bio' => true ],
			$wizard->sanitize_theme_mods(
				[
					'pwa_display_mode' => 'bogus',
					'show_author_bio'  => true,
				] 
			) 
		);
	}

	/**
	 * The theme's background color tints the app's title bar and splash screen, white when the theme sets none.
	 */
	public function test_theme_color_falls_back_to_white_then_follows_custom_background() {
		$manifest = Web_App_Manifest::get_manifest();
		$this->assertSame( '#fff', $manifest['theme_color'] );
		$this->assertSame( '#fff', $manifest['background_color'] );

		add_theme_support( 'custom-background' );
		set_theme_mod( 'background_color', '112233' );
		$manifest = Web_App_Manifest::get_manifest();
		$this->assertSame( '#112233', $manifest['theme_color'] );
		$this->assertSame( '#112233', $manifest['background_color'] );
	}

	/**
	 * Browsers won't install a site without an icon, so there's no manifest.
	 */
	public function test_no_icon_no_manifest() {
		delete_option( 'site_icon' );
		$this->assertNull( Web_App_Manifest::get_manifest() );
	}

	/**
	 * Fire rest_api_init and request the manifest route.
	 *
	 * @return \WP_REST_Response
	 */
	private function request_manifest() {
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		return rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/web-app-manifest' ) );
	}

	/**
	 * The manifest is served from the address PWA used, as a manifest.
	 */
	public function test_rest_route_serves_manifest() {
		$response = $this->request_manifest();
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'application/manifest+json', $response->get_headers()['Content-Type'] );
		$this->assertSame( Web_App_Manifest::get_manifest(), $response->get_data() );
	}

	/**
	 * Without an icon the route answers 404.
	 */
	public function test_rest_route_404_without_icon() {
		delete_option( 'site_icon' );
		$this->assertSame( 404, $this->request_manifest()->get_status() );
	}

	/**
	 * Capture what the manifest adds to the page head.
	 *
	 * @return string
	 */
	private function head_tags() {
		ob_start();
		Web_App_Manifest::print_head_tags();
		return ob_get_clean();
	}

	/**
	 * Every page points browsers at the manifest and tints the app in the theme's color.
	 */
	public function test_head_links_the_manifest_and_tints_the_app() {
		add_theme_support( 'custom-background' );
		set_theme_mod( 'background_color', '112233' );
		$tags = $this->head_tags();
		$this->assertStringContainsString( '<link rel="manifest" href="' . esc_url( Web_App_Manifest::get_url() ) . '">', $tags );
		$this->assertStringContainsString( '<meta name="theme-color" content="#112233">', $tags );
		$this->assertStringContainsString( '<meta name="apple-mobile-web-app-capable" content="yes">', $tags );
		$this->assertStringContainsString( '<meta name="mobile-web-app-capable" content="yes">', $tags );
	}

	/**
	 * Home screens label the app with the short name, or the full name when there isn't one, not the page title.
	 */
	public function test_head_tags_name_the_app() {
		$tags = $this->head_tags();
		$this->assertStringContainsString( '<meta name="apple-mobile-web-app-title" content="Smith &amp; Co">', $tags );
		$this->assertStringContainsString( '<meta name="application-name" content="Smith &amp; Co">', $tags );

		update_option( 'blogname', 'A Much Longer Site Name' );
		$this->assertStringContainsString( '<meta name="apple-mobile-web-app-title" content="A Much Longer Site Name">', $this->head_tags() );
	}

	/**
	 * An app set to open in the browser doesn't ask for its own window.
	 */
	public function test_browser_display_has_no_capable_tags() {
		update_option( 'newspack_pwa_display_mode', 'browser' );
		$tags = $this->head_tags();
		$this->assertStringContainsString( '<link rel="manifest"', $tags );
		$this->assertStringNotContainsString( 'web-app-capable', $tags );
	}

	/**
	 * Without an icon there's nothing to install, so no tags.
	 */
	public function test_no_tags_without_icon() {
		delete_option( 'site_icon' );
		$this->assertSame( '', $this->head_tags() );
	}

	/**
	 * While the PWA plugin runs it serves its own manifest at this address, so ours stays off.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_no_route_when_pwa_active() {
		define( 'PWA_VERSION', '0.8.2' );
		$this->assertSame( 404, $this->request_manifest()->get_status() );
		$this->assertArrayNotHasKey( '/wp/v2/web-app-manifest', rest_get_server()->get_routes() );
	}

	/**
	 * While the PWA plugin runs it prints its own manifest link, so ours stays off.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_no_tags_when_pwa_active() {
		define( 'PWA_VERSION', '0.8.2' );
		$this->assertSame( '', $this->head_tags() );
	}
}
