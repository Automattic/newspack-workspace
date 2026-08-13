<?php
/**
 * REST controller for the institution post type.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies the read authorization the default posts controller does not.
 *
 * Core's WP_REST_Posts_Controller::check_read_permission() returns true as soon
 * as a post is published, before it consults a capability, and
 * get_items_permissions_check() carries no read check at all. A post type's
 * capability map therefore governs writes but not reads of published posts.
 * Institutions are always published, so the map alone leaves the route open.
 *
 * This controller adds the missing gate, and narrows what a caller who may see
 * institution names is shown of the rules that grant access.
 */
class Institution_REST_Controller extends \WP_REST_Posts_Controller {

	/**
	 * Capability required to read institutions through REST.
	 *
	 * Mirrors the capability gating the block editor panel that consumes this
	 * route, so the gate grants exactly what the only non-administrator consumer
	 * needs and nothing wider.
	 *
	 * @var string
	 */
	const READ_CAPABILITY = 'edit_others_posts';

	/**
	 * Capability required to see the stored access rules.
	 *
	 * @var string
	 */
	const RULES_CAPABILITY = 'manage_options';

	/**
	 * Permission check for reading the collection.
	 *
	 * Deliberately does not defer to the parent. The parent's only test refuses
	 * the edit context to anyone lacking this post type's edit_posts, which is
	 * mapped to manage_options — and the consuming dropdowns request the edit
	 * context because they read title.raw.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return true|\WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		return self::check_read_capability();
	}

	/**
	 * Permission check for reading a single institution.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return true|\WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		return self::check_read_capability();
	}

	/**
	 * The shared read gate.
	 *
	 * @return true|\WP_Error
	 */
	private static function check_read_capability() {
		if ( \current_user_can( self::READ_CAPABILITY ) ) {
			return true;
		}

		return new \WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to view institutions.', 'newspack-plugin' ),
			[ 'status' => \rest_authorization_required_code() ]
		);
	}

	/**
	 * Per-item check the parent class uses for two unrelated purposes: filtering
	 * the collection when the request context is edit (a read), and gating
	 * update_item_permissions_check() below (a write). get_items_permissions_check()
	 * above only covers the collection as a whole; core's get_items() still runs
	 * this method against every post in the result set when context=edit, and the
	 * default implementation requires this post type's edit_post, mapped to
	 * RULES_CAPABILITY. Left alone, a caller who passes the collection-level gate
	 * with READ_CAPABILITY would still see an empty list. Broadened here to
	 * READ_CAPABILITY for that read path; update_item_permissions_check() restores
	 * the original, stricter requirement for the write path that also calls this
	 * method, so the broadening never reaches a write.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	protected function check_update_permission( $post ) {
		return \current_user_can( self::READ_CAPABILITY );
	}

	/**
	 * Permission check for updating a single institution.
	 *
	 * Every write-related meta capability on this post type (edit_post,
	 * edit_others_posts, publish_posts, ...) maps to RULES_CAPABILITY uniformly,
	 * so requiring it directly reproduces the parent's write gate without routing
	 * through check_update_permission(), which this class broadens above for the
	 * edit-context collection read.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return true|\WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		if ( \current_user_can( self::RULES_CAPABILITY ) ) {
			return true;
		}

		return new \WP_Error(
			'rest_cannot_edit',
			__( 'Sorry, you are not allowed to edit this post.', 'newspack-plugin' ),
			[ 'status' => \rest_authorization_required_code() ]
		);
	}
}
