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
	 * Set up a post on its own page, with gifting allowed.
	 */
	public function set_up() {
		parent::set_up();
		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( 'jetpack/sharing-button' ) ) {
			register_block_type( 'jetpack/sharing-button', [ 'uses_context' => [ 'postId', 'styleType' ] ] );
		}
		$this->post_id = self::factory()->post->create();
		$this->go_to( get_permalink( $this->post_id ) );
		add_filter( 'newspack_user_can_gift_post', '__return_true' );
	}

	/**
	 * Remove the test block type and filter.
	 */
	public function tear_down() {
		remove_filter( 'newspack_user_can_gift_post', '__return_true' );
		unregister_block_type( 'jetpack/sharing-button' );
		parent::tear_down();
	}

	/**
	 * Render the button as WordPress passes it to the render filter.
	 *
	 * @param string $service Service ID.
	 * @param string $style   Style type of the parent block.
	 *
	 * @return string
	 */
	private function render( $service = 'newspack-gift-article', $style = 'icon' ) {
		$parsed = [
			'blockName' => 'jetpack/sharing-button',
			'attrs'     => [ 'service' => $service ],
		];
		$block  = new WP_Block(
			$parsed,
			[
				'postId'    => $this->post_id,
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
