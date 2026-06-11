<?php
/**
 * Admin shell — legacy CPT list redirect.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Classic CPT list → React page redirect plumbing.
 */
class Admin_Shell_Legacy_Redirect {
	/**
	 * Query args forwarded from the legacy URL onto the React page.
	 *
	 * `paged` is deliberately omitted — legacy WP_List_Table uses 20
	 * per page vs DataView's 25, so the slice wouldn't translate.
	 */
	const FORWARDED_LEGACY_ARGS = [
		'post_status',
		's',
		'orderby',
		'order',
		'author',
		'categories',
		'tags',
		'newspack_newsletters_send_list_id',
	];

	/**
	 * Boot hooks.
	 */
	public static function init() {
		add_action( 'current_screen', [ __CLASS__, 'maybe_redirect_legacy_list' ] );
	}

	/**
	 * Whether `action` / `action2` carry a real value (i.e. not WP's
	 * `-1` "no action selected" sentinel).
	 *
	 * @return bool
	 */
	private static function has_real_get_action() {
		foreach ( [ 'action', 'action2' ] as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only nav check.
			if ( ! isset( $_GET[ $key ] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only nav check.
			$value = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			if ( '' !== $value && '-1' !== $value ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Redirect legacy CPT list GETs to the matching React page.
	 *
	 * Form-submission GETs carrying `?action=…` are left alone so
	 * classic admin flows continue to work; the `-1` sentinel from
	 * a stale bulk-action submit is treated as a no-op.
	 *
	 * @param \WP_Screen $screen Current screen.
	 */
	public static function maybe_redirect_legacy_list( $screen ) {
		if ( ! is_admin() || ! $screen instanceof \WP_Screen ) {
			return;
		}
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		$matching_page = null;
		foreach ( Admin_Shell::get_pages() as $page ) {
			if ( $screen->id === $page->get_legacy_screen_id() ) {
				$matching_page = $page;
				break;
			}
		}
		if ( ! $matching_page ) {
			return;
		}

		if ( self::has_real_get_action() ) {
			return;
		}

		$forwarded = [];
		foreach ( self::FORWARDED_LEGACY_ARGS as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only nav check.
			if ( ! isset( $_GET[ $key ] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised below.
			$value = wp_unslash( $_GET[ $key ] );
			if ( '' === $value || ( is_array( $value ) && empty( $value ) ) ) {
				continue;
			}
			$forwarded[ $key ] = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : sanitize_text_field( $value );
		}

		$target = $matching_page->get_legacy_redirect_target( $forwarded );
		if ( ! $target ) {
			return;
		}

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Build a redirect target URL for a chassis-managed page.
	 *
	 * @param string       $post_type CPT slug the page shadows.
	 * @param string       $page_slug The React page's `?page=` slug.
	 * @param array|string $forwarded Forwarded query args, or a `post_status` string.
	 * @return string
	 */
	public static function build_legacy_redirect_target( $post_type, $page_slug, $forwarded = [] ) {
		$args = [
			'post_type' => $post_type,
			'page'      => $page_slug,
		];

		if ( is_string( $forwarded ) ) {
			$forwarded = '' === $forwarded ? [] : [ 'post_status' => $forwarded ];
		}

		foreach ( self::FORWARDED_LEGACY_ARGS as $key ) {
			if ( ! empty( $forwarded[ $key ] ) ) {
				// `add_query_arg()` does not URL-encode values, so encode to prevent param injection.
				$value        = $forwarded[ $key ];
				$args[ $key ] = is_array( $value ) ? array_map( 'rawurlencode', $value ) : rawurlencode( $value );
			}
		}

		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}
}
