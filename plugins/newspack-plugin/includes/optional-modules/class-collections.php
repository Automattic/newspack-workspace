<?php
/**
 * Collections module.
 *
 * @package Newspack
 */

namespace Newspack\Optional_Modules;

defined( 'ABSPATH' ) || exit;

use Newspack\Optional_Modules;
use Newspack\Collections\Enqueuer;
use Newspack\Collections\Post_Type;
use Newspack\Collections\Collection_Taxonomy;
use Newspack\Collections\Collection_Category_Taxonomy;
use Newspack\Collections\Collection_Section_Taxonomy;
use Newspack\Collections\Post_Meta;
use Newspack\Collections\Cache;
use Newspack\Collections\Template_Helper;
use Newspack\Collections\Content_Inserter;

/**
 * Collections module for managing print editions and other collections.
 */
class Collections {
	/**
	 * Module name for the optional modules system.
	 *
	 * @var string
	 */
	public const MODULE_NAME = 'collections';

	/**
	 * Initialize the module.
	 */
	public static function init() {
		if ( ! self::is_module_active() ) {
			return;
		}

		// Initialize classes.
		Enqueuer::init();
		Post_Type::init();
		Collection_Taxonomy::init();
		Collection_Category_Taxonomy::init();
		Collection_Section_Taxonomy::init();
		Post_Meta::init();
		Cache::init();
		Template_Helper::init();
		Content_Inserter::init();
	}

	/**
	 * Whether the Collections module is enabled.
	 *
	 * @return bool True if Collections is enabled.
	 */
	public static function is_feature_enabled() {
		/**
		 * Filters whether the Collections feature is enabled.
		 *
		 * @param bool $is_enabled Whether the Collections module is enabled.
		 */
		return apply_filters( 'newspack_collections_enabled', true );
	}

	/**
	 * Whether the Collections module is enabled and active.
	 *
	 * @return bool True if Collections is enabled and active.
	 */
	public static function is_module_active() {
		return self::is_feature_enabled() && Optional_Modules::is_optional_module_active( self::MODULE_NAME );
	}
}

// Initialize the module.
Collections::init();
