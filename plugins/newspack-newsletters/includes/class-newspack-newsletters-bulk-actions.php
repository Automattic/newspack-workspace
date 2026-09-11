<?php
/**
 * Newspack Newsletters Bulk Actions
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Newspack Newsletters Bulk Actions Class.
 */
class Newspack_Newsletters_Bulk_Actions {
	/**
	 * Set up hooks.
	 */
	public static function init() {
		add_filter( 'removable_query_args', [ __CLASS__, 'register_removable_args' ] );
		add_filter( 'bulk_actions-edit-newspack_nl_cpt', [ __CLASS__, 'register_bulk_actions' ] );
		add_filter( 'handle_bulk_actions-edit-newspack_nl_cpt', [ __CLASS__, 'bulk_action_handler' ], 10, 3 );
		add_action( 'admin_notices', [ __CLASS__, 'admin_notices' ] );
	}

	/**
	 * Register removable query args.
	 * 
	 * @param array $removable_query_args Removable query args.
	 * 
	 * @return array Updated removable query args.
	 */
	public static function register_removable_args( $removable_query_args ) {
		$removable_query_args[] = 'newsletters_public_count';
		$removable_query_args[] = 'newsletters_non_public_count';
		return $removable_query_args;
	}

	/**
	 * Register bulk action fields in bulk action dropdown.
	 * 
	 * @param array $bulk_actions Bulk actions.
	 * 
	 * @return array Updated bulk actions. 
	 */
	public static function register_bulk_actions( $bulk_actions ) {
		$bulk_actions['newsletters_public']     = __( 'Make newsletter pages public', 'newspack-newsletters' );
		$bulk_actions['newsletters_non_public'] = __( 'Make newsletter pages non-public', 'newspack-newsletters' );
		return $bulk_actions;
	}

	/**
	 * Bulk action handler.
	 * 
	 * @param string $redirect_to Redirect URL.
	 * @param string $action_name Action name.
	 * @param array  $post_ids    Post IDs.
	 */
	public static function bulk_action_handler( $redirect_to, $action_name, $post_ids ) {
		$redirect_to = remove_query_arg( array( 'newsletters_public_count', 'newsletters_non_public_count' ), $redirect_to );
		switch ( $action_name ) {
			case 'newsletters_public':
				$count       = self::set_public_status( $post_ids, true );
				$redirect_to = add_query_arg( 'newsletters_public_count', $count, $redirect_to );
				break;
			case 'newsletters_non_public':
				$count       = self::set_public_status( $post_ids, false );
				$redirect_to = add_query_arg( 'newsletters_non_public_count', $count, $redirect_to );
				break;
		}
		return $redirect_to;
	}

	/**
	 * Set the `is_public` meta on the submitted newsletters, skipping any the
	 * current user cannot edit or that are not newsletters.
	 *
	 * The bulk-actions nonce core verifies binds to the user and the action, not
	 * to the target posts, so the submitted ids must be authorized per post here.
	 *
	 * @param int[] $post_ids  Submitted post IDs.
	 * @param bool  $is_public Whether the newsletter pages should be public.
	 *
	 * @return int Number of newsletters the status was applied to. A newsletter
	 *             already in the requested state counts, since the notice reports
	 *             the resulting state rather than the number of rows changed.
	 */
	private static function set_public_status( $post_ids, $is_public ) {
		$updated = 0;
		foreach ( $post_ids as $post_id ) {
			if ( \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT !== get_post_type( $post_id ) ) {
				continue;
			}
			// The bulk actions render on the Trash view too. The service provider only
			// moves posts whose status it controls (publish/private), so writing the
			// meta on a trashed newsletter changes nothing now, still counts toward the
			// notice, and takes effect silently if the newsletter is later restored.
			if ( 'trash' === get_post_status( $post_id ) ) {
				continue;
			}
			// The rule itself lives in Newspack_Newsletters::current_user_can_set_public_status(),
			// which also backs the guard on the write. It is consulted here as well so a
			// newsletter the user cannot act on is skipped and left out of the count,
			// rather than attempted and blocked -- the notice reports what was applied.
			if ( ! \Newspack_Newsletters::current_user_can_set_public_status( $post_id, (bool) $is_public ) ) {
				continue;
			}
			update_post_meta( $post_id, 'is_public', (bool) $is_public );
			$updated++;
		}
		return $updated;
	}

	/**
	 * Admin notice on bulk action update result.
	 * 
	 * phpcs:disable WordPress.Security.NonceVerification.Recommended
	 * Bulk actions nonces are verified by the core, before the action handler and admin_notices.
	 */
	public static function admin_notices() {
		if ( isset( $_REQUEST['newsletters_public_count'] ) ) {
			$count = (int) $_REQUEST['newsletters_public_count'];
			printf(
				/* translators: %d updated posts count */
				'<div id="message" class="updated notice is-dismissable"><p>' . esc_html( _n( '%d newsletter now has public page available.', '%d newsletters now have public page available.', $count, 'newspack-newsletters' ) ) . '</p></div>',
				esc_attr( number_format_i18n( $count ) )
			);
		}

		if ( isset( $_REQUEST['newsletters_non_public_count'] ) ) {
			$count = (int) $_REQUEST['newsletters_non_public_count'];
			printf( 
				/* translators: %d updated posts count */
				'<div id="message" class="updated notice is-dismissable"><p>' . esc_html( _n( '%d newsletter now has public page disabled.', '%d newsletters now have public page disabled.', $count, 'newspack-newsletters' ) ) . '</p></div>',
				esc_attr( number_format_i18n( $count ) )
			);
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
}

if ( is_admin() ) {
	Newspack_Newsletters_Bulk_Actions::init();
}
