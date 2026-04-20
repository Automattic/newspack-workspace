<?php
/**
 * Agent Access Admin — Profile page integration and AJAX handlers.
 *
 * @package BotCreds Agent Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Access_Admin {

	/**
	 * @var Agent_Access_API
	 */
	private $api;

	/**
	 * @param Agent_Access_API $api
	 */
	public function __construct( Agent_Access_API $api ) {
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
		add_action( 'wp_ajax_agent_access_create', array( $this, 'handle_create_ajax' ) );
		add_action( 'wp_ajax_agent_access_revoke', array( $this, 'handle_revoke_ajax' ) );
	}

	/**
	 * Enqueue admin CSS and JS on profile pages only.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	/**
	 * Register Tools → Agent Access admin page.
	 */
	public function add_admin_menu() {
		add_management_page(
			__( 'BotCreds', 'botcreds-agent-access' ),
			__( 'BotCreds', 'botcreds-agent-access' ),
			'manage_options',
			'agent-access',
			array( $this, 'render_admin_page' )
		);
	}

	public function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'profile.php', 'user-edit.php', 'tools_page_botcreds' ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'agent-access-admin',
			AGENT_ACCESS_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			AGENT_ACCESS_VERSION
		);

		wp_enqueue_script(
			'agent-access-admin',
			AGENT_ACCESS_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			AGENT_ACCESS_VERSION,
			true
		);

		wp_localize_script( 'agent-access-admin', 'agentAccess', array(
			'ajax_url'       => admin_url( 'admin-ajax.php' ),
			'create_nonce'   => wp_create_nonce( 'agent_access_create' ),
			'revoke_nonce'   => wp_create_nonce( 'agent_access_revoke' ),
			'confirm_msg'    => __( 'Are you sure you want to revoke the agent connection? You will need to reconfigure your agent with a new password.', 'botcreds-agent-access' ),
			'creating_text'  => __( 'Connecting…', 'botcreds-agent-access' ),
			'revoking_text'  => __( 'Revoking…', 'botcreds-agent-access' ),
			'copied_text'    => __( 'Copied!', 'botcreds-agent-access' ),
			'copy_text'      => __( 'Copy', 'botcreds-agent-access' ),
		) );
	}

	/**
	 * Handle the AJAX create request.
	 */
	public function handle_create_ajax() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( __( 'You do not have permission to do this.', 'botcreds-agent-access' ) );
		}

		check_ajax_referer( 'agent_access_create', 'nonce' );

		$result = $this->api->create_password();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$connection_info = $this->api->get_connection_info( $result['password'] );

		$user = wp_get_current_user();
		do_action( 'agent_access_audit', 'app_password_created', array( 'username' => $user->user_login ) );

		wp_send_json_success( $connection_info );
	}

	/**
	 * Handle the AJAX revoke request.
	 */
	public function handle_revoke_ajax() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( __( 'You do not have permission to do this.', 'botcreds-agent-access' ) );
		}

		check_ajax_referer( 'agent_access_revoke', 'nonce' );

		$result = $this->api->revoke_password();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$user = wp_get_current_user();
		do_action( 'agent_access_audit', 'app_password_revoked', array( 'username' => $user->user_login ) );

		wp_send_json_success( __( 'Agent connection revoked successfully.', 'botcreds-agent-access' ) );
	}

	/**
	 * Render the Agent Access section on the profile page.
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
		$error_message  = get_transient( 'agent_access_error_' . $user_id );
		$created_info   = get_transient( 'agent_access_created_' . $user_id );
		$just_created   = ! empty( $created_info );

		if ( $error_message ) {
			delete_transient( 'agent_access_error_' . $user_id );
		}
		if ( $just_created ) {
			delete_transient( 'agent_access_created_' . $user_id );
		}

		?>
		<div id="agent-access" class="agent-access-profile-section">
			<h2 class="agent-access-title">
				<span class="agent-access-logo">&#129438;</span>
				<?php esc_html_e( 'BotCreds', 'botcreds-agent-access' ); ?>
				<span class="dashicons dashicons-wordpress" style="font-size:1.2em;vertical-align:middle;opacity:0.7;"></span>
			</h2>
			<p class="description">
				<?php esc_html_e( 'Connect your AI agent to WordPress in one click.', 'botcreds-agent-access' ); ?>
			</p>

			<?php if ( $error_message ) : ?>
				<div class="notice notice-error inline agent-access-notice">
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
		<p><span class="agent-access-success-icon">&#10003;</span> <strong><?php esc_html_e( 'Connection Created!', 'botcreds-agent-access' ); ?></strong></p>

		<div class="agent-access-warning-box">
			<strong><?php esc_html_e( 'Important:', 'botcreds-agent-access' ); ?></strong>
			<?php esc_html_e( 'This password will only be shown once. Copy the message below and send it to your AI agent.', 'botcreds-agent-access' ); ?>
		</div>

		<div class="agent-access-json-block">
			<pre class="agent-access-json" id="agent-access-json"><?php echo esc_html( 'Save these WordPress Application Password credentials and use them to connect to my site via the WordPress REST API:' . "\n" . $json ); ?></pre>
			<button type="button" class="button agent-access-copy-btn" data-target="agent-access-json">
				<?php esc_html_e( 'Copy', 'botcreds-agent-access' ); ?>
			</button>
		</div>

		<p class="agent-access-next-step">
			<?php esc_html_e( 'Paste this into your Agent Access chat (Telegram, WhatsApp, etc.) and your agent will handle the rest.', 'botcreds-agent-access' ); ?>
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
			: __( 'Never', 'botcreds-agent-access' );
		$stats        = Agent_Access_Tracker::get_stats( get_current_user_id() );
		?>
		<div class="agent-access-notice-row">
			<div class="agent-access-notice-box agent-access-notice-box--green">
				<?php esc_html_e( 'Connected', 'botcreds-agent-access' ); ?>
			</div>
			<div class="agent-access-notice-box agent-access-notice-box--red">
				<?php esc_html_e( 'Your AI agent can post here on your behalf.', 'botcreds-agent-access' ); ?>
			</div>
		</div>

		<table class="agent-access-status-table">
			<tr>
				<th><?php esc_html_e( 'Created', 'botcreds-agent-access' ); ?></th>
				<td><?php echo esc_html( $created_date ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Last Used', 'botcreds-agent-access' ); ?></th>
				<td><?php echo esc_html( $last_used ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Posts', 'botcreds-agent-access' ); ?></th>
				<td>
					<?php
					printf(
						/* translators: %d: number of posts */
						esc_html( _n( '%d post created via agent', '%d posts created via agent', $stats['post_count'], 'botcreds-agent-access' ) ),
						(int) $stats['post_count']
					);
					?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Media', 'botcreds-agent-access' ); ?></th>
				<td>
					<?php
					printf(
						/* translators: %d: number of files */
						esc_html( _n( '%d file uploaded via agent', '%d files uploaded via agent', $stats['media_count'], 'botcreds-agent-access' ) ),
						(int) $stats['media_count']
					);
					?>
				</td>
			</tr>
		</table>

		<?php if ( ! empty( $stats['recent_posts'] ) ) : ?>
			<h3><?php esc_html_e( 'Recent Agent Posts', 'botcreds-agent-access' ); ?></h3>
			<table class="widefat striped agent-access-recent-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'botcreds-agent-access' ); ?></th>
						<th><?php esc_html_e( 'Date', 'botcreds-agent-access' ); ?></th>
						<th><?php esc_html_e( 'Status', 'botcreds-agent-access' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $stats['recent_posts'] as $post ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>">
									<?php echo esc_html( $post->post_title ?: __( '(no title)', 'botcreds-agent-access' ) ); ?>
								</a>
							</td>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $post->post_date ) ) ); ?></td>
							<td><span class="agent-access-badge agent-access-badge--<?php echo esc_attr( $post->post_status ); ?>"><?php echo esc_html( ucfirst( $post->post_status ) ); ?></span></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<div class="agent-access-revoke-section">
			<button type="button" class="button agent-access-revoke-btn" id="agent-access-revoke-btn">
				<?php esc_html_e( 'Revoke Connection', 'botcreds-agent-access' ); ?>
			</button>
			<span class="agent-access-revoke-hint">
				<?php esc_html_e( 'This will disconnect your agent from your account.', 'botcreds-agent-access' ); ?>
			</span>
		</div>
		<?php
	}

	/**
	 * Render the "disconnected" state with create button.
	 */
	private function render_disconnected_state() {
		?>
		<div id="agent-access-card">
			<p>
				<button type="button" class="button button-primary agent-access-create-btn" id="agent-access-create-btn">
					<?php esc_html_e( 'Connect Agent', 'botcreds-agent-access' ); ?>
				</button>
			</p>
			<p class="agent-access-create-hint">
				<?php esc_html_e( 'This will generate a secure Application Password for Agent Access. You\'ll be given credentials to paste into your Agent Access config.', 'botcreds-agent-access' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the Tools → Agent Access admin page.
	 */
	public function render_admin_page() {
		$users_with_passwords = $this->get_all_openclaw_users();
		?>
		<div class="wrap">
			<h1>
				<span>&#129438;</span>
				<?php esc_html_e( 'BotCreds', 'botcreds-agent-access' ); ?>
			</h1>
			<p><?php esc_html_e( 'All users with active agent connections on this site.', 'botcreds-agent-access' ); ?></p>

			<?php if ( empty( $users_with_passwords ) ) : ?>
				<p><em><?php esc_html_e( 'No users have connected an agent yet.', 'botcreds-agent-access' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'User', 'botcreds-agent-access' ); ?></th>
							<th><?php esc_html_e( 'Role', 'botcreds-agent-access' ); ?></th>
							<th><?php esc_html_e( 'Created', 'botcreds-agent-access' ); ?></th>
							<th><?php esc_html_e( 'Last Used', 'botcreds-agent-access' ); ?></th>
							<th><?php esc_html_e( 'Posts', 'botcreds-agent-access' ); ?></th>
							<th><?php esc_html_e( 'Media', 'botcreds-agent-access' ); ?></th>
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
									<span class="agent-access-badge agent-access-badge--<?php echo esc_attr( $entry['role_slug'] ); ?>">
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
	 * Get all users who have an Agent Access Application Password.
	 *
	 * @return array
	 */
	private function get_all_openclaw_users() {
		$results = array();
		$users   = get_users( array( 'number' => 200 ) );

		foreach ( $users as $user ) {
			$passwords = WP_Application_Passwords::get_user_application_passwords( $user->ID );

			foreach ( $passwords as $item ) {
				if ( $item['name'] !== AGENT_ACCESS_APP_PASSWORD_NAME ) {
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
					: __( 'Never', 'botcreds-agent-access' );

				$results[] = array(
					'user'      => $user,
					'role_slug' => $role_slug,
					'role_name' => $role_name,
					'created'   => $created,
					'last_used' => $last_used,
					'stats'     => Agent_Access_Tracker::get_stats( $user->ID ),
				);

				break; // Only one Agent Access password per user
			}
		}

		return $results;
	}
}

