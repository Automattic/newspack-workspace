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

use Newspack\Content_Gate;
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
	 * Institution meta recording the IP ranges actually applied at creation
	 * time. Re-run reporting diffs the source against THIS, not the current
	 * rules — so a value the operator deliberately removed (e.g. an internal
	 * range replaced by a proxy egress IP) is never re-suggested, while a value
	 * that later appears in the source is named as genuinely new.
	 *
	 * @var string
	 */
	const APPLIED_IP_RANGES_META_KEY = '_np_institution_migrated_applied_ip_range';

	/**
	 * Institution meta recording the email domains actually applied at
	 * creation time. Same role as APPLIED_IP_RANGES_META_KEY, for domains.
	 *
	 * @var string
	 */
	const APPLIED_DOMAINS_META_KEY = '_np_institution_migrated_applied_email_domain';

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
	 * : Path to a CSV mapping team IDs to email domains. Each row is `team_id,domain[,domain...]`; rows with the same team ID accumulate. An optional `team_id` header row is ignored. Domains are matched exactly against the reader's email domain (no wildcards) and must be ASCII/punycode — pre-convert internationalized domains (e.g. `xn--…`). A supplied CSV that yields no usable rows aborts the command.
	 *
	 * [--live]
	 * : Apply the changes. Without this flag the command runs as a dry-run and writes nothing.
	 *
	 * [--ignore-csv-errors]
	 * : Proceed with a live run even when the domains CSV has malformed rows or rows referencing non-existent teams. Without this flag a live run stops on such rows, because affected teams would be permanently stamped as migrated without their domain rules. Dry-runs always proceed and report the problems.
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
		$dry_run           = ! (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'live', false );
		$csv_path          = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'domains-csv', '' );
		$ignore_csv_errors = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'ignore-csv-errors', false );
		$csv_error_count   = 0;

		$domains_map = [];
		if ( '' !== $csv_path ) {
			$csv_result = self::parse_domains_csv( $csv_path );
			if ( \is_wp_error( $csv_result ) ) {
				WP_CLI::error( $csv_result->get_error_message() );
			}
			$domains_map     = $csv_result['map'];
			$csv_error_count = count( $csv_result['errors'] );
			foreach ( $csv_result['errors'] as $csv_error ) {
				WP_CLI::warning( sprintf( 'domains-csv: %s', $csv_error ) );
			}
			// A supplied CSV that yields zero rows is indistinguishable from a site
			// with no domain rules, and the difference only surfaces after teams have
			// been marked migrated — which a corrected re-run cannot repair. Stop.
			if ( empty( $domains_map ) ) {
				WP_CLI::error( sprintf( 'The domains CSV (%s) yielded no team → domain rows. Aborting so an empty or wrongly-pathed file cannot silently migrate zero domains — fix the CSV, or run without --domains-csv.', $csv_path ) );
			}
		}

		if ( ! Content_Gate::is_newspack_feature_enabled() ) {
			WP_CLI::warning( 'The content gates feature (NEWSPACK_CONTENT_GATES) is not enabled on this site: institutions will be created but will remain dormant until it is enabled.' );
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
				'posts_per_page' => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging.posts_per_page_posts_per_page -- One-shot operator-run CLI migration; every team must be enumerated, and team counts are operator-scale (dozens).
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
			if ( ! is_int( $mapped_team_id ) || ! in_array( $mapped_team_id, $teams, true ) ) {
				WP_CLI::warning( sprintf( 'Access-by-IP option references team "%s", which is not a published team — its ranges will not be migrated.', $mapped_team_id ) );
			}
		}
		$csv_orphan_count = 0;
		foreach ( array_keys( $domains_map ) as $mapped_team_id ) {
			if ( ! in_array( (int) $mapped_team_id, $teams, true ) ) {
				++$csv_orphan_count;
				WP_CLI::warning( sprintf( 'domains-csv references team %d, which is not a published team — its domains will not be migrated.', $mapped_team_id ) );
			}
		}

		// A live run with a partially-bad CSV is as irrecoverable as one with an
		// empty CSV: the affected teams are stamped as migrated without their
		// domain rules, and a corrected re-run never modifies them. Stop before
		// writing unless the operator explicitly opts to proceed. Dry-runs
		// continue so the operator can preview everything at once.
		if ( ! $dry_run && ! $ignore_csv_errors && ( $csv_error_count > 0 || $csv_orphan_count > 0 ) ) {
			WP_CLI::error( sprintf( 'The domains CSV has %d malformed row(s) and %d row(s) referencing non-existent teams (see the warnings above). A live run would permanently stamp the affected teams as migrated without their domain rules. Fix the CSV, or pass --ignore-csv-errors to proceed anyway.', $csv_error_count, $csv_orphan_count ) );
		}

		$summary    = [];
		$unmappable = [];

		foreach ( $teams as $team_id ) {
			$team_name = (string) \get_post_field( 'post_title', $team_id, 'raw' );
			$result    = self::migrate_team(
				$team_id,
				isset( $ip_map[ $team_id ] ) ? $ip_map[ $team_id ] : '',
				isset( $domains_map[ $team_id ] ) ? $domains_map[ $team_id ] : [],
				! $dry_run,
				'' !== $csv_path
			);

			foreach ( $result['invalid_ranges'] as $invalid_range ) {
				WP_CLI::warning( sprintf( 'Team %d: invalid IP range "%s" — not migrated (Access Control IP rules support IPv4 addresses and CIDR blocks only).', $team_id, $invalid_range ) );
			}

			if ( 'unmappable' === $result['status'] ) {
				$unmappable[] = [
					'team_id' => $team_id,
					'name'    => $team_name,
					'reason'  => $result['reason'],
				];
				continue;
			}

			if ( 'error' === $result['status'] ) {
				// A failed create must never read as success in an access-granting migration.
				WP_CLI::warning( sprintf( 'Team %d: FAILED to create institution "%s" — %s', $team_id, $team_name, $result['reason'] ) );
			} elseif ( 'exists' === $result['status'] ) {
				$status_note = 'publish' !== $result['institution_status'] ? sprintf( ' (status: %s — restore it or delete it to re-migrate)', $result['institution_status'] ) : '';
				WP_CLI::line( sprintf( 'Team %d: already migrated to institution %d%s — skipping (rules left untouched).', $team_id, $result['institution_id'], $status_note ) );
				// The skip protects operator edits, but the operator must know which
				// supplied values the re-run declined to add — otherwise a second run
				// with a newly-obtained CSV looks successful while adding nothing.
				// Values the operator deliberately removed are not re-listed: the
				// diff runs against what was applied at creation, not current rules.
				$withheld_values = array_merge( $result['withheld_ranges'], $result['withheld_domains'] );
				if ( ! empty( $withheld_values ) ) {
					WP_CLI::warning( sprintf( 'Team %d: the source data supplies %d value(s) never migrated to institution %d: [%s] — NOT applied (re-runs never modify an existing institution); add manually if intended.', $team_id, count( $withheld_values ), $result['institution_id'], implode( ', ', $withheld_values ) ) );
				}
			} elseif ( $dry_run ) {
				WP_CLI::success( sprintf( 'Team %d: would create institution "%s" — IP ranges: [%s], domains: [%s].', $team_id, $team_name, implode( ', ', $result['ip_ranges'] ), implode( ', ', $result['domains'] ) ) );
			} else {
				WP_CLI::success( sprintf( 'Team %d: created institution %d ("%s") — IP ranges: [%s], domains: [%s].', $team_id, $result['institution_id'], $team_name, implode( ', ', $result['ip_ranges'] ), implode( ', ', $result['domains'] ) ) );
			}

			// A malformed or too-broad range turns an institution into a whitelist for
			// everyone — judge breadth by the numeric mask the access check will use,
			// not by how the range was typed. Error rows wrote nothing, so no warning.
			if ( 'error' !== $result['status'] ) {
				foreach ( $result['ip_ranges'] as $migrated_range ) {
					$breadth_warning = self::get_range_breadth_warning( $migrated_range );
					if ( '' !== $breadth_warning ) {
						WP_CLI::warning( sprintf( 'Team %d: range "%s" %s. Confirm this is intended.', $team_id, $migrated_range, $breadth_warning ) );
					}
				}
			}

			$summary[] = [
				'Team'        => $team_id,
				'Institution' => 'error' === $result['status'] ? '—' : ( $dry_run && 'created' === $result['status'] ? '(dry-run)' : $result['institution_id'] ),
				'Name'        => $team_name,
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
		// internal ranges that will never match behind a proxy. For already-migrated
		// (exists) rows the listed ranges are the institution's CURRENT rules, so an
		// operator's egress-IP fix is what gets re-listed, not the stale source ranges.
		$rows_with_ranges = array_filter( $summary, fn( $row ) => '' !== $row['IP ranges'] && 'error' !== $row['Status'] );
		if ( ! empty( $rows_with_ranges ) ) {
			WP_CLI::line( '' );
			WP_CLI::warning( 'Verify each institution\'s IP ranges: institutions behind a proxy (EZProxy, Zscaler, etc.) need the proxy\'s PUBLIC EGRESS IPs, not internal ranges — the platform overwrites X-Forwarded-For, so internal ranges will never match. Migrated ranges:' );
			foreach ( $rows_with_ranges as $row ) {
				WP_CLI::line( sprintf( '  - %s (team %d): %s', $row['Name'], $row['Team'], $row['IP ranges'] ) );
			}
		}

		// Institutions grant nothing on their own — a content gate must select
		// them, and a gate whose institutional rule is enabled with NO
		// institutions selected admits every visitor. Name the step so the
		// closing success line can't read as "migration done, job done".
		if ( ! empty( $summary ) ) {
			WP_CLI::line( '' );
			WP_CLI::line( 'Next step: institutions grant access only where a content gate\'s institutional-access rule selects them. Configure the gates after migrating — a gate with the rule enabled and no institutions selected admits EVERY visitor.' );
		}

		if ( ! $dry_run ) {
			Institution::invalidate_cache();
		}

		$created_count = count( array_filter( $summary, fn( $row ) => 'created' === $row['Status'] ) );
		$exists_count  = count( array_filter( $summary, fn( $row ) => 'exists' === $row['Status'] ) );
		$error_count   = count( array_filter( $summary, fn( $row ) => 'error' === $row['Status'] ) );
		WP_CLI::line( '' );
		$done_report = sprintf( 'Done. %d team(s) processed: %d institution(s) %s, %d already migrated, %d unmappable, %d error(s).', count( $teams ), $created_count, $dry_run ? 'would be created' : 'created', $exists_count, count( $unmappable ), $error_count );
		if ( $error_count > 0 ) {
			// error() exits non-zero, so a scripted caller can detect partial failure.
			WP_CLI::error( $done_report . ' Errored teams were NOT migrated — see the warnings above.' );
		}
		WP_CLI::success( $done_report );
	}

	/**
	 * Parse the operator-supplied team → email-domain CSV.
	 *
	 * Each row is `team_id,domain[,domain...]`. Rows sharing a team ID
	 * accumulate. A row whose first cell is `team_id`/`team id`/`team-id`
	 * (case-insensitive) is treated as a header and skipped; any other
	 * non-numeric first cell — including on row 1 — is collected as an error,
	 * so a typo'd team ID can never vanish as a phantom header. Domains are
	 * lowercased and a leading `@` is stripped; anything that doesn't look
	 * like a hostname is collected as an error.
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
		// is_readable() alone passes for a directory, which "reads" as empty.
		if ( ! is_file( $csv_path ) || ! is_readable( $csv_path ) ) {
			return new WP_Error( 'newspack_migrate_institutions_csv', sprintf( 'Cannot read domains CSV: %s', $csv_path ) );
		}
		$csv_content = file_get_contents( $csv_path ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
		if ( false === $csv_content ) {
			return new WP_Error( 'newspack_migrate_institutions_csv', sprintf( 'Cannot read domains CSV: %s', $csv_path ) );
		}

		// Strip a UTF-8 BOM (Excel/Sheets exports emit one) — on a headerless CSV it
		// would make the first cell non-numeric and silently drop row 1.
		if ( 0 === strpos( $csv_content, "\xEF\xBB\xBF" ) ) {
			$csv_content = substr( $csv_content, 3 );
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
				// Only a cell that actually reads as a header is skipped silently;
				// any other non-numeric team ID — row 1 included — is reported, so
				// a typo'd team ID can never vanish as a phantom header.
				if ( ! preg_match( '/^team[ _-]?ids?$/i', $team_id ) ) {
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
	 * Digit-like string keys (` 11`, `011`) are normalized to int team IDs so
	 * they match the team inventory; any other key is kept as-is and surfaced
	 * by the command's orphan-entry warning rather than silently dropped.
	 *
	 * @return array Map of team ID => raw ranges value (string or array).
	 */
	public static function get_access_by_ip_map() {
		$raw_map = \get_option( self::ACCESS_BY_IP_OPTION, [] );
		if ( ! is_array( $raw_map ) ) {
			return [];
		}
		$normalized_map = [];
		foreach ( $raw_map as $team_key => $ranges ) {
			$trimmed_key = trim( (string) $team_key );
			$normalized_map[ ctype_digit( $trimmed_key ) ? (int) $trimmed_key : $team_key ] = $ranges;
		}
		return $normalized_map;
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
					// (int)-cast the bits so the stored range is canonical: a
					// leading-zero mask like `/00` would otherwise evade every
					// string-shape breadth check while still matching numerically.
					$valid[] = $subnet . '/' . (int) $bits;
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
	 * Describe why a migrated range is dangerously broad, if it is.
	 *
	 * Breadth is judged by the numeric mask the access check will use
	 * (IP_Access_Rule casts the bits to int), never by the range's spelling.
	 * Three shapes are flagged: a zero mask (matches every visitor), a private
	 * or reserved subnet (never matches real visitors behind the platform's
	 * proxy handling, and can match everyone on a misconfigured host), and a
	 * very wide public block. Non-IPv4 values return '' — for already-migrated
	 * institutions the ranges are operator-owned meta this command must not
	 * second-guess.
	 *
	 * @param string $range A migrated IPv4 address or CIDR range.
	 *
	 * @return string Human-readable reason, or '' when the range is unremarkable.
	 */
	public static function get_range_breadth_warning( $range ) {
		list( $subnet, $bits ) = array_pad( explode( '/', $range, 2 ), 2, '32' );
		// Trim BOTH halves, exactly like the access check's parser — a spelling
		// like `0.0.0.0 / 0` must not evade breadth judgment while matching at
		// read time.
		$subnet = trim( $subnet );
		$bits   = trim( $bits );
		if ( ! filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) || ! ctype_digit( $bits ) ) {
			return '';
		}
		$mask_bits = (int) $bits;
		if ( 0 === $mask_bits ) {
			return 'is a zero-length mask — it matches EVERY visitor IP and grants this institution\'s access to everyone';
		}
		if ( ! filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return 'is a private or reserved range — it will never match real visitors\' public egress IPs (see the proxy caveat below)';
		}
		if ( $mask_bits < 16 ) {
			return sprintf( 'is a very wide block (~%s addresses) — confirm it really is the institution\'s public egress range', number_format( 2 ** ( 32 - $mask_bits ) ) );
		}
		return '';
	}

	/**
	 * Find the institution previously migrated from a team, if any.
	 *
	 * Matches ANY post status (including trash): a trashed migrated institution
	 * must still count as migrated, or a re-run would create a duplicate. The
	 * caller reports the found status so the operator can restore or delete it.
	 *
	 * @param int $team_id Team post ID.
	 *
	 * @return int Institution post ID, or 0 when the team was never migrated.
	 */
	public static function find_migrated_institution( $team_id ) {
		$institution_ids = \get_posts(
			[
				'post_type'      => Institution::POST_TYPE,
				'post_status'    => array_keys( \get_post_stati() ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => self::MIGRATED_FROM_TEAM_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $team_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
		return empty( $institution_ids ) ? 0 : (int) $institution_ids[0];
	}

	/**
	 * Migrate a single team to an institution.
	 *
	 * @param int          $team_id              Team post ID.
	 * @param string|array $raw_ip_ranges        Raw ranges value from the access-by-IP option map.
	 * @param string[]     $domains              Normalized email domains from the domains CSV.
	 * @param bool         $live                 Whether to write. False = dry-run: the result reports
	 *                                           what would happen, but nothing is created.
	 * @param bool         $domains_csv_supplied Whether a --domains-csv was supplied at all; only
	 *                                           affects the wording of the unmappable reason.
	 *
	 * @return array {
	 *     Migration result.
	 *
	 *     @type string   $status             One of 'created' (or would-create on dry-run),
	 *                                        'exists' (already migrated), 'unmappable', 'error'.
	 *     @type int      $institution_id     Created/existing institution post ID (0 on dry-run/unmappable).
	 *     @type string   $institution_status Post status of the existing institution ('' unless status is 'exists').
	 *     @type string[] $ip_ranges          Valid IP ranges migrated (or that would be). For an
	 *                                        already-migrated team these are the institution's CURRENT
	 *                                        ranges, not the source option's — reporting must reflect
	 *                                        the skip-don't-realign semantics.
	 *     @type string[] $invalid_ranges     Rejected source ranges, for reporting (empty for 'exists' —
	 *                                        source data is not consulted for an already-migrated team).
	 *     @type string[] $domains            Email domains applied (current meta for 'exists').
	 *     @type string[] $withheld_ranges    For 'exists' only: valid source ranges never applied to
	 *                                        the institution (diffed against the applied-at-creation
	 *                                        record, so operator-removed values are not re-listed;
	 *                                        falls back to current rules for institutions migrated
	 *                                        before the record existed) — reported, never applied.
	 *     @type string[] $withheld_domains   For 'exists' only: supplied domains never applied to
	 *                                        the institution (same diff base) — reported, never applied.
	 *     @type int      $subscription_id    The team's linked subscription ID, or 0.
	 *     @type string   $subscription_note  Status note about the linked subscription.
	 *     @type string   $reason             Reason, when unmappable or errored.
	 * }
	 */
	public static function migrate_team( $team_id, $raw_ip_ranges, $domains, $live, $domains_csv_supplied = true ) {
		$ranges = self::normalize_ip_ranges( $raw_ip_ranges );

		$result = [
			'status'             => 'unmappable',
			'institution_id'     => 0,
			'institution_status' => '',
			'ip_ranges'          => $ranges['valid'],
			'invalid_ranges'     => $ranges['invalid'],
			'domains'            => array_values( $domains ),
			'withheld_ranges'    => [],
			'withheld_domains'   => [],
			'subscription_id'    => 0,
			'subscription_note'  => '',
			'reason'             => '',
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
		// Report the institution's CURRENT rules, not the source data: after an
		// operator swaps in proxy egress IPs, re-listing the stale source ranges
		// would invite "restoring" the broken internal ranges.
		$existing_institution_id = self::find_migrated_institution( $team_id );
		if ( $existing_institution_id ) {
			$split_meta_list              = fn( $meta_key ) => array_values( array_filter( array_map( 'trim', explode( ',', (string) \get_post_meta( $existing_institution_id, Institution::META_PREFIX . $meta_key, true ) ) ) ) );
			$result['status']             = 'exists';
			$result['institution_id']     = $existing_institution_id;
			$result['institution_status'] = (string) \get_post_status( $existing_institution_id );
			$result['ip_ranges']          = $split_meta_list( 'ip_range' );
			$result['domains']            = $split_meta_list( 'email_domain' );
			$result['invalid_ranges']     = [];
			// Never applied — but supplied values never migrated are surfaced so a
			// re-run with a newly-obtained CSV can't be mistaken for a no-op. Diff
			// against the applied-at-creation record, NOT current rules: a value the
			// operator deliberately removed (internal range → proxy egress IP) must
			// not be re-suggested. Institutions migrated before the record existed
			// fall back to a current-rules diff.
			$split_list                   = fn( $value ) => array_values( array_filter( array_map( 'trim', explode( ',', (string) $value ) ) ) );
			$applied_ranges               = \metadata_exists( 'post', $existing_institution_id, self::APPLIED_IP_RANGES_META_KEY )
				? $split_list( \get_post_meta( $existing_institution_id, self::APPLIED_IP_RANGES_META_KEY, true ) )
				: $result['ip_ranges'];
			$applied_domains              = \metadata_exists( 'post', $existing_institution_id, self::APPLIED_DOMAINS_META_KEY )
				? $split_list( \get_post_meta( $existing_institution_id, self::APPLIED_DOMAINS_META_KEY, true ) )
				: $result['domains'];
			$result['withheld_ranges']    = array_values( array_diff( $ranges['valid'], $applied_ranges ) );
			$result['withheld_domains']   = array_values( array_diff( array_values( $domains ), $applied_domains ) );
			return $result;
		}

		if ( empty( $ranges['valid'] ) && empty( $domains ) ) {
			$reason_parts = [];
			if ( ! empty( $ranges['invalid'] ) ) {
				$reason_parts[] = sprintf( 'all %d IP range(s) invalid', count( $ranges['invalid'] ) );
			} else {
				$reason_parts[] = 'no IP ranges in the access-by-IP option';
			}
			$reason_parts[]   = $domains_csv_supplied ? 'no domains in the domains CSV' : 'no domains CSV supplied';
			$result['reason'] = implode( '; ', $reason_parts ) . '.';
			return $result;
		}

		$result['status'] = 'created';
		if ( ! $live ) {
			return $result;
		}

		$institution_id = Institution::create(
			// Raw title, not get_the_title(): Institution matching reads raw post
			// titles, and the_title filters could texturize the name.
			(string) \get_post_field( 'post_title', $team_id, 'raw' ),
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
		// Record what was actually applied, so re-run reporting can distinguish
		// genuinely-new source values from ones the operator later removed.
		\update_post_meta( $institution_id, self::APPLIED_IP_RANGES_META_KEY, implode( ',', $ranges['valid'] ) );
		\update_post_meta( $institution_id, self::APPLIED_DOMAINS_META_KEY, implode( ',', $domains ) );
		if ( $linked_subscription_id ) {
			\update_post_meta( $institution_id, self::MIGRATED_SUBSCRIPTION_META_KEY, (string) $linked_subscription_id );
		}

		$result['institution_id'] = (int) $institution_id;
		return $result;
	}
}
