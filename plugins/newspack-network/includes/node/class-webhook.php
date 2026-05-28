<?php
/**
 * Newspack Network Node webhook handler.
 *
 * @package Newspack
 */

namespace Newspack_Network\Node;

use WP_CLI;
use WP_CLI\Utils as WP_CLI_Utils;

use Newspack_Network\Accepted_Actions;
use Newspack_Network\Crypto;
use Newspack\Data_Events\Webhooks as Newspack_Webhooks;

use const Newspack_Network\constants\{
	WEBHOOK_RESPONSE_ERRORS
};

/**
 * Class that register the webhook endpoint that will send events to the Hub
 */
class Webhook {

	/**
	 * The endpoint ID.
	 *
	 * @var string
	 */
	const ENDPOINT_ID = 'newspack-network-node';

	/**
	 * The endpoint URL suffix.
	 *
	 * @var string
	 */
	const ENDPOINT_SUFFIX = 'wp-json/newspack-network/v1/webhook';

	/**
	 * Runs the initialization.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_endpoint' ] );
		add_filter( 'newspack_webhooks_request_body', [ __CLASS__, 'filter_webhook_body' ], 10, 2 );
		add_action( 'newspack_webhooks_process_request_errors', [ __CLASS__, 'processs_request_errors' ], 10, 2 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'newspack-network process-webhooks', [ __CLASS__, 'cli_process_webhooks' ] );
		}
	}

	/**
	 * Registers the endpoint.
	 *
	 * @return void
	 */
	public static function register_endpoint() {
		if ( ! class_exists( 'Newspack\Data_Events\Webhooks' ) || ! method_exists( 'Newspack\Data_Events\Webhooks', 'register_system_endpoint' ) ) {
			return;
		}
		Newspack_Webhooks::register_system_endpoint( self::ENDPOINT_ID, self::get_url(), array_keys( Accepted_Actions::ACTIONS ) );
	}

	/**
	 * Gets the endpoint URL
	 *
	 * @return string
	 */
	public static function get_url() {
		return trailingslashit( Settings::get_hub_url() ) . self::ENDPOINT_SUFFIX;
	}

	/**
	 * Filters the event body and signs the data
	 *
	 * @param array  $body The Webhook Event body.
	 * @param string $endpoint_id The endpoint ID.
	 * @return array
	 */
	public static function filter_webhook_body( $body, $endpoint_id ) {
		if ( self::ENDPOINT_ID !== $endpoint_id ) {
			return $body;
		}

		$data  = wp_json_encode( $body['data'] );
		$nonce = Crypto::generate_nonce();
		$data  = self::sign( $data, $nonce );
		if ( is_wp_error( $data ) ) {
			$data = $body['data'];
		}
		$body['nonce'] = $nonce;
		$body['data']  = $data;
		$body['site']  = get_bloginfo( 'url' );

		return $body;
	}

	/**
	 * Signs the data
	 *
	 * @param string $data The data to be signed.
	 * @param string $nonce The nonce to encrypt the message with, generated with Crypto::generate_nonce().
	 * @param string $secret_key The secret key to use for signing. Default is to use the stored secret key.
	 * @return WP_Error|string The signed data or error.
	 */
	public static function sign( $data, $nonce, $secret_key = null ) {
		if ( ! $secret_key ) {
			$secret_key = Settings::get_secret_key();
		}
		if ( empty( $secret_key ) ) {
			return new \WP_Error( 'newspack-network-node-webhook-signing-error', __( 'Missing Secret key', 'newspack-network-node' ) );
		}

		return Crypto::encrypt_message( $data, $secret_key, $nonce );
	}

	/**
	 * Attempt to fix request error before being processed again
	 * 
	 * @see Newspack\Data_Events\Webhooks::process_request()
	 *
	 * @param int   $request_id   Webhook request id.
	 * @param array $errors       Request processing errors.
	 * @return void
	 */
	public static function processs_request_errors( $request_id, $errors ) {
		$last_error = end( $errors );

		// Ensure we're handling only specific errors.
		if ( ! isset( WEBHOOK_RESPONSE_ERRORS[ $last_error ] ) ) {
			return;
		}
		if ( 'INVALID_SIGNATURE' === $last_error ) {
			$body = json_decode( get_post_meta( $request_id, 'body', true ), true );
			[ 'data' => $data ] = $body;
			
			$nonce = Crypto::generate_nonce();
			$data = static::sign( wp_json_encode( $data ), $nonce );

			// This likely means the configuration that caused $last_error still exists.
			if ( is_wp_error( $data ) ) {
				return;
			}

			$body['data'] = $data;
			$body['nonce'] = $nonce;
			update_post_meta( $request_id, 'body', addslashes( wp_json_encode( $body ) ) );
		}
	}

	/**
	 * Process network requests
	 *
	 * @param array $args Indexed array of args.
	 * @param array $assoc_args Associative array of args.
	 * @return void
	 *
	 * ## OPTIONS
	 *
	 * [--per-page=<number>]
	 * : How many requests to process.
	 * ---
	 * default: -1
	 * ---
	 *
	 * [--status=<string>]
	 * : Filters requests to process by status.
	 * ---
	 * default: 'pending'
	 * ---
	 *
	 * [--dry-run]
	 * : Run the command in dry run mode. No requests (with status killed) will be processed.
	 *
	 * [--yes]
	 * : Run the command without confirmations, use with caution.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-network process-webhooks
	 *     wp newspack-network process-webhooks --per-page=200
	 *     wp newspack-network process-webhooks --per-page=200 --status='killed' --dry-run
	 *     wp newspack-network process-webhooks --per-page=200 --status='killed' --dry-run --yes
	 *
	 * @when after_wp_load
	 */
	public function cli_process_webhooks( array $args, array $assoc_args ): void {
		$per_page = (int) ( $assoc_args['per-page'] ?? -1 );
		$dry_run = isset( $assoc_args['dry-run'] );
		$status = $assoc_args['status'] ?? 'pending';

		/**
		 * Get requests by 'status'
		 */
		$requests = array_filter(
			Newspack_Webhooks::get_endpoint_requests( static::ENDPOINT_ID, $per_page ),
			fn ( $r ) => $r['status'] === $status
		);
		usort(
			$requests,
			// OrderBy: id, Order: ASC.
			fn ( $a, $b ) => $a['id'] <=> $b['id']
		);

		// No requests, bail.
		if ( empty( $requests ) ) {
			WP_CLI::error( "No '{$status}' requests exist, exiting!" );
		}

		$request_ids = array_column( $requests, 'id' );

		$counts = [
			'total'   => count( $requests ),
			'failed'  => 0,
			'success' => 0,
		];

		$errors = [];

		if ( $dry_run ) {
			WP_CLI::log( '==== DRY - RUN ====' );
		} else {
			WP_CLI::confirm( "Confirm processing of {$counts['total']} requests?", $assoc_args );
		}

		$progress = WP_CLI_Utils\make_progress_bar( 'Processing requests', $counts['total'] );

		foreach ( $request_ids as $request_id ) {
			if ( ! $dry_run ) {
				Newspack_Webhooks::process_request( $request_id );
				if ( 'finished' !== get_post_meta( $request_id, 'status', true ) ) {
					$errors[ $request_id ] = '<unknown_error>';
					// Get last stored error.
					$request_errors = get_post_meta( $request_id, 'errors', true );
					if ( (array) $request_errors === $request_errors ) {
						$errors[ $request_id ] = end( $request_errors );
					}
					++$counts['failed'];
					continue;
				}
				// Cleanup successfully processed requests.
				$deleted = wp_delete_post( $request_id, true );
				if ( false === $deleted || null === $deleted ) {
					WP_CLI::warning( "There was an error deleting {$request_id}!" );
				}
			}
			++$counts['success'];
			$progress->tick();
		}

		$progress->finish();
		WP_CLI::log( '' );

		/**
		 * If all requests have been processed, output success and return.
		 */
		if ( $counts['success'] === $counts['total'] ) {
			WP_CLI::success( "Successfully processed {$counts['success']}/{$counts['total']} '{$status}' requests.\n" );
			return;
		}

		// Last 100 errors.
		$errors = wp_json_encode( array_slice( $errors, -100, 100, true ), JSON_PRETTY_PRINT );
		/**
		 * All request processing failed.
		 */
		if ( $counts['failed'] === $counts['total'] ) {
			WP_CLI::error( "0/{$counts['total']} '{$status}' request were processed. \nErrors: {$errors}\n" );
			return;
		}

		WP_CLI::warning( "Not all '{$status}' requests have been processed:" );
		WP_CLI::log( "- Success: {$counts['success']}/{$counts['total']}" );
		WP_CLI::log( "- Failed: {$counts['failed']}/{$counts['total']}" );
		WP_CLI::log( "- Errors: {$errors}\n" );
	}
}
