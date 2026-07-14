<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class CheckoutButtonBlockTest
 *
 * @package Newspack_Blocks
 */

require_once dirname( __DIR__ ) . '/src/blocks/checkout-button/view.php';

/**
 * Checkout Button Block.
 */
class CheckoutButtonBlockTest extends WP_UnitTestCase_Blocks { // phpcs:ignore

	/**
	 * Render the block with the given attributes merged into a minimal valid set.
	 *
	 * @param array $attributes Attributes to override.
	 * @return string Rendered HTML.
	 */
	private function render( $attributes = [] ) {
		return \Newspack_Blocks\Checkout_Button\render_callback(
			array_merge(
				[
					'product'     => '1',
					'variation'   => '',
					'text'        => 'Checkout',
					'is_variable' => false,
				],
				$attributes
			)
		);
	}

	/**
	 * Extract the class attribute value from the rendered <button> element.
	 *
	 * @param string $output Rendered HTML.
	 * @return string Class attribute value, or empty string if not found.
	 */
	private function get_button_class( $output ) {
		if ( preg_match( '/<button[^>]*class="([^"]*)"/', $output, $matches ) ) {
			return $matches[1];
		}
		return '';
	}

	/**
	 * Extract the style attribute value from the rendered <button> element.
	 *
	 * @param string $output Rendered HTML.
	 * @return string Style attribute value, or empty string if not found.
	 */
	private function get_button_style( $output ) {
		if ( preg_match( '/<button[^>]*style="([^"]*)"/', $output, $matches ) ) {
			return $matches[1];
		}
		return '';
	}

	/**
	 * Preset slugs (color, font) should emit has-{slug}-* classes and must NOT
	 * appear as literal CSS values in the inline style attribute.
	 */
	public function test_presets_emit_classes_not_inline_values() {
		$output = $this->render(
			[
				'backgroundColor' => 'primary',
				'textColor'       => 'accent',
				'fontSize'        => 'large',
				'fontFamily'      => 'system-sans-serif',
			]
		);
		$class = $this->get_button_class( $output );
		$style = $this->get_button_style( $output );

		$this->assertStringContainsString( 'has-background', $class );
		$this->assertStringContainsString( 'has-primary-background-color', $class );
		$this->assertStringContainsString( 'has-text-color', $class );
		$this->assertStringContainsString( 'has-accent-color', $class );
		$this->assertStringContainsString( 'has-large-font-size', $class );
		$this->assertStringContainsString( 'has-system-sans-serif-font-family', $class );

		$this->assertStringNotContainsString( 'background-color:primary', $style );
		$this->assertStringNotContainsString( 'color:accent', $style );
		$this->assertStringNotContainsString( 'font-size:large', $style );
		$this->assertStringNotContainsString( 'font-family:system-sans-serif', $style );
	}

	/**
	 * Custom background color via style.color.background should trigger has-background.
	 */
	public function test_custom_background_emits_has_background() {
		$output = $this->render(
			[
				'style' => [ 'color' => [ 'background' => '#ff0000' ] ],
			]
		);
		$this->assertStringContainsString( 'has-background', $this->get_button_class( $output ) );
	}

	/**
	 * Custom gradient via style.color.gradient should trigger has-background.
	 */
	public function test_custom_gradient_emits_has_background() {
		$output = $this->render(
			[
				'style' => [ 'color' => [ 'gradient' => 'linear-gradient(45deg,red,blue)' ] ],
			]
		);
		$this->assertStringContainsString( 'has-background', $this->get_button_class( $output ) );
	}

	/**
	 * Custom text color via style.color.text should trigger has-text-color
	 * (no slug class is expected for custom hex values).
	 */
	public function test_custom_text_color_emits_has_text_color() {
		$output = $this->render(
			[
				'style' => [ 'color' => [ 'text' => '#ff0000' ] ],
			]
		);
		$this->assertStringContainsString( 'has-text-color', $this->get_button_class( $output ) );
	}

	/**
	 * Class list should not have leading or trailing whitespace, or any double spaces.
	 */
	public function test_class_list_has_no_extra_whitespace() {
		$output = $this->render(
			[
				'backgroundColor' => 'primary',
				'fontSize'        => 'large',
			]
		);
		$class = $this->get_button_class( $output );

		$this->assertSame( trim( $class ), $class, 'Class list should have no leading or trailing whitespace.' );
		$this->assertStringNotContainsString( '  ', $class, 'Class list should not contain double spaces.' );
	}

	/**
	 * Load rendered block output into a DOMDocument, leaving the libxml error
	 * buffer clean so parse errors do not leak into later tests.
	 *
	 * @param string $output Rendered HTML.
	 * @return DOMDocument The parsed document.
	 */
	private function load_dom( $output ) {
		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<!DOCTYPE html><html><body>' . $output . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return $dom;
	}

	/**
	 * Find the checkout-button container <div> within a parsed document.
	 *
	 * @param DOMDocument $dom Parsed document.
	 * @return DOMElement|null The container element, or null if absent.
	 */
	private function find_container( DOMDocument $dom ) {
		foreach ( $dom->getElementsByTagName( 'div' ) as $div ) {
			if ( false !== strpos( $div->getAttribute( 'class' ), 'wp-block-newspack-blocks-checkout-button' ) ) {
				return $div;
			}
		}
		return null;
	}

	/**
	 * Return the container <div> node from rendered output, or null.
	 *
	 * @param string $output Rendered HTML.
	 * @return DOMElement|null The container element.
	 */
	private function get_container( $output ) {
		return $this->find_container( $this->load_dom( $output ) );
	}

	/**
	 * The className is rendered inside the container's class attribute and must
	 * stay there: it cannot introduce a separate attribute on the container. A
	 * value crafted to close the attribute is escaped, so no style or
	 * event-handler attribute appears and the value remains part of the class.
	 */
	public function test_container_classname_cannot_inject_attributes() {
		$output    = $this->render( [ 'className' => 'x" style="position:fixed;inset:0" onmouseover="window.__x=1" data-x="' ] );
		$container = $this->get_container( $output );

		$this->assertNotNull( $container, 'Checkout-button container should render.' );
		$this->assertFalse( $container->hasAttribute( 'style' ), 'className must not introduce a style attribute.' );
		$this->assertFalse( $container->hasAttribute( 'onmouseover' ), 'className must not introduce an event handler.' );
		$this->assertFalse( $container->hasAttribute( 'data-x' ), 'className must not introduce arbitrary attributes.' );
		$this->assertStringContainsString(
			'style=',
			$container->getAttribute( 'class' ),
			'The className value should remain inside the class attribute.'
		);
	}

	/**
	 * The className must stay inside the class attribute even when it carries
	 * angle brackets: it cannot escape the container tag to add an element.
	 */
	public function test_container_classname_cannot_inject_elements() {
		$output = $this->render( [ 'className' => 'x"><img src=x onerror="window.__x=1">' ] );
		$dom    = $this->load_dom( $output );

		$this->assertSame( 0, $dom->getElementsByTagName( 'img' )->length, 'className must not inject an element.' );

		$container = $this->find_container( $dom );
		$this->assertNotNull( $container, 'Checkout-button container should render.' );
		$this->assertStringContainsString(
			'<img',
			$container->getAttribute( 'class' ),
			'The bracketed className value should remain inside the class attribute.'
		);
	}
}
