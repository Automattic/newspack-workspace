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
 * Core gates writes through the capability map but not reads of published
 * posts, so the read requirement lives here.
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
	 * Capability required to write to institutions, matching the post type's
	 * capability map in class-institution.php (every write capability there
	 * resolves to this one). Not consulted directly by this class today — the
	 * write gate below defers to the parent's own resolution of that map. A
	 * later task also uses this constant to limit which callers see the
	 * stored access rules in read responses.
	 *
	 * @var string
	 */
	const RULES_CAPABILITY = 'manage_options';

	/**
	 * True while get_items() is running.
	 *
	 * Lets check_update_permission() below tell a per-item collection-read call
	 * from a write-permission call apart, since the parent calls that method
	 * from both places with only a \WP_Post argument — no request or context to
	 * branch on otherwise.
	 *
	 * @var bool
	 */
	private $reading_collection = false;

	/**
	 * Permission check for reading the collection.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return true|\WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		return self::check_read_capability();
	}

	/**
	 * Retrieves a collection of institutions.
	 *
	 * Flags check_update_permission() below as answering a read for the
	 * duration of the parent's query, then restores it — including if the
	 * parent throws, so a broadened write check never survives past this call.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$this->reading_collection = true;
		try {
			return parent::get_items( $request );
		} finally {
			$this->reading_collection = false;
		}
	}

	/**
	 * Permission check for reading a single institution.
	 *
	 * Refuses outright if the caller lacks READ_CAPABILITY; otherwise defers to
	 * the parent so its other checks (trashed posts, and edit context on a
	 * single item — the only consumer of that path is the audience wizard's
	 * institutions editor, gated to RULES_CAPABILITY at the page level) still
	 * apply.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return true|\WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$check = self::check_read_capability();
		return true === $check ? parent::get_item_permissions_check( $request ) : $check;
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
			\__( 'Sorry, you are not allowed to view institutions.', 'newspack-plugin' ),
			[ 'status' => \rest_authorization_required_code() ]
		);
	}

	/**
	 * Per-item check the parent also uses to gate updates.
	 *
	 * Core's get_items() calls this — not check_read_permission() — for every
	 * post in the result set when the request context is edit, so
	 * get_items_permissions_check() above isn't enough on its own: a caller who
	 * passes it with READ_CAPABILITY would still see an empty collection once
	 * every item got filtered out here. Broadened to READ_CAPABILITY only while
	 * get_items() (above) is running; every other caller of this method —
	 * including the real write gate in update_item_permissions_check(), which
	 * this class does not override — gets the parent's unmodified check, so the
	 * broadening never reaches a write.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	protected function check_update_permission( $post ) {
		if ( $this->reading_collection ) {
			return \current_user_can( self::READ_CAPABILITY );
		}

		return parent::check_update_permission( $post );
	}
}
