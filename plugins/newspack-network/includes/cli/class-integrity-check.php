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
	 * [--fix]
	 * : Fix discrepancies by dispatching membership update events.
	 *   Checks node sync status first; if a node has unprocessed events,
	 *   suggests running sync-all before --fix. Use --force to skip this check.
	 *   For a dry run, omit --fix: the command will report discrepancies without dispatching.
	 *
	 * [--force]
	 * : Skip the sync lag check and reconcile nodes even if they have unprocessed events or an
	 *   unconfirmed sync status.
	 *
	 * [--yes]
	 * : Answer yes to the confirmation prompt before dispatching events (for non-interactive runs).
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-network integrity-check
	 *     wp newspack-network integrity-check --verbose
	 *     wp newspack-network integrity-check --max=50 --verbose
	 *     wp newspack-network integrity-check --fix
	 *     wp newspack-network integrity-check --fix --force
	 *
	 * @param array $args The command arguments.
	 * @param array $assoc_args The command options.
	 * @return void
	 */
	public static function integrity_check( $args, $assoc_args ) { // phpcs:ignore Generic.NamingConventions.ConstructorName.OldStyle
		$verbose     = isset( $assoc_args['verbose'] ) ? true : false;
		$max_records = isset( $assoc_args['max'] ) ? intval( $assoc_args['max'] ) : null;
		$fix         = isset( $assoc_args['fix'] );
		$force       = isset( $assoc_args['force'] );

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

			if ( null === $node_hash ) {
				continue; // Warning already logged.
			}

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

		// Circular link detection: managed memberships that have a local subscription
		// are incorrectly marked as managed – they should be the source.
		$circular_links = [];

		// Check hub.
		$hub_circulars = self::get_local_circular_links();
		if ( ! empty( $hub_circulars ) ) {
			$circular_links[ get_option( 'siteurl' ) ] = $hub_circulars;
		}

		// Check nodes.
		foreach ( $nodes as $node ) {
			$node_managed = self::get_node_managed_memberships( $node );
			if ( null === $node_managed ) {
				continue;
			}
			$node_circulars = [];
			foreach ( $node_managed as $item ) {
				if ( ! empty( $item['has_subscription'] ) ) {
					$node_circulars[] = $item;
				}
			}
			if ( ! empty( $node_circulars ) ) {
				$circular_links[ $node->get_url() ] = $node_circulars;
			}
		}

		if ( ! empty( $circular_links ) ) {
			WP_CLI::line( '' );
			$total_circular = array_sum( array_map( 'count', $circular_links ) );
			WP_CLI::warning( sprintf( 'Found %d circular links (managed memberships with local subscriptions):', $total_circular ) );
			foreach ( $circular_links as $site_url => $items ) {
				WP_CLI::line( sprintf( '  %s: %d', $site_url, count( $items ) ) );
				if ( $verbose ) {
					foreach ( array_slice( $items, 0, 5 ) as $item ) {
						WP_CLI::line(
							sprintf(
								'    %s (%s) #%d – has subscription but marked as managed, pointing to %s #%d',
								$item['email'],
								$item['network_id'],
								$item['membership_id'],
								$item['remote_site_url'] ?? '?',
								$item['remote_id'] ?? 0
							) 
						);
					}
					if ( count( $items ) > 5 ) {
						WP_CLI::line( sprintf( '    ... and %d more', count( $items ) - 5 ) );
					}
				}
			}
			WP_CLI::line( '' );
			WP_CLI::line( 'These memberships are sources (they have a local subscription) but are incorrectly marked as network-managed.' );
			WP_CLI::line( 'To fix: remove _managed_by_newspack_network, _remote_id, and _remote_site_url meta from these memberships.' );
		}

		if ( $fix ) {
			WP_CLI::line( '' );

			// Query sync status and plan availability from all nodes.
			$node_plan_ids        = []; // Keyed by node URL.
			$nodes_behind         = [];
			$nodes_unknown_status = []; // Node URLs whose sync status could not be confirmed.

			foreach ( $discrepancies as $node ) {
				$sync_status = self::get_node_sync_status( $node );
				if ( null === $sync_status ) {
					// Sync status could not be confirmed. Record it so reconciliation skips this node
					// unless --force is used: without a confirmed status we cannot know whether the node
					// is caught up, and dispatching could overwrite state that is merely unsynced.
					$nodes_unknown_status[ $node->get_url() ] = true;
					continue;
				}
				$node_plan_ids[ $node->get_url() ] = $sync_status['plan_network_ids'];

				if ( ! $force ) {
					// Exclude the node's own events from the hub's latest-event id: the hub /pull endpoint
					// never returns a node its own events, so counting them here would report phantom lag
					// that `sync-all` on the node can never clear. This is per-node, so it cannot be hoisted
					// out of the loop.
					$hub_latest_id = self::get_hub_latest_event_id( $node->get_id() );
					$last_id       = $sync_status['last_processed_id'];
					if ( null !== $last_id && $last_id < $hub_latest_id ) {
						$pending = $hub_latest_id - $last_id;
						$nodes_behind[] = sprintf( '  %s: %d unprocessed events (last processed: %d, hub latest: %d)', $node->get_url(), $pending, $last_id, $hub_latest_id );
					}
				}
			}

			if ( ! $force && ! empty( $nodes_behind ) ) {
				WP_CLI::warning( 'The following nodes have unprocessed events. Discrepancies may resolve after syncing:' );
				foreach ( $nodes_behind as $line ) {
					WP_CLI::line( $line );
				}
				WP_CLI::line( '' );
				WP_CLI::line( 'Run `wp newspack-network sync-all` on these nodes first, then re-run the integrity check.' );
				WP_CLI::line( 'Use --force to skip this check and dispatch events anyway.' );
				return;
			}

			// Reconciliation dispatches membership create/update events across the network and mutates
			// membership state on nodes. It is hard to reverse, so require explicit confirmation
			// (pass --yes to skip the prompt in non-interactive runs).
			WP_CLI::confirm( 'This will dispatch membership create/update events across the network, mutating membership state on nodes. Continue?', $assoc_args );

			WP_CLI::line( 'Analyzing discrepancies for reconciliation...' );

			$total_dispatched = 0;
			$total_skipped    = 0;

			// Build hub lookup keyed by email::network_id for fast access.
			$hub_lookup = [];
			foreach ( $hub_data as $item ) {
				$key                = $item['email'] . '::' . $item['network_id'];
				$hub_lookup[ $key ] = $item;
			}

			foreach ( $discrepancies as $node ) {
				$node_url = $node->get_url();
				WP_CLI::line( '' );
				WP_CLI::line( sprintf( 'Reconciling node: %s', $node_url ) );

				// Skip nodes whose sync status could not be confirmed: we cannot know they are caught up,
				// so dispatching could overwrite unsynced state. --force overrides this guard.
				if ( isset( $nodes_unknown_status[ $node_url ] ) && ! $force ) {
					WP_CLI::warning( sprintf( 'Skipping reconciliation for %s – sync status could not be confirmed. Use --force to reconcile anyway.', $node_url ) );
					continue;
				}

				// Get managed memberships from node for timestamp comparison.
				$node_managed = self::get_node_managed_memberships( $node );

				if ( null === $node_managed ) {
					WP_CLI::warning( sprintf( 'Skipping reconciliation for %s – could not fetch managed memberships.', $node_url ) );
					continue;
				}

				// Get full node membership data.
				$node_data = self::get_node_membership_data( $node );

				if ( null === $node_data ) {
					WP_CLI::warning( sprintf( 'Skipping reconciliation for %s – could not fetch membership data.', $node_url ) );
					continue;
				}

				// Classify discrepancies.
				$available_plans = $node_plan_ids[ $node_url ] ?? [];
				$classified = self::classify_discrepancies( $hub_lookup, $node_data, $node_managed, $available_plans );

				if ( empty( $classified ) ) {
					WP_CLI::line( '  No actionable discrepancies.' );
					continue;
				}

				// Display action table.
				$action_columns = [ 'email', 'network_id', 'type', 'hub_status', 'node_status', 'action' ];
				WP_CLI\Utils\format_items( 'table', $classified, $action_columns );

				// Count actionable items for progress.
				$actionable = array_filter(
					$classified,
					function( $item ) {
						return in_array( $item['action'], [ 'push_to_node', 'push_transfer', 'pull_to_hub' ], true );
					}
				);
				$node_total = count( $actionable );

				if ( $node_total > 0 ) {
					$progress = WP_CLI\Utils\make_progress_bar( sprintf( 'Dispatching %d events', $node_total ), $node_total );
				}

				// Dispatch events.
				try {
					foreach ( $classified as $item ) {
						$is_actionable = in_array( $item['action'], [ 'push_to_node', 'push_transfer', 'pull_to_hub' ], true );

						if ( 'push_to_node' === $item['action'] ) {
							$key      = $item['email'] . '::' . $item['network_id'];
							$hub_item = $hub_lookup[ $key ] ?? null;
							if ( $hub_item ) {
								self::dispatch_to_node( $hub_item );
								$total_dispatched++;
							} else {
								$total_skipped++;
							}
						} elseif ( 'push_transfer' === $item['action'] ) {
							$hub_item = $item['hub_data'] ?? null;
							if ( $hub_item ) {
								self::dispatch_to_node( $hub_item, $item['previous_email'] );
								$total_dispatched++;
							} else {
								$total_skipped++;
							}
						} elseif ( 'pull_to_hub' === $item['action'] ) {
							$node_item_data = $item['node_data'] ?? null;
							if ( $node_item_data && ! empty( $node_item_data['membership_id'] ) ) {
								self::dispatch_to_hub( $node_item_data, $node_url );
								$total_dispatched++;
							} else {
								$total_skipped++;
							}
						} else {
							$total_skipped++;
						}

						// Tick once per actionable item, whether or not it dispatched, so the bar
						// always reaches 100% (its total is the count of actionable items).
						if ( $is_actionable && $node_total > 0 ) {
							$progress->tick();
						}
					}
				} finally {
					if ( $node_total > 0 ) {
						$progress->finish();
					}
				}
			}

			WP_CLI::line( '' );
			WP_CLI::success( sprintf( 'Reconciliation complete. Dispatched: %d, Skipped: %d.', $total_dispatched, $total_skipped ) );
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
			WP_CLI::warning( sprintf( 'Failed to get membership data from node: %s', $node->get_url() ) );
			return null;
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
			WP_CLI::warning( sprintf( 'Failed to get hash from node: %s', $node->get_url() ) );
			return null;
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

			if ( null === $node_chunk_hash ) {
				// Treat unreachable chunk as a full mismatch.
				$node_chunk_hash = '';
			}

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
			WP_CLI::warning( sprintf( 'Failed to get %s from node: %s', $error_type, $node->get_url() ) );
			return null;
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
		if ( null === $data ) {
			return null;
		}
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
		if ( null === $data ) {
			return [];
		}
		return $data['memberships'] ?? [];
	}
	/**
	 * Fetch managed membership data from a node via the /integrity-check/managed-memberships endpoint.
	 *
	 * Returns items that include post_modified for timestamp comparison.
	 *
	 * @param \Newspack_Network\Hub\Node $node The node to query.
	 * @return array|null Array keyed by email::network_id, or null on error.
	 */
	private static function get_node_managed_memberships( $node ) {
		$endpoint = sprintf( '%s/wp-json/newspack-network/v1/integrity-check/managed-memberships', $node->get_url() );
		$endpoint = add_query_arg( [ '_t' => time() ], $endpoint ); // Cache-busting parameter.

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get
		$response = wp_remote_get(
			$endpoint,
			[
				'headers' => $node->get_authorization_headers( 'integrity-check' ),
				'timeout' => 60, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
			]
		);

		if ( is_wp_error( $response ) ) {
			WP_CLI::warning( sprintf( 'Failed to fetch managed memberships from node %s: %s', $node->get_url(), $response->get_error_message() ) );
			return null;
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			WP_CLI::warning( sprintf( 'Non-200 response (%d) fetching managed memberships from node %s.', wp_remote_retrieve_response_code( $response ), $node->get_url() ) );
			return null;
		}

		$data        = json_decode( wp_remote_retrieve_body( $response ), true );
		$memberships = $data['memberships'] ?? [];

		// Build lookup keyed by email::network_id.
		$lookup = [];
		foreach ( $memberships as $item ) {
			if ( empty( $item['network_id'] ) ) {
				continue;
			}
			$key            = $item['email'] . '::' . $item['network_id'];
			$lookup[ $key ] = $item;
		}

		return $lookup;
	}

	/**
	 * Classify discrepancies between hub and node data.
	 *
	 * Compares hub and node membership data keyed by email::network_id and returns
	 * a list of discrepancy records describing the type and recommended action.
	 *
	 * Discrepancy types:
	 *   - missing_on_node: Hub has the membership but the node does not → push_to_node.
	 *   - missing_on_hub:  Node has the membership but the hub does not.
	 *       → pull_to_hub only when the node membership is backed by a subscription (authoritative source);
	 *       otherwise skip_no_subscription (may be a stale mirror; flagged for manual review).
	 *   - transfer:        Node has it under old email, hub has it under new email → push_transfer.
	 *   - status_mismatch: Both have it with different statuses.
	 *       Side with a subscription attached is authoritative.
	 *       If neither has a subscription, hub wins by default.
	 *
	 * @param array $hub_lookup          Hub memberships keyed by email::network_id.
	 * @param array $node_memberships    Raw node membership array (email, status, network_id).
	 * @param array $node_managed_lookup Node managed memberships keyed by email::network_id (includes post_modified).
	 * @param array $node_plan_ids       Plan network IDs available on the node (empty = no filtering).
	 * @return array Array of discrepancy records.
	 */
	private static function classify_discrepancies( $hub_lookup, $node_memberships, $node_managed_lookup, $node_plan_ids = [] ) {
		// Build node lookup keyed by email::network_id.
		$node_lookup = [];
		foreach ( $node_memberships as $item ) {
			$key                 = $item['email'] . '::' . $item['network_id'];
			$node_lookup[ $key ] = $item;
		}

		$all_keys      = array_unique( array_merge( array_keys( $hub_lookup ), array_keys( $node_lookup ) ) );
		$discrepancies = [];

		foreach ( $all_keys as $key ) {
			$hub_item  = $hub_lookup[ $key ] ?? null;
			$node_item = $node_lookup[ $key ] ?? null;

			$parts      = explode( '::', $key, 2 );
			$email      = $parts[0];
			$network_id = $parts[1] ?? '';

			$hub_status  = $hub_item ? $hub_item['status'] : '';
			$node_status = $node_item ? $node_item['status'] : '';

			if ( null === $hub_item ) {
				// Node has it, hub does not.
				// Only pull to the hub when the node membership is backed by a local subscription, which
				// makes the node an authoritative source. Pulling is dispatched as a hub-originated event,
				// so every other node then pulls it too; a managed mirror with no subscription may be
				// stale (e.g. cancelled elsewhere and never synced), and propagating it would resurrect the
				// membership across the whole network. Flag those for manual review instead.
				// A transfer (old email on node, new email on hub) is reclassified below regardless of this
				// action, so genuine transfers are not affected.
				$node_has_sub = ! empty( $node_item['has_subscription'] );
				$discrepancies[] = [
					'email'       => $email,
					'network_id'  => $network_id,
					'type'        => 'missing_on_hub',
					'hub_status'  => '',
					'node_status' => $node_status,
					'action'      => $node_has_sub ? 'pull_to_hub' : 'skip_no_subscription',
					'node_data'   => $node_item,
				];
				continue;
			}

			if ( null === $node_item ) {
				// Hub has it, node does not.
				// Skip if the node doesn't have the plan – can't create the membership.
				$action = 'push_to_node';
				if ( ! empty( $node_plan_ids ) && ! in_array( $network_id, $node_plan_ids, true ) ) {
					$action = 'skip_no_plan';
				}
				$discrepancies[] = [
					'email'       => $email,
					'network_id'  => $network_id,
					'type'        => 'missing_on_node',
					'hub_status'  => $hub_status,
					'node_status' => '',
					'action'      => $action,
				];
				continue;
			}

			if ( $hub_status === $node_status ) {
				// Statuses match – no discrepancy.
				continue;
			}

			// Status mismatch: the side with a subscription attached is authoritative.
			// A subscription (any status) is the source of truth for membership status.
			$hub_has_sub  = ! empty( $hub_item['has_subscription'] );
			$node_has_sub = ! empty( $node_item['has_subscription'] );

			if ( $hub_has_sub && ! $node_has_sub ) {
				$action = 'push_to_node';
			} elseif ( ! $hub_has_sub && $node_has_sub ) {
				// Node has a subscription, hub does not – node is authoritative.
				$action = 'pull_to_hub';
			} else {
				// Both or neither have a subscription – default to hub.
				$action = 'push_to_node';
			}

			$discrepancy = [
				'email'       => $email,
				'network_id'  => $network_id,
				'type'        => 'status_mismatch',
				'hub_status'  => $hub_status,
				'node_status' => $node_status,
				'action'      => $action,
			];
			if ( 'pull_to_hub' === $action ) {
				$discrepancy['node_data'] = $node_item;
			}
			$discrepancies[] = $discrepancy;
		}

		// Detect transfers: a missing_on_hub + missing_on_node pair for the same network_id
		// where the node's managed membership remote_id matches a hub membership_id.
		$missing_on_hub_indices  = [];
		$missing_on_node_indices = [];
		foreach ( $discrepancies as $idx => $d ) {
			if ( 'missing_on_hub' === $d['type'] ) {
				$missing_on_hub_indices[ $d['network_id'] ][] = $idx;
			} elseif ( 'missing_on_node' === $d['type'] ) {
				$missing_on_node_indices[ $d['network_id'] ][] = $idx;
			}
		}

		// Build a hub membership_id → key lookup for matching.
		$hub_id_to_key = [];
		foreach ( $hub_lookup as $key => $item ) {
			if ( ! empty( $item['membership_id'] ) ) {
				$hub_id_to_key[ (int) $item['membership_id'] ] = $key;
			}
		}

		$indices_to_remove = [];
		$transfers         = [];

		foreach ( $missing_on_hub_indices as $network_id => $hub_indices ) {
			if ( empty( $missing_on_node_indices[ $network_id ] ) ) {
				continue;
			}

			foreach ( $hub_indices as $hub_idx ) {
				$old_email       = $discrepancies[ $hub_idx ]['email'];
				$managed_key     = $old_email . '::' . $network_id;
				$managed_item    = $node_managed_lookup[ $managed_key ] ?? null;

				if ( ! $managed_item || empty( $managed_item['remote_id'] ) ) {
					continue;
				}

				$remote_id       = (int) $managed_item['remote_id'];
				$hub_key_for_id  = $hub_id_to_key[ $remote_id ] ?? null;

				if ( ! $hub_key_for_id ) {
					continue;
				}

				$hub_item_for_transfer = $hub_lookup[ $hub_key_for_id ] ?? null;
				if ( ! $hub_item_for_transfer ) {
					continue;
				}

				$new_email = $hub_item_for_transfer['email'];

				// Find the matching missing_on_node entry for the new email.
				foreach ( $missing_on_node_indices[ $network_id ] as $node_idx ) {
					if ( $discrepancies[ $node_idx ]['email'] === $new_email ) {
						$indices_to_remove[] = $hub_idx;
						$indices_to_remove[] = $node_idx;

						$transfers[] = [
							'email'          => $new_email,
							'network_id'     => $network_id,
							'type'           => 'transfer',
							'hub_status'     => $hub_item_for_transfer['status'],
							'node_status'    => $discrepancies[ $hub_idx ]['node_status'],
							'action'         => 'push_transfer',
							'previous_email' => $old_email,
							'hub_data'       => $hub_item_for_transfer,
						];
						break;
					}
				}
			}
		}

		// Remove matched pairs and add transfers.
		if ( ! empty( $indices_to_remove ) ) {
			foreach ( array_unique( $indices_to_remove ) as $idx ) {
				unset( $discrepancies[ $idx ] );
			}
			$discrepancies = array_merge( array_values( $discrepancies ), $transfers );
		}

		return $discrepancies;
	}

	/**
	 * Get the latest pullable event log ID from the hub.
	 *
	 * Only considers event types that nodes actually pull (ACTIONS_THAT_NODES_PULL),
	 * Find managed memberships on the local site that have a subscription.
	 * These are circular links: the membership is the source but incorrectly marked as managed.
	 *
	 * @return array Array of membership data with circular link issues.
	 */
	private static function get_local_circular_links() {
		global $wpdb;

		$managed_key   = \Newspack_Network\Woocommerce_Memberships\Admin::NETWORK_MANAGED_META_KEY;
		$remote_id_key = \Newspack_Network\Woocommerce_Memberships\Admin::REMOTE_ID_META_KEY;
		$site_url_key  = \Newspack_Network\Woocommerce_Memberships\Admin::SITE_URL_META_KEY;
		$network_key   = \Newspack_Network\Woocommerce_Memberships\Admin::NETWORK_ID_META_KEY;

		// phpcs:disable WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID as membership_id, LOWER(u.user_email) as email,
					pm_network.meta_value as network_id,
					pm_remote.meta_value as remote_id,
					pm_site.meta_value as remote_site_url
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->users} u ON p.post_author = u.ID
				INNER JOIN {$wpdb->postmeta} pm_managed ON p.ID = pm_managed.post_id AND pm_managed.meta_key = %s
				INNER JOIN {$wpdb->postmeta} pm_sub ON p.ID = pm_sub.post_id AND pm_sub.meta_key = '_subscription_id' AND pm_sub.meta_value != ''
				LEFT JOIN {$wpdb->postmeta} pm_remote ON p.ID = pm_remote.post_id AND pm_remote.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pm_site ON p.ID = pm_site.post_id AND pm_site.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pm_network ON p.post_parent = pm_network.post_id AND pm_network.meta_key = %s
				WHERE p.post_type = 'wc_user_membership' AND p.post_status != 'trash'",
				$managed_key,
				$remote_id_key,
				$site_url_key,
				$network_key
			)
		);
		// phpcs:enable WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users

		$circulars = [];
		foreach ( $results as $row ) {
			$circulars[] = [
				'email'            => $row->email,
				'network_id'       => $row->network_id ?? '',
				'membership_id'    => (int) $row->membership_id,
				'remote_id'        => (int) $row->remote_id,
				'remote_site_url'  => $row->remote_site_url ?? '',
				'has_subscription' => true,
			];
		}

		return $circulars;
	}

	/**
	 * Get the latest pullable event ID from the hub event log,
	 * avoiding false positives from non-pullable events like order_changed.
	 *
	 * @param int $excluded_node_id Optional node ID whose own events are excluded, mirroring the
	 *                              hub /pull endpoint (a node never pulls its own events). Pass a
	 *                              node's ID when computing sync lag for that node so events it
	 *                              originated are not counted as unprocessed. 0 excludes nothing.
	 * @return int The latest event ID, or 0 if the log is empty.
	 */
	private static function get_hub_latest_event_id( $excluded_node_id = 0 ) {
		$args = [ 'action_name_in' => \Newspack_Network\Accepted_Actions::ACTIONS_THAT_NODES_PULL ];
		if ( $excluded_node_id > 0 ) {
			$args['excluded_node_id'] = $excluded_node_id;
		}
		$events = \Newspack_Network\Hub\Stores\Event_Log::get(
			$args,
			1,
			1,
			'DESC'
		);
		return ! empty( $events ) ? $events[0]->get_id() : 0;
	}

	/**
	 * Query a node's sync status and plan availability via the sync-status endpoint.
	 *
	 * @param \Newspack_Network\Hub\Node $node The node to query.
	 * @return array|null Array with 'last_processed_id' and 'plan_network_ids', or null on error.
	 */
	private static function get_node_sync_status( $node ) {
		$endpoint = sprintf( '%s/wp-json/newspack-network/v1/integrity-check/sync-status', $node->get_url() );
		$endpoint = add_query_arg( [ '_t' => time() ], $endpoint );

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get
		$response = wp_remote_get(
			$endpoint,
			[
				'headers' => $node->get_authorization_headers( 'integrity-check' ),
				'timeout' => 15, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			WP_CLI::warning( sprintf( 'Could not check sync status for %s.', $node->get_url() ) );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return [
			'last_processed_id' => $data['last_processed_id'] ?? null,
			'plan_network_ids'  => $data['plan_network_ids'] ?? [],
		];
	}

	/**
	 * Parse a GMT timestamp string into a Unix timestamp.
	 *
	 * Uses explicit UTC timezone to avoid dependence on the server's default timezone.
	 *
	 * @param string $date_string A date string in 'Y-m-d H:i:s' format (GMT).
	 * @return int|false Unix timestamp, or false on parse failure.
	 */
	private static function parse_gmt_timestamp( $date_string ) {
		if ( empty( $date_string ) ) {
			return false;
		}
		$dt     = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date_string, new \DateTimeZone( 'UTC' ) );
		$errors = \DateTimeImmutable::getLastErrors();
		if ( false === $dt || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			return false;
		}
		$timestamp = $dt->getTimestamp();
		// Reject WordPress zero-dates ('0000-00-00 00:00:00'), which parse to a large-negative
		// timestamp rather than failing, so the caller's time() fallback engages instead.
		return $timestamp > 0 ? $timestamp : false;
	}

	/**
	 * Dispatch a membership_updated event from node data to create the membership on the hub.
	 *
	 * Used for missing_on_hub discrepancies: the node has a membership that the hub doesn't.
	 * Creates an event attributed to the node so the hub processes it locally.
	 *
	 * @param array  $node_item A node membership record (email, status, network_id, membership_id).
	 * @param string $node_url  The node's URL (used as the event's originating site).
	 * @return void
	 */
	private static function dispatch_to_hub( $node_item, $node_url ) {
		$event_data = [
			'email'           => $node_item['email'],
			'user_id'         => 0,
			'plan_network_id' => $node_item['network_id'],
			'membership_id'   => $node_item['membership_id'] ?? 0,
			'new_status'      => str_replace( 'wcm-', '', $node_item['status'] ),
		];

		$timestamp = ! empty( $node_item['post_modified'] ) ? self::parse_gmt_timestamp( $node_item['post_modified'] ) : false;
		if ( ! $timestamp ) {
			$timestamp = time();
		}

		$event = new \Newspack_Network\Incoming_Events\Woocommerce_Membership_Updated(
			$node_url,
			$event_data,
			$timestamp
		);

		$event->process_in_hub();
	}

	/**
	 * Dispatch a membership_updated event for the given hub membership item.
	 *
	 * Creates an event and persists it to the hub's event log. Nodes will pull
	 * it during their next sync cycle and update their local membership accordingly.
	 *
	 * @param array  $hub_item       A single hub membership record (email, status, network_id, membership_id).
	 * @param string $previous_email Optional previous owner email for transfer events.
	 * @return void
	 */
	private static function dispatch_to_node( $hub_item, $previous_email = '' ) {
		$event_data = [
			'email'           => $hub_item['email'],
			'user_id'         => 0,
			'plan_network_id' => $hub_item['network_id'],
			'membership_id'   => $hub_item['membership_id'],
			'new_status'      => str_replace( 'wcm-', '', $hub_item['status'] ),
		];

		if ( ! empty( $previous_email ) ) {
			$event_data['previous_email'] = $previous_email;
		}

		// Use the hub membership's modification time for idempotent dispatch.
		$timestamp = ! empty( $hub_item['post_modified'] ) ? self::parse_gmt_timestamp( $hub_item['post_modified'] ) : false;
		if ( ! $timestamp ) {
			$timestamp = time();
		}

		$event = new \Newspack_Network\Incoming_Events\Woocommerce_Membership_Updated(
			get_bloginfo( 'url' ),
			$event_data,
			$timestamp
		);

		$event->process_in_hub();
	}
}
