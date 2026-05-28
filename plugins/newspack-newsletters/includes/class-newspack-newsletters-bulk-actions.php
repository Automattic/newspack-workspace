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
				foreach ( $post_ids as $post_id ) {
					update_post_meta( $post_id, 'is_public', true );
				}
				$redirect_to = add_query_arg( 'newsletters_public_count', count( $post_ids ), $redirect_to );
				break;
			case 'newsletters_non_public':
				foreach ( $post_ids as $post_id ) {
					update_post_meta( $post_id, 'is_public', false );
				}
				$redirect_to = add_query_arg( 'newsletters_non_public_count', count( $post_ids ), $redirect_to );
				break;
		}
		return $redirect_to;
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
