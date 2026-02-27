<?php
/**
 * ClawPress Admin — Profile page integration and AJAX handlers.
 *
 * @package ClawPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ClawPress_Admin {

	/**
	 * @var ClawPress_API
	 */
	private $api;

	/**
	 * @param ClawPress_API $api
	 */
	public function __construct( ClawPress_API $api ) {
		$this->api = $api;
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'show_user_profile', array( $this, 'render_profile_section' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile_section' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_clawpress_create', array( $this, 'handle_create_ajax' ) );
		add_action( 'wp_ajax_clawpress_revoke', array( $this, 'handle_revoke_ajax' ) );
	}

	/**
	 * Enqueue admin CSS and JS on profile pages only.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'profile.php', 'user-edit.php' ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'clawpress-admin',
			CLAWPRESS_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			CLAWPRESS_VERSION
		);

		wp_enqueue_script(
			'clawpress-admin',
			CLAWPRESS_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			CLAWPRESS_VERSION,
			true
		);

		wp_localize_script( 'clawpress-admin', 'clawpress', array(
			'ajax_url'       => admin_url( 'admin-ajax.php' ),
			'create_nonce'   => wp_create_nonce( 'clawpress_create' ),
			'revoke_nonce'   => wp_create_nonce( 'clawpress_revoke' ),
			'confirm_msg'    => __( 'Are you sure you want to revoke the OpenClaw connection? You will need to reconfigure OpenClaw with a new password.', 'clawpress' ),
			'creating_text'  => __( 'Connecting…', 'clawpress' ),
			'revoking_text'  => __( 'Revoking…', 'clawpress' ),
			'copied_text'    => __( 'Copied!', 'clawpress' ),
			'copy_text'      => __( 'Copy', 'clawpress' ),
		) );
	}

	/**
	 * Handle the AJAX create request.
	 */
	public function handle_create_ajax() {
		if ( ! current_user_can( 'exist' ) ) {
			wp_send_json_error( __( 'You do not have permission to do this.', 'clawpress' ) );
		}

		check_ajax_referer( 'clawpress_create', 'nonce' );

		$result = $this->api->create_password();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$connection_info = $this->api->get_connection_info( $result['password'] );

		wp_send_json_success( $connection_info );
	}

	/**
	 * Handle the AJAX revoke request.
	 */
	public function handle_revoke_ajax() {
		if ( ! current_user_can( 'exist' ) ) {
			wp_send_json_error( __( 'You do not have permission to do this.', 'clawpress' ) );
		}

		check_ajax_referer( 'clawpress_revoke', 'nonce' );

		$result = $this->api->revoke_password();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( __( 'OpenClaw connection revoked successfully.', 'clawpress' ) );
	}

	/**
	 * Render the ClawPress section on the profile page.
	 *
	 * @param WP_User $user The user being edited.
	 */
	public function render_profile_section( $user ) {
		// Only show on own profile
		if ( get_current_user_id() !== $user->ID ) {
			return;
		}

		$existing       = $this->api->get_existing_password();
		$user_id        = get_current_user_id();
		$error_message  = get_transient( 'clawpress_error_' . $user_id );
		$created_info   = get_transient( 'clawpress_created_' . $user_id );
		$just_created   = isset( $_GET['clawpress_created'] ) && '1' === $_GET['clawpress_created'] && $created_info;

		if ( $error_message ) {
			delete_transient( 'clawpress_error_' . $user_id );
		}
		if ( $just_created ) {
			delete_transient( 'clawpress_created_' . $user_id );
		}

		?>
		<div id="clawpress" class="clawpress-profile-section">
			<h2 class="clawpress-title">
				<span class="clawpress-logo">&#129438;</span>
				<?php esc_html_e( 'ClawPress by WordPress.com', 'clawpress' ); ?>
				<img src="https://s.w.org/style/images/about/WordPress-logotype-wmark.png" alt="WordPress" class="clawpress-wp-logo" />
			</h2>
			<p class="description">
				<?php esc_html_e( 'Connect OpenClaw to your WordPress site in one click.', 'clawpress' ); ?>
			</p>

			<?php if ( $error_message ) : ?>
				<div class="notice notice-error inline clawpress-notice">
					<p><?php echo esc_html( $error_message ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $just_created ) : ?>
				<?php $this->render_created_state( $created_info ); ?>
			<?php elseif ( $existing ) : ?>
				<?php $this->render_connected_state( $existing ); ?>
			<?php else : ?>
				<?php $this->render_disconnected_state(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the "just created" state showing the password once.
	 *
	 * @param array $info Connection info with site_url, username, password.
	 */
	private function render_created_state( $info ) {
		$json = wp_json_encode( $info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		?>
		<p><span class="clawpress-success-icon">&#10003;</span> <strong><?php esc_html_e( 'Connection Created!', 'clawpress' ); ?></strong></p>

		<div class="clawpress-warning-box">
			<strong><?php esc_html_e( 'Important:', 'clawpress' ); ?></strong>
			<?php esc_html_e( 'This password will only be shown once. Copy the message below and send it to OpenClaw.', 'clawpress' ); ?>
		</div>

		<div class="clawpress-json-block">
			<pre class="clawpress-json" id="clawpress-json"><?php echo esc_html( 'Save these WordPress Application Password credentials and use them to connect to my site via the WordPress REST API:' . "\n" . $json ); ?></pre>
			<button type="button" class="button clawpress-copy-btn" data-target="clawpress-json">
				<?php esc_html_e( 'Copy', 'clawpress' ); ?>
			</button>
		</div>

		<p class="clawpress-next-step">
			<?php esc_html_e( 'Paste this into your OpenClaw chat (Telegram, WhatsApp, etc.) and your agent will handle the rest.', 'clawpress' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the "connected" state with status info and revoke button.
	 *
	 * @param array $existing The existing application password entry.
	 */
	private function render_connected_state( $existing ) {
		$created_date = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $existing['created'] );
		$last_used    = ! empty( $existing['last_used'] )
			? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $existing['last_used'] )
			: __( 'Never', 'clawpress' );
		?>
		<div class="clawpress-notice-row">
			<div class="clawpress-notice-box clawpress-notice-box--green">
				<?php esc_html_e( 'Connected', 'clawpress' ); ?>
			</div>
			<div class="clawpress-notice-box clawpress-notice-box--red">
				<?php esc_html_e( 'Your OpenClaw agent can post here on your behalf.', 'clawpress' ); ?>
			</div>
		</div>

		<table class="clawpress-status-table">
			<tr>
				<th><?php esc_html_e( 'Status', 'clawpress' ); ?></th>
				<td><span class="clawpress-badge clawpress-badge--active"><?php esc_html_e( 'Active', 'clawpress' ); ?></span></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Created', 'clawpress' ); ?></th>
				<td><?php echo esc_html( $created_date ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Last Used', 'clawpress' ); ?></th>
				<td><?php echo esc_html( $last_used ); ?></td>
			</tr>
		</table>

		<div class="clawpress-revoke-section">
			<button type="button" class="button clawpress-revoke-btn" id="clawpress-revoke-btn">
				<?php esc_html_e( 'Revoke Connection', 'clawpress' ); ?>
			</button>
			<span class="clawpress-revoke-hint">
				<?php esc_html_e( 'This will disconnect OpenClaw from your account.', 'clawpress' ); ?>
			</span>
		</div>
		<?php
	}

	/**
	 * Render the "disconnected" state with create button.
	 */
	private function render_disconnected_state() {
		?>
		<div id="clawpress-card">
			<p>
				<button type="button" class="button button-primary clawpress-create-btn" id="clawpress-create-btn">
					<?php esc_html_e( 'Connect OpenClaw', 'clawpress' ); ?>
				</button>
			</p>
			<p class="clawpress-create-hint">
				<?php esc_html_e( 'This will generate a secure Application Password for OpenClaw. You\'ll be given credentials to paste into your OpenClaw config.', 'clawpress' ); ?>
			</p>
		</div>
		<?php
	}
}
