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
	 * Capability that admits the stored access-rule fields. It decides two things:
	 * who passes the read gate alongside READ_CAPABILITY, and who sees `meta` in a
	 * read response rather than an empty object — see check_read_capability() and
	 * prepare_item_for_response() below.
	 *
	 * It is not the write gate. Writes take their value from the post type's
	 * capability map in class-institution.php, which the parent resolves itself;
	 * every write capability there happens to resolve to this same string, which is
	 * why the two are easy to conflate. Changing this constant re-tiers reads only.
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
	 * Accepts either capability: a role holding only RULES_CAPABILITY (an ops
	 * or billing role scoped to manage_options without content capabilities,
	 * for example) still owns this route — it is the tier the Audience wizard
	 * grants access to, and it already sees every field once past this check,
	 * per prepare_item_for_response() below. Without this, such a role could
	 * open the wizard but never load the institutions list inside it.
	 *
	 * @return true|\WP_Error
	 */
	private static function check_read_capability() {
		if ( \current_user_can( self::READ_CAPABILITY ) || \current_user_can( self::RULES_CAPABILITY ) ) {
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
	 * passes it would still see an empty collection once every item got
	 * filtered out here. Broadened to admit either capability, matching
	 * check_read_capability() above, but only while get_items() (above) is
	 * running; every other caller of this method — including the real write
	 * gate in update_item_permissions_check(), which this class does not
	 * override — gets the parent's unmodified check, so the broadening never
	 * reaches a write. Admitting RULES_CAPABILITY here cannot loosen the data
	 * gate either: prepare_item_for_response() below keys the field strip on
	 * RULES_CAPABILITY independently, so a caller admitted here who lacks it
	 * still gets an empty meta object.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	protected function check_update_permission( $post ) {
		if ( $this->reading_collection ) {
			return \current_user_can( self::READ_CAPABILITY ) || \current_user_can( self::RULES_CAPABILITY );
		}

		return parent::check_update_permission( $post );
	}

	/**
	 * Withhold the stored fields from callers who may not see them.
	 *
	 * Every registered field is removed rather than an enumerated list. An
	 * enumerated list is a denylist: a field registered later would be returned
	 * by default, and nothing would fail. The consumers that read this route
	 * without the rules capability use the id and title only, so removing the
	 * whole object costs them nothing.
	 *
	 * Cast to an object rather than left as an empty PHP array: WP_REST_Server
	 * json_encode()s the response, and an empty PHP array serializes as a JSON
	 * array ([]) while WordPress declares this field's schema type as object.
	 * An administrator's response — which keeps the associative array
	 * get_value() returns — always serializes as an object, so leaving this
	 * one as an array would make the field's JSON type vary by caller: valid
	 * against the schema for one tier, a violation for the other.
	 *
	 * @param \WP_Post         $item    Post object.
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function prepare_item_for_response( $item, $request ) {
		// The parent returns whatever `rest_prepare_np_institution` last returned, and
		// a filter may hand back an array or a WP_Error -- core allows for that, which
		// is why prepare_response_for_collection() carries its own instanceof guard.
		// Without normalising here, get_data() below is a fatal rather than a strip.
		$response = \rest_ensure_response( parent::prepare_item_for_response( $item, $request ) );
		if ( \is_wp_error( $response ) ) {
			return $response;
		}

		if ( \current_user_can( self::RULES_CAPABILITY ) ) {
			return $response;
		}

		// Deliberately after the parent call, so the strip runs on whatever the
		// rest_prepare_np_institution filters left behind. Reordering these two would
		// let a third-party filter re-add the fields this method exists to withhold.
		$data = $response->get_data();
		if ( isset( $data['meta'] ) ) {
			$data['meta'] = (object) [];
			$response->set_data( $data );
		}

		return $response;
	}
}
