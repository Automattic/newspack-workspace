<?php
/**
 * Newspack Multibranded site plugin initialization.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site;

/**
 * Class to handle the plugin initialization
 */
class Initializer {

	/**
	 * Runs the initialization.
	 */
	public static function init() {
		Taxonomy::init();
		Admin::init();

		Customizations\Url::init();
		Customizations\Show_Page_On_Front::init();
		Customizations\Logo::init();
		Customizations\Theme_Colors::init();
		Customizations\Menus::init();
		Customizations\Blogname::init();
		Customizations\Popups_Should_Display_Prompt::init();
		Customizations\Body_Class::init();
		Customizations\Site_Title::init();
		Integrations\Google_Analytics::init();
	}
}
