<?php
/**
 * Tests the gift button in Jetpack's Sharing Buttons block.
 *
 * @package Newspack\Tests
 */

use Newspack\Content_Gifting;

/**
 * Tests the gift button in Jetpack's Sharing Buttons block.
 */
class Newspack_Test_Content_Gifting_Sharing_Button extends WP_UnitTestCase {
	/**
	 * Post shown on the page.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * Block types this test registered.
	 *
	 * @var string[]
	 */
	private $registered = [];

	/**
	 * Set up a post on its own page, with gifting allowed.
	 */
	public function set_up() {
		parent::set_up();
		$this->register_block( 'jetpack/sharing-buttons', [ 'provides_context' => [ 'styleType' => 'styleType' ] ] );
		$this->register_block( 'jetpack/sharing-button', [ 'uses_context' => [ 'postId', 'styleType' ] ] );
		$this->post_id = self::factory()->post->create();
		$this->go_to( get_permalink( $this->post_id ) );
		add_filter( 'newspack_user_can_gift_post', '__return_true' );
	}

	/**
	 * Remove the test block type and filter.
	 */
	public function tear_down() {
		remove_filter( 'newspack_user_can_gift_post', '__return_true' );
		foreach ( $this->registered as $name ) {
			unregister_block_type( $name );
		}
		$this->registered = [];
		parent::tear_down();
	}

	/**
	 * Register a block type unless Jetpack already has, remembering it to remove again.
	 *
	 * @param string $name Block name.
	 * @param array  $args Block type arguments.
	 */
	private function register_block( $name, $args ) {
		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
			register_block_type( $name, $args );
			$this->registered[] = $name;
		}
	}

	/**
	 * Render the button as WordPress passes it to the render filter.
	 *
	 * @param string $service Service ID.
	 * @param string $style   Style type of the parent block.
	 * @param int    $post_id Post the block is rendered for, defaulting to the one shown.
	 *
	 * @return string
	 */
	private function render( $service = 'newspack-gift-article', $style = 'icon', $post_id = 0 ) {
		$parsed = [
			'blockName' => 'jetpack/sharing-button',
			'attrs'     => [ 'service' => $service ],
		];
		$block  = new WP_Block(
			$parsed,
			[
				'postId'    => $post_id ? $post_id : $this->post_id,
				'styleType' => $style,
			] 
		);
		return Content_Gifting::render_block_button( '', $parsed, $block );
	}

	/**
	 * The button carries Jetpack's classes and opens in the same tab.
	 */
	public function test_button_markup() {
		$markup = $this->render();
		$this->assertStringContainsString( 'class="jetpack-sharing-button__button style-icon share-newspack-gift-article"', $markup );
		$this->assertStringContainsString( 'data-service="newspack-gift-article"', $markup );
		$this->assertStringContainsString( '<svg', $markup );
		$this->assertStringNotContainsString( 'target=', $markup );
		$this->assertStringContainsString( 'href="' . esc_url( Content_Gifting::get_gift_url( $this->post_id ) ) . '"', $markup );
	}

	/**
	 * The hook is wired to Jetpack's block, which passes the layout's style type down as context.
	 */
	public function test_button_renders_through_the_block_pipeline() {
		$markup = do_blocks( '<!-- wp:jetpack/sharing-buttons {"styleType":"icon"} --><ul><!-- wp:jetpack/sharing-button {"service":"newspack-gift-article"} /--></ul><!-- /wp:jetpack/sharing-buttons -->' );
		$this->assertStringContainsString( 'share-newspack-gift-article', $markup );
		$this->assertStringContainsString( 'style-icon', $markup );
	}

	/**
	 * The button is empty for a post other than the one shown.
	 */
	public function test_button_is_empty_for_another_post() {
		$other = self::factory()->post->create();
		$this->assertSame( '', $this->render( 'newspack-gift-article', 'icon', $other ) );
	}

	/**
	 * The button is empty when the post can't be gifted.
	 */
	public function test_button_is_empty_when_not_giftable() {
		add_filter( 'newspack_user_can_gift_post', '__return_false', 20 );
		$this->assertSame( '', $this->render() );
		remove_filter( 'newspack_user_can_gift_post', '__return_false', 20 );
	}

	/**
	 * Other services are left to Jetpack.
	 */
	public function test_other_services_are_ignored() {
		$this->assertSame( 'kept', Content_Gifting::render_block_button( 'kept', [ 'attrs' => [ 'service' => 'x' ] ] ) );
	}
}
