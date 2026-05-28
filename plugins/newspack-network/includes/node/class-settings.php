<?php
/**
 * Newspack Node Main Settings page.
 *
 * @package Newspack
 */

namespace Newspack_Network\Node;

use Newspack_Network\Admin;
use Newspack_Network\Crypto;
use Newspack_Network\Hub\Nodes as Hub_Nodes;
use WP_Error;

use const Newspack_Network\constants\WEBHOOK_RESPONSE_ERRORS;

/**
 * Class to handle Node settings page
 */
class Settings {

	/**
	 * The setting section constant
	 */
	const SETTINGS_SECTION = 'newspack_node_settings';

	/**
	 * The admin page slug
	 */
	const PAGE_SLUG = 'newspack-network-node'; // Same as the main admin page slug, it will become the first menu item.

	/**
	 * The action name for the link-site functionality nonce.
	 */
	const CONNECTION_NONCE_ACTION = 'newspack-network-connect-node';

	/**
	 * The connection error message.
	 *
	 * @var string
	 */
	public static $connection_error;

	/**
	 * Initialize this class and register hooks
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
		add_filter( 'allowed_options', [ __CLASS__, 'allowed_options' ] );
		add_action( 'admin_init', [ __CLASS__, 'process_connection_form' ] );
	}

	/**
	 * Get the Hub URL setting
	 *
	 * @return ?string
	 */
	public static function get_hub_url() {
		return get_option( 'newspack_node_hub_url' );
	}

	/**
	 * Get the Secret key setting
	 *
	 * @return ?string
	 */
	public static function get_secret_key() {
		return get_option( 'newspack_node_secret_key' );
	}

	/**
	 * Adds the submenu page
	 *
	 * @return void
	 */
	public static function add_menu() {
		if ( \Newspack_Network\Site_Role::is_node() || self::is_updating_from_url() || self::is_processing_connection_form() ) {
			Admin::add_submenu_page( __( 'Node Settings', 'newspack-network' ), self::PAGE_SLUG, [ __CLASS__, 'render' ] );
		}
	}

	/**
	 * Adds the options page to the allowed list of options
	 *
	 * @param array $allowed_options The allowed options.
	 * @return array
	 */
	public static function allowed_options( $allowed_options ) {
		$allowed_options[ self::SETTINGS_SECTION ] = [
			'newspack_node_hub_url',
			'newspack_node_secret_key',
		];
		return $allowed_options;
	}

	/**
	 * Register the settings
	 *
	 * @return void
	 */
	public static function register_settings() {

		add_settings_section(
			self::SETTINGS_SECTION,
			esc_html__( 'Newspack Network Node Settings', 'newspack-network' ),
			[ __CLASS__, 'section_callback' ],
			self::PAGE_SLUG
		);

		$settings = [
			[
				'key'      => 'newspack_node_hub_url',
				'label'    => esc_html__( 'Hub URL', 'newspack-network' ),
				'callback' => [ __CLASS__, 'hub_url_callback' ],
				'args'     => [
					'sanitize_callback' => [ __CLASS__, 'sanitize_hub_url' ],
				],
			],
			[
				'key'      => 'newspack_node_secret',
				'label'    => esc_html__( 'Secret key', 'newspack-network' ),
				'callback' => [ __CLASS__, 'secret_key_callback' ],
				'args'     => [
					'sanitize_callback' => [ __CLASS__, 'sanitize_secret_key' ],
				],
			],
		];
		foreach ( $settings as $setting ) {
			add_settings_field(
				$setting['key'],
				$setting['label'],
				$setting['callback'],
				self::PAGE_SLUG,
				self::SETTINGS_SECTION
			);
			register_setting(
				self::PAGE_SLUG,
				$setting['key'],
				$setting['args'] ?? []
			);
		}
	}

	/**
	 * The Settings page callback
	 *
	 * @return void
	 */
	public static function section_callback() {
		// Nothing here for now.
	}

	/**
	 * The hub_url setting callback
	 *
	 * @return void
	 */
	public static function hub_url_callback() {
		$content = get_option( 'newspack_node_hub_url' );
		printf(
			'<input type="text" name="%1$s" value="%2$s">',
			'newspack_node_hub_url',
			esc_html( $content )
		);
	}

	/**
	 * The secret_key setting callback
	 *
	 * @return void
	 */
	public static function secret_key_callback() {
		$content = get_option( 'newspack_node_secret_key' );
		printf(
			'<input type="text" name="%1$s" value="%2$s">',
			'newspack_node_secret_key',
			esc_html( $content )
		);
	}

	/**
	 * Validates and sanitizes the hub_url setting
	 *
	 * @param string $value The value to sanitize.
	 * @return string|false
	 */
	public static function sanitize_hub_url( $value ) {
		$value = trim( $value );
		if ( ! empty( $value ) && ! preg_match( '#^https?://#', $value ) ) {
			$value = 'https://' . $value;
		}

		if ( ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
			add_settings_error(
				'newspack_node_hub_url',
				'newspack_node_hub_url',
				__( 'The URL is not valid.', 'newspack-network' )
			);
			return false;
		}

		return $value;
	}

	/**
	 * Tests if the string is a valid secret key
	 *
	 * @param string $value The value to sanitize.
	 * @return bool|string
	 */
	public static function sanitize_secret_key( $value ) {
		$signed = Webhook::sign( 'test', $value );
		if ( is_wp_error( $signed ) ) {
			add_settings_error(
				'newspack_node_secret_key',
				'newspack_node_secret_key',
				__( 'Invalid Secret key:', 'newspack-network' ) . ' ' . $signed->get_error_message()
			);
			return false;
		}
		return $value;
	}

	/**
	 * Get referrer.
	 */
	private static function get_referrer() {
		return isset( $_SERVER['HTTP_REFERER'] ) ? \esc_url_raw( \wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
	}

	/**
	 * Render linking receiving interface.
	 */
	public static function linking_interface_notice() {
		$referrer = self::get_referrer();

		if ( ! $referrer ) {
			return;
		}

		$existing_hub_url    = self::get_hub_url();
		$existing_secret_key = self::get_secret_key();
		$form_action         = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );
		$connect_nonce       = filter_input( INPUT_GET, 'connect_nonce', FILTER_SANITIZE_SPECIAL_CHARS );

		?>
		<div id="message" class="notice">
			<form method="POST" action="<?php echo esc_url( $form_action ); ?>">
				<input type="hidden" name="newspack_node_hub_url" value="<?php echo esc_attr( $referrer ); ?>">
				<input type="hidden" name="connection_nonce" value="<?php echo esc_attr( $connect_nonce ); ?>">
				<?php wp_nonce_field( self::CONNECTION_NONCE_ACTION ); ?>
				<h2>
					<?php esc_html_e( 'Link this site to the Hub', 'newspack-network' ); ?>
				</h2>
				<p>
				<?php
				printf(
					/* translators: %s is the Hub URL */
					esc_html__( 'Are you sure you want to connect this site as Node to the Hub at %s?', 'newspack-network' ),
					esc_html( $referrer )
				);
				?>
				</p>
				<?php if ( $existing_hub_url && $existing_secret_key ) : ?>
					<p>
					<?php
					printf(
						/* translators: %s is the Hub URL */
						esc_html__( 'WARNING: This will reset your current connection to %s.', 'newspack-network' ),
						esc_html( $existing_hub_url )
					);
					?>
				</p>
				<?php endif; ?>
				<p class='submit'>
					<input name='submit' type='submit' id='submit' class='button-primary' value='<?php _e( 'Connect!' ); ?>' />
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the settings page
	 *
	 * @return void
	 */
	public static function render() {
		if ( self::is_updating_from_url() ) {
			self::linking_interface_notice();
			return;
		}
		?>
		<div class='wrap'>
			<?php settings_errors(); ?>
			<form method='post' action='options.php'>
			<?php
				do_settings_sections( self::PAGE_SLUG );
				settings_fields( self::SETTINGS_SECTION );
			?>
				<p class='submit'>
					<input name='submit' type='submit' id='submit' class='button-primary' value='<?php _e( 'Save Changes' ); ?>' />
				</p>
			</form>
		</div>
		<?php
		if ( \Newspack_Network\Site_Role::is_node() ) {
			?>
			<hr />
			<?php
			self::render_debug_tools();
		}
	}

	/**
	 * Renders the debug tools.
	 *
	 * @return void
	 */
	private static function render_debug_tools() {
		$icon          = '❌';
		$error_message = Pulling::get_last_error_message();
		if ( empty( $error_message ) ) {
			$icon          = '✅';
			$error_message = __( 'No recent errors.', 'newspack-network' );
		}
		?>
		<h2><?php esc_html_e( 'Debug Tools', 'newspack-network' ); ?></h2>
		<table class="form-table">
			<tbody>
				<tr>
					<th scope="row"><?php _e( 'Last Processed Item ID', 'newspack-network' ); ?></th>
					<td><code><?php echo esc_html( Pulling::get_last_processed_id() ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php _e( 'Last Error From Server', 'newspack-network' ); ?></th>
					<td><?php echo esc_html( $icon ); ?> <?php echo esc_html( $error_message ); ?></td>
				</tr>
			</tbody>
		</table>
		<form method="post">
			<?php wp_nonce_field( Pulling::MANUAL_PULL_ACTION_NAME ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( Pulling::MANUAL_PULL_ACTION_NAME ); ?>">
			<button class="button"><?php esc_html_e( 'Synchronize latest data', 'newspack-network' ); ?></button>
		</form>

		<?php

		self::render_webhooks_requests();
	}

	/**
	 * Renders the table with the scheduled webhooks requests.
	 *
	 * @return void
	 */
	private static function render_webhooks_requests() {
		$secret_key = self::get_secret_key();
		if ( ! $secret_key ) {
			// Node is not configured yet.
			return;
		}

		$requests = \Newspack\Data_Events\Webhooks::get_endpoint_requests( Webhook::ENDPOINT_ID );

		?>
		<h3>
			<?php esc_html_e( 'Scheduled Events', 'newspack-network' ); ?>
		</h3>
		<p>
			<?php esc_html_e( 'The following events are scheduled to be sent or have recently been sent to the Hub.', 'newspack-network' ); ?>
		</p>
		<table class="wp-list-table widefat fixed striped table-view-list">
			<thead>
				<tr>
					<th scope="col" id="date" class="manage-column column-date">Date</th>
					<th scope="col" id="action_name" class="manage-column column-action_name">Action name</th>
					<th scope="col" id="data" class="manage-column column-data">Data</th>
					<th scope="col" id="data" class="manage-column column-status">Status</th>
					<th scope="col" id="data" class="manage-column column-status">Errors</th>
				</tr>
			</thead>

			<?php
			foreach ( $requests as $request ) :
				$status_label = __( 'Pending', 'newspack-network' );
				$icon         = '🕒';
				if ( 'finished' === $request['status'] ) {
					$status_label = __( 'Sent', 'newspack-network' );
					$icon         = '✅';
				}
				$r             = json_decode( $request['body'], true );
				$data          = Crypto::decrypt_message( $r['data'], $secret_key, $r['nonce'] );
				$date          = gmdate( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $r['timestamp'] );
				$scheduled_for = gmdate( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $request['scheduled'] );
				?>

				<tr>
					<td><?php echo esc_html( $date ); ?> (#<?php echo esc_html( $r['request_id'] ); ?>)</td>
					<td><?php echo esc_html( $r['action'] ); ?></td>
					<?php if ( false === $data ) : ?>
						<td>
							🚫 <strong><?php _e( 'Decryption Error!', 'newspack-network' ); ?></strong><br>
							<?php if ( 'finished' !== $request['status'] ) : ?>
									<?php _e( 'Data is unencrypted and cannot be processed.', 'newspack-network' ); ?>
								<?php endif; ?>
								<br><br>
							<code><?php echo esc_html( wp_json_encode( $r['data'] ) ); ?></code>
						</td>
					<?php else : ?>
					<td><code><?php echo esc_html( $data ); ?></code></td>
					<?php endif; ?>
					<td>
						<?php echo esc_html( $icon . ' ' . $status_label ); ?>.
						<?php
							echo esc_html(
								sprintf(
								/* translators: %s: scheduled date */
									__( 'Scheduled for %s', 'newspack-network' ),
									$scheduled_for
								)
							);
						?>
					</td>
					<td>
						<?php if ( ! empty( $request['errors'] ) ) : ?>
							<?php
								echo esc_html(
									sprintf(
										/* translators: %s is the number of errors */
										_n(
											'There was %s failed attempt to send this request',
											'There were %s failed attempts to send this request',
											count( $request['errors'] ),
											'newspack-network'
										),
										count( $request['errors'] )
									)
								);
								echo '<ul>';
							foreach ( $request['errors'] as $error ) {
								echo '<li><code>';
								echo esc_html( WEBHOOK_RESPONSE_ERRORS[ $error ] ?? $error );
								echo '</code></li>';
							}
								echo '</ul>';
							?>
						<?php endif; ?>
					</td>
				</tr>

			<?php endforeach; ?>
		</table>

		<?php
	}

	/**
	 * Is updating the Node settings from URL?
	 */
	public static function is_updating_from_url() {
		$action = filter_input( INPUT_GET, 'action', FILTER_SANITIZE_SPECIAL_CHARS );
		return $action === Admin::LINK_ACTION_NAME;
	}

	/**
	 * Checks if we are currently processing the connection form
	 */
	public static function is_processing_connection_form() {
		return ! empty( $_POST['connection_nonce'] ) && ! empty( $_POST['newspack_node_hub_url'] ); // phpcs:ignore
	}

	/**
	 * Process the connection form
	 */
	public static function process_connection_form() {
		if ( empty( $_POST['connection_nonce'] ) || empty( $_POST['newspack_node_hub_url'] ) ) {
			return;
		}

		if ( ! check_admin_referer( self::CONNECTION_NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$hub_url = sanitize_text_field( $_POST['newspack_node_hub_url'] );
		$nonce = sanitize_text_field( $_POST['connection_nonce'] );

		$secret_key = self::request_secret_key( $hub_url, $nonce );

		if ( is_wp_error( $secret_key ) ) {
			self::$connection_error = $secret_key->get_error_message();
			add_action( 'admin_notices', [ __CLASS__, 'notice_connection_error' ] );
			return;
		}

		\Newspack_Network\Site_Role::set_as_node();
		update_option( 'newspack_node_hub_url', $hub_url );
		update_option( 'newspack_node_secret_key', $secret_key );

		add_action( 'admin_notices', [ __CLASS__, 'notice_connection_success' ] );
	}

	/**
	 * Requests the secret key from the Hub
	 *
	 * @param string $hub_url The Hub URL.
	 * @param string $nonce The connection nonce.
	 * @return string|\WP_Error
	 */
	private static function request_secret_key( $hub_url, $nonce ) {
		$url      = trailingslashit( $hub_url ) . 'wp-json/newspack-network/v1/retrieve-key';
		$params   = [
			'site'  => get_bloginfo( 'url' ),
			'nonce' => $nonce,
		];

		$response = wp_remote_post(
			$url,
			[
				'body' => $params,
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		if ( 200 !== $response_code ) {
			$error_message = __( 'Error connecting to the Hub', 'newspack-network' );
			if ( $response_body ) {
				$error_message .= ': ' . $response_body;
			}
			return new \WP_Error( 'newspack-network-node-connect-error', $error_message );
		}

		$body = json_decode( $response_body, true );
		return $body['secret_key'] ?? new \WP_Error( 'newspack-network-node-connect-error', __( 'Invalid response from the Hub', 'newspack-network' ) );
	}

	/**
	 * Displays a success notice
	 *
	 * @return void
	 */
	public static function notice_connection_success() {
		?>
			<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Site connected!', 'newspack-network' ); ?></p>
			<div class="misc-pub-section">
				<a
					class="button"
					href="<?php echo esc_url( wp_unslash( self::get_hub_url() ) ); ?>/wp-admin/edit.php?post_type=<?php echo esc_attr( Hub_Nodes::POST_TYPE_SLUG ); ?>"
				>
					<?php esc_html_e( 'Go back to the Hub', 'newspack-network' ); ?>
				</a>
			</div>
			</div>
		<?php
	}

	/**
	 * Displays a success notice
	 *
	 * @return void
	 */
	public static function notice_connection_error() {
		?>
			<div class="notice notice-error is-dismissible">
			<p><?php esc_html_e( 'Error connecting the site', 'newspack-network' ); ?></p>
			<p><?php echo esc_html( self::$connection_error ); ?></p>
			</div>
		<?php
	}
}
