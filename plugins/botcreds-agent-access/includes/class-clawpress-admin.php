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
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'wp_ajax_clawpress_create', array( $this, 'handle_create_ajax' ) );
		add_action( 'wp_ajax_clawpress_revoke', array( $this, 'handle_revoke_ajax' ) );
	}

	/**
	 * Enqueue admin CSS and JS on profile pages only.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	/**
	 * Register Tools → ClawPress admin page.
	 */
	public function add_admin_menu() {
		add_management_page(
			__( 'ClawPress', 'clawpress' ),
			__( 'ClawPress', 'clawpress' ),
			'manage_options',
			'clawpress',
			array( $this, 'render_admin_page' )
		);
	}

	public function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'profile.php', 'user-edit.php', 'tools_page_clawpress' ), true ) ) {
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
		$stats        = ClawPress_Tracker::get_stats( get_current_user_id() );
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
				<th><?php esc_html_e( 'Created', 'clawpress' ); ?></th>
				<td><?php echo esc_html( $created_date ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Last Used', 'clawpress' ); ?></th>
				<td><?php echo esc_html( $last_used ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Posts', 'clawpress' ); ?></th>
				<td>
					<?php
					printf(
						/* translators: %d: number of posts */
						esc_html( _n( '%d post created via OpenClaw', '%d posts created via OpenClaw', $stats['post_count'], 'clawpress' ) ),
						$stats['post_count']
					);
					?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Media', 'clawpress' ); ?></th>
				<td>
					<?php
					printf(
						/* translators: %d: number of files */
						esc_html( _n( '%d file uploaded via OpenClaw', '%d files uploaded via OpenClaw', $stats['media_count'], 'clawpress' ) ),
						$stats['media_count']
					);
					?>
				</td>
			</tr>
		</table>

		<?php if ( ! empty( $stats['recent_posts'] ) ) : ?>
			<h3><?php esc_html_e( 'Recent OpenClaw Posts', 'clawpress' ); ?></h3>
			<table class="widefat striped clawpress-recent-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'clawpress' ); ?></th>
						<th><?php esc_html_e( 'Date', 'clawpress' ); ?></th>
						<th><?php esc_html_e( 'Status', 'clawpress' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $stats['recent_posts'] as $post ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>">
									<?php echo esc_html( $post->post_title ?: __( '(no title)', 'clawpress' ) ); ?>
								</a>
							</td>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $post->post_date ) ) ); ?></td>
							<td><span class="clawpress-badge clawpress-badge--<?php echo esc_attr( $post->post_status ); ?>"><?php echo esc_html( ucfirst( $post->post_status ) ); ?></span></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

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

	/**
	 * Render the Tools → ClawPress admin page.
	 */
	public function render_admin_page() {
		$users_with_passwords = $this->get_all_openclaw_users();
		?>
		<div class="wrap">
			<h1>
				<span>&#129438;</span>
				<?php esc_html_e( 'ClawPress', 'clawpress' ); ?>
			</h1>
			<p><?php esc_html_e( 'All users with active OpenClaw connections on this site.', 'clawpress' ); ?></p>

			<?php if ( empty( $users_with_passwords ) ) : ?>
				<p><em><?php esc_html_e( 'No users have connected OpenClaw yet.', 'clawpress' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'User', 'clawpress' ); ?></th>
							<th><?php esc_html_e( 'Role', 'clawpress' ); ?></th>
							<th><?php esc_html_e( 'Created', 'clawpress' ); ?></th>
							<th><?php esc_html_e( 'Last Used', 'clawpress' ); ?></th>
							<th><?php esc_html_e( 'Posts', 'clawpress' ); ?></th>
							<th><?php esc_html_e( 'Media', 'clawpress' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $users_with_passwords as $entry ) : ?>
							<tr>
								<td>
									<strong>
										<a href="<?php echo esc_url( get_edit_user_link( $entry['user']->ID ) ); ?>">
											<?php echo esc_html( $entry['user']->display_name ); ?>
										</a>
									</strong>
									<br>
									<span class="description"><?php echo esc_html( $entry['user']->user_login ); ?></span>
								</td>
								<td>
									<span class="clawpress-badge clawpress-badge--<?php echo esc_attr( $entry['role_slug'] ); ?>">
										<?php echo esc_html( $entry['role_name'] ); ?>
									</span>
								</td>
								<td><?php echo esc_html( $entry['created'] ); ?></td>
								<td><?php echo esc_html( $entry['last_used'] ); ?></td>
								<td><?php echo esc_html( $entry['stats']['post_count'] ); ?></td>
								<td><?php echo esc_html( $entry['stats']['media_count'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get all users who have an OpenClaw Application Password.
	 *
	 * @return array
	 */
	private function get_all_openclaw_users() {
		$results = array();
		$users   = get_users();

		foreach ( $users as $user ) {
			$passwords = WP_Application_Passwords::get_user_application_passwords( $user->ID );

			foreach ( $passwords as $item ) {
				if ( $item['name'] !== CLAWPRESS_APP_PASSWORD_NAME ) {
					continue;
				}

				$roles      = $user->roles;
				$role_slug  = ! empty( $roles ) ? $roles[0] : 'none';
				$role_obj   = get_role( $role_slug );
				$role_name  = $role_obj ? ucfirst( $role_slug ) : $role_slug;

				// Use wp_roles() for display name
				$wp_roles  = wp_roles();
				$role_name = isset( $wp_roles->role_names[ $role_slug ] ) ? translate_user_role( $wp_roles->role_names[ $role_slug ] ) : ucfirst( $role_slug );

				$created   = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['created'] );
				$last_used = ! empty( $item['last_used'] )
					? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['last_used'] )
					: __( 'Never', 'clawpress' );

				$results[] = array(
					'user'      => $user,
					'role_slug' => $role_slug,
					'role_name' => $role_name,
					'created'   => $created,
					'last_used' => $last_used,
					'stats'     => ClawPress_Tracker::get_stats( $user->ID ),
				);

				break; // Only one OpenClaw password per user
			}
		}

		return $results;
	}
}
