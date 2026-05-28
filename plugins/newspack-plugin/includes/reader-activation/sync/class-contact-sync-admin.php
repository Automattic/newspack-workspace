<?php
/**
 * Admin panel tools for the Contact Sync.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation;

defined( 'ABSPATH' ) || exit;

/**
 * Contact Sync Admin Class.
 */
class Contact_Sync_Admin {

	const ADMIN_ACTION = 'newspack-esp-sync';

	/**
	 * Context of the sync.
	 *
	 * @var string
	 */
	protected static $context = 'Contact sync manually triggered via wp-admin';

	/**
	 * Initializes hooks.
	 */
	public static function init_hooks() {
		/**
		 * Enables ESP sync administrative tools in the WordPress admin.
		 * Provides UI for managing reader data synchronization.
		 *
		 * @constant NEWSPACK_ESP_SYNC_ADMIN
		 * @type     bool
		 * @default  ESP sync admin tools disabled
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_ESP_SYNC_ADMIN', true );
		 */
		if ( ! defined( 'NEWSPACK_ESP_SYNC_ADMIN' ) || ! NEWSPACK_ESP_SYNC_ADMIN ) {
			return;
		}
		add_action( 'admin_init', [ __CLASS__, 'process_admin_action' ] );
		add_filter( 'user_row_actions', [ __CLASS__, 'user_row_actions' ], 100, 2 );
		add_filter( 'bulk_actions-users', [ __CLASS__, 'bulk_actions' ] );
		add_filter( 'handle_bulk_actions-users', [ __CLASS__, 'handle_bulk_actions' ], 10, 3 );
		add_action( 'admin_notices', [ __CLASS__, 'admin_notices' ] );
		add_action( 'newspack_sync_admin_batch', [ 'Newspack\Reader_Activation\Contact_Sync', 'sync_contact' ], 10, 1 ); // ActionScheduler hook.
	}

	/**
	 * Get url for the admin action.
	 *
	 * @param int $user_id User to get the URL for.
	 *
	 * @return string Admin URL to perform the admin action.
	 */
	private static function get_admin_action_url( $user_id ) {
		if ( ! \is_admin() ) {
			return '';
		}
		return \add_query_arg(
			[
				'action'   => self::ADMIN_ACTION,
				'uid'      => $user_id,
				'_wpnonce' => \wp_create_nonce( self::ADMIN_ACTION ),
			]
		);
	}

	/**
	 * Adds sync action to user row actions.
	 *
	 * @param string[] $actions User row actions.
	 * @param \WP_User $user    User object.
	 *
	 * @return string[] User row actions.
	 */
	public static function user_row_actions( $actions, $user ) {
		if ( ! \current_user_can( 'edit_user', $user->ID ) ) {
			return $actions;
		}
		if ( ! Contact_Sync::has_one_syncable_integration() ) {
			return $actions;
		}
		$url = self::get_admin_action_url( $user->ID );
		$actions[ self::ADMIN_ACTION ] = '<a href="' . $url . '">' . \esc_html__( 'Resync contact to ESP', 'newspack-plugin' ) . '</a>';
		return $actions;
	}

	/**
	 * Bulk actions for users.
	 *
	 * @param string[] $actions Bulk actions.
	 *
	 * @return string[] Bulk actions.
	 */
	public static function bulk_actions( $actions ) {
		if ( ! current_user_can( 'edit_users' ) ) {
			return $actions;
		}
		if ( ! Contact_Sync::has_one_syncable_integration() ) {
			return $actions;
		}
		// Bulk action requires ActionScheduler, so we don't show it if it's not available.
		if ( ! class_exists( 'ActionScheduler' ) ) {
			return $actions;
		}
		$actions[ self::ADMIN_ACTION ] = \esc_html__( 'Resync to the ESP', 'newspack-plugin' );
		return $actions;
	}

	/**
	 * Handle bulk actions.
	 *
	 * @param string $sendback The redirect URL.
	 * @param string $doaction The action being taken.
	 * @param array  $items    User IDs.
	 *
	 * @return string The redirect URL.
	 */
	public static function handle_bulk_actions( $sendback, $doaction, $items ) {
		if ( self::ADMIN_ACTION !== $doaction ) {
			return $sendback;
		}
		// Bulk action requires ActionScheduler, so we don't show it if it's not available.
		if ( ! class_exists( 'ActionScheduler' ) ) {
			\wp_die( \esc_html__( 'ActionScheduler is not available to perform the bulk action.', 'newspack-plugin' ) );
		}
		$can_sync = Contact_Sync::has_one_syncable_integration( true );
		if ( $can_sync->has_errors() ) {
			\wp_die( $can_sync ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( ! \current_user_can( 'edit_users' ) ) {
			\wp_die( \esc_html__( 'You do not have permission to do that.', 'newspack-plugin' ) );
		}
		foreach ( $items as $user_id ) {
			as_schedule_single_action( time(), 'newspack_sync_admin_batch', [ 'user_id' => $user_id ], 'newspack-sync' );
		}
		$sendback = \add_query_arg(
			[
				'update'                  => self::ADMIN_ACTION,
				'scheduled-sync-contacts' => count( $items ),
			],
			$sendback
		);
		return $sendback;
	}

	/**
	 * Process admin action request.
	 */
	public static function process_admin_action() {

		if ( ! isset( $_GET['action'] ) || self::ADMIN_ACTION !== $_GET['action'] ) {
			return;
		}

		$action = \sanitize_text_field( \wp_unslash( $_GET['action'] ) );

		// If we don't have UID, it's probably a bulk action.
		if ( ! isset( $_GET['uid'] ) ) {
			return;
		}

		if ( ! \check_admin_referer( $action ) ) {
			\wp_die( \esc_html__( 'Invalid request.', 'newspack-plugin' ) );
		}

		$user_id = \absint( \wp_unslash( $_GET['uid'] ) );

		if ( ! \current_user_can( 'edit_user', $user_id ) ) {
			\wp_die( \esc_html__( 'You do not have permission to do that.', 'newspack-plugin' ) );
		}

		$user = \get_user_by( 'id', $user_id );

		if ( ! $user || \is_wp_error( $user ) ) {
			\wp_die( \esc_html__( 'User not found.', 'newspack-plugin' ) );
		}

		$result = Contact_Sync::sync_contact( $user_id, self::$context );
		if ( \is_wp_error( $result ) ) {
			\wp_die( $result ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		$redirect = \add_query_arg( [ 'update' => $action ], \remove_query_arg( [ 'action', 'uid', '_wpnonce', 'scheduled-sync-contacts' ] ) );
		\wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Admin notices.
	 */
	public static function admin_notices() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! isset( $_GET['update'] ) ) {
			return;
		}
		$update = sanitize_text_field( wp_unslash( $_GET['update'] ) );
		if ( self::ADMIN_ACTION !== $update ) {
			return;
		}
		$message = __( 'Contact resynced to the ESP.', 'newspack-plugin' );
		if ( isset( $_GET['scheduled-sync-contacts'] ) ) {
			$scheduled_syncs = absint( wp_unslash( $_GET['scheduled-sync-contacts'] ) );
			$message         = sprintf(
				// translators: %d: number of contacts resynced.
				__( '%d contacts scheculed for resync to the ESP and should complete in a couple of minutes.', 'newspack-plugin' ),
				$scheduled_syncs
			);
		}
		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php echo esc_html( $message ); ?>
				<?php if ( isset( $_GET['scheduled-sync-contacts'] ) ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=action-scheduler&s=newspack_sync_admin_batch&orderby=schedule&order=desc' ) ); ?>">
						<?php echo esc_html__( 'Click here to monitor progress.', 'newspack-plugin' ); ?>
					</a>
				<?php endif; ?>
			</p>
		</div>
		<?php
		// phpcs:enable
	}
}
Contact_Sync_Admin::init_hooks();
