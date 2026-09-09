<?php
/**
 * WP-CLI command to migrate WooCommerce Membership plans that restrict newsletter
 * lists to Newspack Access Control premium newsletter gates.
 *
 * Sibling of the migrate-membership-gates command: same grouping, titling and
 * verification shape, applied to the premium newsletter gate bucket instead of the
 * content gate bucket. A premium newsletter gate is an ordinary content gate
 * carrying `is_newsletter` post meta, which the evaluator uses to decide which
 * bucket a post is judged against — so migrating one is a matter of writing the
 * right rules and mode settings, not of authoring layouts.
 *
 * The class file is included on every request like the other CLI classes; only the
 * command registration is gated on WP_CLI.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Membership plan → Access Control premium newsletter gate migration CLI command.
 */
class Premium_Newsletters_Migration {
	use One_Time_Purchase_Migration;


	/**
	 * The newsletter list post type, used when Newspack Newsletters is not loaded.
	 *
	 * WooCommerce Memberships stores the post type name on the rule, so a rule
	 * written before the plugin was deactivated still names this CPT.
	 */
	const NEWSLETTER_LIST_CPT_FALLBACK = 'newspack_nl_list';

	/**
	 * Create or update Newspack Access Control premium newsletter gates from
	 * WooCommerce Membership plans.
	 *
	 * For each published plan carrying a newsletter-list content restriction rule,
	 * writes the equivalent premium newsletter gate: the plan's lists as the gate's
	 * restricted lists, and the plan's products as its paid access rules. Plans
	 * restricting the same lists are grouped and represented by a single gate whose
	 * title is all matching plan names joined with " | ".
	 *
	 * Registration mode is always activated; paid access mode only when every plan in
	 * the group requires a purchase (see group_requires_purchase()). The site-wide
	 * auto-signup setting is derived from the post-checkout signup modal. Settling
	 * that setting takes a full run: it is one option for the whole site, so a
	 * --plan run reports what it would be but never writes it.
	 *
	 * Dry-run by default; pass --live to write.
	 *
	 * Re-running overwrites a matching gate's content rules and mode settings, but
	 * never its layouts: this command does not author them, and a publisher may have
	 * customized one in Newsletters > Premium.
	 *
	 * Both modes surface predictable problems as WARN rows. On --live each written
	 * gate is re-read and checked against the conditions the frontend evaluator
	 * applies. Migrated gates stay dormant until WooCommerce Memberships is
	 * deactivated, so without this an unenforceable gate would look migrated for as
	 * long as it takes someone to notice at cutover.
	 *
	 * Every decision that needs an operator is settled before the first write: the
	 * groups are scanned, the problems are reported, and the one confirmation prompt
	 * is asked with nothing yet written. The write loop then runs to completion
	 * unattended, so a run that is interrupted at a prompt is a run that changed
	 * nothing.
	 *
	 * ## OPTIONS
	 *
	 * [--live]
	 * : Apply the changes. Without this flag the command runs as a dry-run and writes nothing.
	 *
	 * [--plan=<id>]
	 * : Only process the plan with this post ID. Useful for testing; never writes the site-wide auto-signup setting.
	 *
	 * [--one-time-duration=<duration>]
	 * : How long access lasts after a one-time purchase. Accepts "forever", "<n>days" or "<n>months". Each plan's own access length is read by default, so this is only needed for a plan whose access ends on a fixed calendar date, which is not a duration from the purchase. Applies to every such plan in the run.
	 *
	 * [--set-auto-signup]
	 * : Apply the derived auto-signup setting even though the site already has one set. Without this the command reports the difference and leaves the existing setting alone, because it is a publisher's choice rather than a migration artefact. A site that has never set it is written either way.
	 *
	 * [--yes]
	 * : Answer yes to the pre-flight confirmation prompt shown when gates would be created alongside gates the same plans were migrated to individually. Required for non-interactive runs (cron, `ssh host "wp ..."`): with no terminal to answer the prompt, the command errors out rather than exiting silently mid-migration.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack migrate-premium-newsletters
	 *     wp newspack migrate-premium-newsletters --live
	 *     wp newspack migrate-premium-newsletters --plan=711923
	 *     wp newspack migrate-premium-newsletters --one-time-duration=12months
	 *     wp newspack migrate-premium-newsletters --live --set-auto-signup
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 *
	 * @return void
	 */
	public function migrate_premium_newsletters( $args, $assoc_args ) {
		$dry_run = ! (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'live', false );

		// A bare value flag never reaches the validation below: WP-CLI warns and strips
		// it, so the command sees nothing at all — a bare --plan widens the run to every
		// plan on the site, and a bare --one-time-duration stops it over a duration the
		// operator did supply. The raw command line is the only place the mistake is
		// still visible.
		$bare_flags = self::get_valueless_value_flags();
		if ( ! empty( $bare_flags ) ) {
			WP_CLI::error( sprintf( 'The following flag(s) require a value but arrived without one: %s. WP-CLI strips a bare flag before the command runs, so the run would proceed as though it had never been passed — fix the invocation and re-run.', implode( ', ', $bare_flags ) ) );
		}

		// A mistyped --plan must never silently widen the run to every plan, so an
		// unusable value is a hard error rather than a fallback to "no filter".
		$plan_arg = \WP_CLI\Utils\get_flag_value( $assoc_args, 'plan', null );
		$plan_id  = 0;
		if ( null !== $plan_arg ) {
			if ( ! self::is_valid_plan_arg( $plan_arg ) ) {
				WP_CLI::error( sprintf( 'Invalid --plan value "%s". Pass a positive membership plan post ID.', $plan_arg ) );
			}
			$plan_id = (int) $plan_arg;
		}

		$duration_arg      = \WP_CLI\Utils\get_flag_value( $assoc_args, 'one-time-duration', null );
		$duration_override = null;
		if ( null !== $duration_arg ) {
			$duration_override = self::parse_one_time_duration( $duration_arg );
			if ( null === $duration_override ) {
				WP_CLI::error( sprintf( 'Invalid --one-time-duration value "%s". Pass "forever", or a positive number followed by "days" or "months" — for example 90days or 12months.', $duration_arg ) );
			}
		}

		if ( ! class_exists( 'Newspack\Content_Gate' ) ) {
			WP_CLI::error( 'Newspack\Content_Gate class not found. Is newspack-plugin active? Aborting.' );
		}
		if ( ! class_exists( 'Newspack\Content_Rules' ) ) {
			WP_CLI::error( 'Newspack\Content_Rules class not found. Is newspack-plugin active? Aborting.' );
		}
		if ( ! function_exists( 'wc_memberships' ) ) {
			WP_CLI::error( 'WooCommerce Memberships is not active. Aborting.' );
		}
		if ( ! class_exists( 'Newspack\Newsletters\Subscription_Lists' ) ) {
			WP_CLI::error( 'Newspack Newsletters is not active, so there are no newsletter lists to migrate. Aborting.' );
		}

		$dormant_gating = self::describe_inactive_gating(
			\Newspack\Content_Gate::is_newspack_feature_enabled(),
			\Newspack\Reader_Activation::is_enabled()
		);
		if ( null !== $dormant_gating ) {
			WP_CLI::warning( $dormant_gating );
		}

		if ( $dry_run ) {
			WP_CLI::line( '' );
			WP_CLI::line( '*** DRY RUN MODE — no data will be modified. Pass --live to write. ***' );
			WP_CLI::line( '' );
		}

		$plan_ids = self::get_plans( $plan_id );
		$total    = count( $plan_ids );

		if ( 0 === $total ) {
			WP_CLI::line( $plan_id ? sprintf( 'No published plan found with ID %d.', $plan_id ) : 'No published membership plans found.' );
			return;
		}

		WP_CLI::line( sprintf( 'Found %d membership plan(s). Starting migration…', $total ) );
		WP_CLI::line( '' );

		// Pre-load existing premium newsletter gates indexed by lower-cased title. Only
		// published gates are considered: the frontend enforces nothing else, so writing
		// into a draft or trashed title match would produce a gate that never restricts.
		$published_gates = \Newspack\Content_Gate::get_gates( \Newspack\Content_Gate::GATE_CPT, 'publish', true );
		$existing_gates  = [];
		foreach ( $published_gates as $gate ) {
			$existing_gates[ trim( strtolower( $gate['title'] ) ) ] = $gate['id'];
		}
		$duplicate_titles = self::find_duplicate_gate_titles( $published_gates );

		if ( ! empty( $duplicate_titles ) ) {
			WP_CLI::error(
				sprintf(
					'More than one published premium newsletter gate is titled %s. A gate is identified by its title here, so the run would update one and leave the other restricting the same lists with nothing to show it. Rename or retire the duplicate(s) and re-run. Nothing has been written.',
					implode( ', ', array_map( fn( $title ) => sprintf( '"%s"', $title ), $duplicate_titles ) )
				)
			);
		}

		$summary         = [];
		$skipped         = [];
		$migrated_lists  = [];
		$all_lists_plans = [];

		$plan_groups = self::group_plans_by_lists( $plan_ids, $skipped, $all_lists_plans );

		// Refused rather than reported: a plan restricting every list has no faithful
		// gate, and reporting it as skipped reads as "nothing to migrate here" when the
		// truth is the opposite ({@see restricts_all_lists()}).
		if ( ! empty( $all_lists_plans ) ) {
			WP_CLI::error(
				sprintf(
					'Plan(s) %s restrict every newsletter list rather than named ones, and WooCommerce Memberships extends such a rule to lists created later. A gate names the lists it covers, so migrating one would under-restrict from the first list added after. Name the lists on those plan(s) and re-run. Nothing has been written.',
					implode( ', ', array_map( fn( $name ) => sprintf( '"%s"', $name ), $all_lists_plans ) )
				)
			);
		}

		$group_count = count( $plan_groups );
		if ( $group_count < ( $total - count( $skipped ) ) ) {
			WP_CLI::line( sprintf( 'Grouped into %d gate(s) after deduplication.', $group_count ) );
			WP_CLI::line( '' );
		}

		// Pre-flight. Everything that can stop the run, or needs an answer from the
		// operator, is settled here — before the first write. Two same-named plan
		// groups are a hard error, and the one confirmation prompt is asked once for
		// the whole run. The write loop below therefore runs to completion without
		// reading STDIN, so it cannot be truncated part-way through by a prompt that
		// nobody is there to answer.
		// Payloads are built up front so every warning they carry is printed before the
		// prompt below, and so the write loop has nothing left to decide. Building one
		// reads the group and its product posts; it writes nothing.
		$payloads = array_map( fn( $group ) => self::build_gate_payload( $group, $duration_override ), $plan_groups );

		$collisions = self::find_colliding_gate_titles( $plan_groups );
		if ( ! empty( $collisions ) ) {
			WP_CLI::error(
				sprintf(
					'Two or more plan groups resolve to the same gate title: %s. A gate is identified by its title, so the second group would replace the first group\'s content rules outright and leave that group\'s lists behind no gate at all. Rename one of the plans and re-run. Nothing has been written.',
					implode( ', ', array_map( fn( $title ) => sprintf( '"%s"', $title ), $collisions ) )
				)
			);
		}

		$shared_lists = self::find_lists_shared_across_groups( $plan_groups );
		if ( ! empty( $shared_lists ) ) {
			$overlaps = [];
			foreach ( $shared_lists as $list_id => $titles ) {
				$overlaps[] = sprintf(
					'"%s" (list %d), restricted by %s',
					get_the_title( $list_id ),
					$list_id,
					implode( ' and ', array_map( fn( $title ) => sprintf( '"%s"', $title ), $titles ) )
				);
			}
			WP_CLI::error(
				sprintf(
					'These list(s) would end up behind more than one gate: %s. WooCommerce Memberships grants such a list to a holder of either plan; gates resolve the other way, so the stricter gate would decide and readers holding only the other plan would lose the list. Make the overlapping plans restrict the same set of lists, or move the shared list onto one of them, and re-run. Nothing has been written.',
					implode( '; ', $overlaps )
				)
			);
		}

		$needs_duration = [];
		foreach ( $payloads as $payload ) {
			if ( ! empty( $payload['one_time_ids'] ) && null === $payload['one_time_duration'] ) {
				$needs_duration = array_merge( $needs_duration, $payload['duration_plans'] );
			}
		}
		if ( ! empty( $needs_duration ) ) {
			WP_CLI::error(
				sprintf(
					'Plan(s) %s grant access through a one-time product, but their access ends on a fixed calendar date rather than lasting a set time from the purchase — so there is no duration for the gate to carry. Pass --one-time-duration=forever, --one-time-duration=<n>days or --one-time-duration=<n>months and re-run. Nothing has been written.',
					implode( ', ', array_map( fn( $name ) => sprintf( '"%s"', $name ), array_values( array_unique( $needs_duration ) ) ) )
				)
			);
		}

		foreach ( $payloads as $payload ) {
			self::report_product_id_issues( $payload );
			self::report_duration_conflict( $payload );
			self::report_dropped_paywall( $payload );
		}

		// Regrouping can merge plans a previous run migrated separately — most likely
		// after a --plan run, which writes a gate titled for that one plan. Gate
		// identity is the title, so the merged title matches no existing gate and this
		// run would create a new one while the originals stay published.
		// is_post_restricted() stops at the first gate that restricts, so a stale
		// stricter gate wins over the merged, more permissive one. Name them, and let
		// the operator stop before anything is created.
		$superseding = self::find_superseding_groups( $plan_groups, $existing_gates );
		foreach ( $superseding as $gate_title => $superseded ) {
			WP_CLI::warning(
				sprintf(
					'"%s" merges plans that were migrated separately before. Creating it leaves these gates in place, still restricting the same lists: %s. Retire them after this run.',
					$gate_title,
					implode(
						', ',
						array_map(
							fn( $title, $id ) => sprintf( '"%s" (gate %d)', $title, $id ),
							array_keys( $superseded ),
							$superseded
						)
					)
				)
			);
		}
		if ( ! empty( $superseding ) && ! $dry_run ) {
			self::confirm_or_error(
				sprintf(
					'Create %d gate(s) that supersede the gates named above? Answering no stops the run before anything is written.',
					count( $superseding )
				),
				$assoc_args,
				self::stdin_is_a_tty()
			);
		}

		// Every group's title is unique (the collision check above errors out
		// otherwise), so the write loop can treat a title as naming one gate.
		$titles_written = array_fill_keys( array_map( fn( $payload ) => trim( strtolower( $payload['title'] ) ), $payloads ), true );

		$progress = \WP_CLI\Utils\make_progress_bar( 'Migrating premium newsletter gates', $group_count );

		foreach ( $payloads as $payload ) {
			$progress->tick();

			$list_ids     = $payload['list_ids'];
			$gate_title   = $payload['title'];
			$gate_key     = trim( strtolower( $gate_title ) );
			$has_purchase = $payload['has_purchase'];
			$access_type  = $payload['access_type'];

			// A null gate ID means the gate does not exist yet; the summary prints it as
			// '(pending)' on a dry run, and the write path below fills it in on --live.
			$action  = array_key_exists( $gate_key, $existing_gates ) ? 'updated' : 'created';
			$gate_id = $existing_gates[ $gate_key ] ?? null;

			$write_error = '';
			if ( ! $dry_run ) {
				if ( null === $gate_id ) {
					$result = \Newspack\Content_Gate::create_gate(
						[
							'title'               => $payload['title'],
							'status'              => 'publish',
							'content_rules'       => $payload['content_rules'],
							'content_rules_match' => $payload['content_rules_match'],
							'registration'        => $payload['registration'],
							'custom_access'       => $payload['custom_access'],
						],
						\Newspack\Content_Gate::GATE_CPT,
						true
					);
					if ( \is_wp_error( $result ) ) {
						$write_error = $result->get_error_message();
					} else {
						$gate_id = $result;
					}
				} else {
					\Newspack\Content_Rules::update_gate_content_rules( $gate_id, $payload['content_rules'] );
					\Newspack\Content_Rules::update_gate_content_rules_match( $gate_id, $payload['content_rules_match'] );
					\Newspack\Content_Gate::update_registration_settings( $gate_id, $payload['registration'] );
					\Newspack\Content_Gate::update_custom_access_settings( $gate_id, $payload['custom_access'] );
				}
			}

			if ( '' !== $write_error ) {
				WP_CLI::warning( sprintf( 'Failed to create gate "%s": %s', $gate_title, $write_error ) );
				$summary[] = [
					'plan_name'   => $gate_title,
					'action'      => 'ERROR: ' . $write_error,
					'gate_id'     => '—',
					'lists'       => count( $list_ids ),
					'access_type' => $access_type,
				];
				continue;
			}

			// Only lists behind a gate this run actually wrote feed the site-wide
			// auto-signup derivation; a group whose write failed migrated nothing.
			$migrated_lists = array_merge( $migrated_lists, $list_ids );

			$verification_issues = [];
			if ( ! $dry_run && $gate_id ) {
				$verification_issues = self::verify_migrated_gate( $gate_id, $has_purchase );
				foreach ( $verification_issues as $issue ) {
					WP_CLI::warning( sprintf( '"%s" (gate %d) will not restrict as intended: %s', $gate_title, $gate_id, $issue ) );
				}
			} elseif ( $dry_run ) {
				$verification_issues = self::compute_pre_write_issues( $list_ids, $has_purchase, $payload['product_ids'], $gate_id );
				foreach ( $verification_issues as $issue ) {
					WP_CLI::warning( sprintf( '"%s" will not migrate correctly: %s', $gate_title, $issue ) );
				}
			}

			if ( ! empty( $verification_issues ) ) {
				$row_action = 'WARN: ' . implode( '; ', $verification_issues );
			} else {
				$row_action = $dry_run ? $action . ' (dry-run)' : $action;
			}

			$summary[] = [
				'plan_name'   => $gate_title,
				'action'      => $row_action,
				'gate_id'     => $gate_id ?? '(pending)',
				'lists'       => count( $list_ids ),
				'access_type' => $access_type,
			];
		}

		$progress->finish();
		WP_CLI::line( '' );

		WP_CLI::line( $dry_run ? '=== DRY RUN SUMMARY ===' : '=== MIGRATION SUMMARY ===' );
		WP_CLI::line( '' );

		\WP_CLI\Utils\format_items(
			'table',
			array_map(
				fn( $row ) => [
					'Plan Name'   => $row['plan_name'],
					'Action'      => $row['action'],
					'Gate ID'     => $row['gate_id'],
					'Lists'       => $row['lists'],
					'Access Type' => $row['access_type'],
				],
				array_merge( $summary, $skipped )
			),
			[ 'Plan Name', 'Action', 'Gate ID', 'Lists', 'Access Type' ]
		);

		WP_CLI::line( '' );
		$untouched_gates = self::report_stale_gates( $titles_written, $dry_run, (bool) $plan_id );

		WP_CLI::line( '' );
		self::report_auto_signup(
			array_values( array_unique( $migrated_lists ) ),
			$dry_run,
			(bool) $plan_id,
			self::count_incomplete_groups( $summary ),
			(bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'set-auto-signup', false ),
			$untouched_gates
		);

		WP_CLI::line( '' );
		$processed = count(
			array_filter(
				$summary,
				fn( $r ) => ! str_starts_with( $r['action'], 'ERROR' )
			)
		);
		$unenforceable = count(
			array_filter(
				$summary,
				fn( $r ) => str_starts_with( $r['action'], 'WARN' )
			)
		);
		WP_CLI::success(
			sprintf(
				'Done. %d gate(s) %s.',
				$processed,
				$dry_run ? 'would be created/updated' : 'created/updated'
			)
		);
		// Said on every run, not just a warning tail: the operator whose run is clean is
		// the one most likely to read the summary as "the gates are live now".
		WP_CLI::line( "These gates do not restrict anything yet, and they do not change anyone's newsletter subscriptions: both the restriction filter and the premium newsletter access check stand down while WooCommerce Memberships is active. They take effect when WooCommerce Memberships is deactivated, so check them before that, not after." );
		// Written but unenforceable is worse than not written at all — it looks
		// migrated. Call it out after the success line so it is not lost in the table.
		if ( $unenforceable ) {
			WP_CLI::warning(
				sprintf(
					'%d of those gate(s) will not restrict as intended (see the WARN rows above). Fix them before deactivating WooCommerce Memberships.',
					$unenforceable
				)
			);
		}
	}

	/**
	 * Value-requiring migrate-premium-newsletters flags found bare (no `=value`) on
	 * the raw command line.
	 *
	 * WP-CLI validates flags against the command synopsis before invoking the command:
	 * a bare `--plan` draws only a warning, then the flag is stripped and the command
	 * receives the flag's default — so the in-method guard against an unusable --plan
	 * value can never fire on a real invocation, and a run the operator scoped to one
	 * plan would silently widen to every plan on the site (and, under --live, write the
	 * site-wide auto-signup setting). Reading the raw argv is the only place the
	 * mistake is still visible.
	 *
	 * @param string[]|null $argv Raw argument vector; defaults to $_SERVER['argv'].
	 *
	 * @return string[] The value-requiring flags present without a value.
	 */
	public static function get_valueless_value_flags( $argv = null ): array {
		if ( null === $argv ) {
			$argv = isset( $_SERVER['argv'] ) ? (array) $_SERVER['argv'] : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		$value_flags = [ '--plan', '--one-time-duration' ];
		$bare_flags  = [];
		foreach ( $argv as $token ) {
			if ( in_array( $token, $value_flags, true ) ) {
				$bare_flags[] = $token;
			}
		}
		return array_values( array_unique( $bare_flags ) );
	}

	/**
	 * Whether a --plan value names a plan post ID.
	 *
	 * Uses ctype_digit() rather than is_numeric(): is_numeric() accepts '12.9' and '1e2',
	 * which cast to 12 and 100 — a run narrowed to a plan the operator never named.
	 * The value is cast to string first because ctype_digit() reads an integer
	 * argument between -128 and 255 as a character code, not as digits.
	 *
	 * @param mixed $plan_arg The raw --plan value.
	 *
	 * @return bool
	 */
	private static function is_valid_plan_arg( $plan_arg ): bool {
		return ctype_digit( (string) $plan_arg ) && (int) $plan_arg > 0;
	}

	/**
	 * Why gates written by this run would not enforce, or null when they will.
	 *
	 * Enforcement asks Content_Gate::is_gating_active(), which is
	 * is_newspack_feature_enabled() AND Reader_Activation::is_enabled():
	 * Content_Restriction_Control::is_post_restricted() returns early without both,
	 * and Premium_Newsletters::check_access() — the half that adds and removes list
	 * members at the ESP — bails on the same predicate. The feature constant alone is
	 * therefore not the question a preflight should ask: a site that defines it with
	 * Audience Management off gets gates that restrict nobody, and no warning.
	 *
	 * Both conditions are taken as arguments rather than read here, so the
	 * composition can be tested without define()ing a constant for the rest of the
	 * PHPUnit process.
	 *
	 * @param bool $feature_enabled          Whether Content_Gate::is_newspack_feature_enabled().
	 * @param bool $reader_activation_active Whether Reader_Activation::is_enabled().
	 *
	 * @return string|null The warning to print, or null when gating is active.
	 */
	private static function describe_inactive_gating( bool $feature_enabled, bool $reader_activation_active ): ?string {
		$missing = [];
		if ( ! $feature_enabled ) {
			$missing[] = 'the content gates feature (NEWSPACK_CONTENT_GATES) is not enabled';
		}
		if ( ! $reader_activation_active ) {
			$missing[] = 'Audience Management (Reader Activation) is not enabled';
		}
		if ( empty( $missing ) ) {
			return null;
		}
		return sprintf(
			'Gates will not enforce on this site: %s. Enforcement asks Content_Gate::is_gating_active(), which needs both — so the gates below will be written but stay dormant, restricting nobody and subscribing nobody, until that is fixed.',
			implode( ', and ', $missing )
		);
	}

	/**
	 * How many of this run's groups must be kept out of the auto-signup derivation.
	 *
	 * A group whose gate failed to write migrated nothing; a group reported as a WARN
	 * row wrote a gate the evaluator cannot enforce. Either way its lists are not
	 * behind a working gate, so the site-wide setting must not be derived as if they
	 * were. Counted from the summary rows rather than tracked through the write loop:
	 * the rows are what the operator is shown, and $migrated_lists is appended before
	 * verify_migrated_gate() runs, so it cannot tell the two apart on its own.
	 *
	 * @param array[] $summary The run's summary rows, each carrying an 'action'.
	 *
	 * @return int The number of rows that failed to write or will not enforce.
	 */
	private static function count_incomplete_groups( array $summary ): int {
		return count(
			array_filter(
				$summary,
				fn( $row ) => str_starts_with( $row['action'], 'ERROR' ) || str_starts_with( $row['action'], 'WARN' )
			)
		);
	}

	/**
	 * Whether a confirmation prompt could not be answered on this invocation.
	 *
	 * WP_CLI::confirm() reads STDIN with fgets(), which returns false at EOF; that
	 * trims to '' rather than 'y', so the command exits — with status 0 and no
	 * message. A run driven from a script or over SSH therefore stops at the prompt
	 * having already written everything before it, and reports success. Erroring out
	 * instead is the honest outcome, and --yes is the way to run unattended.
	 *
	 * @param array $assoc_args     The command's named args.
	 * @param bool  $stdin_is_a_tty Whether STDIN is an interactive terminal.
	 *
	 * @return bool True when the prompt must not be asked.
	 */
	private static function prompt_is_unanswerable( array $assoc_args, bool $stdin_is_a_tty ): bool {
		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false ) ) {
			return false;
		}
		return ! $stdin_is_a_tty;
	}

	/**
	 * Whether STDIN is an interactive terminal.
	 *
	 * STDIN is only defined under the CLI SAPI, so the constant is checked before it
	 * is read.
	 *
	 * @return bool
	 */
	private static function stdin_is_a_tty(): bool {
		return defined( 'STDIN' ) && stream_isatty( STDIN );
	}

	/**
	 * Ask the operator to confirm, or hard-error when nothing can answer.
	 *
	 * The terminal check is passed in rather than read here, so both branches can be
	 * exercised. STDIN is never a terminal under PHPUnit, which would otherwise leave
	 * the prompt itself untested.
	 *
	 * @param string $question       The yes/no question.
	 * @param array  $assoc_args     The command's named args, passed through to WP-CLI
	 *                               so --yes answers the prompt.
	 * @param bool   $stdin_is_a_tty Whether STDIN is a terminal.
	 *
	 * @return void
	 */
	private static function confirm_or_error( string $question, array $assoc_args, bool $stdin_is_a_tty ): void {
		if ( self::prompt_is_unanswerable( $assoc_args, $stdin_is_a_tty ) ) {
			WP_CLI::error(
				sprintf(
					'This run needs an answer to: "%s" — but STDIN is not a terminal, so the prompt would be answered for you and the command would stop without writing a summary. Re-run with --yes to answer yes up front, or run it from an interactive terminal.',
					$question
				)
			);
		}
		WP_CLI::confirm( $question, $assoc_args );
	}

	/**
	 * The gate title a plan group resolves to.
	 *
	 * @param array[] $group Plan descriptors, each carrying a 'name' key.
	 *
	 * @return string The group's plan names joined with " | ".
	 */
	private static function gate_title( array $group ): string {
		return implode( ' | ', array_column( $group, 'name' ) );
	}

	/**
	 * Gate titles that more than one plan group resolves to.
	 *
	 * Gate identity is the title, but groups are keyed by list fingerprint — so two
	 * same-named plans restricting different lists land in different groups and
	 * resolve to one title. The second group takes the update branch, and
	 * update_gate_content_rules() replaces rather than merges, so the first group's
	 * lists end up behind no gate at all while both rows report as processed. The
	 * collision is computable from the grouping alone, so the caller stops the run
	 * before anything is written.
	 *
	 * @param array<string,array> $plan_groups Map of fingerprint => plan descriptors.
	 *
	 * @return string[] The colliding titles, in the casing they were first seen with.
	 */
	private static function find_colliding_gate_titles( array $plan_groups ): array {
		$seen       = [];
		$collisions = [];
		foreach ( $plan_groups as $group ) {
			$title = self::gate_title( $group );
			$key   = trim( strtolower( $title ) );
			if ( isset( $seen[ $key ] ) ) {
				$collisions[ $key ] = $seen[ $key ];
				continue;
			}
			$seen[ $key ] = $title;
		}
		return array_values( $collisions );
	}

	/**
	 * Titles carried by more than one published gate.
	 *
	 * The mirror of {@see find_colliding_gate_titles()}, on the other side of the same
	 * assumption. Gate identity here is the title, but nothing in Content_Gate enforces
	 * one title per bucket, and two published gates are easy to name alike by hand in
	 * the editor. Indexing them by title is last-write-wins, so the run
	 * would update whichever came back last and leave the other published and still
	 * restricting the same lists. It is invisible to report_stale_gates() too, since
	 * its title is one this run wrote.
	 *
	 * @param array[] $gates Gate descriptors from Content_Gate::get_gates(), each
	 *                       carrying 'id' and 'title'.
	 *
	 * @return string[] The duplicated titles, in the casing they were first seen with.
	 */
	private static function find_duplicate_gate_titles( array $gates ): array {
		$seen       = [];
		$duplicates = [];
		foreach ( $gates as $gate ) {
			$gate_key = trim( strtolower( $gate['title'] ) );
			if ( isset( $seen[ $gate_key ] ) ) {
				$duplicates[ $gate_key ] = $seen[ $gate_key ];
				continue;
			}
			$seen[ $gate_key ] = $gate['title'];
		}
		return array_values( $duplicates );
	}

	/**
	 * Newsletter lists that more than one plan group restricts.
	 *
	 * Groups are keyed on the exact set of lists a plan restricts, so two plans that
	 * overlap without matching become two gates over a shared list. The two access
	 * models then disagree about that list: WooCommerce Memberships grants it to a
	 * holder of either plan, while gates resolve restrictive-wins, so the stricter
	 * gate decides and readers holding only the other plan lose it — and, once the
	 * access check is live, are unsubscribed at the provider. Grouping is the last
	 * point at which the two can still be compared with nothing written.
	 *
	 * @param array<string,array> $plan_groups Map of fingerprint => plan descriptors.
	 *
	 * @return array<int,string[]> Map of list post ID => gate titles restricting it,
	 *                             for lists reached by more than one group.
	 */
	private static function find_lists_shared_across_groups( array $plan_groups ): array {
		$groups_by_list = [];
		foreach ( $plan_groups as $group ) {
			// One entry per group rather than per plan: plans within a group share a
			// gate by construction, so only a list reached from two groups is ambiguous.
			foreach ( $group[0]['list_ids'] as $list_id ) {
				$groups_by_list[ (int) $list_id ][] = self::gate_title( $group );
			}
		}
		return array_filter( $groups_by_list, fn( $titles ) => count( $titles ) > 1 );
	}

	/**
	 * The gates each about-to-be-created group would supersede.
	 *
	 * Only groups whose title matches no existing gate are considered: a group that
	 * updates an existing gate supersedes nothing.
	 *
	 * @param array<string,array> $plan_groups    Map of fingerprint => plan descriptors.
	 * @param array               $existing_gates Map of lower-cased gate title => gate ID.
	 *
	 * @return array<string,array<string,int>> Map of group gate title => superseded
	 *                                         gate title => gate ID; empty when no
	 *                                         group supersedes anything.
	 */
	private static function find_superseding_groups( array $plan_groups, array $existing_gates ): array {
		$superseding = [];
		foreach ( $plan_groups as $group ) {
			$gate_title = self::gate_title( $group );
			$gate_key   = trim( strtolower( $gate_title ) );
			if ( array_key_exists( $gate_key, $existing_gates ) ) {
				continue;
			}
			$superseded = self::find_superseded_gates( $group, $gate_key, $existing_gates );
			if ( ! empty( $superseded ) ) {
				$superseding[ $gate_title ] = $superseded;
			}
		}
		return $superseding;
	}

	/**
	 * Build everything a plan group's gate needs, from the group alone.
	 *
	 * Reads only the group descriptors group_plans_by_lists() produced and the product
	 * posts they name, so it runs without WooCommerce Memberships. That is the point of
	 * the extraction: the three decisions below used to sit inline in a loop that cannot
	 * run without it, and so could not be unit-tested.
	 *
	 * - `content_rules_match` is 'any' because the gate carries exactly one content
	 *   rule, on which 'any' and 'all' agree. 'any' is the safer of the two to fix
	 *   here: should a second rule ever join it, 'any' restricts a post on either
	 *   list, while 'all' would restrict only posts on both and quietly open the rest.
	 * - Registration mode is always active because every plan this command migrates
	 *   grants membership to an account — a purchase plan to the purchasing account, a
	 *   signup plan to the registering one. Manual-only plans, the one shape that can
	 *   hold a reader who never registered, never reach here: group_plans_by_lists()
	 *   skips them. So requiring registration never restricts a reader the plan admitted.
	 * - The paid access mode carries a rule only when the group requires a purchase AND
	 *   at least one product ID survives, and it carries one rule group per kind of
	 *   product the plans grant on. An active mode with no rules asks for no purchase at
	 *   all, so the empty shape is left empty deliberately for verify_migrated_gate() and
	 *   compute_pre_write_issues() to flag, rather than papered over with a rule that
	 *   would grant more than the plan did.
	 * - A one-time rule needs a duration, so a group whose plans cannot supply one gets
	 *   no rule while its one-time product IDs stay in the payload. That shape never
	 *   reaches a write because migrate_premium_newsletters() refuses the run over it;
	 *   a caller that skipped that pre-flight would write a paid gate the plan's
	 *   one-time buyers cannot satisfy.
	 *
	 * @param array[]    $group             Plan descriptors from group_plans_by_lists(),
	 *                                      each carrying 'pid', 'name', 'access_method',
	 *                                      'list_ids', 'product_ids' and
	 *                                      'one_time_duration'.
	 * @param array|null $duration_override Operator-supplied one-time purchase duration,
	 *                                      or null to read each plan's own access length.
	 *
	 * @return array The gate payload: 'title', 'list_ids', 'has_purchase', 'dropped_paywalls',
	 *               'access_type',
	 *               'content_rules', 'content_rules_match', 'registration' and
	 *               'custom_access' are what the create and update paths write;
	 *               'product_ids', 'subscription_ids', 'one_time_ids',
	 *               'one_time_duration', 'duration_plans', 'duration_conflict',
	 *               'variation_ids' and 'dropped_product_ids' are what the pre-write
	 *               check and the warnings report on.
	 */
	private static function build_gate_payload( array $group, ?array $duration_override = null ): array {
		$list_ids     = $group[0]['list_ids'] ?? [];
		$has_purchase = self::group_requires_purchase( $group );

		$products    = self::resolve_product_ids( $group );
		$product_ids = $products['product_ids'];
		$duration    = self::resolve_group_duration( $group, $duration_override );

		$content_rules = [
			[
				'slug'  => 'newsletters',
				'value' => array_map( 'strval', $list_ids ),
			],
		];

		// Two rule groups rather than one, when a plan grants on both kinds of product.
		// Groups are OR'd and the rules inside one are AND'd, so a reader satisfies the
		// gate by holding the subscription or by having bought the one-time product —
		// which is what the plan granted. Flattening them into a single group would
		// demand both and admit nobody.
		$access_rules = [];
		if ( $has_purchase ) {
			if ( ! empty( $products['subscription_ids'] ) ) {
				$access_rules[] = [
					[
						'slug'  => 'subscription',
						'value' => $products['subscription_ids'],
					],
				];
			}
			if ( ! empty( $products['one_time_ids'] ) && null !== $duration['duration'] ) {
				$access_rules[] = [
					[
						'slug'  => 'one_time_purchase',
						'value' => array_merge(
							[ 'product_ids' => $products['one_time_ids'] ],
							$duration['duration']
						),
					],
				];
			}
		}

		return [
			'title'               => self::gate_title( $group ),
			'list_ids'            => $list_ids,
			'has_purchase'        => $has_purchase,
			'dropped_paywalls'    => $has_purchase
				? []
				: array_column( array_filter( $group, fn( $plan ) => 'purchase' === $plan['access_method'] ), 'name' ),
			'access_type'         => $has_purchase ? 'purchase' : 'signup',
			'content_rules'       => $content_rules,
			'content_rules_match' => 'any',
			'registration'        => [ 'active' => true ],
			'custom_access'       => [
				'active'       => $has_purchase,
				'access_rules' => $access_rules,
			],
			'product_ids'         => $product_ids,
			'subscription_ids'    => $products['subscription_ids'],
			'one_time_ids'        => $products['one_time_ids'],
			'one_time_duration'   => $duration['duration'],
			'duration_plans'      => $duration['plans'],
			'duration_conflict'   => $duration['conflict'],
			'variation_ids'       => $products['variations'],
			'dropped_product_ids' => $products['dropped'],
		];
	}

	/**
	 * Sort a group's raw product IDs into the ones a subscription rule can carry and
	 * the ones that must never reach it.
	 *
	 * Cast with intval rather than absint. Both give the ints the REST write path
	 * stores (raw `_product_ids` meta can hold strings), but absint() also turns a
	 * negative ID into a positive one, which would silently point the rule at a
	 * different, real product.
	 *
	 * Non-positive IDs are dropped because a rule value of 0 grants the gate to every
	 * paying reader: WC_Subscription::has_product() matches a line item when
	 * `$line_item['variation_id'] == $product_id`, and variation_id is 0 on every
	 * simple-product line item, so a value of [ 0 ] matches any active subscription.
	 * Nothing downstream catches that — verify_migrated_gate() sees a non-empty
	 * access_rules and reports the gate as sound.
	 *
	 * IDs that resolve to no product post are dropped too. Those fail safe on their
	 * own — a rule nothing can satisfy — but they leave the gate stricter than the plan
	 * was, so the caller warns rather than staying silent.
	 *
	 * Variation IDs are kept, unlike in the sibling content gate command.
	 * WC_Subscription::has_product() matches a line item on either its product_id or
	 * its variation_id, so the variation ID admits exactly the readers who bought that
	 * variation — which is what the plan granted. Substituting the parent would also
	 * admit holders of its sibling variations, and dropping the ID restricts readers
	 * the plan admitted, who Premium_Newsletters::check_access() then unsubscribes at
	 * cutover. The cost is that the gate editor's product picker is built from
	 * Access_Rules::get_subscription_products_options(), which lists parent products
	 * only, so a variation ID is not shown there and is lost if that field is re-saved.
	 *
	 * @param array[] $group Plan descriptors, each carrying a 'product_ids' key.
	 *
	 * @return array 'product_ids' are the surviving IDs, in the order they appeared;
	 *               'subscription_ids' and 'one_time_ids' partition them by the gate
	 *               rule that can carry each; 'variations' is the subset of them that
	 *               are product variations;
	 *               'dropped' holds 'invalid' (did not normalize to a positive integer —
	 *               a non-numeric meta value therefore appears as 0) and 'unresolvable'
	 *               (no product post with that ID).
	 */
	private static function resolve_product_ids( array $group ): array {
		$raw = array_merge( ...array_values( array_column( $group, 'product_ids' ) ) );

		$product_ids  = [];
		$invalid      = [];
		$unresolvable = [];
		$variations   = [];

		foreach ( array_values( array_unique( array_map( 'intval', $raw ) ) ) as $product_id ) {
			if ( $product_id <= 0 ) {
				$invalid[] = $product_id;
				continue;
			}
			$post_type = \get_post_type( $product_id );
			if ( 'product_variation' === $post_type ) {
				$variations[] = $product_id;
			} elseif ( 'product' !== $post_type ) {
				$unresolvable[] = $product_id;
				continue;
			}
			$product_ids[] = $product_id;
		}

		$classified = self::classify_product_ids( $product_ids );

		return [
			'product_ids'      => $product_ids,
			'subscription_ids' => $classified['subscription'],
			'one_time_ids'     => $classified['one_time'],
			'variations'       => $variations,
			'dropped'          => [
				'invalid'      => $invalid,
				'unresolvable' => $unresolvable,
			],
		];
	}

	/**
	 * Warn about the product IDs the gate payload dropped, and about the variation IDs
	 * it kept.
	 *
	 * Plain warnings rather than WARN rows: none of these stop the gate being written,
	 * and a group that loses every product is caught separately by
	 * compute_pre_write_issues() and verify_migrated_gate().
	 *
	 * All three describe a paid access rule, so all three are silent for a group that
	 * writes none. A mixed group still collects product IDs, and saying its gate
	 * "keeps variation ID(s)" or would have granted access to every subscriber
	 * describes a rule that was never written. What that group actually lost is
	 * reported by {@see report_dropped_paywall()} instead.
	 *
	 * @param array $payload A build_gate_payload() result.
	 *
	 * @return void
	 */
	private static function report_product_id_issues( array $payload ): void {
		if ( empty( $payload['has_purchase'] ) ) {
			return;
		}
		if ( ! empty( $payload['dropped_product_ids']['invalid'] ) ) {
			WP_CLI::warning(
				sprintf(
					'"%s": dropped product ID(s) %s, which are not positive integers. Writing one would grant the gate to every reader with an active subscription, because a subscription line item matches a rule value of 0. Check the plan\'s products.',
					$payload['title'],
					implode( ', ', $payload['dropped_product_ids']['invalid'] )
				)
			);
		}
		if ( ! empty( $payload['dropped_product_ids']['unresolvable'] ) ) {
			WP_CLI::warning(
				sprintf(
					'"%s": dropped product ID(s) %s, which resolve to no product (deleted?). A rule naming them could never be satisfied, so the gate would be stricter than the plan was. Check the plan\'s products.',
					$payload['title'],
					implode( ', ', $payload['dropped_product_ids']['unresolvable'] )
				)
			);
		}
		if ( ! empty( $payload['variation_ids'] ) ) {
			WP_CLI::warning(
				sprintf(
					'"%s": its paid access rule keeps product variation ID(s) %s, which is what the plan granted. The gate editor\'s product picker lists parent products only, so they are not shown there — and re-saving that field in the editor drops them. Leave it alone unless you mean to change what the gate grants.',
					$payload['title'],
					implode( ', ', $payload['variation_ids'] )
				)
			);
		}
	}

	/**
	 * Warn when a group keeps the free-signup plan's terms and drops a paid plan's.
	 *
	 * Both of the checks that would otherwise catch an unenforced paywall sit behind
	 * the group requiring a purchase, which a mixed group does not, so this is the
	 * only place the operator hears about it. Keeping the most permissive requirement
	 * is still the faithful migration ({@see group_requires_purchase()}) — the point
	 * is that lists someone was paying for stop being paid-only at cutover, and that
	 * is a decision to make deliberately.
	 *
	 * @param array $payload The gate payload.
	 *
	 * @return void
	 */
	private static function report_dropped_paywall( array $payload ): void {
		if ( empty( $payload['dropped_paywalls'] ) ) {
			return;
		}
		WP_CLI::warning(
			sprintf(
				'"%s": plan(s) %s require a purchase, but they restrict the same lists as a plan that grants on signup, so this gate asks only for registration. WooCommerce Memberships grants those lists to a holder of either plan, so keeping the purchase requirement would have taken them from the signup plan\'s members — but it does mean these lists stop being paid-only once WooCommerce Memberships is deactivated. Move the plans onto different lists if the paywall matters more.',
				$payload['title'],
				implode( ', ', array_map( fn( $name ) => sprintf( '"%s"', $name ), $payload['dropped_paywalls'] ) )
			)
		);
	}

	/**
	 * Warn when a group's plans granted one-time access for different lengths.
	 *
	 * The gate stores one duration, so the command picks the longest and says so.
	 * Staying silent would leave an operator to discover at cutover that a gate
	 * grants longer than the plan they are reading it against.
	 *
	 * @param array $payload The gate payload.
	 *
	 * @return void
	 */
	private static function report_duration_conflict( array $payload ): void {
		if ( empty( $payload['duration_conflict'] ) ) {
			return;
		}
		WP_CLI::warning(
			sprintf(
				'"%s": its plans grant one-time access for different lengths — %s. WooCommerce Memberships grants access from any one of them, so the shortest would have taken the list from readers the plans admitted.',
				$payload['title'],
				$payload['duration_conflict']
			)
		);
	}

	/**
	 * The newsletter list post type.
	 *
	 * Read from Newspack Newsletters when it is loaded so the two stay in step. The
	 * literal fallback is unreachable in practice — the command's preflight hard-errors
	 * when Subscription_Lists is missing — but it keeps the helper correct on its own
	 * terms for any caller that reaches it outside that flow.
	 *
	 * @return string The list post type.
	 */
	private static function get_list_cpt(): string {
		if ( class_exists( 'Newspack\Newsletters\Subscription_Lists' ) ) {
			$cpt = \Newspack\Newsletters\Subscription_Lists::CPT;
			if ( $cpt ) {
				return $cpt;
			}
		}
		return self::NEWSLETTER_LIST_CPT_FALLBACK;
	}

	/**
	 * Collect the newsletter list IDs a plan's content restriction rules select.
	 *
	 * Non-newsletter rules are ignored: they belong to the content gate the sibling
	 * command writes, and their object IDs would be read as list IDs here. The
	 * result feeds the gate's single 'newsletters' content rule, whose values the
	 * evaluator compares directly against a list post ID — so WooCommerce's object
	 * IDs carry across without translation.
	 *
	 * @param \WC_Memberships_Membership_Plan_Rule[] $wc_rules Array of WC Memberships rules.
	 *
	 * @return int[] Deduplicated newsletter list post IDs, in the order WC returned them.
	 */
	private static function extract_list_ids( array $wc_rules ): array {
		$list_cpt = self::get_list_cpt();
		$list_ids = [];
		foreach ( $wc_rules as $rule ) {
			if ( $list_cpt !== $rule->get_content_type_name() ) {
				continue;
			}
			foreach ( $rule->get_object_ids() as $object_id ) {
				$list_ids[] = (int) $object_id;
			}
		}
		return array_values( array_unique( array_filter( $list_ids ) ) );
	}

	/**
	 * Whether a plan restricts every newsletter list rather than named ones.
	 *
	 * WooCommerce Memberships spells "all lists" as a newsletter rule carrying no
	 * object IDs, and applies it to every list of that post type — lists created
	 * after the plan was written included. A gate names the lists it covers, so
	 * there is no faithful snapshot of a rule whose membership keeps growing:
	 * migrating one would quietly under-restrict from the first list added after.
	 *
	 * @param \WC_Memberships_Membership_Plan_Rule[] $wc_rules Array of WC Memberships rules.
	 *
	 * @return bool
	 */
	private static function restricts_all_lists( array $wc_rules ): bool {
		$list_cpt = self::get_list_cpt();
		foreach ( $wc_rules as $rule ) {
			if ( $list_cpt === $rule->get_content_type_name() && ! $rule->has_objects() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Compute a canonical fingerprint for a set of newsletter list IDs.
	 *
	 * Groups plans that restrict the same lists so they share one gate. Sorting
	 * makes the fingerprint independent of the order WooCommerce returned the rules
	 * in, so an incidental ordering difference cannot split one gate into two.
	 *
	 * @param int[] $list_ids Newsletter list post IDs.
	 *
	 * @return string Canonical fingerprint.
	 */
	private static function compute_list_fingerprint( array $list_ids ): string {
		$normalised = array_values( array_unique( array_map( 'intval', $list_ids ) ) );
		sort( $normalised, SORT_NUMERIC );
		$fingerprint = \wp_json_encode( $normalised );
		// The values are integers, so a delimiter-joined string is an adequate
		// fallback; the fingerprint is an internal grouping key, never decoded.
		return $fingerprint ? $fingerprint : implode( ',', $normalised );
	}

	/**
	 * Whether a plan group should migrate to a purchase-gated gate.
	 *
	 * True only when every plan in the group requires a purchase. The two gate modes
	 * compose with AND for a logged-in reader — registration mode passes them, then
	 * custom_access restricts them unless they hold a subscription — so activating
	 * paid access on a group that also holds a signup plan would demand the
	 * subscription from everyone. WooCommerce Memberships grants access to a holder
	 * of either plan, so the signup plan's members would silently lose their lists
	 * at cutover. Keeping the most-permissive plan's requirement is the faithful
	 * migration.
	 *
	 * @param array[] $group Plan descriptors, each carrying an 'access_method' key.
	 *
	 * @return bool
	 */
	private static function group_requires_purchase( array $group ): bool {
		return ! array_filter( $group, fn( $g ) => 'purchase' !== $g['access_method'] );
	}

	/**
	 * Existing gates this group's plans were migrated to individually.
	 *
	 * Gate identity is the gate title, and a group's title is its plan names joined.
	 * When regrouping merges plans a previous run migrated separately, the merged
	 * title matches no existing gate — so the run creates a new gate while the
	 * originals stay published and keep restricting the same lists. Naming them lets
	 * the operator retire them.
	 *
	 * @param array[] $group          Plan descriptors, each carrying a 'name' key.
	 * @param string  $gate_key       The group's own lower-cased gate title.
	 * @param array   $existing_gates Map of lower-cased gate title => gate ID.
	 *
	 * @return array<string,int> Map of gate title => gate ID, excluding the group's own title.
	 */
	private static function find_superseded_gates( array $group, string $gate_key, array $existing_gates ): array {
		$superseded = [];
		$seen       = [];
		foreach ( $group as $plan ) {
			// Matched on the lower-cased title, but returned under the title as written:
			// the operator is being asked to find these gates in Newsletters > Premium,
			// where they carry their own casing.
			$plan_key = trim( strtolower( $plan['name'] ) );
			if ( $plan_key === $gate_key || isset( $seen[ $plan_key ] ) ) {
				continue;
			}
			if ( isset( $existing_gates[ $plan_key ] ) ) {
				$seen[ $plan_key ]           = true;
				$superseded[ $plan['name'] ] = $existing_gates[ $plan_key ];
			}
		}
		return $superseded;
	}

	/**
	 * Published premium newsletter gates whose titles this run did not write.
	 *
	 * @param array[]            $gates        Gate descriptors from Content_Gate::get_gates(),
	 *                                         each carrying 'id' and 'title'.
	 * @param array<string,bool> $written_keys Lower-cased gate titles this run wrote, as keys.
	 *
	 * @return array[] The untouched gates, each [ 'id' => int, 'title' => string ].
	 */
	private static function find_stale_newsletter_gates( array $gates, array $written_keys ): array {
		$stale = [];
		foreach ( $gates as $gate ) {
			$key = trim( strtolower( $gate['title'] ?? '' ) );
			if ( isset( $written_keys[ $key ] ) ) {
				continue;
			}
			$stale[] = [
				'id'    => (int) $gate['id'],
				'title' => (string) ( $gate['title'] ?? '' ),
			];
		}
		return $stale;
	}

	/**
	 * Name every published premium newsletter gate this run left untouched.
	 *
	 * A gate this run did not write is a gate no current plan accounts for: the plans
	 * behind it were renamed, regrouped, unpublished or deleted since the gate was
	 * created. It keeps restricting its lists regardless, and is_post_restricted()
	 * stops at the first gate that restricts — so a stale, stricter gate beats the
	 * gate this run wrote. On a newsletter gate that is not a paywall:
	 * Premium_Newsletters::check_access() unsubscribes the reader from the list at the
	 * ESP, so a reader silently loses a newsletter they pay for. Only the operator can
	 * tell which of these are wanted, so they are named rather than touched.
	 *
	 * The gate list is re-read rather than taken from the pre-loop snapshot. Either
	 * source gives the same answer — every gate this run created carries a title in
	 * $written_keys and is filtered out — so a warm get_gates() cache cannot change
	 * the outcome.
	 *
	 * A --plan run is skipped rather than reported. It writes at most one title, so
	 * every other gate on the site comes back as stale: the run cannot compute
	 * staleness, and a list of gates an operator has no reason to retire trains them
	 * to skim the warning that matters on a full run.
	 *
	 * @param array<string,bool> $written_keys Lower-cased gate titles this run wrote, as keys.
	 * @param bool               $dry_run      Whether this is a dry run.
	 * @param bool               $plan_scoped  Whether the run was narrowed with --plan.
	 *
	 * @return bool Whether any published gate this run did not write is still restricting.
	 *              False on a --plan run, which cannot tell.
	 */
	private static function report_stale_gates( array $written_keys, bool $dry_run, bool $plan_scoped = false ): bool {
		if ( $plan_scoped ) {
			WP_CLI::line( 'Skipping the untouched-gate check: a --plan run writes one gate, so it cannot tell which of the others no plan accounts for. Re-run without --plan to see them.' );
			return false;
		}
		$stale = self::find_stale_newsletter_gates(
			\Newspack\Content_Gate::get_gates( \Newspack\Content_Gate::GATE_CPT, 'publish', true ),
			$written_keys
		);
		if ( empty( $stale ) ) {
			return false;
		}
		WP_CLI::warning(
			sprintf(
				'%d published premium newsletter gate(s) %s by this run, and still restrict their lists: %s. The first gate that restricts wins, so one of these can override a gate this run wrote — and a restricted premium newsletter unsubscribes the reader at the ESP. Check each one and retire the ones no plan accounts for.',
				count( $stale ),
				$dry_run ? 'would not be written' : 'were not written',
				implode(
					', ',
					array_map(
						fn( $gate ) => sprintf( '"%s" (gate %d)', $gate['title'], $gate['id'] ),
						$stale
					)
				)
			)
		);
		return true;
	}

	/**
	 * Resolve a newsletter list's public (ESP) list ID.
	 *
	 * The post type is checked first because Subscription_List's constructor does not
	 * check it: it throws only when the post does not exist, so a live post of any
	 * other type would construct and hand back a bogus public ID. The guard is what
	 * makes a stale or mistyped ID return null instead.
	 *
	 * @param int $list_id The list post ID.
	 *
	 * @return string|null The public list ID, or null when it cannot be resolved.
	 */
	private static function get_public_id( int $list_id ): ?string {
		if ( \get_post_type( $list_id ) !== self::get_list_cpt() ) {
			return null;
		}
		if ( ! class_exists( 'Newspack\Newsletters\Subscription_List' ) ) {
			return null;
		}
		try {
			$list = new \Newspack\Newsletters\Subscription_List( $list_id );
		} catch ( \Throwable $e ) {
			return null;
		}
		$public_id = $list->get_public_id();
		return $public_id ? (string) $public_id : null;
	}

	/**
	 * The public list IDs shown in the post-checkout newsletter signup modal.
	 *
	 * Mirrors the lookup the pre-Access-Control WooCommerce Memberships integration
	 * used to decide which lists to leave to reader opt-in.
	 *
	 * With custom lists off the set is empty because there is no post-checkout modal
	 * at all: Reader_Activation::render_newsletters_signup_modal() returns early on
	 * is_newsletters_signup_available(), which is literally
	 * `(bool) self::get_setting( 'use_custom_lists' )`. No modal means no list was
	 * left to reader opt-in, so there is no carve-out. (It is
	 * get_available_newsletter_lists() that falls back to every list when custom
	 * lists are off, and that serves the registration form, not this modal — reading
	 * the saved selection here would carve out lists no reader was offered at
	 * checkout.)
	 *
	 * The stored selection is read raw, without checking it against
	 * Newspack_Newsletters_Subscription::get_lists_config(), which is what the modal
	 * actually renders from. A list that has since been unpublished, or otherwise
	 * dropped from that live set, still counts as a modal list here even though the
	 * modal would no longer show it. That gap is left in place because of the
	 * direction it errs in: over-counting a list as modal can only push
	 * derive_auto_signup() toward OFF or toward the undecided value that writes
	 * nothing, never toward ON — so the operator is left to opt readers in
	 * deliberately rather than the migration silently auto-enrolling someone who was
	 * never actually offered that list. (get_lists_config() is not a remote call: it
	 * reads local Subscription_List CPT posts, and is_active() is a plain
	 * post_status check; it returns a WP_Error only when no ESP provider is
	 * configured at all — that is not a failure mode this derivation needs to guard
	 * against.)
	 *
	 * @return string[] Public list IDs.
	 */
	private static function get_modal_public_ids(): array {
		if ( ! method_exists( 'Newspack\Reader_Activation', 'get_settings' ) ) {
			return [];
		}
		$settings = \Newspack\Reader_Activation::get_settings();
		if ( empty( $settings['use_custom_lists'] ) || empty( $settings['newsletter_lists'] ) ) {
			return [];
		}
		$public_ids = [];
		foreach ( $settings['newsletter_lists'] as $list ) {
			if ( isset( $list['id'] ) ) {
				$public_ids[] = (string) $list['id'];
			}
		}
		return array_values( array_unique( $public_ids ) );
	}

	/**
	 * Derive the site-wide auto-signup setting from the restricted lists.
	 *
	 * Before Access Control, activating a membership auto-subscribed the member to
	 * every list the plan restricted, except lists shown in the post-checkout signup
	 * modal, which were left to reader opt-in. `newspack_premium_newsletters_auto_signup`
	 * is a single site-wide option, so that per-list distinction only survives when
	 * every restricted list falls on the same side. A split returns a null value:
	 * one flag cannot express it, and either guess has a victim — on subscribes
	 * readers who opted out, off drops readers who expected the list.
	 *
	 * A list whose public ID cannot be resolved is reported separately and counted as
	 * non-modal, matching the pre-Access-Control default.
	 *
	 * @param int[] $list_ids The restricted newsletter list post IDs.
	 *
	 * @return array{value: bool|null, modal: int[], non_modal: int[], unresolved: int[]}
	 */
	private static function derive_auto_signup( array $list_ids ): array {
		$modal_public_ids = self::get_modal_public_ids();
		$modal            = [];
		$non_modal        = [];
		$unresolved       = [];

		foreach ( $list_ids as $list_id ) {
			$list_id   = (int) $list_id;
			$public_id = self::get_public_id( $list_id );
			if ( null === $public_id ) {
				$unresolved[] = $list_id;
				$non_modal[]  = $list_id;
				continue;
			}
			if ( in_array( $public_id, $modal_public_ids, true ) ) {
				$modal[] = $list_id;
			} else {
				$non_modal[] = $list_id;
			}
		}

		if ( empty( $modal ) && empty( $non_modal ) ) {
			$value = null;
		} elseif ( empty( $modal ) ) {
			$value = true;
		} elseif ( empty( $non_modal ) ) {
			$value = false;
		} else {
			$value = null;
		}

		return [
			'value'      => $value,
			'modal'      => $modal,
			'non_modal'  => $non_modal,
			'unresolved' => $unresolved,
		];
	}

	/**
	 * Which of the given IDs are not newsletter list posts.
	 *
	 * The evaluator matches a 'newsletters' rule by comparing the list post's own ID
	 * against the rule values, so an ID belonging to a deleted post or to something
	 * that is not a list matches nothing and leaves that list open.
	 *
	 * @param int[] $list_ids Newsletter list post IDs.
	 *
	 * @return int[] The IDs that do not resolve to a newsletter list.
	 */
	private static function list_ids_that_do_not_resolve( array $list_ids ): array {
		$list_cpt = self::get_list_cpt();
		$missing  = [];
		foreach ( $list_ids as $list_id ) {
			if ( \get_post_type( (int) $list_id ) !== $list_cpt ) {
				$missing[] = (int) $list_id;
			}
		}
		return $missing;
	}

	/**
	 * Describe how many of a gate's restricted lists fail to resolve.
	 *
	 * Shared by the live and dry-run passes so both report the same wording.
	 *
	 * @param int[] $list_ids     The gate's restricted list IDs.
	 * @param int[] $unresolvable The subset that does not resolve.
	 *
	 * @return string|null The problem, or null when every list resolves.
	 */
	private static function describe_unresolvable_lists( array $list_ids, array $unresolvable ): ?string {
		if ( empty( $unresolvable ) ) {
			return null;
		}
		if ( count( $unresolvable ) === count( $list_ids ) ) {
			return sprintf(
				'none of its restricted lists (%s) exist as newsletter lists',
				implode( ', ', $unresolvable )
			);
		}
		return sprintf(
			'%d of its %d restricted lists (%s) do not exist as newsletter lists, so those lists stay unrestricted',
			count( $unresolvable ),
			count( $list_ids ),
			implode( ', ', $unresolvable )
		);
	}

	/**
	 * Why an active gate mode's layout leaves the gate unable to do its job, or null
	 * when the mode is inactive or its layout is sound.
	 *
	 * A layout ID of 0 is the load-bearing case. Content_Restriction_Control::
	 * is_post_restricted() ends each gate's turn on `if ( $is_restricted &&
	 * $gate_layout_id )`, and both Content_Gate settings getters always return a
	 * gate_layout_id key defaulting to (int) 0 — so the `?? $gate['id']` fallbacks
	 * beside that assignment never fire, and a mode with no layout makes the gate
	 * restrict nothing at all while looking migrated. create_gate() can leave it 0
	 * without telling the caller: create_gate_layout() returns a WP_Error when the
	 * insert fails, the settings are then written without the key, and create_gate()
	 * returns the gate ID rather than the error.
	 *
	 * A layout ID naming no live published post is the milder case: the ID is truthy,
	 * so the gate does restrict, but the layout is gone from under it and
	 * get_inline_gate_content_for_post() falls back to a hard-coded default paragraph
	 * instead of the publisher's layout. The sibling command treats a layout post it
	 * cannot write to as disqualifying in the same way
	 * ({@see Membership_Gates_Migration::apply_layout()}).
	 *
	 * @param bool   $active    Whether the mode is active.
	 * @param int    $layout_id The mode's gate_layout_id.
	 * @param string $label     The mode's name, as it should read in the message.
	 *
	 * @return string|null The problem, or null when there is none.
	 */
	private static function describe_layout_problem( bool $active, int $layout_id, string $label ): ?string {
		if ( ! $active ) {
			return null;
		}
		if ( $layout_id <= 0 ) {
			return sprintf( 'its %s mode is active but points at no gate layout, so the evaluator passes the gate over and it restricts nothing', $label );
		}
		$status = \get_post_status( $layout_id );
		if ( ! $status ) {
			return sprintf( 'its %s layout (post %d) no longer exists, so readers get a default gate rather than the one the publisher wrote', $label, $layout_id );
		}
		if ( 'publish' !== $status ) {
			return sprintf( 'its %s layout (post %d) is %s rather than published', $label, $layout_id, $status );
		}
		return null;
	}

	/**
	 * Re-read a freshly written gate and report why it would fail to restrict.
	 *
	 * Mirrors the conditions Content_Restriction_Control::get_post_gates() and
	 * is_post_restricted() apply to a newsletter list post, so a gate that passes
	 * here is one the evaluator can act on for the readers the source plan
	 * restricted. Migrated gates stay dormant until WooCommerce Memberships is
	 * deactivated, so without this an unenforceable gate would look migrated for as
	 * long as it takes someone to notice at cutover.
	 *
	 * Each active mode's layout is checked too. Content_Gate::create_gate() seeds both
	 * layout posts, but only when their inserts succeed: it drops a WP_Error from
	 * create_gate_layout(), writes the mode's settings without a gate_layout_id, and
	 * still returns the gate ID — so a creation that looks successful can leave a gate
	 * whose layout ID is 0, and a gate with no layout restricts nothing. An existing
	 * gate can also have lost its layout post since it was written.
	 * {@see describe_layout_problem()} for both mechanisms.
	 *
	 * @param int  $gate_id      The gate post ID.
	 * @param bool $has_purchase Whether every plan behind this gate requires a purchase.
	 *
	 * @return string[] Human-readable problems; empty when the gate is enforceable.
	 */
	private static function verify_migrated_gate( int $gate_id, bool $has_purchase = false ): array {
		$issues = [];

		if ( 'publish' !== \get_post_status( $gate_id ) ) {
			$issues[] = 'the gate is not published';
		}

		if ( ! \get_post_meta( $gate_id, 'is_newsletter', true ) ) {
			$issues[] = 'it is missing the is_newsletter flag, so the evaluator judges list posts against the content gate bucket and this gate never applies';
		}

		$content_rules = \Newspack\Content_Rules::get_gate_content_rules( $gate_id );
		$list_ids      = [];
		foreach ( $content_rules as $content_rule ) {
			if ( 'newsletters' === ( $content_rule['slug'] ?? '' ) ) {
				$list_ids = array_merge( $list_ids, array_map( 'intval', (array) ( $content_rule['value'] ?? [] ) ) );
			}
		}
		$list_ids = array_values( array_unique( $list_ids ) );

		if ( empty( $list_ids ) ) {
			// get_gate_content_rules() drops rules with an empty value, so a gate can be
			// written with rules and still evaluate as having none — say which it is.
			$written_rules = \get_post_meta( $gate_id, 'content_rules', true );
			$issues[]      = empty( $written_rules )
				? 'it has no content rules'
				: 'none of its content rules select a newsletter list';
		} else {
			$unresolvable = self::describe_unresolvable_lists( $list_ids, self::list_ids_that_do_not_resolve( $list_ids ) );
			if ( null !== $unresolvable ) {
				$issues[] = $unresolvable;
			}
		}

		$registration  = \Newspack\Content_Gate::get_registration_settings( $gate_id );
		$custom_access = \Newspack\Content_Gate::get_custom_access_settings( $gate_id );
		if ( empty( $registration['active'] ) && empty( $custom_access['active'] ) ) {
			$issues[] = 'neither the registration nor the paid access mode is active';
		}

		$layout_problems = [
			self::describe_layout_problem( ! empty( $registration['active'] ), (int) $registration['gate_layout_id'], 'registration' ),
			self::describe_layout_problem( ! empty( $custom_access['active'] ), (int) $custom_access['gate_layout_id'], 'paid access' ),
		];
		$issues          = array_merge( $issues, array_values( array_filter( $layout_problems ) ) );

		// A plan that required a purchase must migrate to a gate that gates on the
		// purchase. Registration mode alone stops nobody who has an account, so a paid
		// plan whose paid access mode is missing or unconstrained turns into a premium
		// list any reader can join by registering a free account.
		if ( $has_purchase ) {
			if ( empty( $custom_access['active'] ) ) {
				$issues[] = 'it migrates a plan that requires a purchase, but its paid access mode is not active — any registered reader would keep the list';
			} elseif ( empty( $custom_access['access_rules'] ) ) {
				// No rule at all is the benign shape of this failure. A rule carrying an
				// EMPTY value would be worse: Access_Rules::has_active_subscription() with an
				// empty product list falls through to "any active subscription", so it grants
				// access instead of denying it. build_gate_payload() emits either [] or a rule
				// with a non-empty value, so that shape cannot occur today — do not relax its
				// per-kind `! empty()` guards without handling it here.
				$issues[] = 'its paid access mode is active but has no access rules, so it asks for no purchase — any registered reader would keep the list';
			}
		}

		return $issues;
	}

	/**
	 * Predict migration issues from group data alone, without writing anything.
	 *
	 * The computable subset of verify_migrated_gate(). Called in dry-run mode so the
	 * planning pass surfaces the same warnings --live would.
	 *
	 * Layouts are only computable for a group whose gate already exists: its layout
	 * IDs can be read now, and the update path leaves them alone (both settings
	 * updaters merge over the stored meta). A group that would CREATE its gate is not
	 * predictable — create_gate() makes the layout posts as part of the write, and
	 * whether those inserts succeed is not knowable until it runs — so the dry run
	 * says nothing about those rather than guessing. The mode flags mirror
	 * build_gate_payload(): registration is always activated, paid access only for a
	 * purchase group.
	 *
	 * @param int[]    $list_ids     The group's restricted list IDs.
	 * @param bool     $has_purchase Whether every plan in the group requires a purchase.
	 * @param int[]    $product_ids  The product IDs build_gate_payload() kept for the paid access mode.
	 * @param int|null $gate_id      The existing gate this group would update, or null when it would create one.
	 *
	 * @return string[] Human-readable problems; empty when no issues are predicted.
	 */
	private static function compute_pre_write_issues( array $list_ids, bool $has_purchase, array $product_ids, ?int $gate_id = null ): array {
		$issues = [];

		$unresolvable = self::describe_unresolvable_lists( $list_ids, self::list_ids_that_do_not_resolve( $list_ids ) );
		if ( null !== $unresolvable ) {
			$issues[] = $unresolvable;
		}

		if ( $has_purchase && empty( $product_ids ) ) {
			$issues[] = 'its paid access mode will have no access rules (no usable product IDs remain), so it will ask for no purchase — any registered reader would keep the list';
		}

		if ( $gate_id ) {
			$registration    = \Newspack\Content_Gate::get_registration_settings( $gate_id );
			$custom_access   = \Newspack\Content_Gate::get_custom_access_settings( $gate_id );
			$layout_problems = [
				self::describe_layout_problem( true, (int) $registration['gate_layout_id'], 'registration' ),
				self::describe_layout_problem( $has_purchase, (int) $custom_access['gate_layout_id'], 'paid access' ),
			];
			$issues          = array_merge( $issues, array_values( array_filter( $layout_problems ) ) );
		}

		return $issues;
	}

	/**
	 * Get all published WooCommerce Membership plans, optionally filtered by ID.
	 *
	 * @param int $plan_id Optional plan ID to filter by.
	 *
	 * @return int[] Array of plan post IDs.
	 */
	private static function get_plans( int $plan_id = 0 ): array {
		$args = [
			'post_type'      => 'wc_membership_plan',
			'post_status'    => 'publish',
			'posts_per_page' => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging -- Operator-run CLI command; unbounded by design.
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		];
		if ( $plan_id ) {
			$args['p'] = $plan_id;
		}
		return \get_posts( $args );
	}

	/**
	 * The summary row for a manual-only plan, and the warning its restricted lists
	 * earn.
	 *
	 * Manual-only plans are skipped: membership is assigned by hand, so there is no
	 * purchase or registration for a gate's rules to key on. The lists such a plan
	 * restricts do not go away with it, though. At cutover they either go open to
	 * every reader, or — if another plan's gate restricts the same lists — that gate
	 * judges this plan's members unentitled and Premium_Newsletters::check_access()
	 * unsubscribes them at the ESP. Which of the two happens cannot be read off the
	 * table, so the lists are named and the operator decides.
	 *
	 * Takes the list IDs as an argument, which forces the caller to extract them
	 * before deciding to skip: the row used to be built ahead of the extraction and
	 * reported "—" whether the plan restricted five lists or none.
	 *
	 * @param string $plan_name The plan name.
	 * @param int[]  $list_ids  The newsletter lists the plan restricts.
	 *
	 * @return array The skipped-plan summary row.
	 */
	private static function report_manual_only_plan( string $plan_name, array $list_ids ): array {
		if ( ! empty( $list_ids ) ) {
			WP_CLI::warning(
				sprintf(
					'"%s" is manual-only, so it is skipped and nothing migrates the %d newsletter list(s) it restricts: %s. At cutover those lists go open to every reader unless another plan\'s gate restricts them — and if one does, that gate unsubscribes this plan\'s members at the ESP. Decide what should happen to them before deactivating WooCommerce Memberships.',
					$plan_name,
					count( $list_ids ),
					implode( ', ', $list_ids )
				)
			);
		}
		return [
			'plan_name'   => $plan_name,
			'action'      => 'skipped (manual-only)',
			'gate_id'     => '—',
			'lists'       => count( $list_ids ),
			'access_type' => '—',
		];
	}

	/**
	 * Group published plans by the set of newsletter lists they restrict.
	 *
	 * Manual-only plans (which have no content gates) and plans that restrict no
	 * newsletter list are collected into $skipped instead of grouped. Plans
	 * restricting the same lists share a group, and therefore a single gate.
	 *
	 * @param int[] $plan_ids        Plan post IDs.
	 * @param array $skipped         Skipped-plan summary rows, appended to by reference.
	 * @param array $all_lists_plans Names of plans restricting every list, appended to by
	 *                               reference. The caller refuses the run over these.
	 *
	 * @return array<string,array> Map of fingerprint => list of plan descriptors, each
	 *                             [ 'pid', 'name', 'access_method', 'list_ids', 'product_ids',
	 *                             'one_time_duration' ].
	 */
	private static function group_plans_by_lists( array $plan_ids, array &$skipped, array &$all_lists_plans ): array {
		$plan_groups = [];

		foreach ( $plan_ids as $pid ) {
			// The factory validates the post and lets WC Memberships integrations
			// substitute their own plan subclasses, which direct construction bypasses.
			$plan = \wc_memberships_get_membership_plan( $pid );
			if ( ! $plan ) {
				$skipped[] = [
					'plan_name'   => sprintf( '(plan %d)', $pid ),
					'action'      => 'skipped (not a valid membership plan)',
					'gate_id'     => '—',
					'lists'       => '—',
					'access_type' => '—',
				];
				continue;
			}
			$plan_name     = $plan->get_name();
			$access_method = $plan->get_access_method();

			// Extracted before the manual-only skip rather than after it: a skipped plan's
			// lists are exactly what the operator has to act on ({@see report_manual_only_plan()}).
			$rules    = $plan->get_content_restriction_rules();
			$list_ids = self::extract_list_ids( $rules );

			if ( 'manual-only' === $access_method ) {
				$skipped[] = self::report_manual_only_plan( $plan_name, $list_ids );
				continue;
			}

			// Checked after the manual-only skip, not before it: those plans write no
			// gate, so an unbounded rule on one costs nothing.
			if ( self::restricts_all_lists( $rules ) ) {
				$all_lists_plans[] = $plan_name;
				continue;
			}

			if ( empty( $list_ids ) ) {
				$skipped[] = [
					'plan_name'   => $plan_name,
					'action'      => 'skipped (restricts no newsletter list)',
					'gate_id'     => '—',
					'lists'       => '0',
					'access_type' => $access_method,
				];
				continue;
			}

			$fingerprint                   = self::compute_list_fingerprint( $list_ids );
			$plan_groups[ $fingerprint ][] = [
				'pid'               => $pid,
				'name'              => $plan_name,
				'access_method'     => $access_method,
				'list_ids'          => $list_ids,
				'product_ids'       => 'purchase' === $access_method ? array_values( $plan->get_product_ids() ) : [],
				'one_time_duration' => 'purchase' === $access_method ? self::derive_one_time_duration( $plan ) : null,
			];
		}

		return $plan_groups;
	}

	/**
	 * Derive, report, and (in live mode) write the site-wide auto-signup setting.
	 *
	 * A site that has never set the option is written without ceremony: there is no
	 * choice to override, and a zero-touch migration is the whole point. A site that
	 * has one set is left alone and told about the difference instead. The stored
	 * value and the default are both readable as "on", so a run that overwrote them
	 * alike would silently undo a publisher who had turned auto-signup off — and
	 * turning it back on subscribes their readers to lists at the provider, which is
	 * not something to do on an inference. --set-auto-signup is how an operator says
	 * they mean it.
	 *
	 * A run in which any group failed to write, or was reported as a WARN row, takes
	 * the same report-only path. The derivation is over the lists this run migrated,
	 * and a group that wrote nothing — or wrote a gate the evaluator cannot enforce —
	 * keeps its lists out of that view. Dropping lists can only collapse a
	 * disagreement into a determinate value, never the reverse, and the option
	 * defaults to ON: so the shape that does damage is a modal group succeeding while
	 * a non-modal group fails, which derives false and turns auto-signup off
	 * site-wide from a partial view. Members then stop being auto-subscribed to lists
	 * they should get, with nothing to show for it. Re-runs are idempotent, so fixing
	 * the failure and running again settles the setting.
	 *
	 * The boundary is this run's summary rows. A plan skipped before any write is not
	 * counted here; a manual-only plan's lists are named in their own warning instead
	 * ({@see report_manual_only_plan()}).
	 *
	 * A --plan run is the exception: the option is site-wide, but the derivation only
	 * sees the lists that one plan restricts. If the site's other lists sit on the
	 * other side of the modal split, writing it would flip a global setting from a
	 * partial view — turning auto-signup on for readers who declined those lists at
	 * checkout, or off for readers who expected them. So a --plan run reports the
	 * derivation and says why it is not written; settling the setting takes a full run.
	 *
	 * @param int[] $list_ids          All newsletter list IDs this run migrated.
	 * @param bool  $dry_run           Whether this is a dry run.
	 * @param bool  $plan_scoped       Whether the run was narrowed to a single plan with --plan.
	 * @param int   $incomplete_groups How many groups failed to write or will not enforce
	 *                                 ({@see count_incomplete_groups()}).
	 * @param bool  $force             Whether --set-auto-signup was passed, allowing the
	 *                                 run to overwrite a setting the site already has.
	 * @param bool  $untouched_gates   Whether published gates this run did not write are
	 *                                 still restricting lists of their own
	 *                                 ({@see report_stale_gates()}).
	 *
	 * @return void
	 */
	private static function report_auto_signup( array $list_ids, bool $dry_run, bool $plan_scoped = false, int $incomplete_groups = 0, bool $force = false, bool $untouched_gates = false ): void {
		$derived = self::derive_auto_signup( $list_ids );
		// A sentinel default rather than 1: get_option() cannot otherwise tell a stored
		// "on" from an absent option, and those two mean different things here.
		$stored  = \get_option( 'newspack_premium_newsletters_auto_signup', null );
		$is_set  = null !== $stored;
		$current = $is_set ? (bool) $stored : true;

		if ( ! empty( $derived['unresolved'] ) ) {
			WP_CLI::warning(
				sprintf(
					'Could not resolve an ESP list for list(s) %s, so they are treated as auto-signup lists. Confirm them in Newsletters > Premium.',
					implode( ', ', $derived['unresolved'] )
				)
			);
		}

		if ( null === $derived['value'] ) {
			if ( ! empty( $derived['modal'] ) && ! empty( $derived['non_modal'] ) ) {
				WP_CLI::warning(
					sprintf(
						'Auto-signup is one site-wide setting, but these lists disagree: %s appear in the post-checkout signup modal (auto-signup off), while %s do not (auto-signup on). Leaving it %s — set it in Newsletters > Premium.',
						implode( ', ', $derived['modal'] ),
						implode( ', ', $derived['non_modal'] ),
						$current ? 'on' : 'off'
					)
				);
				return;
			}
			// Nothing to derive from at all. Said out loud rather than passed over in
			// silence: a run where every group failed reaches here, and the absence of an
			// auto-signup line reads exactly like a run that had nothing to change.
			WP_CLI::warning(
				sprintf(
					'Auto-signup was not derived: this run migrated no newsletter lists%s. Leaving it %s — check it in Newsletters > Premium.',
					$incomplete_groups ? sprintf( ', because all %d gate(s) it touched either failed to write or will not enforce', $incomplete_groups ) : '',
					$current ? 'on' : 'off'
				)
			);
			return;
		}

		$derived_label = $derived['value'] ? 'on' : 'off';
		$current_label = $current ? 'on' : 'off';

		if ( $derived['value'] === $current ) {
			WP_CLI::line( sprintf( 'Auto-signup is already %s; leaving it unchanged.', $current_label ) );
			return;
		}
		// Reported before the dry-run branch so a --plan --live operator, who has every
		// reason to expect a write, is told which of the two reasons applies.
		if ( $plan_scoped ) {
			WP_CLI::line(
				sprintf(
					'Auto-signup derives to %s from this plan\'s lists (currently %s), but a --plan run never writes it: the setting is site-wide, and one plan\'s lists cannot stand for the rest of the site\'s. Re-run without --plan to settle it.',
					$derived_label,
					$current_label
				)
			);
			return;
		}
		// Before the dry-run branch for the same reason as the --plan case above, and so
		// a dry run predicts the suppression instead of promising a write.
		if ( $incomplete_groups ) {
			WP_CLI::line(
				sprintf(
					'Auto-signup derives to %s from the lists this run migrated (currently %s), but %d gate(s) failed to write or will not enforce, so their lists are missing from that view — and a missing list can only turn a genuine disagreement into a decision. Leaving the setting alone: fix those gate(s) and re-run to settle it.',
					$derived_label,
					$current_label,
					$incomplete_groups
				)
			);
			return;
		}
		// The option governs get_restricted_lists(), the union of every published
		// premium gate's lists, while the derivation sees only the lists this run
		// migrated. A gate this run did not write contributes lists to the first and
		// not the second, which is the partial view the method's own reasoning warns
		// about, so the same report-only path applies.
		if ( $untouched_gates ) {
			WP_CLI::line(
				sprintf(
					'Auto-signup derives to %s from the lists this run migrated (currently %s), but the gate(s) named above are still restricting lists this run never saw, and the setting governs those too. Leaving it alone: retire or migrate them and re-run to settle it.',
					$derived_label,
					$current_label
				)
			);
			return;
		}
		// Before the dry-run branch for the same reason as the two cases above: a dry
		// run should predict the suppression rather than promise a write.
		if ( $is_set && ! $force ) {
			WP_CLI::warning(
				sprintf(
					'Auto-signup derives to %s from the lists this run migrated, but the site already has it set to %s — a setting someone chose, which this run has no way to tell from one it inherited. Leaving it alone: set it in Newsletters > Premium, or re-run with --set-auto-signup to apply the derived value.',
					$derived_label,
					$current_label
				)
			);
			return;
		}
		if ( $dry_run ) {
			WP_CLI::line(
				sprintf(
					'Auto-signup would be set to %s (currently %s%s).',
					$derived_label,
					$current_label,
					$is_set ? '' : ', never set'
				)
			);
			return;
		}
		\update_option( 'newspack_premium_newsletters_auto_signup', $derived['value'] ? 1 : 0, false );
		WP_CLI::line(
			sprintf(
				'Auto-signup set to %s (%s).',
				$derived_label,
				$is_set ? sprintf( 'was %s', $current_label ) : 'it had never been set'
			)
		);
	}
}
