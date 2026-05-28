<?php
/**
 * Newspack Node Pulling mechanism.
 *
 * @package Newspack
 */

namespace Newspack_Network\Node;

use Newspack_Network\Accepted_Actions;
use Newspack_Network\Crypto;
use Newspack_Network\Debugger;

/**
 * Class to pull data from the Hub and process it
 */
class Pulling {

	/**
	 * The interval in seconds between pulls
	 *
	 * @var int
	 */
	const PULL_INTERVAL = 60 * 2; // 2 minutes

	/**
	 * The option name that stores the ID of the last processed event.
	 *
	 * @var string
	 */
	const LAST_PROCESSED_EVENT_OPTION_NAME = 'newspack_node_last_processed_action';

	/**
	 * The option name that stores the last error information.
	 *
	 * @var string
	 */
	const LAST_ERROR_OPTION_NAME = 'newspack_node_last_pull_error';

	/**
	 * The name of the manual pull action.
	 */
	const MANUAL_PULL_ACTION_NAME = 'newspack_node_pull';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_cron_events' ] );
		add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_schedule' ] ); // phpcs:ignore
		add_action( 'admin_init', [ __CLASS__, 'process_manual_pull' ] );
	}

	/**
	 * Adds a custom cron schedule
	 *
	 * @param array $schedules The Cron schedules.
	 * @return array
	 */
	public static function add_cron_schedule( $schedules ) {
		// translators: %d is the number of seconds.
		$display                                     = sprintf( __( 'Newspack Network Pull Interval: %d seconds', 'newspack-network' ), self::PULL_INTERVAL );
		$schedules['newspack_network_pull_interval'] = array(
			'interval' => self::PULL_INTERVAL,
			'display'  => esc_html( $display ),
		);
		return $schedules;
	}

	/**
	 * Register webhook cron events.
	 */
	public static function register_cron_events() {
		$hook = 'newspack_network_pull_from_hub';
		add_action( $hook, [ __CLASS__, 'pull' ] );
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time(), 'newspack_network_pull_interval', $hook );
		}
	}

	/**
	 * Process manual pull request.
	 */
	public static function process_manual_pull() {
		$action = self::MANUAL_PULL_ACTION_NAME;

		if ( isset( $_GET['update'] ) && $action === $_GET['update'] ) {
			$error_message = self::get_last_error_message();
			add_action(
				'admin_notices',
				function() use ( $error_message ) {
					$message = $error_message ? $error_message : esc_html__( 'Latest data pulled successfully.', 'newspack-network' );
					$status  = $error_message ? 'error' : 'updated';
					?>
					<div id="message" class="<?php echo esc_attr( $status ); ?> notice is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
					<?php
				}
			);
		}

		if ( ! isset( $_REQUEST['action'] ) || $action !== $_REQUEST['action'] ) {
			return;
		}
		if ( ! \check_admin_referer( $action ) ) {
			\wp_die( \esc_html__( 'Invalid request.', 'newspack' ) );
		}

		self::pull();

		$redirect = \add_query_arg( [ 'update' => $action ], \remove_query_arg( [ 'action', 'uid', '_wpnonce', '_wp_http_referer' ] ) );
		\wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Gets the ID of the last processed event
	 *
	 * @return int
	 */
	public static function get_last_processed_id() {
		return get_option( self::LAST_PROCESSED_EVENT_OPTION_NAME, 0 );
	}

	/**
	 * Sets the ID of the last processed event
	 *
	 * @param int $id The event ID.
	 * @return void
	 */
	public static function set_last_processed_id( $id ) {
		update_option( self::LAST_PROCESSED_EVENT_OPTION_NAME, $id );
	}

	/**
	 * Makes a request to the Hub to pull data
	 *
	 * @return array|\WP_Error
	 */
	public static function make_request() {
		$params = [
			'last_processed_id' => self::get_last_processed_id(),
			'actions'           => Accepted_Actions::ACTIONS_THAT_NODES_PULL,
			'site'              => get_bloginfo( 'url' ),
			'signed_response'   => true,
		];
		$response = \Newspack_Network\Utils\Requests::request_to_hub( 'wp-json/newspack-network/v1/pull', $params );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		if ( 200 !== $response_code ) {
			$error_message = __( 'Error pulling data from the Hub', 'newspack-network-node' );
			if ( $response_body ) {
				$error_message .= ': ' . $response_body;
			}
			return new \WP_Error( 'newspack-network-node-pulling-error', $error_message );
		}
		return $response_body;
	}

	/**
	 * Handles an error response from the Hub
	 *
	 * @param \WP_Error $error The error object.
	 *
	 * @return void
	 */
	private static function handle_error( $error ) {
		Debugger::log( 'Error pulling data' );
		Debugger::log( $error->get_error_message() );
		update_option( self::LAST_ERROR_OPTION_NAME, $error->get_error_message() );
	}

	/**
	 * Retrieve the last error message.
	 */
	public static function get_last_error_message() {
		return get_option( self::LAST_ERROR_OPTION_NAME, '' );
	}

	/**
	 * Process pulled data.
	 *
	 * @param array $events The events to process.
	 */
	public static function process_pulled_data( $events ) {
		// Track the highest ID processed in this batch so the stored cursor only ever moves
		// forward. AEAD on the pull response prevents forgery, but a previously-captured
		// envelope can be replayed; clamping the cursor monotonically means a replay can't
		// roll it backward and force re-fetching old events.
		$last_processed_id = (int) self::get_last_processed_id();
		$highest_id        = $last_processed_id;
		foreach ( $events as $event ) {
			$action    = $event['action'] ?? false;
			$site      = $event['site'] ?? false;
			$data      = $event['data'] ?? false;
			$timestamp = $event['timestamp'] ?? false;
			$id        = $event['id'] ?? false;

			if ( ! $action || ! $id || ! $data || ! $timestamp ) {
				continue;
			}

			// Skip events the cursor has already passed.
			if ( (int) $id <= $last_processed_id ) {
				continue;
			}

			$incoming_event_class = 'Newspack_Network\\Incoming_Events\\' . Accepted_Actions::ACTIONS[ $action ];

			$incoming_event = new $incoming_event_class( $site, $data, $timestamp );

			if ( ! method_exists( $incoming_event, 'process_in_node' ) ) {
				continue;
			}

			$incoming_event->process_in_node();

			if ( (int) $id > $highest_id ) {
				$highest_id = (int) $id;
			}
		}

		if ( $highest_id > $last_processed_id ) {
			self::set_last_processed_id( $highest_id );
		}
	}

	/**
	 * Pulls data from the Hub and processes it
	 *
	 * @return void
	 */
	public static function pull() {
		Debugger::log( 'Pulling data' );
		$response = self::make_request();
		Debugger::log( 'Pulled data response:' );
		Debugger::log( $response );
		if ( is_wp_error( $response ) ) {
			self::handle_error( $response );
			return;
		} else {
			update_option( self::LAST_ERROR_OPTION_NAME, '' );
		}
		$response = json_decode( $response, true );

		// We always ask the Hub for a signed response (see make_request()), so the body must
		// be the encrypted envelope: a nonce plus the ciphertext under the shared secret.
		// Anything else (a plaintext body) is either a Hub that needs updating or a tampered
		// response, and must not be processed.
		if ( ! is_array( $response ) || empty( $response['nonce'] ) || ! isset( $response['data'] ) || ! is_string( $response['data'] ) ) {
			self::handle_error( new \WP_Error( 'newspack-network-node-pull-unsigned-response', __( 'The Hub returned an unsigned pull response. Make sure the Hub is up to date.', 'newspack-network' ) ) );
			return;
		}

		$verified = Crypto::decrypt_message( $response['data'], Settings::get_secret_key(), $response['nonce'] );
		if ( false === $verified ) {
			self::handle_error( new \WP_Error( 'newspack-network-node-pull-signature', __( 'Could not verify the Hub pull response signature.', 'newspack-network' ) ) );
			return;
		}

		$response = json_decode( $verified, true );
		if ( ! is_array( $response ) || ! isset( $response['data'] ) || ! is_array( $response['data'] ) ) {
			self::handle_error( new \WP_Error( 'newspack-network-node-pull-malformed-response', __( 'The Hub returned a signed pull response with an unexpected structure.', 'newspack-network' ) ) );
			return;
		}
		self::process_pulled_data( $response['data'] );
	}
}
