<?php
/**
 * Integrity Check CLI command.
 *
 * @package Newspack
 */

namespace Newspack_Network\CLI;

use Newspack_Network\Site_Role;
use Newspack_Network\Hub\Nodes;
use Newspack_Network\Integrity_Check_Utils;
use WP_CLI;

/**
 * Integrity Check CLI command class.
 */
class Integrity_Check {

	/**
	 * Initialize this class and register hooks
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_commands' ] );
	}

	/**
	 * Register the WP-CLI commands
	 *
	 * @return void
	 */
	public static function register_commands() {
		if ( Site_Role::is_hub() && defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'newspack-network integrity-check', [ __CLASS__, 'integrity_check' ] );
		}
	}

	/**
	 * Performs an integrity check on network membership data.
	 *
	 * ## OPTIONS
	 *
	 * [--verbose]
	 * : Output verbose information during the check.
	 *
	 * [--max=<count>]
	 * : Maximum number of memberships to process (for testing only - do not use in production).
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-network integrity-check
	 *     wp newspack-network integrity-check --verbose
	 *     wp newspack-network integrity-check --max=50 --verbose
	 *
	 * @param array $args The command arguments.
	 * @param array $assoc_args The command options.
	 * @return void
	 */
	public static function integrity_check( $args, $assoc_args ) { // phpcs:ignore Generic.NamingConventions.ConstructorName.OldStyle
		$verbose = isset( $assoc_args['verbose'] ) ? true : false;
		$max_records = isset( $assoc_args['max'] ) ? intval( $assoc_args['max'] ) : null;

		if ( $max_records ) {
			WP_CLI::warning( sprintf( 'Using --max=%d for testing. Do not use --max in production as it may produce false positives.', $max_records ) );
		}

		WP_CLI::line( 'Starting integrity check for network membership data...' );
		WP_CLI::line( '' );

		// Step 1: Get hub's membership data and generate hash.
		$hub_data = Integrity_Check_Utils::get_membership_data( $max_records );
		$hub_hash = Integrity_Check_Utils::generate_hash( $hub_data );

		if ( $verbose ) {
			WP_CLI::line( sprintf( '%d memberships found on the hub', count( $hub_data ) ) );
			WP_CLI::line( sprintf( 'Hub hash: %s', $hub_hash ) );
			WP_CLI::line( '' );
		}

		// Step 2: Get node data and compare hashes.
		$nodes = Nodes::get_all_nodes();
		$discrepancies = [];

		foreach ( $nodes as $node ) {
			$node_hash = self::get_node_hash( $node, $max_records );

			if ( $verbose ) {
				WP_CLI::line( sprintf( 'Node %s hash: %s', $node->get_url(), $node_hash ) );
			}

			if ( $hub_hash !== $node_hash ) {
				$discrepancies[] = $node;
				WP_CLI::warning( sprintf( 'Hash mismatch detected for node: %s', $node->get_url() ) );
			} else {
				WP_CLI::success( sprintf( 'Hash match for node: %s', $node->get_url() ) );
			}
		}

		WP_CLI::line( '' );

		if ( empty( $discrepancies ) ) {
			WP_CLI::success( 'All nodes are in sync with the hub!' );
			return;
		}

		WP_CLI::warning( sprintf( 'Found %d nodes with discrepancies', count( $discrepancies ) ) );

		// Step 3: Collect discrepancies from all nodes into a consolidated table.
		$all_discrepancies = [];
		$node_columns = [ 'email', 'network_id', 'hub_status' ];

		foreach ( $discrepancies as $node ) {
			WP_CLI::line( sprintf( 'Analyzing discrepancies for node: %s', $node->get_url() ) );

			$node_url = $node->get_url();
			$node_name = str_replace( [ 'https://www.', 'https://', 'http://www.', 'http://' ], '', $node_url );
			$node_columns[] = $node_name;

			$specific_discrepancies = self::find_discrepancies_chunked( $hub_data, $node, $verbose, $max_records );

			// Process discrepancies for this node.
			foreach ( $specific_discrepancies as $discrepancy ) {
				$key = $discrepancy['email'] . '::' . $discrepancy['network_id'];

				if ( ! isset( $all_discrepancies[ $key ] ) ) {
					$all_discrepancies[ $key ] = [
						'email'      => $discrepancy['email'],
						'network_id' => $discrepancy['network_id'],
						'hub_status' => $discrepancy['hub_status'],
					];
				}

				$all_discrepancies[ $key ][ $node_name ] = $discrepancy['node_status'];
			}
		}

		// Display consolidated table if there are any discrepancies.
		if ( ! empty( $all_discrepancies ) ) {
			WP_CLI::line( '' );
			WP_CLI::line( sprintf( 'Found %d total discrepancies:', count( $all_discrepancies ) ) );
			WP_CLI::line( '' );

			// Prepare table data with node columns.
			$table_data = [];
			foreach ( $all_discrepancies as $discrepancy ) {
				// Fill in missing node statuses with the hub status (node is in sync with hub).
				foreach ( $node_columns as $column ) {
					if ( ! isset( $discrepancy[ $column ] ) && ! in_array( $column, [ 'email', 'network_id', 'hub_status' ] ) ) {
						$discrepancy[ $column ] = $discrepancy['hub_status'];
					}
				}
				$table_data[] = $discrepancy;
			}

			// Display as table using WP-CLI's table formatter.
			WP_CLI\Utils\format_items( 'table', $table_data, $node_columns );
		}
	}


	/**
	 * Get membership data from a node via REST API
	 *
	 * @param \Newspack_Network\Node\Node $node The node to query.
	 * @return array Array of (email, status) pairs
	 */
	private static function get_node_membership_data( $node ) {
		$endpoint = sprintf( '%s/wp-json/newspack-network/v1/integrity-check/memberships', $node->get_url() );
		$endpoint = add_query_arg( [ '_t' => time() ], $endpoint ); // Cache-busting parameter.
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get
		$response = wp_remote_get(
			$endpoint,
			[
				'headers' => $node->get_authorization_headers( 'integrity-check' ),
				'timeout' => 60, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			WP_CLI::error( sprintf( 'Failed to get membership data from node: %s', $node->get_url() ) );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		return $data['memberships'] ?? [];
	}

	/**
	 * Get hash from a node via REST API
	 *
	 * @param \Newspack_Network\Node\Node $node The node to query.
	 * @param int|null                    $max_records Maximum number of records to include in hash.
	 * @return string The hash from the node
	 */
	private static function get_node_hash( $node, $max_records = null ) {
		$endpoint = sprintf( '%s/wp-json/newspack-network/v1/integrity-check/hash', $node->get_url() );

		$query_args = [ '_t' => time() ]; // Cache-busting parameter.
		if ( $max_records ) {
			$query_args['max'] = $max_records;
		}
		$endpoint = add_query_arg( $query_args, $endpoint );

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get
		$response = wp_remote_get(
			$endpoint,
			[
				'headers' => $node->get_authorization_headers( 'integrity-check' ),
				'timeout' => 60, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			WP_CLI::error( sprintf( 'Failed to get hash from node: %s', $node->get_url() ) );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		return $data['hash'] ?? '';
	}

	/**
	 * Find specific discrepancies between hub and node data using range-based chunked approach
	 *
	 * Uses email address ranges instead of positional offsets to avoid the "shifting problem":
	 * If hub has [A,B,C,D] and node has [B,C,D,E] (A missing), positional chunks would ALL
	 * mismatch due to shifting. Range-based chunks (A-B, C-D) only show mismatch in affected range.
	 *
	 * @param array                       $hub_data Hub membership data.
	 * @param \Newspack_Network\Node\Node $node The node to compare with.
	 * @param bool                        $verbose Whether to output verbose information.
	 * @param int|null                    $max_records Maximum number of records to process (for testing).
	 * @return array Array of specific discrepancies
	 */
	private static function find_discrepancies_chunked( $hub_data, $node, $verbose = false, $max_records = null ) {
		$total_hub_memberships = count( $hub_data );
		$chunk_size = 1000; // Target chunk size in number of emails.

		// Create email ranges based on actual data distribution.
		$email_ranges = self::create_email_ranges( $hub_data, $chunk_size );
		$num_chunks = count( $email_ranges );
		$all_discrepancies = [];

		if ( $verbose ) {
			WP_CLI::line( sprintf( 'Checking %d memberships in %d range-based chunks (target size: %d)', $total_hub_memberships, $num_chunks, $chunk_size ) );
		}

		foreach ( $email_ranges as $chunk_index => $range ) {
			// Get hub chunk data for this range.
			$hub_chunk = Integrity_Check_Utils::filter_data_by_range( $hub_data, $range['start'], $range['end'] );

			// Generate hash for this chunk from hub data.
			$hub_chunk_hash = Integrity_Check_Utils::generate_hash( $hub_chunk );

			// Get corresponding chunk hash from node using range.
			$node_chunk_hash = self::get_node_range_hash( $node, $range['start'], $range['end'], $max_records );

			if ( $verbose ) {
				WP_CLI::line(
					sprintf(
						'  Chunk %d (%s to %s): Hub=%s, Node=%s (%d emails)',
						$chunk_index + 1,
						$range['start'],
						$range['end'],
						substr( $hub_chunk_hash, 0, 8 ),
						substr( $node_chunk_hash, 0, 8 ),
						count( $hub_chunk )
					)
				);
			}

			// If chunk hashes match, skip this chunk.
			if ( $hub_chunk_hash === $node_chunk_hash ) {
				if ( $verbose ) {
					WP_CLI::line( sprintf( '    ✓ Chunk %d matches', $chunk_index + 1 ) );
				}
				continue;
			}

			// Chunk hashes don't match - get detailed data for this range.
			if ( $verbose ) {
				WP_CLI::line( sprintf( '    ✗ Chunk %d mismatch - fetching detailed data', $chunk_index + 1 ) );
			}

			$node_chunk_data = self::get_node_range_data( $node, $range['start'], $range['end'], $max_records );
			$chunk_discrepancies = self::compare_chunk_data( $hub_chunk, $node_chunk_data );

			$all_discrepancies = array_merge( $all_discrepancies, $chunk_discrepancies );

			if ( $verbose ) {
				WP_CLI::line( sprintf( '    Found %d discrepancies in chunk %d', count( $chunk_discrepancies ), $chunk_index + 1 ) );
			}
		}

		return $all_discrepancies;
	}

	/**
	 * Compare two chunks of membership data and find discrepancies
	 *
	 * @param array $hub_chunk Hub chunk data.
	 * @param array $node_chunk Node chunk data.
	 * @return array Array of discrepancies
	 */
	private static function compare_chunk_data( $hub_chunk, $node_chunk ) {
		$discrepancies = [];

		// Create lookup arrays for faster comparison using (email, network_id) as key.
		$hub_lookup = [];
		foreach ( $hub_chunk as $item ) {
			$key = $item['email'] . '::' . $item['network_id'];
			$hub_lookup[ $key ] = $item;
		}

		$node_lookup = [];
		foreach ( $node_chunk as $item ) {
			$key = $item['email'] . '::' . $item['network_id'];
			$node_lookup[ $key ] = $item;
		}

		// Find discrepancies within this chunk.
		$all_keys = array_unique( array_merge( array_keys( $hub_lookup ), array_keys( $node_lookup ) ) );
		sort( $all_keys );

		foreach ( $all_keys as $key ) {
			$hub_item = $hub_lookup[ $key ] ?? null;
			$node_item = $node_lookup[ $key ] ?? null;

			$hub_status = $hub_item ? $hub_item['status'] : 'NOT_FOUND';
			$node_status = $node_item ? $node_item['status'] : 'NOT_FOUND';

			// Extract email and network_id for display.
			$parts = explode( '::', $key );
			$email = $parts[0];
			$network_id = $parts[1];

			if ( $hub_status !== $node_status ) {
				$discrepancies[] = [
					'email'       => $email,
					'network_id'  => $network_id,
					'hub_status'  => $hub_status,
					'node_status' => $node_status,
				];
			}
		}

		return $discrepancies;
	}

	/**
	 * Create fixed email ranges for consistent chunking.
	 *
	 * @param array $hub_data Hub membership data (sorted by email).
	 * @param int   $target_chunk_size Target number of emails per chunk.
	 * @return array Array of ranges with start and end email addresses
	 */
	private static function create_email_ranges( $hub_data, $target_chunk_size ) {
		if ( empty( $hub_data ) ) {
			return [];
		}

		$total_emails = count( $hub_data );
		$num_ranges = max( 1, ceil( $total_emails / $target_chunk_size ) );
		$ranges = [];

		// Sort data by email to ensure consistent ordering.
		usort(
			$hub_data,
			function( $a, $b ) {
				return strcasecmp( $a['email'], $b['email'] );
			}
		);

		// Create ranges based on actual data distribution.
		$emails_per_range = ceil( $total_emails / $num_ranges );

		for ( $i = 0; $i < $num_ranges; $i++ ) {
			$start_idx = $i * $emails_per_range;
			$end_idx = min( ( $i + 1 ) * $emails_per_range - 1, $total_emails - 1 );

			if ( $start_idx >= $total_emails ) {
				break;
			}

			// Use actual email addresses as boundaries.
			$start_email = $hub_data[ $start_idx ]['email'];

			// For the last range, use 'zzzzz' as the end to catch everything.
			if ( $i === $num_ranges - 1 ) {
				$end_email = 'zzzzz';
			} else {
				// Use the email just before the next range starts as the upper bound.
				// This ensures no gaps between ranges.
				$next_start_idx = min( ( $i + 1 ) * $emails_per_range, $total_emails - 1 );
				if ( $next_start_idx > 0 ) {
					// Get the character just before the next range's first email.
					$next_email = $hub_data[ $next_start_idx ]['email'];
					// Create an end boundary that includes everything up to (but not including) the next email.
					// We'll use the next email with a character decremented.
					$end_email = $next_email;
					// Adjust to create a proper boundary.
					$last_char = substr( $end_email, -1 );
					if ( ord( $last_char ) > ord( 'a' ) ) {
						$end_email = substr( $end_email, 0, -1 ) . chr( ord( $last_char ) - 1 ) . 'zzz';
					} else {
						// If we can't decrement, just use the email as-is.
						// The range comparison should handle this correctly.
						$end_email = $next_email;
					}
				} else {
					$end_email = 'zzzzz';
				}
			}

			$ranges[] = [
				'start' => strtolower( $start_email ),
				'end'   => strtolower( $end_email ),
			];
		}

		return $ranges;
	}


	/**
	 * Get range data from a node via REST API (common implementation)
	 *
	 * @param \Newspack_Network\Node\Node $node The node to query.
	 * @param string                      $endpoint_type The endpoint type ('range-hash' or 'range-data').
	 * @param string                      $start_email Start email for the range.
	 * @param string                      $end_email End email for the range.
	 * @param int|null                    $max_records Maximum number of records (for testing).
	 * @return mixed The response data from the node
	 */
	private static function get_node_range_request( $node, $endpoint_type, $start_email, $end_email, $max_records = null ) {
		$endpoint = sprintf( '%s/wp-json/newspack-network/v1/integrity-check/%s', $node->get_url(), $endpoint_type );

		$query_args = [
			'start' => strtolower( $start_email ),
			'end'   => strtolower( $end_email ),
			'_t'    => time(), // Cache-busting parameter.
		];

		if ( $max_records ) {
			$query_args['max'] = $max_records;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get
		$response = wp_remote_get(
			add_query_arg( $query_args, $endpoint ),
			[
				'headers' => $node->get_authorization_headers( 'integrity-check' ),
				'timeout' => 60, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$error_type = str_replace( '-', ' ', $endpoint_type );
			WP_CLI::error( sprintf( 'Failed to get %s from node: %s', $error_type, $node->get_url() ) );
		}

		$body = wp_remote_retrieve_body( $response );
		return json_decode( $body, true );
	}

	/**
	 * Get range hash from a node via REST API
	 *
	 * @param \Newspack_Network\Node\Node $node The node to query.
	 * @param string                      $start_email Start email for the range.
	 * @param string                      $end_email End email for the range.
	 * @param int|null                    $max_records Maximum number of records to include in hash (for testing).
	 * @return string The range hash from the node
	 */
	private static function get_node_range_hash( $node, $start_email, $end_email, $max_records = null ) {
		$data = self::get_node_range_request( $node, 'range-hash', $start_email, $end_email, $max_records );
		return $data['hash'] ?? '';
	}

	/**
	 * Get range data from a node via REST API
	 *
	 * @param \Newspack_Network\Node\Node $node The node to query.
	 * @param string                      $start_email Start email for the range.
	 * @param string                      $end_email End email for the range.
	 * @param int|null                    $max_records Maximum number of records to return (for testing).
	 * @return array The range data from the node
	 */
	private static function get_node_range_data( $node, $start_email, $end_email, $max_records = null ) {
		$data = self::get_node_range_request( $node, 'range-data', $start_email, $end_email, $max_records );
		return $data['memberships'] ?? [];
	}
}
