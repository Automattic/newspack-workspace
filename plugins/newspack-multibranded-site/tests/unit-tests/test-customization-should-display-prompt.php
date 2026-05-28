<?php
/**
 * Class TestLogoCustomization
 *
 * @package Newspack_Multibranded_Site
 */

use Newspack_Multibranded_Site\Taxonomy;
use Newspack_Multibranded_Site\Customizations\Popups_Should_Display_Prompt;

/**
 * Test the Logo filter.
 */
class TestCustomizationShouldDisplayPrompt extends WP_UnitTestCase {

	/**
	 * Tests an empty popup
	 */
	public function test_empty_popup() {
		$should_display = Popups_Should_Display_Prompt::filter_should_display( true, [] );
		$this->assertTrue( $should_display );

		$should_display = Popups_Should_Display_Prompt::filter_should_display( true, 'invalid' );
		$this->assertTrue( $should_display );
	}

	/**
	 * Test all scenarios
	 */
	public function test_no_brand() {
		$prompt_without_brands  = $this->factory->post->create_and_get();
		$prompt_with_one_brand  = $this->factory->post->create_and_get();
		$prompt_with_two_brands = $this->factory->post->create_and_get();

		$brand1 = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );
		$brand2 = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );
		$brand3 = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );

		wp_set_post_terms( $prompt_with_one_brand->ID, $brand1->term_id, Taxonomy::SLUG );
		wp_set_post_terms( $prompt_with_two_brands->ID, [ $brand1->term_id, $brand2->term_id ], Taxonomy::SLUG );

		// No current brand.
		$this->go_to( '/' );
		$should_display = Popups_Should_Display_Prompt::filter_should_display( true, [ 'id' => $prompt_without_brands->ID ] );
		$this->assertEquals( true, $should_display );

		$should_display = Popups_Should_Display_Prompt::filter_should_display( true, [ 'id' => $prompt_with_one_brand->ID ] );
		$this->assertEquals( false, $should_display );

		$should_display = Popups_Should_Display_Prompt::filter_should_display( true, [ 'id' => $prompt_with_two_brands->ID ] );
		$this->assertEquals( false, $should_display );

		// Brand 1.
		$this->go_to( get_term_link( $brand1 ) );
		$should_display = Popups_Should_Display_Prompt::filter_should_display( true, [ 'id' => $prompt_without_brands->ID ] );
		$this->assertEquals( true, $should_display );

		$should_display = Popups_Should_Display_Prompt::filter_should_display( true, [ 'id' => $prompt_with_one_brand->ID ] );
		$this->assertEquals( true, $should_display );

		$should_display = Popups_Should_Display_Prompt::filter_should_display( true, [ 'id' => $prompt_with_two_brands->ID ] );
		$this->assertEquals( true, $should_display );

		// Brand 2.
		$this->go_to( get_term_link( $brand2 ) );
		$should_display = Popups_Should_Display_Prompt::filter_should_display( true, [ 'id' => $prompt_without_brands->ID ] );
		$this->assertEquals( true, $should_display );

		$should_display = Popups_Should_Display_Prompt::filter_should_display( true, [ 'id' => $prompt_with_one_brand->ID ] );
		$this->assertEquals( false, $should_display );

		$should_display = Popups_Should_Display_Prompt::filter_should_display( true, [ 'id' => $prompt_with_two_brands->ID ] );
		$this->assertEquals( true, $should_display );

		// If the initial value is false, the return should always be false.
		$should_display = Popups_Should_Display_Prompt::filter_should_display( false, [ 'id' => $prompt_with_two_brands->ID ] );
		$this->assertEquals( false, $should_display );

		// Brand 3.
		$this->go_to( get_term_link( $brand3 ) );
		$should_display = Popups_Should_Display_Prompt::filter_should_display( true, [ 'id' => $prompt_without_brands->ID ] );
		$this->assertEquals( true, $should_display );

		$should_display = Popups_Should_Display_Prompt::filter_should_display( true, [ 'id' => $prompt_with_one_brand->ID ] );
		$this->assertEquals( false, $should_display );

		$should_display = Popups_Should_Display_Prompt::filter_should_display( true, [ 'id' => $prompt_with_two_brands->ID ] );
		$this->assertEquals( false, $should_display );
	}
}
