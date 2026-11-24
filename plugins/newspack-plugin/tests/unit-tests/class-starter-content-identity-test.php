<?php
/**
 * Tests starter content identity import and layout homepage.
 *
 * @package Newspack\Tests
 */

use Newspack\Starter_Content;
use Newspack\Starter_Content_WordPress;

/**
 * Tests starter content identity import and layout homepage.
 */
class Newspack_Test_Starter_Content_Identity extends WP_UnitTestCase {

	/**
	 * Mocked responses keyed by URL.
	 *
	 * @var array
	 */
	private $responses = [];

	/**
	 * Request args keyed by URL.
	 *
	 * @var array
	 */
	private $requests = [];

	/**
	 * Callbacks run when a URL is requested, keyed by URL.
	 *
	 * @var callable[]
	 */
	private $on_request = [];

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->responses = [
			'http://localhost/wp-json/wp/v2/posts?per_page=40' => [ 200, '[]' ],
		];
		add_filter( 'pre_http_request', [ $this, 'mock_http' ], 10, 3 );
		add_filter( 'http_request_host_is_external', '__return_true' );
		remove_theme_mod( 'custom_logo' );
		delete_option( 'site_icon' );
		delete_option( 'woocommerce_currency' );
		delete_option( Starter_Content::IMPORTED_CURRENCY_OPTION );
		Starter_Content::initialize( 'import', 'http://localhost/' );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', [ $this, 'mock_http' ], 10 );
		remove_filter( 'http_request_host_is_external', '__return_true' );
		delete_option( '_newspack_starter_content_approach' );
		parent::tear_down();
	}

	/**
	 * Mock HTTP.
	 *
	 * @param false|array $pre  Preempt.
	 * @param array       $args Args.
	 * @param string      $url  URL.
	 * @return array
	 */
	public function mock_http( $pre, $args, $url ) {
		if ( isset( $this->on_request[ $url ] ) ) {
			call_user_func( $this->on_request[ $url ] );
		}
		$this->requests[ $url ] = $args;
		$content_type           = 'application/json';
		if ( preg_match( '/broken\.png$/', $url ) ) {
			$content_type = '';
			$body         = 'not an image';
			$code         = 200;
			if ( ! empty( $args['filename'] ) ) {
				file_put_contents( $args['filename'], $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
			}
		} elseif ( preg_match( '/\.png$/', $url ) ) {
			$content_type = 'image/png';
			$png          = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( ! empty( $args['filename'] ) ) {
				file_put_contents( $args['filename'], $png ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
			}
			$body = $png;
			$code = 200;
		} elseif ( isset( $this->responses[ $url ] ) ) {
			$code = $this->responses[ $url ][0];
			$body = $this->responses[ $url ][1];
			if ( 0 === strpos( $body, '<' ) ) {
				$content_type = 'text/html';
			}
		} else {
			$code = 404;
			$body = '{}';
		}
		return [
			'headers'  => [ 'content-type' => $content_type ],
			'body'     => $body,
			'response' => [
				'code'    => $code,
				'message' => '',
			],
			'cookies'  => [],
			'filename' => ! empty( $args['filename'] ) ? $args['filename'] : null,
		];
	}

	/**
	 * Layout homepage needs no categories.
	 */
	public function test_layout_homepage_needs_no_sample_categories() {
		delete_option( '_newspack_starter_content_approach' );
		$page_id = Starter_Content::create_layout_homepage();
		$this->assertSame( $page_id, (int) get_option( 'page_on_front' ) );
		$this->assertStringContainsString( 'newspack-blocks/homepage-articles', get_post( $page_id )->post_content );
		$starter_slugs = array_filter(
			get_terms(
				[
					'taxonomy'   => 'category',
					'hide_empty' => false,
					'fields'     => 'slugs',
				]
			),
			function( $slug ) {
				return 0 === strpos( $slug, '_newspack_' );
			}
		);
		$this->assertSame( [], array_values( $starter_slugs ) );
	}

	/**
	 * Layout homepage is idempotent.
	 */
	public function test_layout_homepage_is_created_once() {
		update_option( 'show_on_front', 'posts' );
		delete_option( 'page_on_front' );
		$first  = Starter_Content::create_layout_homepage();
		$second = Starter_Content::create_layout_homepage();
		$this->assertSame( $first, $second );
		$this->assertCount(
			1,
			get_posts(
				[
					'post_type'   => 'page',
					'title'       => 'Homepage',
					'post_status' => 'any',
					'fields'      => 'ids',
				] 
			) 
		);
	}

	/**
	 * An existing front page is left alone.
	 */
	public function test_layout_homepage_keeps_an_existing_front_page() {
		$front = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Our front',
			] 
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );
		$this->assertSame( $front, Starter_Content::create_layout_homepage() );
		$this->assertSame( $front, (int) get_option( 'page_on_front' ) );
	}

	/**
	 * A front page set after an earlier layout page wins over it.
	 */
	public function test_layout_homepage_keeps_a_front_page_set_after_an_earlier_layout_page() {
		update_option( 'show_on_front', 'posts' );
		delete_option( 'page_on_front' );
		Starter_Content::create_layout_homepage();
		$front = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Our front',
			]
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );

		$this->assertSame( $front, Starter_Content::create_layout_homepage() );
		$this->assertSame( $front, (int) get_option( 'page_on_front' ) );
	}

	/**
	 * A layout page built earlier becomes the published front page again.
	 */
	public function test_layout_homepage_republishes_an_earlier_layout_page_as_front() {
		update_option( 'show_on_front', 'posts' );
		delete_option( 'page_on_front' );
		$layout_id = Starter_Content::create_layout_homepage();
		update_option( 'show_on_front', 'posts' );
		delete_option( 'page_on_front' );
		wp_update_post(
			[
				'ID'          => $layout_id,
				'post_status' => 'draft',
			]
		);

		$this->assertSame( $layout_id, Starter_Content::create_layout_homepage() );
		$this->assertSame( 'publish', get_post_status( $layout_id ) );
		$this->assertSame( 'page', get_option( 'show_on_front' ) );
		$this->assertSame( $layout_id, (int) get_option( 'page_on_front' ) );
	}

	/**
	 * A trashed front page counts as no front page.
	 */
	public function test_layout_homepage_replaces_a_trashed_front_page() {
		$trashed = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'trash',
			]
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $trashed );

		$page_id = Starter_Content::create_layout_homepage();
		$this->assertNotSame( $trashed, $page_id );
		$this->assertSame( $page_id, (int) get_option( 'page_on_front' ) );
		$this->assertSame( 'publish', get_post_status( $page_id ) );
	}

	/**
	 * No placeholder logo.
	 */
	public function test_theme_setup_does_not_set_a_logo() {
		remove_theme_mod( 'custom_logo' );
		$level = ob_get_level();
		Starter_Content::initialize_theme();
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		$this->assertFalse( get_theme_mod( 'custom_logo' ) );
	}

	/**
	 * Full identity import.
	 */
	public function test_identity_import_brings_over_title_tagline_logo_icon_and_currency() {
		$this->responses['http://localhost/wp-json/?_fields=name,description,site_logo,site_icon']                = [
			200,
			wp_json_encode(
				[
					'name'        => 'Riverbend Gazette',
					'description' => 'Independent news',
					'site_logo'   => 11,
					'site_icon'   => 12,
				]
			),
		];
		$this->responses['http://localhost/wp-json/wp/v2/media/11?_fields=source_url']  = [ 200, wp_json_encode( [ 'source_url' => 'http://localhost/logo.png' ] ) ];
		$this->responses['http://localhost/wp-json/wp/v2/media/12?_fields=source_url']  = [ 200, wp_json_encode( [ 'source_url' => 'http://localhost/icon.png' ] ) ];
		$this->responses['http://localhost/wp-json/wc/store/v1/cart'] = [ 200, wp_json_encode( [ 'totals' => [ 'currency_code' => 'USD' ] ] ) ];

		$result = Starter_Content::import_identity();
		$this->assertSame( 'Riverbend Gazette', get_option( 'blogname' ) );
		$this->assertSame( 'Independent news', get_option( 'blogdescription' ) );
		$this->assertGreaterThan( 0, (int) get_theme_mod( 'custom_logo' ) );
		$this->assertGreaterThan( 0, (int) get_option( 'site_icon' ) );
		$this->assertSame( 'USD', get_option( 'woocommerce_currency' ) );
		$this->assertSame( 'USD', get_option( Starter_Content::IMPORTED_CURRENCY_OPTION ) );
		$this->assertSame(
			[
				'title'    => true,
				'tagline'  => true,
				'logo'     => true,
				'icon'     => true,
				'currency' => true,
			],
			$result
		);
	}

	/**
	 * Custom logo fallback.
	 */
	public function test_identity_import_falls_back_to_the_custom_logo_image() {
		$this->responses['http://localhost/wp-json/?_fields=name,description,site_logo,site_icon'] = [ 200, wp_json_encode( [ 'name' => 'Riverbend Gazette' ] ) ];
		$this->responses['http://localhost/'] = [ 200, '<html><body><a><img class="custom-logo" src="http://localhost/logo.png"></a></body></html>' ];

		Starter_Content::import_identity();
		$this->assertGreaterThan( 0, (int) get_theme_mod( 'custom_logo' ) );
	}

	/**
	 * An imported logo stands alone in the header, as one uploaded in Theme and Brand does.
	 */
	public function test_identity_import_hides_the_header_text_only_when_it_brings_a_logo() {
		set_theme_mod( 'header_text', true );
		set_theme_mod( 'header_display_tagline', true );
		$this->responses['http://localhost/wp-json/?_fields=name,description,site_logo,site_icon'] = [ 200, wp_json_encode( [ 'name' => 'Riverbend Gazette' ] ) ];
		$this->responses['http://localhost/'] = [ 200, '<html><body>No logo</body></html>' ];

		Starter_Content::import_identity();
		$this->assertTrue( get_theme_mod( 'header_text' ) );
		$this->assertTrue( get_theme_mod( 'header_display_tagline' ) );

		$this->responses['http://localhost/'] = [ 200, '<html><body><img class="custom-logo" src="http://localhost/logo.png"></body></html>' ];

		Starter_Content::import_identity();
		$this->assertGreaterThan( 0, (int) get_theme_mod( 'custom_logo' ) );
		$this->assertFalse( get_theme_mod( 'header_text' ) );
		$this->assertFalse( get_theme_mod( 'header_display_tagline' ) );
	}

	/**
	 * Every lookup on the moved site gives up quickly, and the homepage download is capped.
	 */
	public function test_identity_import_requests_are_time_bound() {
		$this->responses['http://localhost/wp-json/?_fields=name,description,site_logo,site_icon'] = [ 200, wp_json_encode( [ 'site_icon' => 12 ] ) ];
		$this->responses['http://localhost/wp-json/wp/v2/media/12?_fields=source_url'] = [ 200, wp_json_encode( [ 'source_url' => 'http://localhost/icon.png' ] ) ];
		$this->responses['http://localhost/'] = [ 200, '<html><body><img class="custom-logo" src="http://localhost/logo.png"></body></html>' ];

		Starter_Content::import_identity();
		$this->assertSame( 10, $this->requests['http://localhost/wp-json/?_fields=name,description,site_logo,site_icon']['timeout'] );
		$this->assertSame( 10, $this->requests['http://localhost/']['timeout'] );
		$this->assertSame( 512 * KB_IN_BYTES, $this->requests['http://localhost/']['limit_response_size'] );
		$this->assertSame( 15, $this->requests['http://localhost/logo.png']['timeout'] );
		$this->assertSame( 15, $this->requests['http://localhost/icon.png']['timeout'] );
	}

	/**
	 * Move the identity import's deadline, as if time had passed.
	 *
	 * @param float $seconds_left Seconds left before the deadline.
	 */
	private function leave_identity_import( float $seconds_left ) {
		$deadline = new ReflectionProperty( Starter_Content_WordPress::class, 'identity_deadline' );
		$deadline->setValue( null, microtime( true ) + $seconds_left );
	}

	/**
	 * Once the overall budget is spent, the remaining lookups are skipped.
	 */
	public function test_identity_import_skips_remaining_lookups_once_the_budget_is_spent() {
		$this->responses['http://localhost/wp-json/?_fields=name,description,site_logo,site_icon']               = [
			200,
			wp_json_encode(
				[
					'name'      => 'Riverbend Gazette',
					'site_logo' => 11,
					'site_icon' => 12,
				]
			),
		];
		$this->responses['http://localhost/wp-json/wp/v2/media/11?_fields=source_url'] = [ 200, wp_json_encode( [ 'source_url' => 'http://localhost/logo.png' ] ) ];
		$this->on_request['http://localhost/wp-json/?_fields=name,description,site_logo,site_icon']              = function() {
			$this->leave_identity_import( 0.5 );
		};

		$result = Starter_Content::import_identity();
		$this->assertTrue( $result['title'] );
		$this->assertFalse( $result['logo'] );
		$this->assertFalse( $result['icon'] );
		$this->assertFalse( $result['currency'] );
		$this->assertArrayNotHasKey( 'http://localhost/wp-json/wp/v2/media/11?_fields=source_url', $this->requests );
		$this->assertArrayNotHasKey( 'http://localhost/wp-json/wc/store/v1/cart', $this->requests );
	}

	/**
	 * A lookup gets no longer than the time left in the overall budget.
	 */
	public function test_identity_import_shortens_a_lookup_to_the_time_left() {
		$this->responses['http://localhost/wp-json/?_fields=name,description,site_logo,site_icon'] = [ 200, wp_json_encode( [ 'site_logo' => 11 ] ) ];
		$this->on_request['http://localhost/wp-json/?_fields=name,description,site_logo,site_icon'] = function() {
			$this->leave_identity_import( 3.5 );
		};

		Starter_Content::import_identity();
		$this->assertSame( 3, $this->requests['http://localhost/wp-json/wp/v2/media/11?_fields=source_url']['timeout'] );
	}

	/**
	 * A store currency another request saved during the lookup is kept.
	 */
	public function test_identity_import_keeps_a_currency_saved_during_the_lookup() {
		$this->responses['http://localhost/wp-json/wc/store/v1/cart'] = [ 200, wp_json_encode( [ 'totals' => [ 'currency_code' => 'USD' ] ] ) ];
		$this->on_request['http://localhost/wp-json/wc/store/v1/cart'] = function() {
			global $wpdb;
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->options,
				[
					'option_name'  => 'woocommerce_currency',
					'option_value' => 'GBP',
					'autoload'     => 'yes',
				]
			);
		};

		$result = Starter_Content::import_identity();
		$this->assertFalse( $result['currency'] );
		$this->assertSame( 'GBP', get_option( 'woocommerce_currency' ) );
	}

	/**
	 * A site icon another request saved during the download is kept, and the download discarded.
	 */
	public function test_identity_import_keeps_a_site_icon_saved_during_the_download() {
		$this->responses['http://localhost/wp-json/?_fields=name,description,site_logo,site_icon']               = [ 200, wp_json_encode( [ 'site_icon' => 12 ] ) ];
		$this->responses['http://localhost/wp-json/wp/v2/media/12?_fields=source_url'] = [ 200, wp_json_encode( [ 'source_url' => 'http://localhost/icon.png' ] ) ];
		$this->on_request['http://localhost/icon.png'] = function() {
			global $wpdb;
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->options,
				[
					'option_name'  => 'site_icon',
					'option_value' => '99',
					'autoload'     => 'yes',
				]
			);
		};

		$result = Starter_Content::import_identity();
		$this->assertFalse( $result['icon'] );
		$this->assertSame( '99', get_option( 'site_icon' ) );
		$this->assertSame(
			[],
			get_posts(
				[
					'post_type'   => 'attachment',
					'post_status' => 'any',
					'fields'      => 'ids',
				]
			)
		);
	}

	/**
	 * A download that is not a usable image is skipped and its temporary file removed.
	 */
	public function test_identity_import_removes_the_download_when_the_image_is_rejected() {
		$this->responses['http://localhost/'] = [ 200, '<html><body><img class="custom-logo" src="http://localhost/broken.png"></body></html>' ];

		$result   = Starter_Content::import_identity();
		$tmp_name = $this->requests['http://localhost/broken.png']['filename'];
		$this->assertFalse( $result['logo'] );
		$this->assertFalse( get_theme_mod( 'custom_logo' ) );
		$this->assertFileDoesNotExist( $tmp_name );
	}

	/**
	 * A store currency Newspack does not list is not brought over.
	 */
	public function test_identity_import_skips_a_currency_newspack_does_not_list() {
		$this->responses['http://localhost/wp-json/wc/store/v1/cart'] = [ 200, wp_json_encode( [ 'totals' => [ 'currency_code' => 'XYZ' ] ] ) ];

		$result = Starter_Content::import_identity();
		$this->assertFalse( $result['currency'] );
		$this->assertFalse( get_option( 'woocommerce_currency' ) );
	}

	/**
	 * A relative or protocol-relative logo address is resolved against the moved site.
	 *
	 * @dataProvider relative_logo_sources
	 *
	 * @param string $src Logo src as written on the moved site.
	 */
	public function test_identity_import_resolves_a_relative_logo_address( $src ) {
		$this->responses['http://localhost/'] = [ 200, '<html><body><img class="custom-logo" src="' . $src . '"></body></html>' ];

		Starter_Content::import_identity();
		$this->assertArrayHasKey( 'http://localhost/wp-content/logo.png', $this->requests );
		$this->assertGreaterThan( 0, (int) get_theme_mod( 'custom_logo' ) );
	}

	/**
	 * Relative logo sources.
	 *
	 * @return array
	 */
	public function relative_logo_sources() {
		return [
			'root-relative'     => [ '/wp-content/logo.png' ],
			'protocol-relative' => [ '//localhost/wp-content/logo.png' ],
		];
	}

	/**
	 * Skips missing items.
	 */
	public function test_identity_import_skips_what_the_site_does_not_expose() {
		$this->responses['http://localhost/wp-json/?_fields=name,description,site_logo,site_icon'] = [
			200,
			wp_json_encode(
				[
					'name'        => 'Riverbend Gazette',
					'description' => 'Independent news',
				]
			),
		];
		$this->responses['http://localhost/'] = [ 200, '<html><body>No logo</body></html>' ];

		$result = Starter_Content::import_identity();
		$this->assertSame(
			[
				'title'    => true,
				'tagline'  => true,
				'logo'     => false,
				'icon'     => false,
				'currency' => false,
			],
			$result
		);
	}

	/**
	 * Existing values are kept.
	 */
	public function test_identity_import_keeps_an_existing_logo_and_icon() {
		set_theme_mod( 'custom_logo', 77 );
		update_option( 'site_icon', 78 );
		update_option( 'woocommerce_currency', 'GBP' );
		$this->responses['http://localhost/wp-json/?_fields=name,description,site_logo,site_icon']                = [
			200,
			wp_json_encode(
				[
					'name'      => 'Riverbend Gazette',
					'site_logo' => 11,
					'site_icon' => 12,
				]
			),
		];
		$this->responses['http://localhost/wp-json/wp/v2/media/11?_fields=source_url']  = [ 200, wp_json_encode( [ 'source_url' => 'http://localhost/logo.png' ] ) ];
		$this->responses['http://localhost/wp-json/wp/v2/media/12?_fields=source_url']  = [ 200, wp_json_encode( [ 'source_url' => 'http://localhost/icon.png' ] ) ];
		$this->responses['http://localhost/wp-json/wc/store/v1/cart'] = [ 200, wp_json_encode( [ 'totals' => [ 'currency_code' => 'USD' ] ] ) ];

		Starter_Content::import_identity();
		$this->assertSame( 77, (int) get_theme_mod( 'custom_logo' ) );
		$this->assertSame( 78, (int) get_option( 'site_icon' ) );
		$this->assertSame( 'GBP', get_option( 'woocommerce_currency' ) );
		$this->assertFalse( get_option( Starter_Content::IMPORTED_CURRENCY_OPTION ) );
	}
}
