<?php
/**
 * Content Gate Preview.
 *
 * Force-renders a gate layout on a recent published post so it can be previewed
 * from the block editor, mirroring the newspack-popups prompt preview. Keeps
 * full parity with the real front-end: it does not bypass the "Woo Memberships
 * active" guard, so the preview is only ever offered when Access Control owns
 * the front-end (see Content_Gate\Gate_Preview::is_preview_request()).
 *
 * @package Newspack
 */

namespace Newspack\Content_Gate;

use Newspack\Content_Gate as Content_Gate_Controller;
use Newspack\Memberships;

defined( 'ABSPATH' ) || exit;

/**
 * Gate preview controller.
 */
class Gate_Preview {

	/**
	 * Query param carrying the previewed layout post ID.
	 *
	 * @var string
	 */
	const PREVIEW_QUERY_PARAM = 'ngp_id';

	/**
	 * Map of layout meta keys to their abbreviated preview query params.
	 *
	 * Autosaves do not persist meta, so the editor ships the reader's unsaved
	 * meta through the URL under these short keys (chosen to avoid collision
	 * with newspack-popups' `pid`/`n_*` scheme).
	 *
	 * @var array<string,string>
	 */
	const PREVIEW_QUERY_KEYS = [
		'style'              => 'ngp_st',
		'inline_fade'        => 'ngp_if',
		'use_more_tag'       => 'ngp_mt',
		'visible_paragraphs' => 'ngp_vp',
		'overlay_position'   => 'ngp_op',
		'overlay_size'       => 'ngp_os',
	];

	/**
	 * Register the force-render callbacks.
	 *
	 * All callbacks are registered unconditionally and no-op unless the request
	 * is a preview request (see is_preview_request()), mirroring the
	 * newspack-popups pattern. The hot filters short-circuit on a cheap
	 * key/object check before ever consulting is_preview_request().
	 */
	public static function init() {
		add_filter( 'newspack_is_post_restricted', [ __CLASS__, 'filter_is_post_restricted' ], PHP_INT_MAX, 2 );
		add_filter( 'newspack_content_gate_layout_id', [ __CLASS__, 'filter_gate_layout_id' ], PHP_INT_MAX );
		add_filter( 'newspack_content_gate_post_id', [ __CLASS__, 'filter_gate_post_id' ], PHP_INT_MAX );
		add_filter( 'newspack_content_gate_metering_short_circuit', [ __CLASS__, 'filter_metering_short_circuit' ], PHP_INT_MAX );
		add_filter( 'newspack_can_render_overlay_gate', [ __CLASS__, 'filter_can_render_overlay_gate' ], PHP_INT_MAX );
		add_filter( 'newspack_gate_layout_content', [ __CLASS__, 'filter_gate_layout_content' ], 10, 2 );
		add_filter( 'get_post_metadata', [ __CLASS__, 'filter_layout_meta' ], 10, 4 );
		add_filter( 'show_admin_bar', [ __CLASS__, 'filter_show_admin_bar' ] ); // phpcs:ignore WordPressVIPMinimum.UserExperience.AdminBarRemoval.RemovalDetected
	}

	/**
	 * The previewed layout post ID from the request, if valid.
	 *
	 * @return int The layout post ID, or 0 if not a preview request or the ID
	 *             does not point at a gate layout.
	 */
	public static function previewed_layout_id() {
		// Not using filter_input(): it doesn't play well with PHPUnit.
		if ( empty( $_GET[ self::PREVIEW_QUERY_PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return 0;
		}
		$layout_id = absint( $_GET[ self::PREVIEW_QUERY_PARAM ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $layout_id || Content_Gate_Controller::GATE_LAYOUT_CPT !== get_post_type( $layout_id ) ) {
			return 0;
		}
		return $layout_id;
	}

	/**
	 * Whether the current user may preview gates.
	 *
	 * No nonce is checked (newspack-popups parity); the capability gate is the
	 * only guard on the force-render, so it must be a real capability check.
	 *
	 * @return bool
	 */
	public static function current_user_can_preview() {
		/**
		 * Filters the capability required to preview a gate layout.
		 *
		 * @param string $capability Capability to check. Default: edit_others_pages.
		 */
		$capability = apply_filters( 'newspack_gate_preview_capability', 'edit_others_pages' );
		return is_user_logged_in() && current_user_can( $capability );
	}

	/**
	 * Whether this request is a gate preview request.
	 *
	 * Parity decision: no Woo Memberships bypass. When WCM is active it still
	 * controls the front-end, so a forced preview would show an ungated post;
	 * we don't offer the preview there and this returns false.
	 *
	 * Memoized for the request lifetime (the underlying signals are immutable
	 * within a request) since it is consulted from hot filters. The cache is
	 * skipped under IS_TEST_ENV, where a single suite drives multiple users and
	 * query states through this method (precedent: Content_Gate::is_newspack_feature_enabled()).
	 *
	 * @return bool
	 */
	public static function is_preview_request() {
		if ( defined( 'IS_TEST_ENV' ) && IS_TEST_ENV ) {
			return self::compute_is_preview_request();
		}
		static $is_preview = null;
		if ( null === $is_preview ) {
			$is_preview = self::compute_is_preview_request();
		}
		return $is_preview;
	}

	/**
	 * Compute whether this request is a gate preview request.
	 *
	 * @return bool
	 */
	private static function compute_is_preview_request() {
		return ! is_admin()
			&& Content_Gate_Controller::is_newspack_feature_enabled()
			&& ! Memberships::is_active()
			&& (bool) self::previewed_layout_id()
			&& self::current_user_can_preview();
	}

	/**
	 * Sanitized layout meta overrides carried in the preview URL.
	 *
	 * Only present, valid params are returned; anything missing or invalid is
	 * dropped so the layout's stored meta wins for that key.
	 *
	 * @return array<string,mixed> Meta key => override value.
	 */
	public static function get_preview_meta_overrides() {
		$overrides = [];
		foreach ( self::PREVIEW_QUERY_KEYS as $meta_key => $query_key ) {
			if ( ! isset( $_GET[ $query_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				continue;
			}
			$raw   = wp_unslash( $_GET[ $query_key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$value = self::sanitize_meta_override( $meta_key, $raw );
			if ( null !== $value ) {
				$overrides[ $meta_key ] = $value;
			}
		}
		return $overrides;
	}

	/**
	 * Sanitize a single layout meta override value.
	 *
	 * @param string $meta_key Layout meta key.
	 * @param mixed  $raw      Raw query value.
	 *
	 * @return mixed|null Sanitized value, or null if invalid (stored meta wins).
	 */
	private static function sanitize_meta_override( $meta_key, $raw ) {
		switch ( $meta_key ) {
			case 'style':
				return in_array( $raw, [ 'inline', 'overlay' ], true ) ? $raw : null;
			case 'inline_fade':
			case 'use_more_tag':
				// Only recognized boolean strings win; anything else is invalid and
				// falls back to stored meta (rest_sanitize_boolean would coerce a
				// malformed value like "abc" to false and wrongly override).
				$normalized = strtolower( trim( (string) $raw ) );
				if ( in_array( $normalized, [ 'true', '1', 'yes', 'on' ], true ) ) {
					return true;
				}
				if ( in_array( $normalized, [ 'false', '0', 'no', 'off' ], true ) ) {
					return false;
				}
				return null;
			case 'visible_paragraphs':
				return is_numeric( $raw ) ? absint( $raw ) : null;
			case 'overlay_position':
				return in_array( $raw, [ 'center', 'bottom' ], true ) ? $raw : null;
			case 'overlay_size':
				return in_array( $raw, [ 'x-small', 'small', 'medium', 'large', 'full-width' ], true ) ? $raw : null;
			default:
				return null;
		}
	}

	/**
	 * Find the gate that references the given layout.
	 *
	 * Gates link to layouts (not the reverse) via their registration /
	 * custom_access `gate_layout_id`. Scans both the regular and newsletter gate
	 * sets. Returns the first matching gate ID regardless of its post status;
	 * the caller decides how to treat a draft parent.
	 *
	 * @param int $layout_id Gate layout post ID.
	 *
	 * @return int Parent gate post ID, or 0 if none references the layout.
	 */
	public static function get_layout_parent_gate_id( $layout_id ) {
		$layout_id = (int) $layout_id;
		if ( ! $layout_id ) {
			return 0;
		}
		$gate_sets = [
			Content_Gate_Controller::get_gates( Content_Gate_Controller::GATE_CPT ),
			Content_Gate_Controller::get_gates( Content_Gate_Controller::GATE_CPT, null, true ),
		];
		foreach ( $gate_sets as $gates ) {
			foreach ( $gates as $gate ) {
				if ( is_wp_error( $gate ) ) {
					continue;
				}
				$layout_ids = [
					(int) ( $gate['registration']['gate_layout_id'] ?? 0 ),
					(int) ( $gate['custom_access']['gate_layout_id'] ?? 0 ),
				];
				if ( in_array( $layout_id, $layout_ids, true ) ) {
					return (int) $gate['id'];
				}
			}
		}
		return 0;
	}

	/**
	 * Permalink of the post used as the preview canvas.
	 *
	 * The latest published, non-password `post` (mirrors
	 * Newspack_Popups::preview_post_permalink()). Restricting to the `post`
	 * type keeps the privacy/cart/checkout/account guards in
	 * Content_Gate::restrict_post() unreachable.
	 *
	 * @return string Permalink, or '' if there is no eligible post.
	 */
	public static function preview_post_permalink() {
		$query = new \WP_Query(
			[
				'post_type'      => 'post',
				'posts_per_page' => 1,
				'post_status'    => 'publish',
				'has_password'   => false,
				'orderby'        => 'post_date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			]
		);
		$posts = $query->get_posts();
		return $posts ? (string) get_the_permalink( $posts[0] ) : '';
	}

	/**
	 * Data localized to the layout editor to power the Preview button.
	 *
	 * @return array{preview_post:string, frontend_url:string, query_param:string, preview_query_keys:array}
	 */
	public static function get_editor_preview_data() {
		return [
			'preview_post'       => self::preview_post_permalink(),
			'frontend_url'       => get_site_url(),
			'query_param'        => self::PREVIEW_QUERY_PARAM,
			'preview_query_keys' => self::PREVIEW_QUERY_KEYS,
		];
	}

	/**
	 * Force the queried post to be restricted during a preview.
	 *
	 * Runs at PHP_INT_MAX so it wins over Content_Restriction_Control (which
	 * returns false for users who can edit the post, and caches per
	 * post/user). Only the queried post is forced; other post IDs pass through.
	 *
	 * @param bool $restricted Whether the post is restricted.
	 * @param int  $post_id    Post ID.
	 *
	 * @return bool
	 */
	public static function filter_is_post_restricted( $restricted, $post_id ) {
		if ( ! self::is_preview_request() ) {
			return $restricted;
		}
		// Force only the queried (previewed) post. An empty $post_id resolves to
		// the queried object so in-loop calls still gate, but an explicit,
		// unrelated post id is never force-restricted, and nothing is forced when
		// there is no singular queried object.
		$queried_id = (int) get_queried_object_id();
		$target_id  = empty( $post_id ) ? $queried_id : (int) $post_id;
		if ( $queried_id && $target_id === $queried_id ) {
			return true;
		}
		return $restricted;
	}

	/**
	 * Force the gate layout ID to the previewed layout.
	 *
	 * @param int $gate_layout_id Gate layout ID.
	 *
	 * @return int
	 */
	public static function filter_gate_layout_id( $gate_layout_id ) {
		if ( ! self::is_preview_request() ) {
			return $gate_layout_id;
		}
		return self::previewed_layout_id();
	}

	/**
	 * Force the gate post ID during a preview.
	 *
	 * Returns the previewed layout's published parent gate. When the parent is a
	 * draft (or none references the layout), it falls back to the layout ID so
	 * that Content_Gate::has_gate() — which requires a `publish` status — stays
	 * true (layout posts are always published). Consumers that expect a *gate*
	 * post ID (e.g. metering settings, analytics) degrade gracefully: the layout
	 * carries no gate meta, so they read defaults. This is contained to preview
	 * requests only.
	 *
	 * @param int $gate_post_id Gate post ID.
	 *
	 * @return int
	 */
	public static function filter_gate_post_id( $gate_post_id ) {
		if ( ! self::is_preview_request() ) {
			return $gate_post_id;
		}
		$layout_id = self::previewed_layout_id();
		$parent_id = self::get_layout_parent_gate_id( $layout_id );
		if ( $parent_id && 'publish' === get_post_status( $parent_id ) ) {
			return $parent_id;
		}
		return $layout_id;
	}

	/**
	 * Short-circuit metering during a preview.
	 *
	 * Returning a non-null value disables all three metering checks: it keeps
	 * the restriction on, passes the overlay metering bail, and — critically —
	 * prevents the previewing admin's metering allowance from being consumed.
	 *
	 * @param mixed $short_circuit Short-circuit value.
	 *
	 * @return mixed
	 */
	public static function filter_metering_short_circuit( $short_circuit ) {
		if ( ! self::is_preview_request() ) {
			return $short_circuit;
		}
		return true;
	}

	/**
	 * Allow the overlay gate to render during a preview.
	 *
	 * Content_Gifting disables the overlay gate via __return_false on this
	 * filter; this restores it at PHP_INT_MAX so the overlay preview works even
	 * when a gift is in play.
	 *
	 * @param bool $can_render Whether the overlay gate can render.
	 *
	 * @return bool
	 */
	public static function filter_can_render_overlay_gate( $can_render ) {
		if ( ! self::is_preview_request() ) {
			return $can_render;
		}
		return true;
	}

	/**
	 * Substitute the previewed layout's autosaved content.
	 *
	 * Autosaves don't persist to the published post, so the reader's unsaved
	 * edits are read from the autosave revision. Falls back to the passed
	 * (saved) content when there is no autosave. The returned raw block content
	 * flows on through the existing `newspack_gate_content` pipeline.
	 *
	 * @param string $content        Layout content.
	 * @param int    $gate_layout_id Gate layout ID being rendered.
	 *
	 * @return string
	 */
	public static function filter_gate_layout_content( $content, $gate_layout_id ) {
		if ( ! self::is_preview_request() ) {
			return $content;
		}
		if ( (int) $gate_layout_id !== self::previewed_layout_id() ) {
			return $content;
		}
		$autosave = wp_get_post_autosave( $gate_layout_id );
		if ( $autosave instanceof \WP_Post ) {
			return $autosave->post_content;
		}
		return $content;
	}

	/**
	 * Override the previewed layout's meta from the preview URL params.
	 *
	 * Autosaves don't carry meta, so the editor's unsaved Style/Settings travel
	 * in the URL. The key/object checks run before is_preview_request() so this
	 * globally-registered filter stays cheap for unrelated meta reads.
	 *
	 * @param mixed  $value     The meta value (null to fall through to the DB).
	 * @param int    $object_id Object ID.
	 * @param string $meta_key  Meta key.
	 * @param bool   $single    Whether a single value was requested.
	 *
	 * @return mixed
	 */
	public static function filter_layout_meta( $value, $object_id, $meta_key, $single ) {
		if ( ! array_key_exists( $meta_key, self::PREVIEW_QUERY_KEYS ) ) {
			return $value;
		}
		if ( (int) $object_id !== self::previewed_layout_id() ) {
			return $value;
		}
		if ( ! self::is_preview_request() ) {
			return $value;
		}
		$overrides = self::get_preview_meta_overrides();
		if ( ! array_key_exists( $meta_key, $overrides ) ) {
			return $value;
		}
		// Always return an array; get_metadata_raw() unwraps [0] for single requests.
		return [ $overrides[ $meta_key ] ];
	}

	/**
	 * Hide the admin bar during a preview so the iframe shows a clean front-end.
	 *
	 * @param bool $show Whether to show the admin bar.
	 *
	 * @return bool
	 */
	public static function filter_show_admin_bar( $show ) {
		if ( $show && self::is_preview_request() ) {
			return false;
		}
		return $show;
	}
}
