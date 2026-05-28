<?php
/**
 * Tests for My Account Button block.
 *
 * @package Newspack\Tests
 */

use Newspack\Blocks\My_Account_Button\My_Account_Button_Block;

require_once NEWSPACK_ABSPATH . 'tests/mocks/wc-my-account.php';

/**
 * Tests the My Account Button block rendering.
 */
class Newspack_Test_My_Account_Button_Block extends WP_UnitTestCase {
	/**
	 * Whether reader activation is enabled.
	 *
	 * @var bool
	 */
	private static $reader_activation_enabled = true;

	/**
	 * Setup.
	 */
	public function setUp(): void {
		parent::setUp();
		add_filter( 'newspack_reader_activation_enabled', [ __CLASS__, 'filter_reader_activation_enabled' ], 9999 );

		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( 'newspack/my-account-button' ) ) {
			\register_block_type_from_metadata(
				NEWSPACK_ABSPATH . 'src/blocks/my-account-button/block.json',
				[
					'render_callback' => [ My_Account_Button_Block::class, 'render_block' ],
				]
			);
		}
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_filter( 'newspack_reader_activation_enabled', [ __CLASS__, 'filter_reader_activation_enabled' ], 9999 );
		wp_logout();
		parent::tearDown();
	}

	/**
	 * Filter to set reader activation enabled state.
	 *
	 * @param bool $enabled Default enabled value.
	 *
	 * @return bool
	 */
	public static function filter_reader_activation_enabled( $enabled ) {
		return self::$reader_activation_enabled;
	}

	/**
	 * Test signed-out rendering includes labels and href.
	 */
	public function test_render_block_signed_out() {
		self::$reader_activation_enabled = true;

		$output = do_blocks(
			'<!-- wp:newspack/my-account-button {"signedInLabel":"My Account","signedOutLabel":"Sign in"} /-->'
		);

		$this->assertNotEmpty( $output );
		$this->assertStringContainsString( 'data-newspack-reader-account-link', $output );
		$this->assertStringContainsString( 'href="#"', $output );
		$this->assertStringContainsString( '&quot;signedout&quot;:&quot;Sign in&quot;', $output );
	}

	/**
	 * Test that My Account button links to /my-account and doesn't trigger the modal when the reader is signed in..
	 */
	public function test_render_block_signed_in() {
		self::$reader_activation_enabled = true;

		$user_id = self::factory()->user->create(
			[
				'role' => 'subscriber',
			]
		);
		wp_set_current_user( $user_id );

		$output = do_blocks(
			'<!-- wp:newspack/my-account-button {"signedInLabel":"My Account","signedOutLabel":"Sign in"} /-->'
		);

		$this->assertNotEmpty( $output );
		$this->assertStringContainsString( 'href="https://example.com/my-account"', $output );
		$this->assertStringNotContainsString( 'data-newspack-reader-account-link', $output );
	}

	/**
	 * Test empty signed-out label falls back to default.
	 */
	public function test_render_block_empty_signed_out_label() {
		self::$reader_activation_enabled = true;

		$signed_out_output = do_blocks(
			'<!-- wp:newspack/my-account-button {"signedInLabel":"My Account","signedOutLabel":""} /-->'
		);

		$this->assertNotEmpty( $signed_out_output );
		$this->assertStringContainsString( '&quot;signedout&quot;:&quot;Sign in&quot;', $signed_out_output );
	}

	/**
	 * Test icon-only style applies the screen-reader-text class to the label.
	 */
	public function test_render_block_icon_only_style_adds_screen_reader_text_class() {
		self::$reader_activation_enabled = true;

		$output = do_blocks(
			'<!-- wp:newspack/my-account-button {"signedInLabel":"My Account","signedOutLabel":"Sign in","className":"is-style-icon-only"} /-->'
		);

		$this->assertNotEmpty( $output );
		$this->assertStringContainsString( 'newspack-reader__account-link__label screen-reader-text', $output );
	}

	/**
	 * Test text-only style removes the icon markup.
	 */
	public function test_render_block_text_only_style_removes_icon_markup() {
		self::$reader_activation_enabled = true;

		$output = do_blocks(
			'<!-- wp:newspack/my-account-button {"signedInLabel":"My Account","signedOutLabel":"Sign in","className":"is-style-text-only"} /-->'
		);

		$this->assertNotEmpty( $output );
		$this->assertStringNotContainsString( 'wp-block-newspack-my-account-button__icon', $output );
	}

	/**
	 * Test default style renders the icon markup.
	 */
	public function test_render_block_default_style_renders_icon_markup() {
		self::$reader_activation_enabled = true;

		$output = do_blocks(
			'<!-- wp:newspack/my-account-button {"signedInLabel":"My Account","signedOutLabel":"Sign in"} /-->'
		);

		$this->assertNotEmpty( $output );
		$this->assertStringContainsString( 'wp-block-newspack-my-account-button__icon', $output );
	}

	/**
	 * Test default style (no className) renders both label and icon.
	 */
	public function test_render_block_default_style_renders_label_and_icon() {
		self::$reader_activation_enabled = true;

		$output = do_blocks(
			'<!-- wp:newspack/my-account-button {"signedInLabel":"My Account","signedOutLabel":"Sign in"} /-->'
		);

		$this->assertNotEmpty( $output );
		$this->assertStringContainsString( 'newspack-reader__account-link__label', $output );
		$this->assertStringContainsString( 'wp-block-newspack-my-account-button__icon', $output );
	}

	/**
	 * Test empty signed-in label falls back to default.
	 */
	public function test_render_block_empty_signed_in_label() {
		self::$reader_activation_enabled = true;

		$user_id = self::factory()->user->create(
			[
				'role' => 'subscriber',
			]
		);
		wp_set_current_user( $user_id );

		$signed_in_output = do_blocks(
			'<!-- wp:newspack/my-account-button {"signedInLabel":"","signedOutLabel":"Sign in"} /-->'
		);

		$this->assertNotEmpty( $signed_in_output );
		$this->assertStringContainsString( '&quot;signedin&quot;:&quot;My Account&quot;', $signed_in_output );
	}

	/**
	 * Test for the My Account URL, and don't render the button for logged-in readers if the link does not exist.
	 */
	public function test_render_block_signed_in_without_account_url() {
		self::$reader_activation_enabled = true;

		$user_id = self::factory()->user->create(
			[
				'role' => 'subscriber',
			]
		);
		wp_set_current_user( $user_id );

		add_filter(
			'newspack_test_wc_account_url',
			static function () {
				return '';
			},
			10,
			2
		);

		$output = do_blocks( '<!-- wp:newspack/my-account-button /-->' );
		$this->assertSame( '', trim( $output ) );

		remove_all_filters( 'newspack_test_wc_account_url' );
	}
}
