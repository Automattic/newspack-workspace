<?php
/**
 * REST surface for the Layouts list DataView.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Admin;

defined( 'ABSPATH' ) || exit;

use Newspack_Newsletters_Layouts;

/**
 * Adds the read-only author field the Layouts list renders, so the list
 * never has to ask for `_embed=author` (see `Rest_Author_Field`).
 */
class Layouts_List_REST {
	use Rest_Author_Field;

	/**
	 * Boot hooks.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_fields' ] );
	}

	/**
	 * Register REST fields on the layouts CPT.
	 */
	public static function register_rest_fields(): void {
		self::register_author_field(
			Newspack_Newsletters_Layouts::NEWSPACK_NEWSLETTERS_LAYOUT_CPT,
			'newspack_newsletters_author'
		);
	}
}
