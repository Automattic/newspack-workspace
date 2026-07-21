<?php
/**
 * Tests the InDesign Exporter functionality.
 *
 * @package Newspack\Tests
 */

use Newspack\Optional_Modules\InDesign_Export\InDesign_Converter;
use Newspack\Optional_Modules\InDesign_Exporter;

/**
 * Tests the InDesign Exporter functionality.
 */
class Newspack_Test_InDesign_Exporter extends WP_UnitTestCase {
	/**
	 * Post types individual tests may register. Torn down centrally so a failed
	 * assertion mid-test can't leak a registration into later tests.
	 *
	 * @var string[]
	 */
	private const TEST_POST_TYPES = [ 'product', 'hidden_cpt', 'partner_rss_feed', 'newspack_nl_list', 'newspack_collection', 'event', 'flyer', 'reviewcpt' ];

	/**
	 * The ambient User-Agent, captured so platform-resolution tests can restore it.
	 *
	 * @var string|null
	 */
	private $original_user_agent;

	/**
	 * Capture request state that individual tests may mutate.
	 */
	public function set_up() {
		parent::set_up();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__
		$this->original_user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
	}

	/**
	 * Set the request User-Agent for the current test. Wraps the write so the VIP
	 * cache-constraint sniff — not meaningful for a unit test — is silenced once.
	 *
	 * @param string $user_agent User-Agent string to set.
	 */
	private function set_request_user_agent( $user_agent ) {
		$_SERVER['HTTP_USER_AGENT'] = $user_agent; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__
	}

	/**
	 * Reset options, post-type registrations, and request state after every test,
	 * regardless of whether the test's own assertions passed. Keeping cleanup here
	 * (rather than inline at the end of each test) makes failures self-contained.
	 */
	public function tear_down() {
		delete_option( InDesign_Exporter::PLATFORM_OPTION );
		delete_option( InDesign_Exporter::POST_TYPES_OPTION );
		delete_option( InDesign_Exporter::EXCLUDE_CAPTIONS_OPTION );

		foreach ( self::TEST_POST_TYPES as $post_type ) {
			if ( post_type_exists( $post_type ) ) {
				unregister_post_type( $post_type );
			}
		}

		// Filters added by the register_list_table_actions() test. Removing a
		// filter that was never added is a no-op, so this is safe to run
		// unconditionally — and here (rather than inline after the test's
		// assertions) so a mid-test failure can't leak the hooks.
		remove_filter( 'bulk_actions-edit-reviewcpt', [ InDesign_Exporter::class, 'add_bulk_action' ] );
		remove_filter( 'handle_bulk_actions-edit-reviewcpt', [ InDesign_Exporter::class, 'handle_bulk_action' ], 100 );
		remove_filter( 'post_row_actions', [ InDesign_Exporter::class, 'add_row_action' ], 10 );
		remove_filter( 'page_row_actions', [ InDesign_Exporter::class, 'add_row_action' ], 10 );

		if ( null === $this->original_user_agent ) {
			unset( $_SERVER['HTTP_USER_AGENT'] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__
		} else {
			$_SERVER['HTTP_USER_AGENT'] = $this->original_user_agent; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__
		}

		parent::tear_down();
	}

	/**
	 * Test converting a simple post.
	 */
	public function test_convert_simple_post() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<p>This is a test post.</p>',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<ASCII-WIN>', $content );
		$this->assertStringContainsString( '<pstyle:24head>Test Post', $content );
		$this->assertStringContainsString( '<pstyle:text>This is a test post.', $content );
	}

	/**
	 * Test that the Mac platform option emits the <ASCII-MAC> header.
	 *
	 * InDesign on macOS requires the file to begin with <ASCII-MAC> for the
	 * tagged text to be interpreted as markup rather than literal content.
	 */
	public function test_convert_post_mac_platform() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<p>This is a test post.</p>',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id, [ 'platform' => 'mac' ] );
		$this->assertStringContainsString( '<ASCII-MAC>', $content );
		$this->assertStringNotContainsString( '<ASCII-WIN>', $content );
	}

	/**
	 * Test that the Win platform option emits the <ASCII-WIN> header.
	 */
	public function test_convert_post_win_platform() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<p>This is a test post.</p>',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id, [ 'platform' => 'win' ] );
		$this->assertStringContainsString( '<ASCII-WIN>', $content );
		$this->assertStringNotContainsString( '<ASCII-MAC>', $content );
	}

	/**
	 * Test that the platform setting defaults to 'auto' when unset.
	 */
	public function test_platform_setting_default() {
		delete_option( InDesign_Exporter::PLATFORM_OPTION );
		$this->assertSame( 'auto', InDesign_Exporter::get_platform_setting() );
	}

	/**
	 * Test that the platform setting returns the stored value when valid.
	 */
	public function test_platform_setting_valid_values() {
		update_option( InDesign_Exporter::PLATFORM_OPTION, 'mac' );
		$this->assertSame( 'mac', InDesign_Exporter::get_platform_setting() );

		update_option( InDesign_Exporter::PLATFORM_OPTION, 'win' );
		$this->assertSame( 'win', InDesign_Exporter::get_platform_setting() );

		update_option( InDesign_Exporter::PLATFORM_OPTION, 'auto' );
		$this->assertSame( 'auto', InDesign_Exporter::get_platform_setting() );
	}

	/**
	 * Test that the platform setting sanitizes invalid stored values.
	 */
	public function test_platform_setting_rejects_invalid_value() {
		update_option( InDesign_Exporter::PLATFORM_OPTION, 'linux' );
		$this->assertSame( 'auto', InDesign_Exporter::get_platform_setting() );

		update_option( InDesign_Exporter::PLATFORM_OPTION, '' );
		$this->assertSame( 'auto', InDesign_Exporter::get_platform_setting() );
	}

	/**
	 * Test that the post types setting defaults to ['post'] when unset.
	 */
	public function test_post_types_setting_default() {
		delete_option( InDesign_Exporter::POST_TYPES_OPTION );
		$this->assertSame( [ 'post' ], InDesign_Exporter::get_post_types_setting() );
	}

	/**
	 * Test that valid stored post types are returned.
	 */
	public function test_post_types_setting_valid_values() {
		update_option( InDesign_Exporter::POST_TYPES_OPTION, [ 'post', 'page' ] );
		$this->assertSame( [ 'post', 'page' ], InDesign_Exporter::get_post_types_setting() );
	}

	/**
	 * Test that slugs whose post type is no longer registered get filtered out.
	 */
	public function test_post_types_setting_drops_stale_slugs() {
		update_option( InDesign_Exporter::POST_TYPES_OPTION, [ 'post', 'no_such_cpt', 42, '' ] );
		$this->assertSame( [ 'post' ], InDesign_Exporter::get_post_types_setting() );
	}

	/**
	 * Test that a non-array stored value falls back to the default.
	 */
	public function test_post_types_setting_rejects_non_array() {
		update_option( InDesign_Exporter::POST_TYPES_OPTION, 'post' );
		$this->assertSame( [ 'post' ], InDesign_Exporter::get_post_types_setting() );
	}

	/**
	 * Test that slugs hidden from the settings UI (excluded, or not public/no
	 * admin UI) are dropped from the stored setting, even if registered. This
	 * keeps the stored value in sync with what the admin can actually manage.
	 */
	public function test_post_types_setting_drops_unavailable_slugs() {
		// `product` is registered and public but lives in EXCLUDED_POST_TYPES.
		register_post_type(
			'product',
			[
				'public'  => true,
				'show_ui' => true,
			]
		);
		// Registered but not exposed in the admin UI, so never "available".
		register_post_type(
			'hidden_cpt',
			[
				'public'  => false,
				'show_ui' => false,
			]
		);

		update_option( InDesign_Exporter::POST_TYPES_OPTION, [ 'post', 'product', 'hidden_cpt' ] );
		$this->assertSame( [ 'post' ], InDesign_Exporter::get_post_types_setting() );
	}

	/**
	 * Test that get_supported_post_types() honors the stored setting.
	 */
	public function test_get_supported_post_types_uses_setting() {
		update_option( InDesign_Exporter::POST_TYPES_OPTION, [ 'page' ] );
		$this->assertSame( [ 'page' ], InDesign_Exporter::get_supported_post_types() );
	}

	/**
	 * Test that available_post_types excludes attachments, RSS feeds,
	 * subscription lists, collections, and WooCommerce products.
	 */
	public function test_get_available_post_types_excludes_non_editorial_types() {
		register_post_type(
			'partner_rss_feed',
			[
				'public'  => true,
				'show_ui' => true,
			]
		);
		register_post_type(
			'newspack_nl_list',
			[
				'public'  => true,
				'show_ui' => true,
			]
		);
		register_post_type(
			'newspack_collection',
			[
				'public'  => true,
				'show_ui' => true,
			]
		);
		register_post_type(
			'product',
			[
				'public'  => true,
				'show_ui' => true,
			]
		);
		register_post_type(
			'event',
			[
				'public'  => true,
				'show_ui' => true,
			]
		);

		$available = InDesign_Exporter::get_available_post_types();
		$slugs     = array_column( $available, 'value' );

		$this->assertContains( 'post', $slugs );
		$this->assertContains( 'page', $slugs );
		$this->assertContains( 'event', $slugs, 'Editorial CPTs should remain available.' );
		$this->assertNotContains( 'attachment', $slugs );
		$this->assertNotContains( 'partner_rss_feed', $slugs );
		$this->assertNotContains( 'newspack_nl_list', $slugs );
		$this->assertNotContains( 'newspack_collection', $slugs );
		$this->assertNotContains( 'product', $slugs );
	}

	/**
	 * Test that the excluded-types filter can add or remove exclusions.
	 */
	public function test_get_available_post_types_filter() {
		register_post_type(
			'flyer',
			[
				'public'  => true,
				'show_ui' => true,
			]
		);

		$callback = static function ( $excluded ) {
			$excluded[] = 'flyer';
			return $excluded;
		};
		add_filter( 'newspack_indesign_export_excluded_post_types', $callback );

		$available = InDesign_Exporter::get_available_post_types();
		$slugs     = array_column( $available, 'value' );

		$this->assertNotContains( 'flyer', $slugs );

		remove_filter( 'newspack_indesign_export_excluded_post_types', $callback );
	}

	/**
	 * Test User-Agent → platform mapping for representative strings.
	 */
	public function test_sniff_user_agent_platform() {
		// macOS Safari / Chrome.
		$this->assertSame(
			'mac',
			InDesign_Exporter::sniff_user_agent_platform( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15' )
		);
		// iPad.
		$this->assertSame(
			'mac',
			InDesign_Exporter::sniff_user_agent_platform( 'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15' )
		);
		// iPhone.
		$this->assertSame(
			'mac',
			InDesign_Exporter::sniff_user_agent_platform( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15' )
		);
		// Windows Chrome.
		$this->assertSame(
			'win',
			InDesign_Exporter::sniff_user_agent_platform( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36' )
		);
		// Linux (treated as Windows-compatible by InDesign Tagged Text — there is no Linux variant).
		$this->assertSame(
			'win',
			InDesign_Exporter::sniff_user_agent_platform( 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36' )
		);
		// Empty.
		$this->assertSame( 'win', InDesign_Exporter::sniff_user_agent_platform( '' ) );
	}

	/**
	 * Test that is_post_supported gates posts by the configured post types setting.
	 */
	public function test_is_post_supported() {
		update_option( InDesign_Exporter::POST_TYPES_OPTION, [ 'post' ] );

		$post_id = $this->factory->post->create();
		$page_id = $this->factory->post->create( [ 'post_type' => 'page' ] );

		$this->assertTrue( InDesign_Exporter::is_post_supported( $post_id ) );
		$this->assertFalse( InDesign_Exporter::is_post_supported( $page_id ) );
		$this->assertFalse( InDesign_Exporter::is_post_supported( 0 ) );
		$this->assertFalse( InDesign_Exporter::is_post_supported( 99999999 ) );

		update_option( InDesign_Exporter::POST_TYPES_OPTION, [ 'post', 'page' ] );
		$this->assertTrue( InDesign_Exporter::is_post_supported( $page_id ) );
	}

	/**
	 * Test that en-dashes and em-dashes map to their own Unicode code points.
	 *
	 * Previously '–' (en-dash, U+2013) was incorrectly mapped to <0x2014> (em-dash).
	 */
	public function test_convert_dashes() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<p>en–dash and em—dash and double--hyphen.</p>',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id );
		$this->assertStringContainsString( 'en<0x2013>dash', $content );
		$this->assertStringContainsString( 'em<0x2014>dash', $content );
		$this->assertStringContainsString( 'double<0x2014>hyphen', $content );
	}

	/**
	 * Test converting pullquotes.
	 */
	public function test_convert_pullquote() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<blockquote><p>A pullquote content</p><cite>John Doe</cite></blockquote>',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<pstyle:pullquote>A pullquote content', $content );
		$this->assertStringContainsString( '<pstyle:pullquotename>John Doe', $content );
	}

	/**
	 * Test converting blockquotes.
	 */
	public function test_convert_blockquote() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<blockquote class="wp-block-quote">This is a blockquote.</blockquote>',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<pstyle:blockquote>This is a blockquote.', $content );
	}

	/**
	 * Test converting lists.
	 */
	public function test_convert_list() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<ul><li>Item 1.</li><li>Item 2.</li></ul>',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<bnListType:Bullet>Item 1.<bnListType:>', $content );
		$this->assertStringContainsString( '<bnListType:Bullet>Item 2.<bnListType:>', $content );
	}

	/**
	 * Test cleaning HTML markup.
	 */
	public function test_clean_html_markup() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<div><p>This is a test post.</p></div>',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<pstyle:text>This is a test post.', $content );
		$this->assertStringNotContainsString( '<div>', $content );
		$this->assertStringNotContainsString( '<p>', $content );
	}

	/**
	 * Test converting superscript and subscript.
	 */
	public function test_convert_superscript_and_subscript() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<p>This is a test post with <sup>superscript</sup> and <sub>subscript</sub>.</p>',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<pstyle:text>This is a test post with <cPosition:Superscript>superscript<cPosition:> and <cPosition:Subscript>subscript<cPosition:>.', $content );
	}

	/**
	 * Test cleaning img markup.
	 */
	public function test_clean_img_markup() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<figure class="wp-block-image size-large"><img src="http://localhost/image.jpg" alt="" class="wp-image-1234"/><figcaption class="wp-element-caption">My Caption <span class="image-credit"><span class="credit-label-wrapper">Credit:</span> <a href="http://localhost/credit">My Credit</a></span></figcaption></figure>',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringNotContainsString( '<figure', $content );
		$this->assertStringNotContainsString( '<figcaption', $content );
		$this->assertStringNotContainsString( '<img', $content );
	}

	/**
	 * Test image processing.
	 */
	public function test_image_processing() {
		$thumbnail_id = $this->factory->attachment->create();
		wp_update_post(
			[
				'ID'           => $thumbnail_id,
				'post_excerpt' => 'Featured Image Caption',
			]
		);
		update_post_meta( $thumbnail_id, '_media_credit', 'Featured Image Credit' );

		$image_id = $this->factory->attachment->create();
		wp_update_post(
			[
				'ID'           => $image_id,
				'post_excerpt' => 'Image Caption',
			]
		);
		update_post_meta( $image_id, '_media_credit', 'Image Credit' );

		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:image {"id":' . $image_id . '} --><!-- /wp:image -->',
			]
		);
		update_post_meta( $post_id, '_thumbnail_id', $thumbnail_id );

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<pstyle:PhotoCaption>Featured Image Caption', $content );
		$this->assertStringContainsString( '<pstyle:PhotoCredit>Featured Image Credit', $content );
		$this->assertStringContainsString( '<pstyle:PhotoCaption>Image Caption', $content );
		$this->assertStringContainsString( '<pstyle:PhotoCredit>Image Credit', $content );
	}

	/**
	 * Test image with custom caption.
	 */
	public function test_image_with_custom_caption() {
		$image_id = $this->factory->attachment->create();
		wp_update_post(
			[
				'ID'           => $image_id,
				'post_excerpt' => 'Image Caption',
			]
		);

		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:image {"id":' . $image_id . '} --><figure class="wp-block-image"><img src="http://localhost/wp-content/uploads/2025/01/image.jpg" /><figcaption class="wp-element-caption">Custom Caption</figcaption></figure><!-- /wp:image -->',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<pstyle:PhotoCaption>Custom Caption', $content );
	}

	/**
	 * Test converting HTML entities.
	 */
	public function test_convert_html_entities() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<p>This is a test post with &nbsp;, &amp;, &lt;, &gt; and •.</p>',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<pstyle:text>This is a test post with  , &, <, > and <CharStyle:bullet>n<CharStyle:>.', $content );
	}

	/**
	 * Test converting special characters.
	 */
	public function test_convert_special_characters() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<p>àáâãäåæçèéêëìíîïñòóôõöøùúûüýÿĀāĂă…€</p>',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<0x00E0><0x00E1><0x00E2><0x00E3><0x00E4><0x00E5><0x00E6><0x00E7><0x00E8><0x00E9><0x00EA><0x00EB><0x00EC><0x00ED><0x00EE><0x00EF><0x00F1><0x00F2><0x00F3><0x00F4><0x00F5><0x00F6><0x00F8><0x00F9><0x00FA><0x00FB><0x00FC><0x00FD><0x00FF><0x0100><0x0101><0x0102><0x0103><0x2026><0x20AC>', $content );
	}

	/**
	 * Test blocks with custom tags.
	 */
	public function test_convert_blocks_with_custom_tags() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:paragraph {"indesignTag":"customparagraph"} --><p>This is a test post with custom tag.</p><!-- /wp:paragraph -->',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<customparagraph>This is a test post with custom tag.', $content );
	}

	/**
	 * Test headings.
	 */
	public function test_convert_headings() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<h1>Heading 1</h1><h2>Heading 2</h2><h3>Heading 3</h3><h4>Heading 4</h4><h5>Heading 5</h5><h6>Heading 6</h6>',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<pstyle:h1>Heading 1', $content );
		$this->assertStringContainsString( '<pstyle:h2>Heading 2', $content );
		$this->assertStringContainsString( '<pstyle:h3>Heading 3', $content );
		$this->assertStringContainsString( '<pstyle:h4>Heading 4', $content );
		$this->assertStringContainsString( '<pstyle:h5>Heading 5', $content );
		$this->assertStringContainsString( '<pstyle:h6>Heading 6', $content );
	}

	/**
	 * Test horizontal rule.
	 */
	public function test_convert_horizontal_rule() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<hr>',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<pstyle:hr>', $content );
	}

	/**
	 * Test that core/file blocks are excluded from export.
	 *
	 * PDF embeds have no print equivalent and their raw markup (<object> tags,
	 * download links) must not appear in the InDesign output.
	 */
	public function test_file_block_excluded() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:paragraph --><p>Before the file.</p><!-- /wp:paragraph --><!-- wp:file {"id":1,"href":"https://example.com/document.pdf"} --><div class="wp-block-file"><object class="wp-block-file__embed" data="https://example.com/document.pdf" type="application/pdf" style="width:100%;height:600px"></object><a href="https://example.com/document.pdf" class="wp-block-file__button">Download</a></div><!-- /wp:file --><!-- wp:paragraph --><p>After the file.</p><!-- /wp:paragraph -->',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id );

		$this->assertStringContainsString( 'Before the file.', $content );
		$this->assertStringContainsString( 'After the file.', $content );
		$this->assertStringNotContainsString( '<object', $content );
		$this->assertStringNotContainsString( 'document.pdf', $content );
		$this->assertStringNotContainsString( 'Download', $content );
	}

	/**
	 * Test that core/embed blocks are excluded from export.
	 *
	 * Rich media embeds (YouTube, etc.) have no print equivalent and their
	 * raw URLs must not appear in the InDesign output.
	 */
	public function test_embed_block_excluded() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:paragraph --><p>Before the embed.</p><!-- /wp:paragraph --><!-- wp:embed {"url":"https://www.youtube.com/watch?v=abc123","type":"video","providerNameSlug":"youtube"} --><figure class="wp-block-embed is-type-video is-provider-youtube"><div class="wp-block-embed__wrapper">' . "\n" . 'https://www.youtube.com/watch?v=abc123' . "\n" . '</div></figure><!-- /wp:embed --><!-- wp:paragraph --><p>After the embed.</p><!-- /wp:paragraph -->',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id );

		$this->assertStringContainsString( 'Before the embed.', $content );
		$this->assertStringContainsString( 'After the embed.', $content );
		$this->assertStringNotContainsString( 'youtube.com', $content );
		$this->assertStringNotContainsString( 'abc123', $content );
	}

	/**
	 * Test that core/file blocks are excluded from export when nested inside a group block.
	 */
	public function test_file_block_excluded_when_nested_in_group() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:paragraph --><p>Before the group.</p><!-- /wp:paragraph --><!-- wp:group --><div class="wp-block-group"><!-- wp:file {"id":1,"href":"https://example.com/document.pdf"} --><div class="wp-block-file"><object class="wp-block-file__embed" data="https://example.com/document.pdf" type="application/pdf" style="width:100%;height:600px"></object><a href="https://example.com/document.pdf" class="wp-block-file__button">Download</a></div><!-- /wp:file --></div><!-- /wp:group --><!-- wp:paragraph --><p>After the group.</p><!-- /wp:paragraph -->',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id );

		$this->assertStringContainsString( 'Before the group.', $content );
		$this->assertStringContainsString( 'After the group.', $content );
		$this->assertStringNotContainsString( '<object', $content );
		$this->assertStringNotContainsString( 'document.pdf', $content );
		$this->assertStringNotContainsString( 'Download', $content );
	}

	/**
	 * Test that core/embed blocks are excluded from export when nested inside a group block.
	 */
	public function test_embed_block_excluded_when_nested_in_group() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:paragraph --><p>Before the group.</p><!-- /wp:paragraph --><!-- wp:group --><div class="wp-block-group"><!-- wp:embed {"url":"https://www.youtube.com/watch?v=abc123","type":"video","providerNameSlug":"youtube"} --><figure class="wp-block-embed is-type-video is-provider-youtube"><div class="wp-block-embed__wrapper">' . "\n" . 'https://www.youtube.com/watch?v=abc123' . "\n" . '</div></figure><!-- /wp:embed --></div><!-- /wp:group --><!-- wp:paragraph --><p>After the group.</p><!-- /wp:paragraph -->',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id );

		$this->assertStringContainsString( 'Before the group.', $content );
		$this->assertStringContainsString( 'After the group.', $content );
		$this->assertStringNotContainsString( 'youtube.com', $content );
		$this->assertStringNotContainsString( 'abc123', $content );
	}

	/**
	 * Test that core/video blocks are excluded from export.
	 *
	 * Video embeds have no print equivalent and their raw markup must not
	 * appear in the InDesign output.
	 */
	public function test_video_block_excluded() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:paragraph --><p>Before the video.</p><!-- /wp:paragraph --><!-- wp:video {"id":1} --><figure class="wp-block-video"><video controls src="https://example.com/video.mp4"></video></figure><!-- /wp:video --><!-- wp:paragraph --><p>After the video.</p><!-- /wp:paragraph -->',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id );

		$this->assertStringContainsString( 'Before the video.', $content );
		$this->assertStringContainsString( 'After the video.', $content );
		$this->assertStringNotContainsString( 'video.mp4', $content );
		$this->assertStringNotContainsString( '<video', $content );
	}

	/**
	 * Test that core/audio blocks are excluded from export.
	 *
	 * Audio embeds have no print equivalent and their raw markup must not
	 * appear in the InDesign output.
	 */
	public function test_audio_block_excluded() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:paragraph --><p>Before the audio.</p><!-- /wp:paragraph --><!-- wp:audio {"id":1} --><figure class="wp-block-audio"><audio controls src="https://example.com/audio.mp3"></audio></figure><!-- /wp:audio --><!-- wp:paragraph --><p>After the audio.</p><!-- /wp:paragraph -->',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id );

		$this->assertStringContainsString( 'Before the audio.', $content );
		$this->assertStringContainsString( 'After the audio.', $content );
		$this->assertStringNotContainsString( 'audio.mp3', $content );
		$this->assertStringNotContainsString( '<audio', $content );
	}

	/**
	 * Test that core/embed blocks are excluded from export when nested inside a columns block.
	 *
	 * The core/columns block has a different innerContent shape from core/group (it contains
	 * core/column children which in turn contain the embed), exercising the recursive
	 * strip logic through two container levels.
	 */
	public function test_embed_block_excluded_when_nested_in_columns() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:paragraph --><p>Before the columns.</p><!-- /wp:paragraph --><!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:embed {"url":"https://www.youtube.com/watch?v=xyz789","type":"video","providerNameSlug":"youtube"} --><figure class="wp-block-embed is-type-video is-provider-youtube"><div class="wp-block-embed__wrapper">' . "\n" . 'https://www.youtube.com/watch?v=xyz789' . "\n" . '</div></figure><!-- /wp:embed --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Text in second column.</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns --><!-- wp:paragraph --><p>After the columns.</p><!-- /wp:paragraph -->',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id );

		$this->assertStringContainsString( 'Before the columns.', $content );
		$this->assertStringContainsString( 'After the columns.', $content );
		$this->assertStringNotContainsString( 'youtube.com', $content );
		$this->assertStringNotContainsString( 'xyz789', $content );
	}

	/**
	 * Test that two consecutive excluded blocks inside a container are both removed.
	 *
	 * This exercises the $inner_index increment path in strip_excluded_blocks() where
	 * two null placeholders in innerContent map to two consecutive excluded innerBlocks
	 * entries — ensuring the index stays in sync after the first block is skipped.
	 */
	public function test_two_excluded_siblings_in_container() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:paragraph --><p>Before the group.</p><!-- /wp:paragraph --><!-- wp:group --><div class="wp-block-group"><!-- wp:embed {"url":"https://www.youtube.com/watch?v=first","type":"video","providerNameSlug":"youtube"} --><figure class="wp-block-embed is-type-video is-provider-youtube"><div class="wp-block-embed__wrapper">' . "\n" . 'https://www.youtube.com/watch?v=first' . "\n" . '</div></figure><!-- /wp:embed --><!-- wp:embed {"url":"https://www.youtube.com/watch?v=second","type":"video","providerNameSlug":"youtube"} --><figure class="wp-block-embed is-type-video is-provider-youtube"><div class="wp-block-embed__wrapper">' . "\n" . 'https://www.youtube.com/watch?v=second' . "\n" . '</div></figure><!-- /wp:embed --><!-- wp:paragraph --><p>After both embeds.</p><!-- /wp:paragraph --></div><!-- /wp:group --><!-- wp:paragraph --><p>After the group.</p><!-- /wp:paragraph -->',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id );

		$this->assertStringContainsString( 'Before the group.', $content );
		$this->assertStringContainsString( 'After both embeds.', $content );
		$this->assertStringContainsString( 'After the group.', $content );
		$this->assertStringNotContainsString( 'first', $content );
		$this->assertStringNotContainsString( 'second', $content );
	}

	/**
	 * Test that legacy core-embed/* blocks (pre-WP 5.6) are excluded from export.
	 *
	 * WordPress 5.6 unified embed blocks under core/embed. Older content may still
	 * contain core-embed/youtube, core-embed/vimeo, etc. These must also be excluded.
	 */
	public function test_legacy_core_embed_block_excluded() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:paragraph --><p>Before the embed.</p><!-- /wp:paragraph --><!-- wp:core-embed/youtube {"url":"https://www.youtube.com/watch?v=legacy123"} --><figure class="wp-block-embed-youtube"><div class="wp-block-embed__wrapper">' . "\n" . 'https://www.youtube.com/watch?v=legacy123' . "\n" . '</div></figure><!-- /wp:core-embed/youtube --><!-- wp:paragraph --><p>After the embed.</p><!-- /wp:paragraph -->',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id );

		$this->assertStringContainsString( 'Before the embed.', $content );
		$this->assertStringContainsString( 'After the embed.', $content );
		$this->assertStringNotContainsString( 'youtube.com', $content );
		$this->assertStringNotContainsString( 'legacy123', $content );
	}

	/**
	 * Test that a custom block type added via the filter is excluded from export.
	 *
	 * Verifies the `newspack_indesign_export_excluded_blocks` filter is an effective
	 * extension point for publishers with custom rich-media blocks.
	 */
	public function test_custom_block_excluded_via_filter() {
		$callback = function ( $types ) {
			$types[] = 'my-plugin/custom-embed';
			return $types;
		};
		add_filter( 'newspack_indesign_export_excluded_blocks', $callback );

		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:paragraph --><p>Before the custom block.</p><!-- /wp:paragraph --><!-- wp:my-plugin/custom-embed --><div>CUSTOM_EMBED_MARKER</div><!-- /wp:my-plugin/custom-embed --><!-- wp:paragraph --><p>After the custom block.</p><!-- /wp:paragraph -->',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id );

		remove_filter( 'newspack_indesign_export_excluded_blocks', $callback );

		$this->assertStringContainsString( 'Before the custom block.', $content );
		$this->assertStringContainsString( 'After the custom block.', $content );
		$this->assertStringNotContainsString( 'CUSTOM_EMBED_MARKER', $content );
	}

	/**
	 * Test that a misbehaving filter callback does not break the export.
	 *
	 * The filter result is normalized to an array of strings, so a callback
	 * returning null, a string, or any non-array type must not cause a TypeError.
	 */
	public function test_filter_returning_non_array_does_not_break_export() {
		$callback = function () {
			return null;
		};
		add_filter( 'newspack_indesign_export_excluded_blocks', $callback );

		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:paragraph --><p>Content survives a bad filter.</p><!-- /wp:paragraph -->',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id );

		remove_filter( 'newspack_indesign_export_excluded_blocks', $callback );

		$this->assertStringContainsString( 'Content survives a bad filter.', $content );
	}

	/**
	 * Test that legacy core-embed/* blocks follow the core/embed filter state.
	 *
	 * When a publisher removes core/embed from the filter to allow embed content
	 * in exports, legacy core-embed/* blocks should also be allowed for consistency.
	 */
	public function test_legacy_core_embed_follows_core_embed_filter() {
		$callback = function ( $types ) {
			return array_values( array_diff( $types, [ 'core/embed' ] ) );
		};
		add_filter( 'newspack_indesign_export_excluded_blocks', $callback );

		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:paragraph --><p>Before the embed.</p><!-- /wp:paragraph --><!-- wp:core-embed/youtube {"url":"https://www.youtube.com/watch?v=legacy123"} --><figure class="wp-block-embed-youtube"><div class="wp-block-embed__wrapper">' . "\n" . 'https://www.youtube.com/watch?v=legacy123' . "\n" . '</div></figure><!-- /wp:core-embed/youtube --><!-- wp:paragraph --><p>After the embed.</p><!-- /wp:paragraph -->',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id );

		remove_filter( 'newspack_indesign_export_excluded_blocks', $callback );

		$this->assertStringContainsString( 'Before the embed.', $content );
		$this->assertStringContainsString( 'After the embed.', $content );
		$this->assertStringContainsString( 'legacy123', $content );
	}

	/**
	 * Test image caption and credit special characters.
	 */
	public function test_image_caption_and_credit_special_characters() {
		$image_id = $this->factory->attachment->create();
		wp_update_post(
			[
				'ID'           => $image_id,
				'post_excerpt' => 'Image Caption with á é í ó ú ñ ç ð ð &nbsp;, &amp;, &lt;, &gt; and •.',
			]
		);
		update_post_meta( $image_id, '_media_credit', 'Image Credit with á é í ó ú ñ ç ð ð &nbsp;, &amp;, &lt;, &gt; and •.' );

		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:image {"id":' . $image_id . '} --><figure class="wp-block-image"><img src="http://localhost/wp-content/uploads/2025/01/image.jpg" /><figcaption class="wp-element-caption">Image Caption with á é í ó ú ñ ç ð ð &nbsp;, &amp;, &lt;, &gt; and •.</figcaption></figure><!-- /wp:image -->',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<pstyle:PhotoCaption>Image Caption with <0x00E1> <0x00E9> <0x00ED> <0x00F3> <0x00FA> <0x00F1> <0x00E7> <0x00F0> <0x00F0>  , &, <, > and <CharStyle:bullet>n<CharStyle:>.', $content );
		$this->assertStringContainsString( '<pstyle:PhotoCredit>Image Credit with <0x00E1> <0x00E9> <0x00ED> <0x00F3> <0x00FA> <0x00F1> <0x00E7> <0x00F0> <0x00F0>  , &, <, > and <CharStyle:bullet>n<CharStyle:>.', $content );
	}

	/**
	 * Test that photo captions are dropped when include_captions is false, while
	 * photo credits — a separate attribution field — are still exported.
	 */
	public function test_convert_post_excludes_captions_when_disabled() {
		$image_id = $this->factory->attachment->create();
		wp_update_post(
			[
				'ID'           => $image_id,
				'post_excerpt' => 'Image Caption',
			]
		);
		update_post_meta( $image_id, '_media_credit', 'Image Credit' );

		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:image {"id":' . $image_id . '} --><!-- /wp:image -->',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id, [ 'include_captions' => false ] );

		$this->assertStringNotContainsString( '<pstyle:PhotoCaption>', $content );
		$this->assertStringNotContainsString( 'Image Caption', $content );
		$this->assertStringContainsString( '<pstyle:PhotoCredit>Image Credit', $content );
	}

	/**
	 * Test that an image carrying only a caption (no credit) produces no photo
	 * block at all when captions are disabled.
	 */
	public function test_convert_post_excludes_caption_only_image_when_disabled() {
		$image_id = $this->factory->attachment->create();
		wp_update_post(
			[
				'ID'           => $image_id,
				'post_excerpt' => 'Caption Only Image',
			]
		);

		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:image {"id":' . $image_id . '} --><!-- /wp:image -->',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id, [ 'include_captions' => false ] );

		$this->assertStringNotContainsString( 'Caption Only Image', $content );
		$this->assertStringNotContainsString( '<pstyle:PhotoCaption>', $content );
		$this->assertStringNotContainsString( '<pstyle:PhotoCredit>', $content );
	}

	/**
	 * Test that captions are included by default (preserves prior behavior when
	 * the option is omitted).
	 */
	public function test_convert_post_includes_captions_by_default() {
		$image_id = $this->factory->attachment->create();
		wp_update_post(
			[
				'ID'           => $image_id,
				'post_excerpt' => 'Default Caption',
			]
		);

		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:image {"id":' . $image_id . '} --><!-- /wp:image -->',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id );

		$this->assertStringContainsString( '<pstyle:PhotoCaption>Default Caption', $content );
	}

	/**
	 * Test that the exclude-captions setting defaults to false when unset.
	 */
	public function test_exclude_captions_setting_default() {
		delete_option( InDesign_Exporter::EXCLUDE_CAPTIONS_OPTION );
		$this->assertFalse( InDesign_Exporter::get_exclude_captions_setting() );
	}

	/**
	 * Test that the exclude-captions setting returns the stored boolean value.
	 */
	public function test_exclude_captions_setting_returns_stored_bool() {
		update_option( InDesign_Exporter::EXCLUDE_CAPTIONS_OPTION, true );
		$this->assertTrue( InDesign_Exporter::get_exclude_captions_setting() );

		update_option( InDesign_Exporter::EXCLUDE_CAPTIONS_OPTION, false );
		$this->assertFalse( InDesign_Exporter::get_exclude_captions_setting() );
	}

	/**
	 * Test that register_list_table_actions() registers the bulk export action
	 * for a configured custom post type.
	 *
	 * Guards the hook-ordering fix: the module boots at file scope (before
	 * `init`), but custom post types register on `init`, so bulk-action
	 * registration is deferred to `init` priority 20. Calling the deferred
	 * method directly here reproduces that post-`init` timing.
	 */
	public function test_register_list_table_actions_registers_bulk_action_for_custom_post_type() {
		register_post_type(
			'reviewcpt',
			[
				'public'  => true,
				'show_ui' => true,
			]
		);
		update_option( InDesign_Exporter::POST_TYPES_OPTION, [ 'reviewcpt' ] );

		// The dynamic bulk-action filter must not exist before registration runs.
		$this->assertFalse( has_filter( 'bulk_actions-edit-reviewcpt', [ InDesign_Exporter::class, 'add_bulk_action' ] ) );

		InDesign_Exporter::register_list_table_actions();

		$this->assertNotFalse(
			has_filter( 'bulk_actions-edit-reviewcpt', [ InDesign_Exporter::class, 'add_bulk_action' ] ),
			'The bulk export action must be registered for a configured custom post type.'
		);
		$this->assertNotFalse(
			has_filter( 'handle_bulk_actions-edit-reviewcpt', [ InDesign_Exporter::class, 'handle_bulk_action' ] )
		);
		// The filters registered here are removed in tear_down(), so a failed
		// assertion above can't leak them into later tests.
	}

	/**
	 * Test that an explicit platform setting wins over the request User-Agent.
	 */
	public function test_resolve_platform_setting_overrides_user_agent() {
		update_option( InDesign_Exporter::PLATFORM_OPTION, 'mac' );
		$this->set_request_user_agent( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)' );
		$this->assertSame( 'mac', InDesign_Exporter::resolve_platform() );

		update_option( InDesign_Exporter::PLATFORM_OPTION, 'win' );
		$this->set_request_user_agent( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5)' );
		$this->assertSame( 'win', InDesign_Exporter::resolve_platform() );
	}

	/**
	 * Test that the 'auto' setting resolves the platform from the User-Agent.
	 */
	public function test_resolve_platform_auto_sniffs_user_agent() {
		update_option( InDesign_Exporter::PLATFORM_OPTION, 'auto' );

		$this->set_request_user_agent( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5)' );
		$this->assertSame( 'mac', InDesign_Exporter::resolve_platform() );

		$this->set_request_user_agent( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)' );
		$this->assertSame( 'win', InDesign_Exporter::resolve_platform() );
	}

	/**
	 * Test that the platform filter can override the resolved value, and that a
	 * non-'mac' return normalizes to 'win' instead of leaking an invalid platform.
	 */
	public function test_resolve_platform_filter_overrides_and_normalizes() {
		update_option( InDesign_Exporter::PLATFORM_OPTION, 'win' );

		$to_mac = static function () {
			return 'mac';
		};
		add_filter( 'newspack_indesign_export_platform', $to_mac );
		$this->assertSame( 'mac', InDesign_Exporter::resolve_platform() );
		remove_filter( 'newspack_indesign_export_platform', $to_mac );

		$to_auto = static function () {
			return 'auto';
		};
		add_filter( 'newspack_indesign_export_platform', $to_auto );
		$this->assertSame( 'win', InDesign_Exporter::resolve_platform() );
		remove_filter( 'newspack_indesign_export_platform', $to_auto );
	}

	/**
	 * Test that the platform filter receives the resolved platform, the stored
	 * setting, and the sanitized User-Agent.
	 */
	public function test_resolve_platform_filter_receives_context() {
		update_option( InDesign_Exporter::PLATFORM_OPTION, 'auto' );
		$this->set_request_user_agent( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5)' );

		$captured = [];
		$callback = static function ( $platform, $setting, $user_agent ) use ( &$captured ) {
			$captured = compact( 'platform', 'setting', 'user_agent' );
			return $platform;
		};
		add_filter( 'newspack_indesign_export_platform', $callback, 10, 3 );
		InDesign_Exporter::resolve_platform();
		remove_filter( 'newspack_indesign_export_platform', $callback, 10 );

		$this->assertSame( 'mac', $captured['platform'] );
		$this->assertSame( 'auto', $captured['setting'] );
		$this->assertStringContainsString( 'Macintosh', $captured['user_agent'] );
	}

	/**
	 * Test that a supported-post-types filter returning a non-array value does not
	 * break get_supported_post_types() (defensive (array) cast).
	 */
	public function test_get_supported_post_types_survives_non_array_filter() {
		update_option( InDesign_Exporter::POST_TYPES_OPTION, [ 'post' ] );

		$callback = static function () {
			return null;
		};
		add_filter( 'newspack_indesign_export_supported_post_types', $callback );
		$result = InDesign_Exporter::get_supported_post_types();
		remove_filter( 'newspack_indesign_export_supported_post_types', $callback );

		$this->assertIsArray( $result );
	}

	/**
	 * Test that a caption-only image contributes nothing to the export when
	 * captions are excluded — no stray blank line from an otherwise-empty image
	 * block. The export with the image must be byte-identical to one without it.
	 */
	public function test_caption_only_image_adds_no_content_when_captions_excluded() {
		$image_id = $this->factory->attachment->create();
		wp_update_post(
			[
				'ID'           => $image_id,
				'post_excerpt' => 'Caption Only Image',
			]
		);

		$with_image = $this->factory->post->create(
			[
				'post_title'   => 'Blank Line Post',
				'post_content' => '<!-- wp:paragraph --><p>Body copy.</p><!-- /wp:paragraph --><!-- wp:image {"id":' . $image_id . '} --><!-- /wp:image -->',
			]
		);
		$without_image = $this->factory->post->create(
			[
				'post_title'   => 'Blank Line Post',
				'post_content' => '<!-- wp:paragraph --><p>Body copy.</p><!-- /wp:paragraph -->',
			]
		);

		$converter = new InDesign_Converter();
		$with      = $converter->convert_post( $with_image, [ 'include_captions' => false ] );
		$without   = $converter->convert_post( $without_image, [ 'include_captions' => false ] );

		$this->assertSame( $without, $with );
	}
}
