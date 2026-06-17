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
		add_action( 'admin_menu', array( $this, 'add_users_menu' ) );
		add_action( 'wp_ajax_agent_access_create', array( $this, 'handle_create_ajax' ) );
		add_action( 'wp_ajax_agent_access_revoke', array( $this, 'handle_revoke_ajax' ) );
		add_action( 'wp_ajax_agent_access_admin_create', array( $this, 'handle_admin_create_ajax' ) );
		add_action( 'wp_ajax_agent_access_admin_revoke', array( $this, 'handle_admin_revoke_ajax' ) );
		add_action( 'wp_ajax_agent_access_admin_update', array( $this, 'handle_admin_update_ajax' ) );
		add_action( 'admin_post_agent_access_save_approval_setting', array( $this, 'handle_save_approval_setting' ) );
		add_action( 'admin_post_agent_access_add_agent', array( $this, 'handle_add_agent' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_add_agent_notice' ) );
		add_action( 'wp_ajax_agent_access_restore', array( 'Agent_Access_Rollback', 'handle_restore_ajax' ) );
	}

	/**
	 * Register Users → Add Agent submenu page.
	 */
	public function add_users_menu() {
		add_users_page(
			__( 'Add Agent', 'botcreds-agent-access' ),
			__( 'Add Agent', 'botcreds-agent-access' ),
			'create_users',
			'agent-access-add-agent',
			array( $this, 'render_add_agent_page' )
		);
	}

	/**
	 * Render the Users → Add Agent page.
	 */
	public function render_add_agent_page() {
		if ( ! current_user_can( 'create_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to create users.', 'botcreds-agent-access' ) );
		}

		$error_key = 'agent_access_add_agent_error_' . get_current_user_id();
		$errors    = get_transient( $error_key );
		if ( $errors ) {
			delete_transient( $error_key );
		}

		// Re-populate fields from a failed submission.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$prev_username     = isset( $_POST['agent_username'] )     ? sanitize_user( wp_unslash( $_POST['agent_username'] ) )          : '';
		$prev_email        = isset( $_POST['agent_email'] )        ? sanitize_email( wp_unslash( $_POST['agent_email'] ) )             : '';
		$prev_display_name = isset( $_POST['agent_display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['agent_display_name'] ) ) : '';
		// phpcs:enable
		?>
		<div class="wrap">
			<h1>
				<span>&#129438;</span>
				<?php esc_html_e( 'Add Agent', 'botcreds-agent-access' ); ?>
			</h1>
			<p class="description">
				<?php esc_html_e( 'Creates a WordPress user account with the Agent role. After creation, go to their profile to connect their BotCreds credential.', 'botcreds-agent-access' ); ?>
			</p>

			<?php if ( ! empty( $errors ) ) : ?>
				<div class="notice notice-error">
					<?php foreach ( (array) $errors as $err ) : ?>
						<p><?php echo esc_html( $err ); ?></p>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:520px;margin-top:1.5em;">
				<?php wp_nonce_field( 'agent_access_add_agent_nonce', 'agent_access_add_agent_nonce' ); ?>
				<input type="hidden" name="action" value="agent_access_add_agent">
				<input type="hidden" name="agent_password" id="agent_password_hidden">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="agent_username"><?php esc_html_e( 'Username', 'botcreds-agent-access' ); ?> <span aria-hidden="true">*</span></label>
						</th>
						<td>
							<input type="text"
								name="agent_username"
								id="agent_username"
								class="regular-text"
								value="<?php echo esc_attr( $prev_username ); ?>"
								required
								autocomplete="off">
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="agent_email"><?php esc_html_e( 'Email', 'botcreds-agent-access' ); ?> <span aria-hidden="true">*</span></label>
						</th>
						<td>
							<input type="email"
								name="agent_email"
								id="agent_email"
								class="regular-text"
								value="<?php echo esc_attr( $prev_email ); ?>"
								required
								autocomplete="off">
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="agent_display_name"><?php esc_html_e( 'Agent Name', 'botcreds-agent-access' ); ?></label>
						</th>
						<td>
							<input type="text"
								name="agent_display_name"
								id="agent_display_name"
								class="regular-text"
								value="<?php echo esc_attr( $prev_display_name ); ?>"
								autocomplete="off">
							<p class="description"><?php esc_html_e( 'Display name for the agent (optional). Maps to display_name and first_name.', 'botcreds-agent-access' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="agent_password_display"><?php esc_html_e( 'Password', 'botcreds-agent-access' ); ?></label>
						</th>
						<td>
							<input type="text"
								id="agent_password_display"
								class="regular-text"
								readonly
								autocomplete="new-password"
								style="font-family:monospace;">
							<a href="#" id="agent_password_regenerate" style="margin-left:0.5em;"><?php esc_html_e( 'Regenerate', 'botcreds-agent-access' ); ?></a>
							<p class="description"><?php esc_html_e( 'Auto-generated password. Copy it now — it will not be shown again.', 'botcreds-agent-access' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Role', 'botcreds-agent-access' ); ?></th>
						<td>
							<span class="agent-access-badge agent-access-badge--agent"><?php esc_html_e( 'Agent', 'botcreds-agent-access' ); ?></span>
							<p class="description"><?php esc_html_e( 'Agent role is pre-selected and cannot be changed here.', 'botcreds-agent-access' ); ?></p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Create Agent', 'botcreds-agent-access' ); ?>
					</button>
				</p>
			</form>
		</div>

		<script>
		( function() {
			function generatePassword( length ) {
				var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%^&*';
				var pass  = '';
				for ( var i = 0; i < length; i++ ) {
					pass += chars.charAt( Math.floor( Math.random() * chars.length ) );
				}
				return pass;
			}

			function syncPassword() {
				var pass = generatePassword( 24 );
				document.getElementById( 'agent_password_display' ).value = pass;
				document.getElementById( 'agent_password_hidden' ).value  = pass;
			}

			syncPassword();

			document.getElementById( 'agent_password_regenerate' ).addEventListener( 'click', function( e ) {
				e.preventDefault();
				syncPassword();
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Handle admin-post: create a new agent user account.
	 */
	public function handle_add_agent() {
		if ( ! current_user_can( 'create_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to create users.', 'botcreds-agent-access' ) );
		}

		check_admin_referer( 'agent_access_add_agent_nonce', 'agent_access_add_agent_nonce' );

		$add_agent_url = admin_url( 'users.php?page=agent-access-add-agent' );
		$error_key     = 'agent_access_add_agent_error_' . get_current_user_id();

		$username     = isset( $_POST['agent_username'] )     ? sanitize_user( wp_unslash( $_POST['agent_username'] ) )          : '';
		$email        = isset( $_POST['agent_email'] )        ? sanitize_email( wp_unslash( $_POST['agent_email'] ) )             : '';
		$display_name = isset( $_POST['agent_display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['agent_display_name'] ) ) : '';
		$password     = isset( $_POST['agent_password'] ) && ! empty( $_POST['agent_password'] )
			? wp_unslash( $_POST['agent_password'] )
			: wp_generate_password( 24 );

		$errs = array();

		if ( empty( $username ) ) {
			$errs[] = __( 'Username is required.', 'botcreds-agent-access' );
		}

		if ( empty( $email ) || ! is_email( $email ) ) {
			$errs[] = __( 'A valid email address is required.', 'botcreds-agent-access' );
		}

		if ( ! empty( $username ) && username_exists( $username ) ) {
			$errs[] = __( 'That username is already taken.', 'botcreds-agent-access' );
		}

		if ( ! empty( $email ) && is_email( $email ) && email_exists( $email ) ) {
			$errs[] = __( 'That email address is already registered.', 'botcreds-agent-access' );
		}

		if ( ! empty( $errs ) ) {
			set_transient( $error_key, $errs, 60 );
			wp_safe_redirect( $add_agent_url );
			exit;
		}

		$new_user_id = wp_create_user( $username, $password, $email );

		if ( is_wp_error( $new_user_id ) ) {
			set_transient( $error_key, array( $new_user_id->get_error_message() ), 60 );
			wp_safe_redirect( $add_agent_url );
			exit;
		}

		$user = get_userdata( $new_user_id );
		$user->set_role( 'agent' );

		if ( ! empty( $display_name ) ) {
			wp_update_user( array(
				'ID'           => $new_user_id,
				'display_name' => $display_name,
				'first_name'   => $display_name,
			) );
		}

		set_transient( 'agent_access_agent_created_' . get_current_user_id(), $new_user_id, 60 );

		wp_safe_redirect( get_edit_user_link( $new_user_id ) . '#agent-access' );
		exit;
	}

	/**
	 * Show an admin notice after a new agent is created successfully.
	 */
	public function maybe_show_add_agent_notice() {
		$transient_key = 'agent_access_agent_created_' . get_current_user_id();
		$new_user_id   = get_transient( $transient_key );

		if ( ! $new_user_id ) {
			return;
		}

		delete_transient( $transient_key );

		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php esc_html_e( 'Agent created! Set up their BotCreds credential in the Agent Access section below.', 'botcreds-agent-access' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Register Tools → Agent Access admin page.
	 */
	public function add_admin_menu() {
		add_management_page(
			__( 'Agent Access', 'botcreds-agent-access' ),
			__( 'Agent Access', 'botcreds-agent-access' ),
			'manage_options',
			'agent-access',
			array( $this, 'render_admin_page' )
		);
	}

	public function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'profile.php', 'user-edit.php', 'tools_page_agent-access', 'users_page_agent-access-add-agent' ), true ) ) {
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
			'ajax_url'            => admin_url( 'admin-ajax.php' ),
			'create_nonce'        => wp_create_nonce( 'agent_access_create' ),
			'revoke_nonce'        => wp_create_nonce( 'agent_access_revoke' ),
			'admin_create_nonce'  => current_user_can( 'manage_options' ) ? wp_create_nonce( 'agent_access_admin_create' ) : '',
			'admin_revoke_nonce'  => current_user_can( 'manage_options' ) ? wp_create_nonce( 'agent_access_admin_revoke' ) : '',
			'admin_update_nonce'  => current_user_can( 'manage_options' ) ? wp_create_nonce( 'agent_access_admin_nonce' ) : '',
			'confirm_msg'         => __( 'Are you sure you want to revoke the agent connection? You will need to reconfigure your agent with a new password.', 'botcreds-agent-access' ),
			'confirm_admin_msg'   => __( 'Are you sure you want to revoke this user\'s agent connection? They will need new credentials to reconnect.', 'botcreds-agent-access' ),
			'creating_text'       => __( 'Connecting…', 'botcreds-agent-access' ),
			'revoking_text'       => __( 'Revoking…', 'botcreds-agent-access' ),
			'copied_text'         => __( 'Copied!', 'botcreds-agent-access' ),
			'copy_text'           => __( 'Copy', 'botcreds-agent-access' ),
		) );
	}

	/**
	 * Handle the AJAX create request (own profile).
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

		// Save scope and rate-limit tier for this credential.
		$scope = self::collect_scope_from_post();
		$tier  = isset( $_POST['rate_limit'] ) ? sanitize_key( $_POST['rate_limit'] ) : Agent_Access_Rate_Limiter::DEFAULT_TIER; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Agent_Access_Scope::save( get_current_user_id(), $result['uuid'], $scope );
		Agent_Access_Rate_Limiter::save( get_current_user_id(), $result['uuid'], $tier );
		if ( Agent_Access_Approval_Queue::is_required() ) {
			Agent_Access_Approval_Queue::set_pending( get_current_user_id(), $result['uuid'] );
		}
		$policy = isset( $_POST['content_policy'] ) ? sanitize_key( $_POST['content_policy'] ) : Agent_Access_Content_Policy::DEFAULT_POLICY; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Agent_Access_Content_Policy::save( get_current_user_id(), $result['uuid'], $policy );

		$connection_info = $this->api->get_connection_info( $result['password'] );

		$user = wp_get_current_user();
		do_action( 'agent_access_audit', 'app_password_created', array( 'username' => $user->user_login ) );

		wp_send_json_success( $connection_info );
	}

	/**
	 * Handle the AJAX revoke request (own profile).
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
	 * Handle the admin AJAX create request (on behalf of another user).
	 */
	public function handle_admin_create_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have permission to do this.', 'botcreds-agent-access' ) );
		}

		check_ajax_referer( 'agent_access_admin_create', 'nonce' );

		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			wp_send_json_error( __( 'Invalid user.', 'botcreds-agent-access' ) );
		}

		$result = $this->api->create_password( $user_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		// Save scope and rate-limit tier for this credential.
		$scope = self::collect_scope_from_post();
		$tier  = isset( $_POST['rate_limit'] ) ? sanitize_key( $_POST['rate_limit'] ) : Agent_Access_Rate_Limiter::DEFAULT_TIER; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Agent_Access_Scope::save( $user_id, $result['uuid'], $scope );
		Agent_Access_Rate_Limiter::save( $user_id, $result['uuid'], $tier );
		if ( Agent_Access_Approval_Queue::is_required() ) {
			Agent_Access_Approval_Queue::set_pending( $user_id, $result['uuid'] );
		}
		$policy = isset( $_POST['content_policy'] ) ? sanitize_key( $_POST['content_policy'] ) : Agent_Access_Content_Policy::DEFAULT_POLICY; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Agent_Access_Content_Policy::save( $user_id, $result['uuid'], $policy );

		$connection_info = $this->api->get_connection_info( $result['password'], $user_id );

		$target_user = get_userdata( $user_id );
		do_action( 'agent_access_audit', 'app_password_created', array(
			'username'      => $target_user->user_login,
			'created_by'    => wp_get_current_user()->user_login,
			'admin_action'  => true,
		) );

		wp_send_json_success( $connection_info );
	}

	/**
	 * Handle the admin AJAX revoke request (on behalf of another user).
	 */
	public function handle_admin_revoke_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have permission to do this.', 'botcreds-agent-access' ) );
		}

		check_ajax_referer( 'agent_access_admin_revoke', 'nonce' );

		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			wp_send_json_error( __( 'Invalid user.', 'botcreds-agent-access' ) );
		}

		$result = $this->api->revoke_password( $user_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$target_user = get_userdata( $user_id );
		do_action( 'agent_access_audit', 'app_password_revoked', array(
			'username'      => $target_user->user_login,
			'revoked_by'    => wp_get_current_user()->user_login,
			'admin_action'  => true,
		) );

		wp_send_json_success( __( 'Agent connection revoked successfully.', 'botcreds-agent-access' ) );
	}

	/**
	 * Handle the admin AJAX update request — save scope/policy/rate-limit for an already-connected user.
	 */
	public function handle_admin_update_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have permission to do this.', 'botcreds-agent-access' ) );
		}

		check_ajax_referer( 'agent_access_admin_nonce', 'nonce' );

		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			wp_send_json_error( __( 'Invalid user.', 'botcreds-agent-access' ) );
		}

		$existing = $this->api->get_existing_password( $user_id );
		if ( ! $existing ) {
			wp_send_json_error( __( 'No active agent connection found for this user.', 'botcreds-agent-access' ) );
		}

		$uuid       = $existing['uuid'];
		$scope      = self::collect_scope_from_post();
		$policy     = isset( $_POST['policy'] )     ? sanitize_key( $_POST['policy'] )     : Agent_Access_Content_Policy::DEFAULT_POLICY;
		$rate_limit = isset( $_POST['rate_limit'] ) ? sanitize_key( $_POST['rate_limit'] ) : Agent_Access_Rate_Limiter::DEFAULT_TIER;

		Agent_Access_Scope::save( $user_id, $uuid, $scope );
		Agent_Access_Content_Policy::save( $user_id, $uuid, $policy );
		Agent_Access_Rate_Limiter::save( $user_id, $uuid, $rate_limit );

		wp_send_json_success( __( 'Settings saved.', 'botcreds-agent-access' ) );
	}

	/**
	 * Collect scope from the current AJAX POST request.
	 *
	 * Reads scope_read_only and scope_types[] POST fields and returns an array
	 * of post type slugs suitable for Agent_Access_Scope::save().
	 *
	 * @return array
	 */
	private static function collect_scope_from_post() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_POST['scope_read_only'] ) ) {
			return array( '__read_only__' );
		}

		$raw_types = isset( $_POST['scope_types'] ) && is_array( $_POST['scope_types'] )
			? (array) $_POST['scope_types']
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$clean = array();
		foreach ( $raw_types as $slug ) {
			$s = sanitize_key( (string) $slug );
			if ( '' !== $s ) {
				$clean[] = $s;
			}
		}

		// Default to full if nothing was selected.
		if ( empty( $clean ) ) {
			return array( '*' );
		}

		return $clean;
	}

	/**
	 * Handle admin-post: save 'require approval' setting.
	 */
	public function handle_save_approval_setting() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'botcreds-agent-access' ) );
		}

		check_admin_referer( 'agent_access_save_approval_setting', 'aa_approval_nonce' );

		$enabled = ! empty( $_POST['require_approval'] );
		Agent_Access_Approval_Queue::set_required( $enabled );

		wp_safe_redirect( add_query_arg( 'updated', '1', wp_get_referer() ) );
		exit;
	}

	/**
	 * Render the Agent Access section on the profile page.
	 *
	 * @param WP_User $user The user being edited.
	 */
	public function render_profile_section( $user ) {
		$is_own_profile = ( get_current_user_id() === $user->ID );
		$is_admin       = current_user_can( 'manage_options' );

		// Own profile: visible to anyone with edit_posts.
		// Another user's profile: admins only.
		if ( ! $is_own_profile && ! $is_admin ) {
			return;
		}

		if ( ! $is_own_profile && $is_admin ) {
			$this->render_admin_profile_section( $user );
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
				<?php esc_html_e( 'Agent Access', 'botcreds-agent-access' ); ?>
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
	 * Render the Agent Access section on another user's profile page (admin only).
	 *
	 * @param WP_User $user The user being edited.
	 */
	private function render_admin_profile_section( $user ) {
		$existing = $this->api->get_existing_password( $user->ID );
		?>
		<div id="agent-access" class="agent-access-profile-section">
			<h2 class="agent-access-title">
				<span class="agent-access-logo">&#129438;</span>
				<?php esc_html_e( 'Agent Access', 'botcreds-agent-access' ); ?>
				<span class="agent-access-admin-badge"><?php esc_html_e( 'Admin', 'botcreds-agent-access' ); ?></span>
			</h2>
			<p class="description">
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s: display name of the user being edited */
						__( 'Connect or revoke Agent Access on behalf of %s.', 'botcreds-agent-access' ),
						'<strong>' . esc_html( $user->display_name ) . '</strong>'
					)
				);
				?>
			</p>

			<?php if ( $existing ) : ?>
				<?php
				$created_date = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $existing['created'] );
				$last_used    = ! empty( $existing['last_used'] )
					? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $existing['last_used'] )
					: __( 'Never', 'botcreds-agent-access' );
				?>
				<div class="agent-access-notice-row">
					<div class="agent-access-notice-box agent-access-notice-box--green">
						<?php esc_html_e( 'Connected', 'botcreds-agent-access' ); ?>
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
				</table>
				<div class="agent-access-revoke-section">
					<button type="button"
						class="button agent-access-admin-revoke-btn"
						data-user-id="<?php echo esc_attr( $user->ID ); ?>">
						<?php esc_html_e( 'Revoke Connection', 'botcreds-agent-access' ); ?>
					</button>
					<span class="agent-access-revoke-hint">
						<?php
						printf(
							/* translators: %s: display name */
							esc_html__( 'This will disconnect %s\'s agent from their account.', 'botcreds-agent-access' ),
							esc_html( $user->display_name )
						);
						?>
					</span>
				</div>

				<?php
				$uuid           = $existing['uuid'];
				$current_scope  = Agent_Access_Scope::get( $user->ID, $uuid );
				$current_policy = Agent_Access_Content_Policy::get( $user->ID, $uuid );
				$current_rl     = Agent_Access_Rate_Limiter::get( $user->ID, $uuid );
				?>
				<?php
					$update_available_types = Agent_Access_Scope::get_available_post_types();
					$is_read_only_scope     = is_array( $current_scope ) && array( '__read_only__' ) === $current_scope;
					$is_wildcard_scope      = is_array( $current_scope ) && in_array( '*', (array) $current_scope, true );
					$checked_slugs          = is_array( $current_scope ) ? $current_scope : array( 'post', 'attachment' );
				?>
				<div class="agent-access-settings-section" style="margin-top:1.5em;">
					<h3 style="margin-bottom:0.5em;"><?php esc_html_e( 'Agent Settings', 'botcreds-agent-access' ); ?></h3>
					<table class="form-table" role="presentation" style="max-width:480px;">
						<tr>
							<th scope="row">
								<?php esc_html_e( 'Scope', 'botcreds-agent-access' ); ?>
							</th>
							<td>
								<div class="agent-access-scope-checklist agent-access-admin-update-scope"
									data-user-id="<?php echo esc_attr( $user->ID ); ?>">
									<label class="agent-access-scope-read-only-label" style="display:block;margin-bottom:6px;font-weight:600;">
										<input type="checkbox"
											name="scope_read_only"
											class="agent-access-scope-read-only"
											value="1"
											<?php checked( $is_read_only_scope ); ?>>
										<?php esc_html_e( 'Read only (no writes)', 'botcreds-agent-access' ); ?>
									</label>
									<hr style="margin:4px 0 6px;">
									<?php foreach ( $update_available_types as $type ) : ?>
										<label style="display:block;margin-bottom:3px;">
											<input type="checkbox"
												name="scope_types[]"
												class="agent-access-scope-type"
												value="<?php echo esc_attr( $type['slug'] ); ?>"
												<?php checked( $is_wildcard_scope || in_array( $type['slug'], $checked_slugs, true ) ); ?>
												<?php echo $is_read_only_scope ? 'disabled' : ''; ?>>
											<?php echo esc_html( $type['label'] ); ?>
											<span class="description" style="font-size:11px;color:#777;">(<?php echo esc_html( $type['slug'] ); ?>)</span>
										</label>
									<?php endforeach; ?>
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="agent-access-admin-update-rate-limit-<?php echo esc_attr( $user->ID ); ?>"><?php esc_html_e( 'Rate limit', 'botcreds-agent-access' ); ?></label>
							</th>
							<td>
								<select id="agent-access-admin-update-rate-limit-<?php echo esc_attr( $user->ID ); ?>"
									class="agent-access-admin-update-rate-limit"
									data-user-id="<?php echo esc_attr( $user->ID ); ?>">
									<?php foreach ( Agent_Access_Rate_Limiter::get_tiers() as $key => $tier ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>"
											<?php selected( $key, $current_rl ); ?>
											title="<?php echo esc_attr( $tier['description'] ); ?>">
											<?php echo esc_html( $tier['label'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="agent-access-admin-update-content-policy-<?php echo esc_attr( $user->ID ); ?>"><?php esc_html_e( 'Content policy', 'botcreds-agent-access' ); ?></label>
							</th>
							<td>
								<select id="agent-access-admin-update-content-policy-<?php echo esc_attr( $user->ID ); ?>"
									class="agent-access-admin-update-content-policy"
									data-user-id="<?php echo esc_attr( $user->ID ); ?>">
									<?php foreach ( Agent_Access_Content_Policy::get_policies() as $key => $pol ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>"
											<?php selected( $key, $current_policy ); ?>
											title="<?php echo esc_attr( $pol['description'] ); ?>">
											<?php echo esc_html( $pol['label'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"></th>
							<td>
								<button type="button"
									class="button button-primary agent-access-admin-update-btn"
									data-user-id="<?php echo esc_attr( $user->ID ); ?>">
									<?php esc_html_e( 'Save settings', 'botcreds-agent-access' ); ?>
								</button>
								<span class="agent-access-admin-update-status" data-user-id="<?php echo esc_attr( $user->ID ); ?>" style="margin-left:0.75em;display:none;"></span>
							</td>
						</tr>
					</table>
				</div>

				<?php $this->render_profile_content_table( $user->ID ); ?>
			<?php else : ?>
				<?php $admin_create_types = Agent_Access_Scope::get_available_post_types(); ?>
				<?php $admin_create_defaults = array( 'post', 'attachment' ); ?>
				<div id="agent-access-admin-card" data-user-id="<?php echo esc_attr( $user->ID ); ?>">
					<table class="form-table" role="presentation" style="max-width:480px;">
						<tr>
							<th scope="row">
								<?php esc_html_e( 'Scope', 'botcreds-agent-access' ); ?>
							</th>
							<td>
								<div class="agent-access-scope-checklist">
									<label class="agent-access-scope-read-only-label" style="display:block;margin-bottom:6px;font-weight:600;">
										<input type="checkbox"
											name="scope_read_only"
											class="agent-access-scope-read-only"
											value="1">
										<?php esc_html_e( 'Read only (no writes)', 'botcreds-agent-access' ); ?>
									</label>
									<hr style="margin:4px 0 6px;">
									<?php foreach ( $admin_create_types as $type ) : ?>
										<label style="display:block;margin-bottom:3px;">
											<input type="checkbox"
												name="scope_types[]"
												class="agent-access-scope-type"
												value="<?php echo esc_attr( $type['slug'] ); ?>"
												<?php checked( in_array( $type['slug'], $admin_create_defaults, true ) ); ?>>
											<?php echo esc_html( $type['label'] ); ?>
											<span class="description" style="font-size:11px;color:#777;">(<?php echo esc_html( $type['slug'] ); ?>)</span>
										</label>
									<?php endforeach; ?>
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="agent-access-admin-rate-limit-<?php echo esc_attr( $user->ID ); ?>"><?php esc_html_e( 'Rate limit', 'botcreds-agent-access' ); ?></label>
							</th>
							<td>
								<select id="agent-access-admin-rate-limit-<?php echo esc_attr( $user->ID ); ?>" name="rate_limit">
									<?php foreach ( Agent_Access_Rate_Limiter::get_tiers() as $key => $tier ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>"
											<?php selected( $key, Agent_Access_Rate_Limiter::DEFAULT_TIER ); ?>
											title="<?php echo esc_attr( $tier['description'] ); ?>">
											<?php echo esc_html( $tier['label'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="agent-access-admin-content-policy-<?php echo esc_attr( $user->ID ); ?>"><?php esc_html_e( 'Content policy', 'botcreds-agent-access' ); ?></label>
							</th>
							<td>
								<select id="agent-access-admin-content-policy-<?php echo esc_attr( $user->ID ); ?>" name="content_policy">
									<?php foreach ( Agent_Access_Content_Policy::get_policies() as $key => $pol ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>"
											<?php selected( $key, Agent_Access_Content_Policy::DEFAULT_POLICY ); ?>
											title="<?php echo esc_attr( $pol['description'] ); ?>">
											<?php echo esc_html( $pol['label'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"></th>
							<td>
								<button type="button"
									class="button button-primary agent-access-admin-create-btn"
									data-user-id="<?php echo esc_attr( $user->ID ); ?>"
									data-display-name="<?php echo esc_attr( $user->display_name ); ?>">
									<?php
									printf(
										/* translators: %s: display name */
										esc_html__( 'Generate credentials for %s', 'botcreds-agent-access' ),
										esc_html( $user->display_name )
									);
									?>
								</button>
								<p class="description" style="margin-top:0.5em;">
									<?php esc_html_e( 'Generates a scoped Application Password to share with the user or their agent.', 'botcreds-agent-access' ); ?>
								</p>
							</td>
						</tr>
					</table>
				</div>
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

		<div class="agent-access-revoke-section">
			<button type="button" class="button agent-access-revoke-btn" id="agent-access-revoke-btn">
				<?php esc_html_e( 'Revoke Connection', 'botcreds-agent-access' ); ?>
			</button>
			<span class="agent-access-revoke-hint">
				<?php esc_html_e( 'This will disconnect your agent from your account.', 'botcreds-agent-access' ); ?>
			</span>
		</div>

		<?php $this->render_profile_content_table( get_current_user_id() ); ?>
		<?php
	}

	/**
	 * Render the agent-created content table shown at the bottom of a user profile.
	 *
	 * @param int $user_id The user whose content to display.
	 */
	private function render_profile_content_table( $user_id ) {
		$result = Agent_Access_Tracker::get_user_content_paged( $user_id, 10, 0 );
		$items  = $result['items'];
		$total  = $result['total'];

		if ( empty( $items ) ) {
			return;
		}

		$content_tab_url = add_query_arg(
			array( 'tab' => 'content', 'user_id' => $user_id ),
			menu_page_url( 'agent-access', false )
		);
		?>
		<div class="agent-access-profile-content">
			<h3 class="agent-access-content-heading">
				<?php esc_html_e( 'Agent-Created Content', 'botcreds-agent-access' ); ?>
				<?php if ( $total > 10 ) : ?>
					<a href="<?php echo esc_url( $content_tab_url ); ?>" class="agent-access-view-all">
						<?php
						printf(
							/* translators: %d: total content count */
							esc_html__( 'View all %d in Content Log →', 'botcreds-agent-access' ),
							(int) $total
						);
						?>
					</a>
				<?php elseif ( current_user_can( 'manage_options' ) ) : ?>
					<a href="<?php echo esc_url( $content_tab_url ); ?>" class="agent-access-view-all">
						<?php esc_html_e( 'View in Content Log →', 'botcreds-agent-access' ); ?>
					</a>
				<?php endif; ?>
			</h3>
			<table class="widefat striped agent-access-recent-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'botcreds-agent-access' ); ?></th>
						<th><?php esc_html_e( 'Type', 'botcreds-agent-access' ); ?></th>
						<th><?php esc_html_e( 'Date', 'botcreds-agent-access' ); ?></th>
						<th><?php esc_html_e( 'Status', 'botcreds-agent-access' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $items as $post ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ?: '' ); ?>">
									<?php echo esc_html( $post->post_title ?: __( '(no title)', 'botcreds-agent-access' ) ); ?>
								</a>
							</td>
							<td><?php echo esc_html( ucfirst( $post->post_type ) ); ?></td>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $post->post_date ) ) ); ?></td>
							<td><span class="agent-access-badge agent-access-badge--<?php echo esc_attr( $post->post_status ); ?>"><?php echo esc_html( ucfirst( $post->post_status ) ); ?></span></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render the "disconnected" state with create button.
	 */
	private function render_disconnected_state() {
		$available_types = Agent_Access_Scope::get_available_post_types();
		// Default checked: post + attachment (posts_media equivalent)
		$default_slugs   = array( 'post', 'attachment' );
		?>
		<div id="agent-access-card">
			<table class="form-table" role="presentation" style="max-width:480px;">
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Scope', 'botcreds-agent-access' ); ?>
					</th>
					<td>
						<div class="agent-access-scope-checklist">
							<label class="agent-access-scope-read-only-label" style="display:block;margin-bottom:6px;font-weight:600;">
								<input type="checkbox"
									name="scope_read_only"
									class="agent-access-scope-read-only"
									value="1">
								<?php esc_html_e( 'Read only (no writes)', 'botcreds-agent-access' ); ?>
							</label>
							<hr style="margin:4px 0 6px;">
							<?php foreach ( $available_types as $type ) : ?>
								<label style="display:block;margin-bottom:3px;">
									<input type="checkbox"
										name="scope_types[]"
										class="agent-access-scope-type"
										value="<?php echo esc_attr( $type['slug'] ); ?>"
										<?php checked( in_array( $type['slug'], $default_slugs, true ) ); ?>>
									<?php echo esc_html( $type['label'] ); ?>
									<span class="description" style="font-size:11px;color:#777;">(<?php echo esc_html( $type['slug'] ); ?>)</span>
								</label>
							<?php endforeach; ?>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="agent-access-rate-limit"><?php esc_html_e( 'Rate limit', 'botcreds-agent-access' ); ?></label>
					</th>
					<td>
						<select id="agent-access-rate-limit">
							<?php foreach ( Agent_Access_Rate_Limiter::get_tiers() as $key => $tier ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"
									<?php selected( $key, Agent_Access_Rate_Limiter::DEFAULT_TIER ); ?>
									title="<?php echo esc_attr( $tier['description'] ); ?>">
									<?php echo esc_html( $tier['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="agent-access-content-policy"><?php esc_html_e( 'Content policy', 'botcreds-agent-access' ); ?></label>
					</th>
					<td>
						<select id="agent-access-content-policy">
							<?php foreach ( Agent_Access_Content_Policy::get_policies() as $key => $pol ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"
									<?php selected( $key, Agent_Access_Content_Policy::DEFAULT_POLICY ); ?>
									title="<?php echo esc_attr( $pol['description'] ); ?>">
									<?php echo esc_html( $pol['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"></th>
					<td>
						<button type="button" class="button button-primary agent-access-create-btn" id="agent-access-create-btn">
							<?php esc_html_e( 'Connect Agent', 'botcreds-agent-access' ); ?>
						</button>
						<p class="description" style="margin-top:0.5em;">
							<?php esc_html_e( "Generates a scoped Application Password for Agent Access. You'll be given credentials to paste into your agent config.", 'botcreds-agent-access' ); ?>
						</p>
					</td>
				</tr>
			</table>
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
				<?php esc_html_e( 'Agent Access', 'botcreds-agent-access' ); ?>
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
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'content', menu_page_url( 'agent-access', false ) ) ); ?>"
				   class="nav-tab <?php echo 'content' === $current_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Content', 'botcreds-agent-access' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'revisions', menu_page_url( 'agent-access', false ) ) ); ?>"
				   class="nav-tab <?php echo 'revisions' === $current_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( '↩ Revisions', 'botcreds-agent-access' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'experimental', menu_page_url( 'agent-access', false ) ) ); ?>"
				   class="nav-tab <?php echo 'experimental' === $current_tab ? 'nav-tab-active' : ''; ?>" style="color:#856404;">
					<?php esc_html_e( '⚗ Experimental', 'botcreds-agent-access' ); ?>
				</a>
			</nav>

			<?php if ( 'activity' === $current_tab ) : ?>
				<?php $this->render_activity_log_tab(); ?>
			<?php elseif ( 'content' === $current_tab ) : ?>
				<?php $this->render_content_tab(); ?>
			<?php elseif ( 'revisions' === $current_tab ) : ?>
				<?php $this->render_revisions_tab(); ?>
			<?php elseif ( 'experimental' === $current_tab ) : ?>
				<?php $this->render_experimental_tab(); ?>
			<?php else : ?>
				<?php $this->render_connections_tab(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the Connections tab (formerly the full admin page).
	 */
	/**
	 * Render the ⚗ Experimental tab — Pro Auth settings and token management.
	 */
	private function render_revisions_tab() {
		$snapshots = Agent_Access_Rollback::get_recent_snapshots( 50 );
		?>
		<h2><?php esc_html_e( 'Agent Write History', 'botcreds-agent-access' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Before-state snapshots captured when agents modified or deleted content. Click Restore to roll back a post to its pre-agent state.', 'botcreds-agent-access' ); ?>
		</p>

		<?php if ( empty( $snapshots ) ) : ?>
			<p><em><?php esc_html_e( 'No snapshots yet. Snapshots are captured automatically when agents modify existing posts or attachments.', 'botcreds-agent-access' ); ?></em></p>
		<?php else : ?>
			<table class="widefat striped" style="margin-top:1em;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Post', 'botcreds-agent-access' ); ?></th>
						<th><?php esc_html_e( 'Type', 'botcreds-agent-access' ); ?></th>
						<th><?php esc_html_e( 'Agent (user)', 'botcreds-agent-access' ); ?></th>
						<th><?php esc_html_e( 'Action', 'botcreds-agent-access' ); ?></th>
						<th><?php esc_html_e( 'Captured', 'botcreds-agent-access' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $snapshots as $snap ) : ?>
						<?php
						$state     = json_decode( $snap->before_state, true );
						$post      = get_post( (int) $snap->post_id );
						$kind      = $state['kind'] ?? 'posts';
						$title     = $state['post_title'] ?? __( '(untitled)', 'botcreds-agent-access' );
						$edit_url  = $post ? get_edit_post_link( $post->ID ) : '';
						$nonce_key = 'agent_access_restore_' . (int) $snap->id;
						?>
						<tr>
							<td>
								<?php if ( $edit_url ) : ?>
									<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $title ); ?></a>
								<?php else : ?>
									<em><?php echo esc_html( $title ); ?> <?php esc_html_e( '(deleted)', 'botcreds-agent-access' ); ?></em>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( 'media' === $kind ? __( 'Media', 'botcreds-agent-access' ) : __( 'Post', 'botcreds-agent-access' ) ); ?></td>
							<td><?php echo esc_html( $snap->user_login ?: __( '—', 'botcreds-agent-access' ) ); ?></td>
							<td><?php echo esc_html( $snap->method ); ?></td>
							<td><?php echo esc_html( get_date_from_gmt( $snap->captured_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
							<td>
								<?php if ( $post ) : ?>
									<button type="button"
										class="button button-small agent-access-restore-btn"
										data-snapshot-id="<?php echo esc_attr( $snap->id ); ?>"
										data-nonce="<?php echo esc_attr( wp_create_nonce( $nonce_key ) ); ?>"
										data-post-title="<?php echo esc_attr( $title ); ?>">
										<?php esc_html_e( '↩ Restore', 'botcreds-agent-access' ); ?>
									</button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description" style="margin-top:0.5em;">
				<?php
				printf(
					/* translators: %d: retention days */
					esc_html__( 'Snapshots are retained for %d days and pruned automatically.', 'botcreds-agent-access' ),
					(int) Agent_Access_Rollback::RETAIN_DAYS
				);
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	private function render_experimental_tab() {
		$is_enabled = Agent_Access_Pro_Auth::is_enabled();
		$tokens     = $is_enabled ? Agent_Access_Pro_Auth::get_tokens() : array();
		$users      = get_users( array(
			'role__in' => array( 'administrator', 'editor', 'author', 'agent' ),
			'number'   => -1,
		) );
		?>

		<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<?php if ( ! empty( $_GET['updated'] ) ) : ?>
			<div class="notice notice-success inline" style="margin:1em 0;"><p><?php esc_html_e( 'Settings saved.', 'botcreds-agent-access' ); ?></p></div>
		<?php endif; ?>

		<div class="notice notice-warning inline" style="margin:1.5em 0 1em;">
			<p>
				<strong><?php esc_html_e( 'Experimental', 'botcreds-agent-access' ); ?></strong>
				&mdash; <?php esc_html_e( 'These features are in active development. Token format and API may change between versions.', 'botcreds-agent-access' ); ?>
			</p>
		</div>

		<h2><?php esc_html_e( 'Pro Auth', 'botcreds-agent-access' ); ?></h2>
		<p>
			<?php esc_html_e( 'Token-based agent authentication with optional Ed25519 request signing. Agents receive a persistent', 'botcreds-agent-access' ); ?>
			<code>agt_</code>
			<?php esc_html_e( 'token via a one-time setup link — credential never enters LLM context.', 'botcreds-agent-access' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'agent_access_save_pro_auth', 'aa_pro_auth_nonce' ); ?>
			<input type="hidden" name="action" value="agent_access_save_pro_auth">
			<label>
				<input type="checkbox" name="pro_auth_enabled" value="1" <?php checked( $is_enabled ); ?>>
				<?php esc_html_e( 'Enable Pro Auth', 'botcreds-agent-access' ); ?>
			</label>
			<button type="submit" class="button" style="margin-left:1em;"><?php esc_html_e( 'Save', 'botcreds-agent-access' ); ?></button>
		</form>

		<?php if ( $is_enabled ) : ?>

			<hr style="margin:2em 0;">
			<h3><?php esc_html_e( 'Generate Setup Code', 'botcreds-agent-access' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Creates a one-time setup code (15-min TTL). Paste the JSON payload to your agent — it POSTs the code to the activate endpoint and receives a persistent token.', 'botcreds-agent-access' ); ?>
			</p>

			<div style="display:flex;gap:0.5em;align-items:center;flex-wrap:wrap;margin-top:0.75em;">
				<select id="aa-setup-user">
					<?php foreach ( $users as $u ) : ?>
						<option value="<?php echo esc_attr( $u->ID ); ?>">
							<?php echo esc_html( $u->display_name . ' (' . $u->user_login . ')' ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<input type="text" id="aa-setup-label"
					placeholder="<?php esc_attr_e( 'Label (e.g. Bob / OpenClaw)', 'botcreds-agent-access' ); ?>"
					style="width:200px;">
				<button type="button" class="button button-primary" id="aa-generate-setup-btn">
					<?php esc_html_e( 'Generate', 'botcreds-agent-access' ); ?>
				</button>
			</div>

			<div id="aa-setup-result" style="display:none;margin-top:1.5em;">
				<p><strong><?php esc_html_e( 'Paste this to your agent:', 'botcreds-agent-access' ); ?></strong></p>
				<textarea id="aa-setup-payload" rows="10"
					style="width:100%;font-family:monospace;font-size:12px;background:#f6f7f7;"
					readonly></textarea>
				<div style="margin-top:0.5em;display:flex;gap:0.5em;align-items:center;">
					<button type="button" class="button" id="aa-copy-setup-btn">
						<?php esc_html_e( 'Copy', 'botcreds-agent-access' ); ?>
					</button>
					<span style="color:#d63638;font-size:12px;">
						⚠ <?php esc_html_e( 'Expires in 15 minutes. One-time use only.', 'botcreds-agent-access' ); ?>
					</span>
				</div>
			</div>

			<hr style="margin:2em 0;">
			<h3><?php esc_html_e( 'Active Pro Tokens', 'botcreds-agent-access' ); ?></h3>

			<?php if ( empty( $tokens ) ) : ?>
				<p><em><?php esc_html_e( 'No active pro tokens yet.', 'botcreds-agent-access' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped" style="margin-top:0.5em;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Label', 'botcreds-agent-access' ); ?></th>
							<th><?php esc_html_e( 'User', 'botcreds-agent-access' ); ?></th>
							<th><?php esc_html_e( 'Mode', 'botcreds-agent-access' ); ?></th>
							<th><?php esc_html_e( 'Origin IP', 'botcreds-agent-access' ); ?></th>
							<th><?php esc_html_e( 'Created', 'botcreds-agent-access' ); ?></th>
							<th><?php esc_html_e( 'Last Used', 'botcreds-agent-access' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $tokens as $token ) : ?>
						<tr>
							<td><?php echo esc_html( $token->label ); ?></td>
							<td><?php echo esc_html( $token->display_name ?: $token->user_login ?: '#' . $token->user_id ); ?></td>
							<td>
								<?php if ( $token->public_key ) : ?>
									<span class="agent-access-badge agent-access-badge--green"
										title="<?php esc_attr_e( 'Ed25519 request signing enabled', 'botcreds-agent-access' ); ?>">
										Pro
									</span>
								<?php else : ?>
									<span class="agent-access-badge">Standard</span>
								<?php endif; ?>
							</td>
							<td><code><?php echo esc_html( $token->origin_ip ?: '—' ); ?></code></td>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $token->created_at ) ) ); ?></td>
							<td>
								<?php
								echo $token->last_used_at
									? esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $token->last_used_at ) ) )
									: '—';
								?>
							</td>
							<td>
								<button type="button"
									class="button aa-revoke-pro-token-btn"
									data-token-id="<?php echo esc_attr( $token->id ); ?>"
									data-label="<?php echo esc_attr( $token->label ); ?>">
									<?php esc_html_e( 'Revoke', 'botcreds-agent-access' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

		<?php endif; ?>

		<script>
		if ( typeof jQuery !== 'undefined' ) {
			jQuery( function( $ ) {
				$( '#aa-generate-setup-btn' ).on( 'click', function() {
					var $btn = $( this );
					$btn.prop( 'disabled', true ).text( 'Generating…' );
					$.post( agentAccess.ajax_url, {
						action:  'agent_access_generate_setup',
						nonce:   agentAccess.generate_setup_nonce,
						user_id: $( '#aa-setup-user' ).val(),
						label:   $( '#aa-setup-label' ).val()
					}, function( res ) {
						$btn.prop( 'disabled', false ).text( 'Generate' );
						if ( res.success ) {
							var d = res.data;
							var payload = JSON.stringify( {
								action:       'activate_agent_access',
								activate_url: d.activate_url,
								setup_code:   d.setup_code,
								expires_in:   d.expires_in,
								instructions: 'POST { setup_code, public_key (optional Ed25519 base64 32-byte), agent_id (optional) } to activate_url. Returns a persistent agt_ token.'
							}, null, 2 );
							$( '#aa-setup-payload' ).val( payload );
							$( '#aa-setup-result' ).show();
						} else {
							alert( res.data || 'Error generating setup code.' );
						}
					} );
				} );

				$( '#aa-copy-setup-btn' ).on( 'click', function() {
					$( '#aa-setup-payload' ).select();
					document.execCommand( 'copy' );
					var $self = $( this );
					$self.text( 'Copied!' );
					setTimeout( function() { $self.text( 'Copy' ); }, 2000 );
				} );

				$( document ).on( 'click', '.aa-revoke-pro-token-btn', function() {
					var $btn  = $( this );
					var label = $btn.data( 'label' );
					if ( ! confirm( 'Revoke token "' + label + '"? The agent will lose access immediately.' ) ) {
						return;
					}
					$btn.prop( 'disabled', true ).text( 'Revoking…' );
					$.post( agentAccess.ajax_url, {
						action:   'agent_access_revoke_pro_token',
						nonce:    agentAccess.revoke_pro_token_nonce,
						token_id: $btn.data( 'token-id' )
					}, function( res ) {
						if ( res.success ) {
							$btn.closest( 'tr' ).fadeOut( 300, function() { $( this ).remove(); } );
						} else {
							$btn.prop( 'disabled', false ).text( 'Revoke' );
							alert( res.data || 'Error revoking token.' );
						}
					} );
				} );
			} );
		}
		</script>
		<?php
	}

	/**
	 * AJAX: generate a setup code for Pro Auth.
	 */
	public function handle_generate_setup_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'botcreds-agent-access' ) );
		}
		check_ajax_referer( 'agent_access_generate_setup', 'nonce' );

		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		$label   = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';

		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			wp_send_json_error( __( 'Invalid user.', 'botcreds-agent-access' ) );
		}

		$result = Agent_Access_Pro_Auth::generate_setup_link( $user_id, $label );

		wp_send_json_success( array(
			'activate_url' => $result['url'],
			'setup_code'   => $result['code'],
			'expires_in'   => $result['expires_in'],
		) );
	}

	/**
	 * AJAX: revoke a Pro Auth token by DB id.
	 */
	public function handle_revoke_pro_token_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'botcreds-agent-access' ) );
		}
		check_ajax_referer( 'agent_access_revoke_pro_token', 'nonce' );

		$token_id = isset( $_POST['token_id'] ) ? (int) $_POST['token_id'] : 0;
		if ( ! $token_id ) {
			wp_send_json_error( __( 'Invalid token.', 'botcreds-agent-access' ) );
		}

		if ( ! Agent_Access_Pro_Auth::revoke_token( $token_id ) ) {
			wp_send_json_error( __( 'Failed to revoke token.', 'botcreds-agent-access' ) );
		}

		wp_send_json_success( __( 'Token revoked.', 'botcreds-agent-access' ) );
	}

	/**
	 * admin-post: save Pro Auth enabled/disabled setting.
	 */
	public function handle_save_pro_auth() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'botcreds-agent-access' ) );
		}
		check_admin_referer( 'agent_access_save_pro_auth', 'aa_pro_auth_nonce' );

		$enabled = ! empty( $_POST['pro_auth_enabled'] ) && '1' === $_POST['pro_auth_enabled'];
		update_option( Agent_Access_Pro_Auth::OPTION_ENABLED, $enabled );

		if ( $enabled ) {
			Agent_Access_Pro_Auth::install_table();
		}

		wp_redirect(
			add_query_arg(
				array(
					'page'    => 'agent-access',
					'tab'     => 'experimental',
					'updated' => '1',
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Render the Connections tab (formerly the full admin page).
	 */
	private function render_connections_tab() {
		$users_with_passwords = $this->get_connected_users();
		$approval_required    = Agent_Access_Approval_Queue::is_required();
		?>

		<div style="margin:1em 0 1.5em;padding:0.75em 1em;background:#f6f7f7;border:1px solid #ddd;border-radius:3px;display:flex;align-items:center;gap:1em;flex-wrap:wrap;">
			<strong><?php esc_html_e( 'Approval queue:', 'botcreds-agent-access' ); ?></strong>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
				<?php wp_nonce_field( 'agent_access_save_approval_setting', 'aa_approval_nonce' ); ?>
				<input type="hidden" name="action" value="agent_access_save_approval_setting">
				<label style="display:flex;align-items:center;gap:0.4em;cursor:pointer;">
					<input type="checkbox" name="require_approval" value="1" <?php checked( $approval_required ); ?> onchange="this.form.submit()">
					<?php esc_html_e( 'Require admin approval for new credentials', 'botcreds-agent-access' ); ?>
				</label>
			</form>
			<span class="description">
				<?php esc_html_e( 'When enabled, newly created credentials start in ⏳ Pending status. Write access is suspended until an admin approves each credential.', 'botcreds-agent-access' ); ?>
			</span>
		</div>

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
						<th><?php esc_html_e( 'Scope', 'botcreds-agent-access' ); ?></th>
						<th><?php esc_html_e( 'Rate Limit', 'botcreds-agent-access' ); ?></th>
						<th><?php esc_html_e( 'Approval', 'botcreds-agent-access' ); ?></th>
						<th><?php esc_html_e( 'Content Policy', 'botcreds-agent-access' ); ?></th>
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
								<?php $tooltip = $this->get_role_tooltip( $entry['role_slug'] ); ?>
								<span class="agent-access-badge agent-access-badge--<?php echo esc_attr( $entry['role_slug'] ); ?><?php echo $tooltip ? ' agent-access-badge--has-tooltip' : ''; ?>"
									<?php if ( $tooltip ) : ?>title="<?php echo esc_attr( $tooltip ); ?>"<?php endif; ?>
								>
									<?php echo esc_html( $entry['role_name'] ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $entry['scope_label'] ); ?></td>
							<td><?php echo esc_html( $entry['rl_label'] ); ?></td>
							<td>
								<?php echo esc_html( Agent_Access_Approval_Queue::get_label( $entry['approval'] ) ); ?>
								<?php if ( 'pending' === $entry['approval'] ) : ?>
									<br>
									<button type="button"
										class="button button-small agent-access-approve-btn"
										data-user-id="<?php echo esc_attr( $entry['user']->ID ); ?>"
										data-uuid="<?php echo esc_attr( $entry['uuid'] ); ?>"
										data-nonce="<?php echo esc_attr( wp_create_nonce( 'agent_access_approve_' . $entry['uuid'] ) ); ?>"
										style="margin:0.25em 0.25em 0 0;">
										<?php esc_html_e( 'Approve', 'botcreds-agent-access' ); ?>
									</button>
									<button type="button"
										class="button button-small agent-access-reject-btn"
										data-user-id="<?php echo esc_attr( $entry['user']->ID ); ?>"
										data-uuid="<?php echo esc_attr( $entry['uuid'] ); ?>"
										data-nonce="<?php echo esc_attr( wp_create_nonce( 'agent_access_reject_' . $entry['uuid'] ) ); ?>"
										style="color:#b32d2e;margin:0.25em 0 0;">
										<?php esc_html_e( 'Reject', 'botcreds-agent-access' ); ?>
									</button>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $entry['policy_label'] ); ?></td>
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
	 * Render the Content tab — all agent-created content across all users.
	 */
	private function render_content_tab() {
		$per_page = 50;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$paged            = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
		$filter_user      = isset( $_GET['user_id'] ) ? (int) $_GET['user_id'] : 0;
		$filter_post_type = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';
		// phpcs:enable

		$filter_args = array(
			'user_id'   => $filter_user,
			'post_type' => $filter_post_type,
		);

		$total   = Agent_Access_Tracker::count_all_content( $filter_args );
		$entries = Agent_Access_Tracker::get_all_content( array_merge( $filter_args, array(
			'limit'  => $per_page,
			'offset' => ( $paged - 1 ) * $per_page,
		) ) );

		$base_url          = add_query_arg( 'tab', 'content', menu_page_url( 'agent-access', false ) );
		$content_post_types = Agent_Access_Tracker::get_content_post_types();

		// Only roles that can hold agent connections — avoids loading subscriber/follower tails.
		$filter_users = get_users( array(
			'role__in' => array( 'administrator', 'editor', 'author', 'agent' ),
			'number'   => -1,
		) );
		?>

		<form method="get" style="margin: 1em 0;">
			<input type="hidden" name="page" value="agent-access">
			<input type="hidden" name="tab" value="content">

			<select name="user_id">
				<option value=""><?php esc_html_e( 'All users', 'botcreds-agent-access' ); ?></option>
				<?php foreach ( $filter_users as $u ) : ?>
					<option value="<?php echo esc_attr( $u->ID ); ?>" <?php selected( $filter_user, $u->ID ); ?>>
						<?php echo esc_html( $u->display_name . ' (' . $u->user_login . ')' ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<select name="post_type">
				<option value=""><?php esc_html_e( 'All types', 'botcreds-agent-access' ); ?></option>
				<?php foreach ( $content_post_types as $pt ) : ?>
					<?php
					$pt_obj   = get_post_type_object( $pt );
					$pt_label = $pt_obj ? $pt_obj->labels->name : ucfirst( str_replace( '_', ' ', $pt ) );
					?>
					<option value="<?php echo esc_attr( $pt ); ?>" <?php selected( $filter_post_type, $pt ); ?>>
						<?php echo esc_html( $pt_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<?php submit_button( __( 'Filter', 'botcreds-agent-access' ), 'secondary', '', false ); ?>
			<?php if ( $filter_user || $filter_post_type ) : ?>
				<a href="<?php echo esc_url( $base_url ); ?>" class="button"><?php esc_html_e( 'Clear', 'botcreds-agent-access' ); ?></a>
			<?php endif; ?>
		</form>

		<p>
			<?php
			printf(
				/* translators: %d: total content count */
				esc_html__( '%d total items created via Agent Access.', 'botcreds-agent-access' ),
				(int) $total
			);
			?>
		</p>

		<?php if ( empty( $entries ) ) : ?>
			<p><em><?php esc_html_e( 'No agent-created content found.', 'botcreds-agent-access' ); ?></em></p>
		<?php else : ?>
			<table class="widefat striped" style="font-size:13px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'botcreds-agent-access' ); ?></th>
						<th><?php esc_html_e( 'Type', 'botcreds-agent-access' ); ?></th>
						<th><?php esc_html_e( 'Author', 'botcreds-agent-access' ); ?></th>
						<th><?php esc_html_e( 'Status', 'botcreds-agent-access' ); ?></th>
						<th><?php esc_html_e( 'Date', 'botcreds-agent-access' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $entries as $row ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( $row->ID ) ?: '' ); ?>">
									<?php echo esc_html( $row->post_title ?: __( '(no title)', 'botcreds-agent-access' ) ); ?>
								</a>
							</td>
							<td><?php echo esc_html( ucfirst( $row->post_type ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( add_query_arg( array( 'user_id' => $row->user_id, 'tab' => 'content' ), menu_page_url( 'agent-access', false ) ) ); ?>">
									<?php echo esc_html( $row->display_name ?: $row->user_login ); ?>
								</a>
							</td>
							<td>
								<span class="agent-access-badge agent-access-badge--<?php echo esc_attr( $row->post_status ); ?>">
									<?php echo esc_html( ucfirst( $row->post_status ) ); ?>
								</span>
							</td>
							<td style="white-space:nowrap;"><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $row->post_date ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			$total_pages = (int) ceil( $total / $per_page );
			if ( $total_pages > 1 ) {
				$pag_args = array( 'tab' => 'content' );
				if ( $filter_user ) $pag_args['user_id'] = $filter_user;
				if ( $filter_post_type ) $pag_args['post_type'] = $filter_post_type;

				$pagination_links = paginate_links( array(
					'base'      => add_query_arg( array_merge( $pag_args, array( 'paged' => '%#%' ) ), menu_page_url( 'agent-access', false ) ),
					'format'    => '',
					'current'   => $paged,
					'total'     => $total_pages,
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
					'type'      => 'plain',
				) );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<div class="tablenav"><div class="tablenav-pages">' . $pagination_links . '</div></div>';
			}
			?>
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
	 * Return a human-readable tooltip describing what a role can do.
	 *
	 * Returns hardcoded strings for the five WordPress core roles. For custom
	 * roles it inspects the role's capabilities and builds a short summary.
	 *
	 * @param string $role_slug Role slug.
	 * @return string Tooltip text, or empty string if none can be derived.
	 */
	private function get_role_tooltip( $role_slug ) {
		$core = array(
			'administrator' => __( 'Full site access: publish and manage all content, users, plugins, and settings.', 'botcreds-agent-access' ),
			'editor'        => __( 'Can publish and manage all posts, pages, categories, and comments.', 'botcreds-agent-access' ),
			'author'        => __( 'Can publish and manage their own posts only.', 'botcreds-agent-access' ),
			'contributor'   => __( 'Can write posts but cannot publish — requires editor approval.', 'botcreds-agent-access' ),
			'subscriber'    => __( 'Read-only access. Cannot create or edit content.', 'botcreds-agent-access' ),
			'agent'         => __( 'AI agent account. Can publish posts and pages, upload media, and manage categories. No access to site settings or user management.', 'botcreds-agent-access' ),
		);

		if ( isset( $core[ $role_slug ] ) ) {
			return $core[ $role_slug ];
		}

		// Custom role: derive from capabilities.
		$role = get_role( $role_slug );
		if ( ! $role ) {
			return '';
		}

		$caps  = $role->capabilities;
		$parts = array();

		if ( ! empty( $caps['manage_options'] ) ) {
			$parts[] = __( 'manage site settings', 'botcreds-agent-access' );
		}
		if ( ! empty( $caps['edit_others_posts'] ) ) {
			$parts[] = __( 'edit all posts', 'botcreds-agent-access' );
		} elseif ( ! empty( $caps['publish_posts'] ) ) {
			$parts[] = __( 'publish own posts', 'botcreds-agent-access' );
		} elseif ( ! empty( $caps['edit_posts'] ) ) {
			$parts[] = __( 'draft own posts', 'botcreds-agent-access' );
		}
		if ( ! empty( $caps['upload_files'] ) ) {
			$parts[] = __( 'upload media', 'botcreds-agent-access' );
		}
		if ( ! empty( $caps['manage_categories'] ) ) {
			$parts[] = __( 'manage categories', 'botcreds-agent-access' );
		}

		if ( empty( $parts ) ) {
			return __( 'Custom role — limited or read-only access.', 'botcreds-agent-access' );
		}

		return sprintf(
			/* translators: %s: comma-separated list of capabilities */
			__( 'Can: %s.', 'botcreds-agent-access' ),
			implode( ', ', $parts )
		);
	}

	/**
	 * Whether the Connections list may be truncated.
	 *
	 * Now always false: we query by application-password meta directly so
	 * site size no longer affects which users appear.
	 *
	 * @return bool
	 */
	private function connections_list_is_truncated() {
		return false;
	}

	/**
	 * Get users who have an Agent Access Application Password.
	 *
	 * Queries by user meta so only users who actually have application passwords
	 * are loaded — works correctly regardless of total user count.
	 *
	 * @return array
	 */
	private function get_connected_users() {
		$results = array();

		// Only roles that can realistically hold agent connections.
		// This sidesteps the old 200-user cap and avoids scanning subscriber/follower tails.
		$users = get_users( array(
			'role__in' => array( 'administrator', 'editor', 'author', 'agent' ),
			'number'   => -1,
		) );

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
					'user'        => $user,
					'role_slug'   => $role_slug,
					'role_name'   => $role_name,
					'scope_label' => Agent_Access_Scope::get_label( Agent_Access_Scope::get( $user->ID, $item['uuid'] ) ),
					'rl_label'    => Agent_Access_Rate_Limiter::get_label( Agent_Access_Rate_Limiter::get( $user->ID, $item['uuid'] ) ),
					'approval'    => Agent_Access_Approval_Queue::get_status( $user->ID, $item['uuid'] ),
					'policy_label' => Agent_Access_Content_Policy::get_label( Agent_Access_Content_Policy::get( $user->ID, $item['uuid'] ) ),
					'uuid'        => $item['uuid'],
					'created'     => $created,
					'last_used'   => $last_used,
					'stats'       => Agent_Access_Tracker::get_stats( $user->ID ),
				);

				break; // Only one Agent Access password per user
			}
		}

		return $results;
	}
}

