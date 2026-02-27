<?php
/**
 * ClawPress Admin page rendering and AJAX handlers.
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
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_clawpress_create', array( $this, 'handle_create' ) );
		add_action( 'wp_ajax_clawpress_revoke', array( $this, 'handle_revoke_ajax' ) );
	}

	/**
	 * Add the settings page under Settings menu.
	 */
	public function add_menu_page() {
		add_options_page(
			__( 'ClawPress', 'clawpress' ),
			__( 'ClawPress', 'clawpress' ),
			'manage_options',
			'clawpress',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin CSS and JS on our page only.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'settings_page_clawpress' !== $hook_suffix ) {
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
			'ajax_url'      => admin_url( 'admin-ajax.php' ),
			'revoke_nonce'  => wp_create_nonce( 'clawpress_revoke' ),
			'confirm_msg'   => __( 'Are you sure you want to revoke the OpenClaw connection? You will need to reconfigure OpenClaw with a new password.', 'clawpress' ),
			'revoking_text' => __( 'Revoking…', 'clawpress' ),
			'copied_text'   => __( 'Copied!', 'clawpress' ),
			'copy_text'     => __( 'Copy', 'clawpress' ),
		) );
	}

	/**
	 * Handle the Create Application Password form submission.
	 */
	public function handle_create() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'clawpress' ) );
		}

		check_admin_referer( 'clawpress_create' );

		$result = $this->api->create_password();

		if ( is_wp_error( $result ) ) {
			set_transient( 'clawpress_error_' . get_current_user_id(), $result->get_error_message(), 60 );
			wp_safe_redirect( admin_url( 'options-general.php?page=clawpress' ) );
			exit;
		}

		$connection_info = $this->api->get_connection_info( $result['password'] );
		set_transient(
			'clawpress_created_' . get_current_user_id(),
			$connection_info,
			300
		);

		wp_safe_redirect( admin_url( 'options-general.php?page=clawpress&created=1' ) );
		exit;
	}

	/**
	 * Handle the AJAX revoke request.
	 */
	public function handle_revoke_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
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
	 * Render the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$existing       = $this->api->get_existing_password();
		$user_id        = get_current_user_id();
		$error_message  = get_transient( 'clawpress_error_' . $user_id );
		$created_info   = get_transient( 'clawpress_created_' . $user_id );
		$just_created   = isset( $_GET['created'] ) && '1' === $_GET['created'] && $created_info;

		if ( $error_message ) {
			delete_transient( 'clawpress_error_' . $user_id );
		}
		if ( $just_created ) {
			delete_transient( 'clawpress_created_' . $user_id );
		}

		?>
		<div class="wrap clawpress-wrap">
			<h1 class="clawpress-title">
				<span class="clawpress-logo">&#129438;</span>
				<?php esc_html_e( 'ClawPress', 'clawpress' ); ?>
			</h1>
			<p class="clawpress-subtitle">
				<?php esc_html_e( 'Connect OpenClaw to your WordPress site in one click.', 'clawpress' ); ?>
			</p>

			<?php if ( $error_message ) : ?>
				<div class="notice notice-error clawpress-notice">
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
		<div class="clawpress-card clawpress-card--success">
			<div class="clawpress-card__icon">&#10003;</div>
			<h2><?php esc_html_e( 'Connection Created!', 'clawpress' ); ?></h2>

			<div class="clawpress-warning-box">
				<strong><?php esc_html_e( 'Important:', 'clawpress' ); ?></strong>
				<?php esc_html_e( 'This password will only be shown once. Copy it now and paste it into your OpenClaw config.', 'clawpress' ); ?>
			</div>

			<div class="clawpress-credentials">
				<table class="clawpress-credentials__table">
					<tr>
						<th><?php esc_html_e( 'Site URL', 'clawpress' ); ?></th>
						<td><code><?php echo esc_html( $info['site_url'] ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Username', 'clawpress' ); ?></th>
						<td><code><?php echo esc_html( $info['username'] ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Password', 'clawpress' ); ?></th>
						<td><code class="clawpress-password"><?php echo esc_html( $info['password'] ); ?></code></td>
					</tr>
				</table>
			</div>

			<div class="clawpress-json-block">
				<label><?php esc_html_e( 'Or copy as JSON for your OpenClaw config:', 'clawpress' ); ?></label>
				<pre class="clawpress-json" id="clawpress-json"><?php echo esc_html( $json ); ?></pre>
				<button type="button" class="button clawpress-copy-btn" data-target="clawpress-json">
					<?php esc_html_e( 'Copy', 'clawpress' ); ?>
				</button>
			</div>

			<p class="clawpress-next-step">
				<?php esc_html_e( 'Paste this into your OpenClaw config, then you\'re all set.', 'clawpress' ); ?>
			</p>
		</div>
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
		<div class="clawpress-card clawpress-card--connected">
			<div class="clawpress-card__icon clawpress-card__icon--connected">&#9679;</div>
			<h2><?php esc_html_e( 'Connected', 'clawpress' ); ?></h2>
			<p class="clawpress-card__desc">
				<?php esc_html_e( 'An OpenClaw Application Password is active for this site.', 'clawpress' ); ?>
			</p>

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
				<p class="clawpress-revoke-hint">
					<?php esc_html_e( 'This will disconnect OpenClaw from your site. You can create a new connection afterward.', 'clawpress' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the "disconnected" state with create button.
	 */
	private function render_disconnected_state() {
		?>
		<div class="clawpress-card clawpress-card--disconnected">
			<div class="clawpress-card__icon clawpress-card__icon--disconnected">&#9675;</div>
			<h2><?php esc_html_e( 'Not Connected', 'clawpress' ); ?></h2>
			<p class="clawpress-card__desc">
				<?php esc_html_e( 'Create a secure connection to let OpenClaw manage your WordPress content.', 'clawpress' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="clawpress_create" />
				<?php wp_nonce_field( 'clawpress_create' ); ?>
				<button type="submit" class="button button-primary clawpress-create-btn">
					<?php esc_html_e( 'Connect OpenClaw', 'clawpress' ); ?>
				</button>
			</form>

			<p class="clawpress-create-hint">
				<?php esc_html_e( 'This will generate a secure Application Password for OpenClaw. You\'ll be given credentials to paste into your OpenClaw config.', 'clawpress' ); ?>
			</p>
		</div>
		<?php
	}
}
