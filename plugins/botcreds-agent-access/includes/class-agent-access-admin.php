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
		if ( ! in_array( $hook_suffix, array( 'profile.php', 'user-edit.php', 'tools_page_agent-access' ), true ) ) {
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

		$existing      = $this->api->get_existing_password();
		$user_id       = get_current_user_id();
		$error_message = get_transient( 'agent_access_error_' . $user_id );
		if ( $error_message ) {
			delete_transient( 'agent_access_error_' . $user_id );
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

			<?php if ( $existing ) : ?>
				<?php $this->render_connected_state( $existing ); ?>
			<?php else : ?>
				<?php $this->render_disconnected_state(); ?>
			<?php endif; ?>
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'connections';
		?>
		<div class="wrap">
			<h1>
				<span>&#129438;</span>
				<?php esc_html_e( 'BotCreds', 'botcreds-agent-access' ); ?>
			</h1>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'connections', menu_page_url( 'agent-access', false ) ) ); ?>"
				   class="nav-tab <?php echo 'connections' === $current_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Connections', 'botcreds-agent-access' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'activity', menu_page_url( 'agent-access', false ) ) ); ?>"
				   class="nav-tab <?php echo 'activity' === $current_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Activity Log', 'botcreds-agent-access' ); ?>
				</a>
			</nav>

			<?php if ( 'activity' === $current_tab ) : ?>
				<?php $this->render_activity_log_tab(); ?>
			<?php else : ?>
				<?php $this->render_connections_tab(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the Connections tab (formerly the full admin page).
	 */
	private function render_connections_tab() {
		$users_with_passwords = $this->get_connected_users();
		?>
		<p><?php esc_html_e( 'All users with active agent connections on this site.', 'botcreds-agent-access' ); ?></p>

		<?php if ( $this->connections_list_is_truncated() ) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'This site has more than 200 users. Only the first 200 are shown. Users with agent connections beyond this limit will not appear in this list.', 'botcreds-agent-access' ); ?></p>
			</div>
		<?php endif; ?>

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
		<?php endif;
	}

	/**
	 * Render the Activity Log tab.
	 */
	private function render_activity_log_tab() {
		$per_page = 50;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$paged    = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
		$source   = isset( $_GET['source'] ) ? sanitize_key( $_GET['source'] ) : '';
		$method   = isset( $_GET['method'] ) ? strtoupper( sanitize_key( $_GET['method'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$filter_args = array(
			'source'          => $source,
			'method'          => $method,
			'exclude_methods' => empty( $method ) ? array( 'GET' ) : array(),
		);

		$total   = Agent_Access_Activity_Log::count_entries( $filter_args );
		$entries = Agent_Access_Activity_Log::get_entries( array_merge( $filter_args, array(
			'limit'  => $per_page,
			'offset' => ( $paged - 1 ) * $per_page,
		) ) );

		$base_url = add_query_arg( 'tab', 'activity', menu_page_url( 'agent-access', false ) );
		?>

		<form method="get" style="margin: 1em 0;">
			<input type="hidden" name="page" value="agent-access">
			<input type="hidden" name="tab" value="activity">

			<select name="source">
				<option value=""><?php esc_html_e( 'All sources', 'botcreds-agent-access' ); ?></option>
				<option value="agent-access" <?php selected( $source, 'botcreds-agent-access' ); ?>><?php esc_html_e( 'Agent Access', 'botcreds-agent-access' ); ?></option>
				<option value="wordpress-mcp" <?php selected( $source, 'wordpress-mcp' ); ?>><?php esc_html_e( 'WordPress.com MCP', 'botcreds-agent-access' ); ?></option>
				<option value="rest-api" <?php selected( $source, 'rest-api' ); ?>><?php esc_html_e( 'REST API', 'botcreds-agent-access' ); ?></option>
			</select>

			<select name="method">
				<option value=""><?php esc_html_e( 'Writes (POST/PUT/PATCH/DELETE)', 'botcreds-agent-access' ); ?></option>
				<?php foreach ( array( 'POST', 'PUT', 'PATCH', 'DELETE' ) as $m ) : ?>
					<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $method, $m ); ?>><?php echo esc_html( $m ); ?></option>
				<?php endforeach; ?>
			</select>

			<?php submit_button( __( 'Filter', 'botcreds-agent-access' ), 'secondary', '', false ); ?>
		</form>

		<p>
			<?php
			printf(
				/* translators: %d: total log entries */
				esc_html__( '%d total log entries.', 'botcreds-agent-access' ),
				(int) $total
			);
			?>
		</p>

		<?php if ( empty( $entries ) ) : ?>
			<p><em><?php esc_html_e( 'No activity logged yet.', 'botcreds-agent-access' ); ?></em></p>
		<?php else : ?>
			<table class="widefat striped" style="font-size:13px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'botcreds-agent-access' ); ?></th>
						<th><?php esc_html_e( 'Source', 'botcreds-agent-access' ); ?></th>
						<th><?php esc_html_e( 'User', 'botcreds-agent-access' ); ?></th>
						<th><?php esc_html_e( 'Method', 'botcreds-agent-access' ); ?></th>
						<th><?php esc_html_e( 'Route', 'botcreds-agent-access' ); ?></th>
						<th style="min-width:140px;"><?php esc_html_e( 'Object', 'botcreds-agent-access' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $entries as $row ) : ?>
						<tr>
							<td style="white-space:nowrap;"><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $row->created_at ) ) ); ?></td>
							<td>
								<?php
								$source_labels = array(
									'agent-access'  => 'Agent Access',
									'wordpress-mcp' => 'WP.com MCP',
									'rest-api'      => 'REST API',
								);
								$source_label = isset( $source_labels[ $row->source ] ) ? $source_labels[ $row->source ] : $row->source;
								?>
								<span class="agent-access-badge agent-access-badge--<?php echo esc_attr( str_replace( '-', '', $row->source ) ); ?>">
									<?php echo esc_html( $source_label ); ?>
								</span>
							</td>
							<td>
								<?php if ( $row->user_id ) : ?>
									<a href="<?php echo esc_url( get_edit_user_link( $row->user_id ) ); ?>">
										<?php echo esc_html( $row->display_name ?: $row->user_login ?: '#' . $row->user_id ); ?>
									</a>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</td>
							<td><code><?php echo esc_html( $row->method ); ?></code></td>
							<td style="word-break:break-all;"><code><?php echo esc_html( $row->route ); ?></code></td>
							<td>
								<?php if ( $row->object_id ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( $row->object_id ) ?: '' ); ?>">
										<?php echo esc_html( $row->object_type . ' #' . $row->object_id ); ?>
									</a>
								<?php elseif ( $row->object_type ) : ?>
									<?php echo esc_html( $row->object_type ); ?>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			// Windowed pagination — avoids emitting thousands of links if the log grows very large.
			$total_pages = (int) ceil( $total / $per_page );
			if ( $total_pages > 1 ) {
				$pagination_links = paginate_links( array(
					'base'      => add_query_arg( 'paged', '%#%', $base_url ),
					'format'    => '',
					'current'   => $paged,
					'total'     => $total_pages,
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
					'type'      => 'plain',
				) );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links() output is escaped by core.
				echo '<div class="tablenav"><div class="tablenav-pages">' . $pagination_links . '</div></div>';
			}
			?>
		<?php endif;
	}

	/**
	 * Get all users who have an Agent Access Application Password.
	 *
	 * @return array
	 */
	/**
	 * Whether the Connections list may be truncated due to the 200-user fetch cap.
	 *
	 * @return bool
	 */
	private function connections_list_is_truncated() {
		$counts = count_users();
		return $counts['total_users'] > 200;
	}

	/**
	 * Get users who have an Agent Access Application Password.
	 *
	 * Note: silently capped at 200 users. A truncation notice is shown when
	 * the site has more users than this limit.
	 *
	 * @return array
	 */
	private function get_connected_users() {
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

