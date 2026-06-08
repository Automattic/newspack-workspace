<?php
/**
 * Bootstrap for the Newspack Profiles plugin.
 *
 * @package NewspackProfiles
 */

declare( strict_types=1 );

namespace NewspackProfiles;

use NewspackProfiles\Registrars\Block_Registrar;
use NewspackProfiles\Registrars\Rewrite_Rule_Registrar;
use NewspackProfiles\RestAPIs\Data_Source_Rest_Api;
use NewspackProfiles\RestAPIs\Profile_Collections_Rest_Api;
use NewspackProfiles\Traits\Singleton;

/**
 * Plugin class to initialize the Newspack Profiles plugin.
 */
class Plugin {

	use Singleton;

	/**
	 * Constructor for the Plugin class.
	 */
	protected function __construct() {
		Menu::get_instance();
		Profile_Collections_Rest_Api::get_instance();
		Data_Source_Rest_Api::get_instance();
		Block_Registrar::get_instance();
		Page_Template_Manager::get_instance();
		Rewrite_Rule_Registrar::get_instance();
		Import_Manager::get_instance();
		SEO_Manager::get_instance();
		Sitemap_Generator::get_instance();
		Block_Editor::get_instance();
	}
}
