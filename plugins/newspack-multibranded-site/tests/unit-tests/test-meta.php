<?php
/**
 * Class TestMeta
 *
 * @package Newspack_Multibranded_Site
 */

/**
 * Tests the Meta class.
 */
class TestMeta extends WP_UnitTestCase {

	/**
	 * Tests get_capability method
	 */
	public function test_get_cap() {
		$this->assertSame( 'edit_users', Newspack_Multibranded_Site\Meta\User_Primary_Brand::get_capability() );
		$this->assertSame( 'edit_post', Newspack_Multibranded_Site\Meta\Post_Primary_Brand::get_capability() );

		$this->assertSame( get_taxonomy( 'category' )->cap->manage_terms, Newspack_Multibranded_Site\Meta\Category_Primary_Brand::get_capability() );
		$this->assertSame( get_taxonomy( 'post_tag' )->cap->manage_terms, Newspack_Multibranded_Site\Meta\Tag_Primary_Brand::get_capability() );

		$brand_tax_cap = get_taxonomy( Newspack_Multibranded_Site\Taxonomy::SLUG )->cap->manage_terms;

		$this->assertSame( $brand_tax_cap, Newspack_Multibranded_Site\Meta\Logo::get_capability() );
		$this->assertSame( $brand_tax_cap, Newspack_Multibranded_Site\Meta\Menus::get_capability() );
		$this->assertSame( $brand_tax_cap, Newspack_Multibranded_Site\Meta\Show_Page_On_Front::get_capability() );
		$this->assertSame( $brand_tax_cap, Newspack_Multibranded_Site\Meta\Theme_Colors::get_capability() );
		$this->assertSame( $brand_tax_cap, Newspack_Multibranded_Site\Meta\Url::get_capability() );
	}
}
