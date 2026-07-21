<?php
/**
 * WP-CLI command to migrate teams-based institutional access (the custom
 * `newspack-teams-for-wc-memberships-access-by-ip` / `-auto-join-by-email`
 * plugins) to Newspack Access Control Institutions.
 *
 * The source data is WooCommerce Memberships teams plus the
 * `wc_team_memberships_access_by_ip` option (a `team_id => IP ranges` map).
 * The per-team email-domain mapping is NOT recoverable from the database (it
 * lives in the auto-join plugin's source), so it is operator-supplied via
 * --domains-csv.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use Newspack\Institution;
use WP_CLI;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Teams-based institutional access → AC Institutions migration CLI command.
 */
class Institutions_Migration {

	/**
	 * The option holding the `team_id => IP ranges` map written by the custom
	 * access-by-IP plugin.
	 *
	 * @var string
	 */
	const ACCESS_BY_IP_OPTION = 'wc_team_memberships_access_by_ip';

	/**
	 * Institution meta recording the source team post ID. This is the
	 * idempotency key: a team whose ID appears in this meta on any institution
	 * is never migrated again.
	 *
	 * @var string
	 */
	const MIGRATED_FROM_TEAM_META_KEY = '_np_institution_migrated_from_team_id';

	/**
	 * Institution meta recording the team's linked subscription ID
	 * (`_subscription_id`). Informational only — the Institution entity has no
	 * functional subscription link; access via the subscription is handled by
	 * the group subscription migrated via `wp newspack migrate-teams`.
	 *
	 * @var string
	 */
	const MIGRATED_SUBSCRIPTION_META_KEY = '_np_institution_migrated_subscription_id';

	/**
	 * Migrate teams-based institutional access to Access Control Institutions.
	 *
	 * Reads the `wc_memberships_team` inventory and the
	 * `wc_team_memberships_access_by_ip` option map, and creates one Institution
	 * per team that has usable rules: the team's IP ranges become the
	 * institution's IP-range rule, and operator-supplied email domains (via
	 * --domains-csv) become its email-domain rule. A team's `_subscription_id`
	 * link is recorded on the institution as informational meta and reported.
	 *
	 * Teams with no usable rules are reported as unmappable, never silently
	 * skipped. Invalid IP ranges in the option map (malformed, IPv6 — which
	 * Access Control IP rules don't support) are reported per team.
	 *
	 * The command is idempotent: a team already migrated (recorded on the
	 * institution via migrated-from-team meta) is skipped on re-run, and its
	 * institution's rules are left untouched — post-migration operator edits,
	 * such as replacing internal ranges with proxy egress IPs, survive re-runs.
	 *
	 * IMPORTANT: institutions behind a proxy (EZProxy, Zscaler, etc.) must be
	 * configured with the proxy's public egress IPs, not the institution's
	 * internal ranges — the platform overwrites X-Forwarded-For, so internal
	 * ranges will never match. The command prints every migrated range so each
	 * institution can be checked.
	 *
	 * Dry-run by default; pass --live to write.
	 *
	 * ## OPTIONS
	 *
	 * [--domains-csv=<path>]
	 * : Path to a CSV mapping team IDs to email domains. Each row is `team_id,domain[,domain...]`; rows with the same team ID accumulate. An optional header row is ignored. Domains are matched exactly against the reader's email domain (no wildcards).
	 *
	 * [--live]
	 * : Apply the changes. Without this flag the command runs as a dry-run and writes nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack migrate-institutions
	 *     wp newspack migrate-institutions --domains-csv=/tmp/domains.csv
	 *     wp newspack migrate-institutions --domains-csv=/tmp/domains.csv --live
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 *
	 * @return void
	 */
	public function migrate_institutions( $args, $assoc_args ) {
		$dry_run  = ! (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'live', false );
		$csv_path = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'domains-csv', '' );

		$domains_map = [];
		if ( '' !== $csv_path ) {
			$csv_result = self::parse_domains_csv( $csv_path );
			if ( \is_wp_error( $csv_result ) ) {
				WP_CLI::error( $csv_result->get_error_message() );
			}
			$domains_map = $csv_result['map'];
			foreach ( $csv_result['errors'] as $csv_error ) {
				WP_CLI::warning( sprintf( 'domains-csv: %s', $csv_error ) );
			}
		}

		if ( $dry_run ) {
			WP_CLI::line( '' );
			WP_CLI::line( '*** DRY RUN MODE — no data will be modified. Pass --live to apply. ***' );
			WP_CLI::line( '' );
		}

		$ip_map = self::get_access_by_ip_map();

		$teams = \get_posts(
			[
				'post_type'      => 'wc_memberships_team',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			]
		);

		WP_CLI::line( sprintf( 'Found %d team(s), %d team(s) in the access-by-IP option map, %d team(s) in the domains CSV.', count( $teams ), count( $ip_map ), count( $domains_map ) ) );
		WP_CLI::line( '' );

		// Surface option-map / CSV entries that reference no existing team, so an
		// operator typo or a deleted team never disappears silently.
		foreach ( array_keys( $ip_map ) as $mapped_team_id ) {
			if ( ! in_array( (int) $mapped_team_id, $teams, true ) ) {
				WP_CLI::warning( sprintf( 'Access-by-IP option references team %d, which is not a published team — its ranges will not be migrated.', $mapped_team_id ) );
			}
		}
		foreach ( array_keys( $domains_map ) as $mapped_team_id ) {
			if ( ! in_array( (int) $mapped_team_id, $teams, true ) ) {
				WP_CLI::warning( sprintf( 'domains-csv references team %d, which is not a published team — its domains will not be migrated.', $mapped_team_id ) );
			}
		}

		$summary    = [];
		$unmappable = [];

		foreach ( $teams as $team_id ) {
			$result = self::migrate_team(
				$team_id,
				isset( $ip_map[ $team_id ] ) ? $ip_map[ $team_id ] : '',
				isset( $domains_map[ $team_id ] ) ? $domains_map[ $team_id ] : [],
				! $dry_run
			);

			foreach ( $result['invalid_ranges'] as $invalid_range ) {
				WP_CLI::warning( sprintf( 'Team %d: invalid IP range "%s" — not migrated (Access Control IP rules support IPv4 addresses and CIDR blocks only).', $team_id, $invalid_range ) );
			}

			if ( 'unmappable' === $result['status'] ) {
				$unmappable[] = [
					'team_id' => $team_id,
					'name'    => \get_the_title( $team_id ),
					'reason'  => $result['reason'],
				];
				continue;
			}

			if ( 'exists' === $result['status'] ) {
				WP_CLI::line( sprintf( 'Team %d: already migrated to institution %d — skipping (rules left untouched).', $team_id, $result['institution_id'] ) );
			} elseif ( $dry_run ) {
				WP_CLI::success( sprintf( 'Team %d: would create institution "%s" — IP ranges: [%s], domains: [%s].', $team_id, \get_the_title( $team_id ), implode( ', ', $result['ip_ranges'] ), implode( ', ', $result['domains'] ) ) );
			} else {
				WP_CLI::success( sprintf( 'Team %d: created institution %d ("%s") — IP ranges: [%s], domains: [%s].', $team_id, $result['institution_id'], \get_the_title( $team_id ), implode( ', ', $result['ip_ranges'] ), implode( ', ', $result['domains'] ) ) );
			}

			$summary[] = [
				'Team'        => $team_id,
				'Institution' => $dry_run && 'created' === $result['status'] ? '(dry-run)' : $result['institution_id'],
				'Name'        => \get_the_title( $team_id ),
				'Status'      => $result['status'],
				'IP ranges'   => implode( ', ', $result['ip_ranges'] ),
				'Domains'     => implode( ', ', $result['domains'] ),
				'Linked sub'  => $result['subscription_id'] ? $result['subscription_id'] . ( '' !== $result['subscription_note'] ? ' (' . $result['subscription_note'] . ')' : '' ) : '—',
			];
		}

		// Summary table.
		WP_CLI::line( '' );
		WP_CLI::line( $dry_run ? '=== DRY RUN SUMMARY ===' : '=== MIGRATION SUMMARY ===' );
		WP_CLI::line( '' );
		if ( ! empty( $summary ) ) {
			\WP_CLI\Utils\format_items( 'table', $summary, [ 'Team', 'Institution', 'Name', 'Status', 'IP ranges', 'Domains', 'Linked sub' ] );
		} else {
			WP_CLI::line( 'No teams with usable institutional rules found.' );
		}

		// Unmappable teams section: every team the command could not map, with a reason.
		if ( ! empty( $unmappable ) ) {
			WP_CLI::line( '' );
			WP_CLI::line( sprintf( '=== UNMAPPABLE TEAMS — %d total ===', count( $unmappable ) ) );
			WP_CLI::line( '' );
			\WP_CLI\Utils\format_items( 'table', $unmappable, [ 'team_id', 'name', 'reason' ] );
		}

		// Proxy caveat: migrated ranges came from the source site's config and may be
		// internal ranges that will never match behind a proxy.
		$rows_with_ranges = array_filter( $summary, fn( $row ) => '' !== $row['IP ranges'] );
		if ( ! empty( $rows_with_ranges ) ) {
			WP_CLI::line( '' );
			WP_CLI::warning( 'Verify each institution\'s IP ranges: institutions behind a proxy (EZProxy, Zscaler, etc.) need the proxy\'s PUBLIC EGRESS IPs, not internal ranges — the platform overwrites X-Forwarded-For, so internal ranges will never match. Migrated ranges:' );
			foreach ( $rows_with_ranges as $row ) {
				WP_CLI::line( sprintf( '  - %s (team %d): %s', $row['Name'], $row['Team'], $row['IP ranges'] ) );
			}
		}

		if ( ! $dry_run ) {
			Institution::invalidate_cache();
		}

		$created_count = count( array_filter( $summary, fn( $row ) => 'created' === $row['Status'] ) );
		$exists_count  = count( array_filter( $summary, fn( $row ) => 'exists' === $row['Status'] ) );
		WP_CLI::line( '' );
		WP_CLI::success( sprintf( 'Done. %d team(s) processed: %d institution(s) %s, %d already migrated, %d unmappable.', count( $teams ), $created_count, $dry_run ? 'would be created' : 'created', $exists_count, count( $unmappable ) ) );
	}

	/**
	 * Parse the operator-supplied team → email-domain CSV.
	 *
	 * Each row is `team_id,domain[,domain...]`. Rows sharing a team ID
	 * accumulate. A first row whose first cell is not numeric is treated as a
	 * header and skipped. Domains are lowercased and a leading `@` is stripped;
	 * anything that doesn't look like a hostname is collected as an error.
	 *
	 * @param string $csv_path Path to the CSV file.
	 *
	 * @return array|WP_Error {
	 *     Parse result, or WP_Error when the file is unreadable.
	 *
	 *     @type array    $map    Map of team ID => list of normalized domains.
	 *     @type string[] $errors Malformed rows/domains, for reporting.
	 * }
	 */
	public static function parse_domains_csv( $csv_path ) {
		if ( ! is_readable( $csv_path ) ) {
			return new WP_Error( 'newspack_migrate_institutions_csv', sprintf( 'Cannot read domains CSV: %s', $csv_path ) );
		}
		$csv_content = file_get_contents( $csv_path ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
		if ( false === $csv_content ) {
			return new WP_Error( 'newspack_migrate_institutions_csv', sprintf( 'Cannot read domains CSV: %s', $csv_path ) );
		}

		$map    = [];
		$errors = [];
		$lines  = preg_split( '/\r\n|\r|\n/', $csv_content );
		foreach ( $lines as $line_index => $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cells   = array_map( 'trim', str_getcsv( $line ) );
			$team_id = $cells[0];
			if ( ! ctype_digit( $team_id ) ) {
				// The first row may be a header; anything later is malformed.
				if ( 0 !== $line_index ) {
					$errors[] = sprintf( 'row %d: "%s" is not a team ID — row skipped.', $line_index + 1, $team_id );
				}
				continue;
			}
			$team_id = (int) $team_id;
			foreach ( array_slice( $cells, 1 ) as $raw_domain ) {
				if ( '' === $raw_domain ) {
					continue;
				}
				$domain = strtolower( ltrim( $raw_domain, '@' ) );
				if ( ! preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain ) ) {
					$errors[] = sprintf( 'row %d: "%s" is not a valid domain — skipped.', $line_index + 1, $raw_domain );
					continue;
				}
				if ( ! isset( $map[ $team_id ] ) ) {
					$map[ $team_id ] = [];
				}
				if ( ! in_array( $domain, $map[ $team_id ], true ) ) {
					$map[ $team_id ][] = $domain;
				}
			}
		}

		return [
			'map'    => $map,
			'errors' => $errors,
		];
	}

	/**
	 * Read the access-by-IP option map.
	 *
	 * @return array Map of team ID => raw ranges value (string or array).
	 */
	public static function get_access_by_ip_map() {
		$raw_map = \get_option( self::ACCESS_BY_IP_OPTION, [] );
		return is_array( $raw_map ) ? $raw_map : [];
	}

	/**
	 * Normalize a raw IP-ranges value into valid and invalid entries.
	 *
	 * Accepts both option-value shapes (a delimited string or an array), splits
	 * on commas and newlines, and validates each entry against the same rules
	 * as IP_Access_Rule: IPv4 addresses and IPv4 CIDR blocks (`/0`–`/32`).
	 * Anything else (hostnames, IPv6, malformed CIDR) is returned as invalid so
	 * the caller can report it instead of silently dropping it.
	 *
	 * @param string|array $raw Raw ranges value from the option map.
	 *
	 * @return array {
	 *     @type string[] $valid   Valid IPv4/CIDR entries.
	 *     @type string[] $invalid Rejected entries, for reporting.
	 * }
	 */
	public static function normalize_ip_ranges( $raw ) {
		$tokens = [];
		foreach ( ( is_array( $raw ) ? $raw : [ $raw ] ) as $chunk ) {
			$tokens = array_merge( $tokens, preg_split( '/[,\n\r]+/', (string) $chunk ) );
		}
		$tokens = array_filter( array_map( 'trim', $tokens ) );

		$valid   = [];
		$invalid = [];
		foreach ( $tokens as $token ) {
			if ( false !== strpos( $token, '/' ) ) {
				list( $subnet, $bits ) = array_pad( explode( '/', $token, 2 ), 2, '' );
				$subnet                = trim( $subnet );
				$bits                  = trim( $bits );
				if ( ctype_digit( $bits ) && (int) $bits <= 32 && filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
					$valid[] = $subnet . '/' . $bits;
				} else {
					$invalid[] = $token;
				}
			} elseif ( filter_var( $token, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				$valid[] = $token;
			} else {
				$invalid[] = $token;
			}
		}

		return [
			'valid'   => array_values( $valid ),
			'invalid' => array_values( $invalid ),
		];
	}

	/**
	 * Find the institution previously migrated from a team, if any.
	 *
	 * @param int $team_id Team post ID.
	 *
	 * @return int Institution post ID, or 0 when the team was never migrated.
	 */
	public static function find_migrated_institution( $team_id ) {
		$institution_ids = \get_posts(
			[
				'post_type'      => Institution::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::MIGRATED_FROM_TEAM_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $team_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
		return empty( $institution_ids ) ? 0 : (int) $institution_ids[0];
	}

	/**
	 * Migrate a single team to an institution.
	 *
	 * @param int          $team_id       Team post ID.
	 * @param string|array $raw_ip_ranges Raw ranges value from the access-by-IP option map.
	 * @param string[]     $domains       Normalized email domains from the domains CSV.
	 * @param bool         $live          Whether to write. False = dry-run: the result reports
	 *                                    what would happen, but nothing is created.
	 *
	 * @return array {
	 *     Migration result.
	 *
	 *     @type string   $status            One of 'created' (or would-create on dry-run),
	 *                                       'exists' (already migrated), 'unmappable', 'error'.
	 *     @type int      $institution_id    Created/existing institution post ID (0 on dry-run/unmappable).
	 *     @type string[] $ip_ranges         Valid IP ranges migrated (or that would be).
	 *     @type string[] $invalid_ranges    Rejected source ranges, for reporting.
	 *     @type string[] $domains           Email domains applied.
	 *     @type int      $subscription_id   The team's linked subscription ID, or 0.
	 *     @type string   $subscription_note Status note about the linked subscription.
	 *     @type string   $reason            Reason, when unmappable or errored.
	 * }
	 */
	public static function migrate_team( $team_id, $raw_ip_ranges, $domains, $live ) {
		$ranges = self::normalize_ip_ranges( $raw_ip_ranges );

		$result = [
			'status'            => 'unmappable',
			'institution_id'    => 0,
			'ip_ranges'         => $ranges['valid'],
			'invalid_ranges'    => $ranges['invalid'],
			'domains'           => array_values( $domains ),
			'subscription_id'   => 0,
			'subscription_note' => '',
			'reason'            => '',
		];

		// The linked subscription is informational: recorded and reported, but not a
		// functional institution rule (group access rides the subscription itself).
		$linked_subscription_id = (int) \get_post_meta( $team_id, '_subscription_id', true );
		if ( $linked_subscription_id ) {
			$result['subscription_id'] = $linked_subscription_id;
			if ( function_exists( 'wcs_get_subscription' ) ) {
				$linked_subscription        = \wcs_get_subscription( $linked_subscription_id );
				$result['subscription_note'] = $linked_subscription ? $linked_subscription->get_status() : 'not found';
			}
		}

		// Idempotency: never re-create, and never touch the existing institution's
		// rules — post-migration operator edits (e.g. proxy egress IPs) must survive.
		$existing_institution_id = self::find_migrated_institution( $team_id );
		if ( $existing_institution_id ) {
			$result['status']         = 'exists';
			$result['institution_id'] = $existing_institution_id;
			return $result;
		}

		if ( empty( $ranges['valid'] ) && empty( $domains ) ) {
			$reason_parts = [];
			if ( ! empty( $ranges['invalid'] ) ) {
				$reason_parts[] = sprintf( 'all %d IP range(s) invalid', count( $ranges['invalid'] ) );
			} else {
				$reason_parts[] = 'no IP ranges in the access-by-IP option';
			}
			$reason_parts[]   = 'no domains in the domains CSV';
			$result['reason'] = implode( '; ', $reason_parts ) . '.';
			return $result;
		}

		$result['status'] = 'created';
		if ( ! $live ) {
			return $result;
		}

		$institution_id = Institution::create(
			\get_the_title( $team_id ),
			sprintf( 'Migrated from WooCommerce Memberships team #%d.', $team_id ),
			[
				'ip_range'     => implode( ',', $ranges['valid'] ),
				'email_domain' => implode( ',', $domains ),
			]
		);
		if ( \is_wp_error( $institution_id ) ) {
			$result['status'] = 'error';
			$result['reason'] = $institution_id->get_error_message();
			return $result;
		}

		\update_post_meta( $institution_id, self::MIGRATED_FROM_TEAM_META_KEY, (string) $team_id );
		if ( $linked_subscription_id ) {
			\update_post_meta( $institution_id, self::MIGRATED_SUBSCRIPTION_META_KEY, (string) $linked_subscription_id );
		}

		$result['institution_id'] = (int) $institution_id;
		return $result;
	}
}
