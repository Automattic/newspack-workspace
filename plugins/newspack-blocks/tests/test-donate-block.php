<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class DonateBlockTest
 *
 * @package Newspack_Blocks
 */

/**
 * Mock WooCommerce functions for testing.
 */
if ( ! function_exists( 'wc_price' ) ) {
	/**
	 * Mock wc_price function.
	 *
	 * @param float $price The price to format.
	 * @param array $args Formatting arguments.
	 * @return string Formatted price.
	 */
	function wc_price( $price, $args = [] ) {
		$decimals = isset( $args['decimals'] ) ? $args['decimals'] : 2;
		$formatted_price = number_format( $price, $decimals );
		return '<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">$</span>' . $formatted_price . '</bdi></span>';
	}
}

if ( ! function_exists( 'get_woocommerce_currency_symbol' ) ) {
	/**
	 * Mock get_woocommerce_currency_symbol function.
	 *
	 * @return string Currency symbol.
	 */
	function get_woocommerce_currency_symbol() {
		return '$';
	}
}

if ( ! function_exists( 'wcs_price_string' ) ) {
	/**
	 * Mock wcs_price_string function for subscriptions.
	 *
	 * @param array $args Price string arguments.
	 * @return string Formatted subscription price string.
	 */
	function wcs_price_string( $args ) {
		// Recorded so other test files can assert on the args this mock received,
		// e.g. the initial_amount (sign-up fee) passed by Checkout_Data::get_price_summary().
		$GLOBALS['newspack_blocks_test_last_wcs_price_string_args'] = $args;

		$recurring_amount = isset( $args['recurring_amount'] ) ? $args['recurring_amount'] : '';
		$period = isset( $args['subscription_period'] ) ? $args['subscription_period'] : 'month';

		if ( 'day' === $period ) {
			return $recurring_amount;
		}

		return $recurring_amount . ' / ' . $period;
	}
}

/**
 * Donate Block.
 */
class DonateBlockTest extends WP_UnitTestCase_Blocks { // phpcs:ignore
	/**
	 * Test the amount formatting.
	 */
	public function test_donate_block_amount_formatting() {
		$expected_format_wrapper = '<span class="wpbnbd__tiers__amount__value"><span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">$</span>%s</bdi></span></span>';

		$this->assertEquals(
			sprintf( $expected_format_wrapper, '25' ),
			Newspack_Blocks::get_formatted_amount( 25, 'once' )
		);
		$this->assertEquals(
			sprintf( $expected_format_wrapper, '1,000' ),
			Newspack_Blocks::get_formatted_amount( 1000, 'once' )
		);

		// Test float amounts.
		$formatted_float = Newspack_Blocks::get_formatted_amount( 25.50, 'once' );
		$this->assertEquals(
			sprintf( $expected_format_wrapper, '25.50' ),
			Newspack_Blocks::get_formatted_amount( 25.5, 'once' )
		);

		// Test zero amount.
		$formatted_zero = Newspack_Blocks::get_formatted_amount( 0, 'once' );
		$this->assertIsString( $formatted_zero );

		// Returning placeholder format.
		$this->assertEquals(
			$with_placeholder = Newspack_Blocks::get_formatted_amount(),
			'<span class="wpbnbd__tiers__amount__value"><span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">$</span>AMOUNT_PLACEHOLDER</bdi></span> FREQUENCY_PLACEHOLDER</span>'
		);
	}
	/**
	 * Theme to restore after each test.
	 *
	 * @var string|null
	 */
	private $original_theme = null;

	/**
	 * Restore the theme a test switched.
	 */
	public function tear_down() {
		if ( null !== $this->original_theme ) {
			switch_theme( $this->original_theme );
			$this->original_theme = null;
		}
		if ( null !== $this->classic_theme_root ) {
			$GLOBALS['wp_theme_directories'] = array_values( array_diff( $GLOBALS['wp_theme_directories'], [ $this->classic_theme_root ] ) );
			$this->classic_theme_root        = null;
		}
		wp_clean_themes_cache();
		parent::tear_down();
	}

	/**
	 * Switch theme, skipping when it is not available.
	 *
	 * @param string $slug Theme slug.
	 */
	private function use_theme( $slug ) {
		if ( ! wp_get_theme( $slug )->exists() ) {
			$this->markTestSkipped( $slug . ' is not available in this environment.' );
		}
		$this->original_theme = get_stylesheet();
		switch_theme( $slug );
	}

	/**
	 * Theme root registered for the classic fixture, when one was.
	 *
	 * @var string|null
	 */
	private $classic_theme_root = null;

	/**
	 * Switch to the classic theme fixture.
	 */
	private function use_classic_theme() {
		$this->classic_theme_root = untrailingslashit( NEWSPACK_BLOCKS__PLUGIN_DIR . 'tests/fixtures/themes' );
		register_theme_directory( $this->classic_theme_root );
		wp_clean_themes_cache();
		$this->use_theme( 'classic-fixture' );
	}

	/**
	 * Call a protected renderer method.
	 *
	 * @param string $class_name Renderer class.
	 * @param string $method     Method name.
	 * @param array  $args       Arguments.
	 * @return mixed
	 */
	private function call_renderer( $class_name, $method, $args ) {
		require_once NEWSPACK_BLOCKS__PLUGIN_DIR . 'src/blocks/donate/frontend/class-newspack-blocks-donate-renderer-frequency-based.php';
		require_once NEWSPACK_BLOCKS__PLUGIN_DIR . 'src/blocks/donate/frontend/class-newspack-blocks-donate-renderer-tiers-based.php';
		$reflection = new ReflectionMethod( $class_name, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( null, $args );
	}

	/**
	 * Get a button's raw style.
	 *
	 * @param array $attributes Block attributes.
	 * @param bool  $reverse    Reverse style.
	 * @return string
	 */
	private function get_button_style( $attributes, $reverse = false ) {
		return $this->call_renderer( 'Newspack_Blocks_Donate_Renderer_Frequency_Based', 'get_button_style', [ $attributes, $reverse ] );
	}

	/**
	 * Get a button's raw style and class values.
	 *
	 * @param array $attributes Block attributes.
	 * @param bool  $reverse    Reverse style.
	 * @return array
	 */
	private function get_button_attrs( $attributes, $reverse = false ) {
		return $this->call_renderer( 'Newspack_Blocks_Donate_Renderer_Frequency_Based', 'get_button_attrs', [ $attributes, $reverse ] );
	}

	/**
	 * The default button color defers to the site's button on a block theme.
	 */
	public function test_donate_button_uses_site_style_when_default_on_a_block_theme() {
		$this->use_theme( 'twentytwentyfive' );
		$this->assertTrue( Newspack_Blocks::donate_button_uses_site_style( [ 'buttonColor' => '#dd3333' ] ) );
		$this->assertTrue( Newspack_Blocks::donate_button_uses_site_style( [ 'buttonColor' => '#DD3333' ] ) );
		$this->assertTrue( Newspack_Blocks::donate_button_uses_site_style( [ 'buttonColor' => '' ] ) );
		$this->assertSame( '', $this->get_button_style( [ 'buttonColor' => '#dd3333' ] ) );
		$this->assertSame(
			[
				'style' => '',
				'class' => 'wp-element-button',
			],
			$this->get_button_attrs( [ 'buttonColor' => '#dd3333' ] )
		);
	}

	/**
	 * A picked color is kept.
	 */
	public function test_donate_button_keeps_custom_color() {
		$this->use_theme( 'twentytwentyfive' );
		$this->assertFalse( Newspack_Blocks::donate_button_uses_site_style( [ 'buttonColor' => '#6c6c6c' ] ) );
		$attrs = $this->get_button_attrs( [ 'buttonColor' => '#6c6c6c' ] );
		$this->assertStringContainsString( 'background-color: #6c6c6c', $attrs['style'] );
		$this->assertSame( '', $attrs['class'] );
	}

	/**
	 * Classic themes keep the inline colors.
	 */
	public function test_donate_button_unchanged_on_classic() {
		$this->use_classic_theme();
		$this->assertFalse( Newspack_Blocks::donate_button_uses_site_style( [ 'buttonColor' => '#dd3333' ] ) );
		$this->assertSame(
			[
				'style' => 'border-color: #dd3333; background-color: #dd3333; color: white;',
				'class' => '',
			],
			$this->get_button_attrs( [ 'buttonColor' => '#dd3333' ] )
		);
	}

	/**
	 * The reverse tier is the site's button with the outline modifier and no inline style.
	 */
	public function test_reverse_tier_is_the_sites_outline_button_on_a_block_theme() {
		$this->use_theme( 'twentytwentyfive' );
		$this->assertSame(
			[
				'style' => '',
				'class' => 'wp-element-button wpbnbd__tiers__outline',
			],
			$this->get_button_attrs( [ 'buttonColor' => '#dd3333' ], true )
		);
	}

	/**
	 * A picked color keeps the inline outline and gets no site classes.
	 */
	public function test_reverse_tier_keeps_inline_outline_for_a_custom_color() {
		$this->use_theme( 'twentytwentyfive' );
		$this->assertSame(
			[
				'style' => 'border-color: #6c6c6c; color: #6c6c6c; background: transparent;',
				'class' => '',
			],
			$this->get_button_attrs( [ 'buttonColor' => '#6c6c6c' ], true )
		);
	}
}
