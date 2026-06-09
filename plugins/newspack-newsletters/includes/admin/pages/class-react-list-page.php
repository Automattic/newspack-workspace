<?php
/**
 * Abstract React list-page base.
 *
 * Wires the legacy-list redirect target from the subclass's
 * `get_redirect_post_type()` + page slug.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Admin\Pages;

use Newspack\Newsletters\Admin\Admin_Page;
use Newspack\Newsletters\Admin\Admin_Shell_Legacy_Redirect;

defined( 'ABSPATH' ) || exit;

/**
 * Base for admin list pages backed by a React shell.
 */
abstract class React_List_Page extends Admin_Page {
	/**
	 * `post_type` slug the React page lives under in the admin URL:
	 * `edit.php?post_type=<this>&page=<slug>`. Used by the legacy-list
	 * redirect to build the canonical React URL.
	 *
	 * @return string
	 */
	abstract public function get_redirect_post_type(): string;

	/**
	 * Default redirect target — page's CPT slug + own slug.
	 *
	 * @param array|string $forwarded Forwarded query args, or a `post_status` string.
	 * @return string
	 */
	public function get_legacy_redirect_target( $forwarded = [] ): ?string {
		return Admin_Shell_Legacy_Redirect::build_legacy_redirect_target(
			$this->get_redirect_post_type(),
			$this->slug,
			$forwarded
		);
	}
}
