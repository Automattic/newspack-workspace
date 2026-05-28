<?php
/**
 * Class TestThemeColorsCustomization
 *
 * @package Newspack_Multibranded_Site
 */

use Newspack_Multibranded_Site\Taxonomy;
use Newspack_Multibranded_Site\Meta\Theme_Colors as Theme_Colors_Meta;
use Newspack_Multibranded_Site\Customizations\Theme_Colors as Theme_Colors_Customization;

/**
 * Test the Logo filter.
 *
 * NOTE: This class relies on the newspack_multibranded_site_theme_colors filter added in the bootstrap file
 */
class TestThemeColorsCustomization extends WP_UnitTestCase {

	/**
	 * Tests filter theme colors
	 */
	public function test_filter_theme_colors() {
		$term_without_theme_colors = $this->factory->term->create_and_get( [ 'taxonomy' => Taxonomy::SLUG ] );
		$term_with_theme_colors    = $this->factory->term->create_and_get( [ 'taxonomy' => Taxonomy::SLUG ] );
		add_term_meta(
			$term_with_theme_colors->term_id,
			Theme_Colors_Meta::get_key(),
			[
				[
					'name'  => 'primary_color',
					'color' => '#000000',
				],
			]
		);

		$this->go_to( get_term_link( $term_without_theme_colors->term_id ) );
		$this->assertSame( false, get_theme_mod( 'primary_color' ) );

		$this->go_to( get_term_link( $term_with_theme_colors->term_id ) );
		$this->assertSame( '#000000', get_theme_mod( 'primary_color' ) );
	}

	/**
	 * Tests has theme colors
	 */
	public function test_has_theme_colors() {
		$term_without_theme_colors = $this->factory->term->create_and_get( [ 'taxonomy' => Taxonomy::SLUG ] );
		$term_with_theme_colors    = $this->factory->term->create_and_get( [ 'taxonomy' => Taxonomy::SLUG ] );
		add_term_meta(
			$term_with_theme_colors->term_id,
			Theme_Colors_Meta::get_key(),
			[
				[
					'name'  => 'primary_color',
					'color' => '#000000',
				],
			]
		);

		$this->go_to( get_term_link( $term_without_theme_colors->term_id ) );
		$this->assertFalse( Theme_Colors_Customization::current_brand_has_custom_colors() );
		$this->assertFalse( Theme_Colors_Customization::current_brand_has_custom_colors( [ 'primary_color' ] ) );
		$this->assertFalse( Theme_Colors_Customization::current_brand_has_custom_colors( [ 'primary_color', 'secondary_color' ] ) );
		$this->assertFalse( Theme_Colors_Customization::current_brand_has_custom_colors( [ 'secondary_color' ] ) );

		$this->go_to( get_term_link( $term_with_theme_colors->term_id ) );
		$this->assertTrue( Theme_Colors_Customization::current_brand_has_custom_colors() );
		$this->assertTrue( Theme_Colors_Customization::current_brand_has_custom_colors( [ 'primary_color' ] ) );
		$this->assertTrue( Theme_Colors_Customization::current_brand_has_custom_colors( [ 'primary_color', 'secondary_color' ] ) );
		$this->assertFalse( Theme_Colors_Customization::current_brand_has_custom_colors( [ 'secondary_color' ] ) );
	}
}
