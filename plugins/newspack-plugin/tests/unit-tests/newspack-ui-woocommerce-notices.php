<?php
/**
 * Tests for the newspack-ui icons added to WooCommerce notices.
 *
 * @package Newspack\Tests
 */

use Newspack\Newspack_UI;
use Newspack\Reader_Activation;

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- Test file mixes a WooCommerce stub with the test class.

if ( ! function_exists( 'is_account_page' ) ) {
	/**
	 * Stub for WooCommerce is_account_page().
	 *
	 * @return bool
	 */
	function is_account_page() {
		return $GLOBALS['newspack_test_is_account_page'] ?? false;
	}
}

if ( ! function_exists( 'wc_get_notice_data_attr' ) ) {
	/**
	 * Stub for WooCommerce wc_get_notice_data_attr().
	 *
	 * @param array $notice Notice.
	 * @return string
	 */
	function wc_get_notice_data_attr( $notice ) {
		return '';
	}
}

if ( ! function_exists( 'wc_kses_notice' ) ) {
	/**
	 * Stub for WooCommerce wc_kses_notice().
	 *
	 * @param string $message Notice message.
	 * @return string
	 */
	function wc_kses_notice( $message ) {
		return wp_kses_post( $message );
	}
}

/**
 * Test which WooCommerce notices get newspack-ui icons, and where the icons go.
 *
 * @group Newspack_UI_WooCommerce_Notices
 */
class Newspack_Test_UI_WooCommerce_Notices extends WP_UnitTestCase {

	/**
	 * Run count of the `wp` action before the test.
	 *
	 * @var int|null
	 */
	private ?int $wp_action_count = null;

	/**
	 * Load My Account as a signed-in reader.
	 */
	public function set_up() {
		parent::set_up();
		$GLOBALS['newspack_test_is_account_page'] = true;
		$this->wp_action_count                    = $GLOBALS['wp_actions']['wp'] ?? null;
		$GLOBALS['wp_actions']['wp']              = 1; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$reader_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_user_meta( $reader_id, Reader_Activation::READER, true );
		wp_set_current_user( $reader_id );
	}

	/**
	 * Restore the request state.
	 */
	public function tear_down() {
		unset( $GLOBALS['newspack_test_is_account_page'] );
		if ( null === $this->wp_action_count ) {
			unset( $GLOBALS['wp_actions']['wp'] );
		} else {
			$GLOBALS['wp_actions']['wp'] = $this->wp_action_count; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Whether the notice sanitizing lets the icons through, which it does only
	 * where the notices get icons.
	 *
	 * @return bool
	 */
	private function notices_get_icons(): bool {
		$allowed_tags = Newspack_UI::allow_woocommerce_notice_icons( [ 'p' => [] ] );
		return isset( $allowed_tags['svg'] );
	}

	/**
	 * Render a WooCommerce notice template through the icon hooks.
	 *
	 * @param string $template_name Template name.
	 * @param string $html          Markup the template prints.
	 * @return string The printed markup.
	 */
	private function render_notice( string $template_name, string $html ): string {
		ob_start();
		Newspack_UI::start_woocommerce_notice( $template_name );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Newspack_UI::add_woocommerce_notice_icons( $template_name );
		return ob_get_clean();
	}

	/**
	 * The v1 My Account a signed-in reader sees renders newspack-ui notices.
	 */
	public function test_v1_account_notices_get_icons() {
		$this->assertTrue( $this->notices_get_icons() );
	}

	/**
	 * A signed-out visitor sees WooCommerce's own account page.
	 */
	public function test_signed_out_account_notices_get_no_icons() {
		wp_set_current_user( 0 );
		$this->assertFalse( $this->notices_get_icons() );
	}

	/**
	 * With Audience Management off, My Account is WooCommerce's own.
	 */
	public function test_account_notices_get_no_icons_without_reader_activation() {
		add_filter( 'newspack_reader_activation_enabled', '__return_false' );
		$this->assertFalse( $this->notices_get_icons() );
		remove_filter( 'newspack_reader_activation_enabled', '__return_false' );
	}

	/**
	 * The v0 My Account is WooCommerce's own.
	 */
	public function test_v0_account_notices_get_no_icons() {
		$use_v0 = fn() => '0.0.0';
		add_filter( 'newspack_my_account_version', $use_v0 );
		$this->assertFalse( $this->notices_get_icons() );
		remove_filter( 'newspack_my_account_version', $use_v0 );
	}

	/**
	 * Each item of a classic error list gets one icon; items of a list nested
	 * inside a message do not.
	 */
	public function test_classic_error_icons_go_on_top_level_items_only() {
		$output = $this->render_notice(
			'notices/error.php',
			'<ul class="woocommerce-error" role="alert"><li data-id="a">First</li><li data-id="b">Second<ul><li>Detail</li></ul></li></ul>'
		);
		$this->assertSame( 2, substr_count( $output, '<svg' ) );
		$this->assertMatchesRegularExpression( '#<li data-id="a"><svg\b#', $output );
		$this->assertMatchesRegularExpression( '#<li data-id="b"><svg\b#', $output );
		$this->assertStringContainsString( '<li>Detail</li>', $output );
	}

	/**
	 * A block notice banner keeps one icon: ours replaces WooCommerce's, and the
	 * list items inside it get none.
	 */
	public function test_block_notice_banner_icon_is_replaced() {
		$output = $this->render_notice(
			'notices/error.php',
			'<div class="wc-block-components-notice-banner is-error" role="alert"><svg class="woocommerce-own-icon" viewBox="0 0 24 24"><path d="M0 0"></path></svg><div class="wc-block-components-notice-banner__content"><ul><li>First</li><li>Second</li></ul></div></div>'
		);
		$this->assertSame( 1, substr_count( $output, '<svg' ) );
		$this->assertStringNotContainsString( 'woocommerce-own-icon', $output );
		$this->assertStringContainsString( 'newspack-ui__svg-icon--', $output );
		$this->assertStringContainsString( '<li>First</li>', $output );
	}

	/**
	 * The v1 notice template prints notices inline: urgent ones as warnings, the
	 * rest as info, none of them as a snackbar.
	 */
	public function test_v1_notice_template_prints_notices_inline() {
		$is_urgent = fn( $urgent, $notice ) => str_contains( $notice, 'has stopped' ) ? true : $urgent;
		add_filter( 'newspack_ui_notice_is_urgent', $is_urgent, 10, 2 );
		$notices = [
			[
				'notice' => 'Your recurring donation has stopped. Please <a href="#">update your payment method</a>.',
				'data'   => [],
			],
			[
				'notice' => 'Your address was saved.',
				'data'   => [],
			],
		];
		ob_start();
		include NEWSPACK_ABSPATH . 'includes/plugins/woocommerce/my-account/templates/v1/notices/notice.php';
		$html = ob_get_clean();
		remove_filter( 'newspack_ui_notice_is_urgent', $is_urgent );

		$this->assertMatchesRegularExpression( '#<div class="newspack-ui__notice newspack-ui__notice--warning" role="status">\s*<svg[^>]*newspack-ui__svg-icon--[^>]*>.*?</svg>\s*<p[^>]*>Your recurring donation has stopped. Please <a href="\#">update your payment method</a>.</p>#s', $html );
		$this->assertMatchesRegularExpression( '#<div class="newspack-ui__notice newspack-ui__notice--info" role="status">\s*<svg[^>]*newspack-ui__svg-icon--info[^>]*>.*?</svg>\s*<p[^>]*>Your address was saved.</p>#s', $html );

		ob_start();
		Newspack_UI::print_notices();
		$snackbar = ob_get_clean();
		$this->assertStringNotContainsString( 'Your address was saved.', $snackbar );
		$this->assertStringNotContainsString( 'has stopped', $snackbar );
	}

	/**
	 * The v1 error template prints each error inline as an alert, not as a snackbar.
	 */
	public function test_v1_error_template_prints_errors_inline() {
		$notices = [
			[
				'notice' => 'Current password is <strong>incorrect</strong>.',
				'data'   => [],
			],
			[
				'notice' => 'Please enter a valid email address.',
				'data'   => [],
			],
		];
		ob_start();
		include NEWSPACK_ABSPATH . 'includes/plugins/woocommerce/my-account/templates/v1/notices/error.php';
		$html = ob_get_clean();

		$this->assertSame( 2, substr_count( $html, '<div class="newspack-ui__notice newspack-ui__notice--error" role="alert">' ) );
		$this->assertStringContainsString( 'newspack-ui__svg-icon--caution', $html );
		$this->assertStringContainsString( 'Current password is <strong>incorrect</strong>.', $html );
		$this->assertStringContainsString( 'Please enter a valid email address.', $html );

		ob_start();
		Newspack_UI::print_notices();
		$snackbar = ob_get_clean();
		$this->assertStringNotContainsString( 'Current password', $snackbar );
	}
}
