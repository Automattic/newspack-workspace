<?php
/**
 * WP-CLI commands to migrate WooCommerce Memberships content restriction to
 * Newspack Access Control: `migrate-membership-gates` for the plans and their
 * content gate layouts, `migrate-post-exemptions` for the per-post "public"
 * overrides those plans never carried.
 *
 * Ported from the standalone `migrate-memberships` drop-in so the tooling ships
 * with the plugin. The class file is included on every request (like the other
 * CLI classes), but only the command registration is gated on WP_CLI, so the
 * runtime cost outside CLI is a class definition. `migrate-membership-gates` writes
 * through the Content_Gate / Content_Rules data layer; `migrate-post-exemptions` is
 * postmeta plumbing and touches neither.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Memberships → Access Control content restriction migration CLI commands.
 */
class Membership_Gates_Migration {
	use One_Time_Purchase_Migration;

	/**
	 * Create or update Newspack Access Control content gates from WooCommerce
	 * Membership plans.
	 *
	 * Plans with identical content restriction rules are grouped and represented by
	 * a single gate whose title is all matching plan names joined with " | ". Plans
	 * with different restrictions each get their own gate.
	 *
	 * For each gate (group of plans):
	 * - Creates a new content gate (or updates an existing one matched by title).
	 * - Sets content rules from the shared restriction rules, other than the ones
	 *   selecting newsletter lists.
	 * - Enables registration settings (always) and custom_access settings (only
	 *   when every plan in the group requires a purchase — a group that also holds a
	 *   signup plan is registration-gated, since either plan grants access in WCM).
	 * - Copies block content from the first plan's np_memberships_gate post (falling
	 *   back to the Primary gate) into the gate's registration / paid-access layouts.
	 *
	 * Newsletter list restrictions are not migrated here. A plan can restrict articles
	 * and newsletter lists at once, and the two halves live in different gate buckets:
	 * this command writes the article half, and `wp newspack
	 * migrate-premium-newsletters` writes the list half. A plan that restricts only
	 * lists is skipped with a row saying so, but a plan that restricts both migrates
	 * here and reports as a plain success, so run both commands on any site whose
	 * plans gate newsletters.
	 *
	 * Dry-run by default; pass --live to write.
	 *
	 * Both modes surface predictable migration issues as WARN rows. Purchase-mode
	 * gaps (no custom_access layout found, no usable product IDs)
	 * and content-rule slugs the evaluator cannot resolve are computable from the
	 * group data before any write, so they appear in dry-run and make the planning
	 * pass predictive rather than optimistic. On --live each written gate is
	 * additionally re-read and checked against the full set of conditions the frontend
	 * evaluator applies; any gate that would not restrict — or would restrict less
	 * than the plan it came from, e.g. a paid plan that migrated to a gate any
	 * registered reader passes — is reported as a WARN row rather than counted as a
	 * plain success. Migrated gates stay dormant until WooCommerce Memberships is
	 * deactivated, so without this an unenforceable gate would look migrated for as
	 * long as it takes someone to notice at cutover.
	 *
	 * Re-running is NOT edit-preserving: an existing gate matched by title has its
	 * content rules and layout content overwritten with freshly extracted markup, so
	 * any customization made in the admin after a previous run is lost. A layout is
	 * left alone when nothing could be extracted for it, so an empty extraction never
	 * blanks a working layout.
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
	 * : Only process the plan with this post ID. Useful for testing.
	 *
	 * [--one-time-duration=<duration>]
	 * : How long access lasts after a one-time purchase. Accepts "forever", "<n>days" or "<n>months". Each plan's own access length is read by default, so this is only needed for a plan whose access ends on a fixed calendar date, which is not a duration from the purchase. Applies to every such plan in the run.
	 *
	 * [--yes]
	 * : Answer yes to the pre-flight confirmation prompt shown when gates would be created alongside gates the same plans were migrated to individually. Required for non-interactive runs (cron, `ssh host "wp ..."`): with no terminal to answer the prompt, the command errors out rather than exiting silently mid-migration.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack migrate-membership-gates
	 *     wp newspack migrate-membership-gates --live
	 *     wp newspack migrate-membership-gates --plan=711923
	 *     wp newspack migrate-membership-gates --one-time-duration=12months
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 *
	 * @return void
	 */
	public function migrate_membership_gates( $args, $assoc_args ) {
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

		// Pre-flight checks.
		if ( ! class_exists( 'Newspack\Content_Gate' ) ) {
			WP_CLI::error( 'Newspack\Content_Gate class not found. Is newspack-plugin active? Aborting.' );
		}
		if ( ! class_exists( 'Newspack\Content_Rules' ) ) {
			WP_CLI::error( 'Newspack\Content_Rules class not found. Is newspack-plugin active? Aborting.' );
		}
		if ( ! function_exists( 'wc_memberships' ) ) {
			WP_CLI::error( 'WooCommerce Memberships is not active. Aborting.' );
		}

		if ( $dry_run ) {
			WP_CLI::line( '' );
			WP_CLI::line( '*** DRY RUN MODE — no data will be modified. Pass --live to write. ***' );
			WP_CLI::line( '' );
		}

		// Fetch plans.
		$plan_ids = self::get_plans( $plan_id );
		$total    = count( $plan_ids );

		if ( 0 === $total ) {
			WP_CLI::line( $plan_id ? sprintf( 'No published plan found with ID %d.', $plan_id ) : 'No published membership plans found.' );
			return;
		}

		WP_CLI::line( sprintf( 'Found %d membership plan(s). Starting migration…', $total ) );
		WP_CLI::line( '' );

		// Pre-load existing gates indexed by lower-cased title. Only published gates
		// are considered: the frontend enforces nothing else, so writing into a
		// draft/trashed title match would produce a gate that never restricts.
		$published_gates = \Newspack\Content_Gate::get_gates( \Newspack\Content_Gate::GATE_CPT, 'publish' );
		$existing_gates  = [];
		foreach ( $published_gates as $gate ) {
			$existing_gates[ trim( strtolower( $gate['title'] ) ) ] = $gate['id'];
		}
		$duplicate_titles = self::find_duplicate_gate_titles( $published_gates );
		if ( ! empty( $duplicate_titles ) ) {
			WP_CLI::error(
				sprintf(
					'More than one published content gate is titled %s. A gate is identified by its title here, so the run would update one and leave the other restricting the same content with nothing to show it. Rename or retire the duplicate(s) and re-run. Nothing has been written.',
					implode( ', ', array_map( fn( $title ) => sprintf( '"%s"', $title ), $duplicate_titles ) )
				)
			);
		}

		$summary = [];
		$skipped = [];

		// Phase 1: group plans by content-rule fingerprint.
		$plan_groups = self::group_plans_by_fingerprint( $plan_ids, $skipped );

		$group_count = count( $plan_groups );
		if ( $group_count < ( $total - count( $skipped ) ) ) {
			WP_CLI::line( sprintf( 'Grouped into %d gate(s) after deduplication.', $group_count ) );
			WP_CLI::line( '' );
		}

		// Phase 2: pre-flight. Everything that can stop the run, or needs an answer
		// from the operator, is settled here — before the first write. Two same-named
		// plan groups are a hard error, and the one confirmation prompt is asked once
		// for the whole run. The write loop below therefore runs to completion without
		// reading STDIN, so it cannot be truncated part-way through by a prompt that
		// nobody is there to answer.
		$collisions = self::find_colliding_gate_titles( $plan_groups );
		if ( ! empty( $collisions ) ) {
			WP_CLI::error(
				sprintf(
					'Two or more plan groups resolve to the same gate title: %s. A gate is identified by its title, so the second group would replace the first group\'s content rules and layouts outright and leave that group\'s content behind no gate at all. Rename one of the plans and re-run. Nothing has been written.',
					implode( ', ', array_map( fn( $title ) => sprintf( '"%s"', $title ), $collisions ) )
				)
			);
		}

		// Product resolution reads the group and its product posts and writes nothing,
		// so it runs here — putting every warning it raises in front of the prompt. The
		// whole classified result is kept, not just the surviving IDs: the write loop
		// needs the split by product kind to know which access rules to build.
		$products_by_group  = [];
		$durations_by_group = [];
		foreach ( $plan_groups as $fingerprint => $group ) {
			$gate_title                         = self::gate_title( $group );
			$products_by_group[ $fingerprint ]  = self::resolve_product_ids( $group );
			$durations_by_group[ $fingerprint ] = self::resolve_group_duration( $group, $duration_override );
			self::report_dropped_product_ids( $gate_title, $products_by_group[ $fingerprint ]['dropped'], self::group_requires_purchase( $group ) );
			self::report_duration_conflict( $gate_title, $durations_by_group[ $fingerprint ]['conflict'] );
		}

		// A one-time product with no duration has no rule to write, and a gate that
		// silently drops the plan's only paid access rule lets any registered reader in.
		// Named and refused before the first write, so the operator can supply the
		// duration rather than discover the gap at cutover.
		$needs_duration = [];
		foreach ( $plan_groups as $fingerprint => $group ) {
			if ( ! empty( $products_by_group[ $fingerprint ]['one_time_ids'] ) && null === $durations_by_group[ $fingerprint ]['duration'] ) {
				$needs_duration = array_merge( $needs_duration, $durations_by_group[ $fingerprint ]['plans'] );
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

		// Regrouping can merge plans a previous run migrated separately. Gate identity
		// is the title, so the merged title matches no existing gate and this run would
		// write a new one while the originals stay published and keep restricting. Name
		// them, and let the operator stop before anything is created.
		$superseding = self::find_superseding_groups( $plan_groups, $existing_gates );
		foreach ( $superseding as $superseding_title => $superseded ) {
			WP_CLI::warning(
				sprintf(
					'"%s" merges plans that were migrated separately before. Creating it leaves these gates in place, still restricting the same content: %s. Retire them after this run.',
					$superseding_title,
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

		// Phase 3: create/update one gate per group. Every group's title is unique (the
		// collision check above errors out otherwise), so a title names one gate.
		$progress = \WP_CLI\Utils\make_progress_bar( 'Migrating gates', $group_count );

		foreach ( $plan_groups as $fingerprint => $group ) {
			$progress->tick();

			$layout_errors = [];
			$ac_rules      = $group[0]['ac_rules'];
			$gate_title    = self::gate_title( $group );
			$gate_key      = trim( strtolower( $gate_title ) );
			$has_purchase  = self::group_requires_purchase( $group );
			$access_type   = $has_purchase ? 'purchase' : 'signup';

			$products           = $products_by_group[ $fingerprint ];
			$merged_product_ids = $products['product_ids'];

			// A null gate ID means the gate does not exist yet; the summary prints it as
			// '(pending)' on a dry run, and the write path below fills it in on --live.
			$action  = array_key_exists( $gate_key, $existing_gates ) ? 'updated' : 'created';
			$gate_id = $existing_gates[ $gate_key ] ?? null;

			// Resolve layout content — try each plan in the group for a plan-specific gate.
			$memberships_gate = null;
			$group_plan_count = count( $group );
			foreach ( $group as $i => $group_plan ) {
				$is_last          = ( $i === $group_plan_count - 1 );
				$memberships_gate = self::get_memberships_gate_for_plan( $group_plan['pid'], $is_last );
				if ( $memberships_gate ) {
					break;
				}
			}
			$layouts = $memberships_gate
				? self::extract_gate_layouts( $memberships_gate )
				: [
					'registration'  => '',
					'custom_access' => null,
				];

			if ( ! $dry_run ) {
				if ( null === $gate_id ) {
					$result = \Newspack\Content_Gate::create_gate( [ 'title' => $gate_title ] );
					if ( \is_wp_error( $result ) ) {
						WP_CLI::warning( sprintf( 'Failed to create gate "%s": %s', $gate_title, $result->get_error_message() ) );
						$summary[] = [
							'plan_name'     => $gate_title,
							'action'        => 'ERROR: ' . $result->get_error_message(),
							'gate_id'       => '—',
							'content_rules' => '—',
							'access_type'   => $access_type,
						];
						continue;
					}
					$gate_id = $result;
				}

				// Set content rules. WooCommerce Memberships restricts the *union* of a
				// plan's restriction rules, while the gate evaluator defaults an unset
				// match mode to AND — so the mode has to be written explicitly or every
				// multi-rule plan under-gates after cutover.
				\Newspack\Content_Rules::update_gate_content_rules( $gate_id, $ac_rules );
				\Newspack\Content_Rules::update_gate_content_rules_match( $gate_id, 'any' );

				// Registration layout (always).
				if ( ! self::apply_layout( $gate_id, $gate_title, 'registration', $layouts['registration'] ) ) {
					$layout_errors[] = 'registration layout';
				}

				// Custom access layout — only when every plan in the group requires a
				// purchase (see $has_purchase). A mixed group is left registration-gated.
				if ( $has_purchase && null !== $layouts['custom_access'] ) {
					$access_rules = self::build_access_rules( $products, $durations_by_group[ $fingerprint ]['duration'] );
					if ( ! self::apply_layout( $gate_id, $gate_title, 'custom_access', $layouts['custom_access'], $access_rules ) ) {
						$layout_errors[] = 'paid access layout';
					}
				}
			}

			// Verification. In live mode, the written gate is re-read against the full
			// set of conditions the frontend evaluator applies — an unenforceable gate
			// that looks migrated could otherwise go unnoticed until cutover. In
			// dry-run mode, a computable pre-write subset of the same checks runs off
			// the group data so the planning pass is predictive rather than optimistic:
			// purchase-mode gaps and unresolvable content-rule slugs surface before
			// anything is written.
			$verification_issues = [];
			if ( ! $dry_run && empty( $layout_errors ) && $gate_id ) {
				$verification_issues = self::verify_migrated_gate( $gate_id, $has_purchase );
				foreach ( $verification_issues as $issue ) {
					WP_CLI::warning( sprintf( '"%s" (gate %d) will not restrict as intended: %s', $gate_title, $gate_id, $issue ) );
				}
			} elseif ( $dry_run ) {
				$verification_issues = self::compute_pre_write_issues( $ac_rules, $has_purchase, $layouts, $merged_product_ids );
				foreach ( $verification_issues as $issue ) {
					WP_CLI::warning( sprintf( '"%s" will not migrate correctly: %s', $gate_title, $issue ) );
				}
			}

			if ( ! empty( $layout_errors ) ) {
				$row_action = 'ERROR: could not write ' . implode( ' + ', $layout_errors );
			} elseif ( ! empty( $verification_issues ) ) {
				$row_action = 'WARN: ' . implode( '; ', $verification_issues );
			} else {
				$row_action = $dry_run ? $action . ' (dry-run)' : $action;
			}

			$summary[] = [
				'plan_name'     => $gate_title,
				'action'        => $row_action,
				'gate_id'       => $gate_id ?? '(pending)',
				'content_rules' => count( $ac_rules ),
				'access_type'   => $access_type,
			];
		}

		$progress->finish();
		WP_CLI::line( '' );

		// Summary table.
		WP_CLI::line( $dry_run ? '=== DRY RUN SUMMARY ===' : '=== MIGRATION SUMMARY ===' );
		WP_CLI::line( '' );

		\WP_CLI\Utils\format_items(
			'table',
			array_map(
				fn( $row ) => [
					'Plan Name'     => $row['plan_name'],
					'Action'        => $row['action'],
					'Gate ID'       => $row['gate_id'],
					'Content Rules' => $row['content_rules'],
					'Access Type'   => $row['access_type'],
				],
				array_merge( $summary, $skipped )
			),
			[ 'Plan Name', 'Action', 'Gate ID', 'Content Rules', 'Access Type' ]
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
	 * Value-requiring migrate-membership-gates flags found bare (no `=value`) on the
	 * raw command line.
	 *
	 * WP-CLI validates flags against the command synopsis before invoking the command:
	 * a bare `--plan` draws only a warning, then the flag is stripped and the command
	 * receives the flag's default — so the in-method guard against an unusable --plan
	 * value can never fire on a real invocation, and a run the operator scoped to one
	 * plan would silently widen to every plan on the site. A bare
	 * `--one-time-duration` disappears the same way, stopping the run over a duration
	 * the operator did supply. Reading the raw argv is the only place either mistake is
	 * still visible.
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
	 * Gate titles that more than one plan group resolves to.
	 *
	 * Gate identity is the title, but groups are keyed by content-rule fingerprint —
	 * so two same-named plans with different rules land in different groups and
	 * resolve to one title. The second group takes the update branch, and
	 * update_gate_content_rules() replaces rather than merges, so the first group's
	 * content ends up behind no gate at all while both rows report as processed. The
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
	 * Re-read a freshly written gate and report why it would fail to restrict.
	 *
	 * Mirrors the conditions Content_Restriction_Control::get_post_gates() and
	 * is_post_restricted() apply when deciding whether a gate applies to a post and
	 * whether a given reader is stopped by it, so a gate that passes here is one the
	 * evaluator can actually act on — for the readers the source plan restricted.
	 *
	 * @param int  $gate_id      The content gate post ID.
	 * @param bool $has_purchase Whether every plan behind this gate requires a purchase.
	 *
	 * @return string[] Human-readable problems; empty when the gate is enforceable.
	 */
	private static function verify_migrated_gate( int $gate_id, bool $has_purchase = false ): array {
		$issues = [];

		if ( 'publish' !== \get_post_status( $gate_id ) ) {
			$issues[] = 'the gate is not published';
		}

		// get_gate_content_rules() drops rules with an empty value, so anything left
		// is a rule with content behind it — but the evaluator still has to be able
		// to resolve the slug, which is where the NPPD-2063 slug mistranslation bites.
		$content_rules = \Newspack\Content_Rules::get_gate_content_rules( $gate_id );
		$unresolvable  = array_values(
			array_filter(
				array_column( $content_rules, 'slug' ),
				fn( $slug ) => ! self::is_content_rule_slug_resolvable( $slug )
			)
		);
		if ( empty( $content_rules ) ) {
			// get_gate_content_rules() drops empty-value rules, so a gate can be written
			// with rules and still evaluate as having none — say which of the two it is,
			// because the summary's Content Rules column reports the pre-write count.
			$written_rules = \get_post_meta( $gate_id, 'content_rules', true );
			$issues[]      = empty( $written_rules )
				? 'it has no content rules'
				: 'none of its content rules select any content';
		} elseif ( count( $unresolvable ) === count( $content_rules ) ) {
			$issues[] = sprintf(
				'none of its content rules resolve to a post type or taxonomy the evaluator can match (%s)',
				implode( ', ', $unresolvable )
			);
		} elseif ( ! empty( $unresolvable ) ) {
			// A partially dead rule set is a partial leak, not a clean gate: the rules
			// combine with 'any', so the content selected by the unresolvable rules is
			// left ungated while the rest is covered. A plan restricting all posts plus a
			// category is exactly this shape, and it is a common way plans are configured.
			$issues[] = sprintf(
				'%d of its %d content rules do not resolve to a post type or taxonomy the evaluator can match (%s), so the content they select stays ungated',
				count( $unresolvable ),
				count( $content_rules ),
				implode( ', ', $unresolvable )
			);
		}

		// A gate with neither mode active is skipped outright; an active mode with no
		// layout post is skipped too, so it restricts nothing while looking configured.
		$registration  = \Newspack\Content_Gate::get_registration_settings( $gate_id );
		$custom_access = \Newspack\Content_Gate::get_custom_access_settings( $gate_id );
		if ( empty( $registration['active'] ) && empty( $custom_access['active'] ) ) {
			$issues[] = 'neither the registration nor the paid access mode is active';
		}
		foreach ( [
			'registration' => $registration,
			'paid access'  => $custom_access,
		] as $label => $settings ) {
			if ( empty( $settings['active'] ) ) {
				continue;
			}
			if ( empty( $settings['gate_layout_id'] ) ) {
				$issues[] = sprintf( 'the %s mode is active with no layout', $label );
				continue;
			}
			// The evaluator only checks that the layout ID is truthy, so a missing or
			// blank layout post still counts as "restricted" — the reader gets a
			// truncated article with nothing underneath it: no form, no upsell, no
			// explanation of why the article stops.
			$layout_post = \get_post( $settings['gate_layout_id'] );
			if ( ! $layout_post ) {
				$issues[] = sprintf( 'the %s mode points at layout post %d, which no longer exists', $label, $settings['gate_layout_id'] );
			} elseif ( '' === trim( $layout_post->post_content ) ) {
				$issues[] = sprintf( 'the %s mode points at an empty layout (post %d), so the reader would see a blank gate', $label, $settings['gate_layout_id'] );
			}
		}

		// A plan that required a purchase must migrate to a gate that gates on the
		// purchase. Registration mode alone stops nobody who has an account —
		// is_post_restricted() only re-checks a logged-in reader when
		// require_verification is set, which this migration never writes — so a paid
		// plan whose paid access mode is missing or unconstrained turns into content
		// any reader can unlock by registering a free account.
		if ( $has_purchase ) {
			if ( empty( $custom_access['active'] ) ) {
				$issues[] = 'it migrates a plan that requires a purchase, but its paid access mode is not active — any registered reader would get in';
			} elseif ( empty( $custom_access['access_rules'] ) ) {
				$issues[] = 'its paid access mode is active but has no access rules, so it asks for no purchase — any registered reader would get in';
			}
		}

		return $issues;
	}

	/**
	 * Predict migration issues from group data alone, without writing anything.
	 *
	 * A computable subset of verify_migrated_gate() that needs no written gate: slugs
	 * that the evaluator cannot resolve, and purchase-mode gaps (no custom_access
	 * layout extracted, or no usable product IDs). Called in
	 * dry-run mode so the planning pass surfaces the same warnings --live would.
	 *
	 * @param array[] $ac_rules           AC-format content rules: [ [ 'slug' => string, 'value' => string[] ], ... ].
	 * @param bool    $has_purchase       Whether every plan in the group requires a purchase.
	 * @param array   $layouts            Extracted layout markup keyed by 'registration' and 'custom_access'.
	 * @param int[]   $merged_product_ids Merged parent product IDs for the custom_access mode.
	 *
	 * @return string[] Human-readable problems; empty when no issues are predicted.
	 */
	private static function compute_pre_write_issues( array $ac_rules, bool $has_purchase, array $layouts, array $merged_product_ids ): array {
		$issues = [];

		// Mirror the unresolvable-slug check from verify_migrated_gate(): slugs the
		// evaluator cannot resolve map to no content, so those rules leave their
		// content ungated after cutover.
		$unresolvable = array_values(
			array_filter(
				array_column( $ac_rules, 'slug' ),
				fn( $slug ) => ! self::is_content_rule_slug_resolvable( $slug )
			)
		);
		if ( ! empty( $unresolvable ) ) {
			if ( count( $unresolvable ) === count( $ac_rules ) ) {
				$issues[] = sprintf(
					'none of its content rules resolve to a post type or taxonomy the evaluator can match (%s)',
					implode( ', ', $unresolvable )
				);
			} else {
				$issues[] = sprintf(
					'%d of its %d content rules do not resolve to a post type or taxonomy the evaluator can match (%s), so the content they select stays ungated',
					count( $unresolvable ),
					count( $ac_rules ),
					implode( ', ', $unresolvable )
				);
			}
		}

		if ( $has_purchase ) {
			if ( null === $layouts['custom_access'] ) {
				// No custom_access layout found → apply_layout() is never called for
				// the custom_access mode → paid access mode stays inactive → any
				// registered reader passes. Mirrors verify_migrated_gate()'s "paid
				// access mode is not active" check.
				$issues[] = 'it migrates a plan that requires a purchase, but no paid access layout was found — the paid access mode will not be activated, so any registered reader would get in';
			} elseif ( empty( $merged_product_ids ) ) {
				// apply_layout() is called but with an empty $product_ids → access_rules
				// will be an empty array → mode is active with no purchase constraint.
				// Mirrors verify_migrated_gate()'s "active but has no access rules" check.
				$issues[] = 'its paid access mode will have no access rules (no usable product IDs remain), so it will ask for no purchase — any registered reader would get in';
			}
		}

		return $issues;
	}

	/**
	 * Whether the gate evaluator can resolve a content-rule slug to real content.
	 *
	 * Content_Restriction_Control handles 'post_types', 'specific_posts' and
	 * 'newsletters' by name and treats every other slug as a taxonomy — an
	 * unregistered slug therefore matches no post at all.
	 *
	 * @param string $slug The content-rule slug.
	 *
	 * @return bool
	 */
	private static function is_content_rule_slug_resolvable( string $slug ): bool {
		if ( in_array( $slug, [ 'post_types', 'specific_posts', 'newsletters' ], true ) ) {
			return true;
		}
		return (bool) \get_taxonomy( $slug );
	}

	/**
	 * Group published plans by the fingerprint of their mapped content rules.
	 *
	 * Manual-only plans (no content gate) and plans with no content restriction rules
	 * are collected into $skipped instead of grouped. Plans that map to the same
	 * canonical rule fingerprint share a group (and therefore a single gate).
	 *
	 * This is the primary grouping/split seam for NPPD-2064 (fingerprint
	 * gate-splitting): the fix lands here (and in the merged-product consolidation
	 * in migrate_membership_gates()). This method depends on
	 * WC_Memberships_Membership_Plan, so its grouping/split behavior is NOT covered
	 * by unit tests — the NPPD-2064 author must add net-new tests (the existing
	 * compute_rules_fingerprint() tests only pin canonicality, which the fix keeps).
	 *
	 * @param int[] $plan_ids Plan post IDs.
	 * @param array $skipped  Skipped-plan summary rows, appended to by reference.
	 *
	 * @return array<string,array> Map of fingerprint => list of plan descriptors, each
	 *                             [ 'pid', 'name', 'access_method', 'ac_rules', 'product_ids',
	 *                             'one_time_duration' ].
	 */
	private static function group_plans_by_fingerprint( array $plan_ids, array &$skipped ): array {
		$plan_groups = [];

		foreach ( $plan_ids as $pid ) {
			// The factory validates the post and lets WC Memberships integrations
			// substitute their own plan subclasses (e.g. the Subscriptions-aware one),
			// which direct construction bypasses.
			$plan = \wc_memberships_get_membership_plan( $pid );
			if ( ! $plan ) {
				$skipped[] = [
					'plan_name'     => sprintf( '(plan %d)', $pid ),
					'action'        => 'skipped (not a valid membership plan)',
					'gate_id'       => '—',
					'content_rules' => '—',
					'access_type'   => '—',
				];
				continue;
			}
			$plan_name     = $plan->get_name();
			$access_method = $plan->get_access_method();

			// Skip manual-only plans — they have no content gates.
			if ( 'manual-only' === $access_method ) {
				$skipped[] = [
					'plan_name'     => $plan_name,
					'action'        => 'skipped (manual-only)',
					'gate_id'       => '—',
					'content_rules' => '—',
					'access_type'   => '—',
				];
				continue;
			}

			$wc_rules = $plan->get_content_restriction_rules();
			$ac_rules = self::map_rules_to_ac_format( $wc_rules );

			// A plan with no content restriction rules restricts nothing in WooCommerce
			// Memberships, and a gate with empty content_rules is skipped for every post
			// by Content_Restriction_Control::get_post_gates() — so migrating one would
			// only publish an inert gate the summary misreports as created.
			if ( empty( $ac_rules ) ) {
				$skipped[] = [
					'plan_name'     => $plan_name,
					'action'        => self::plan_has_newsletter_rules( $wc_rules )
						? 'skipped (newsletter lists only — run migrate-premium-newsletters)'
						: 'skipped (no restrictions)',
					'gate_id'       => '—',
					'content_rules' => '0',
					'access_type'   => $access_method,
				];
				continue;
			}

			$fingerprint                   = self::compute_rules_fingerprint( $ac_rules );
			$plan_groups[ $fingerprint ][] = [
				'pid'               => $pid,
				'name'              => $plan_name,
				'access_method'     => $access_method,
				'ac_rules'          => $ac_rules,
				'product_ids'       => 'purchase' === $access_method ? array_values( $plan->get_product_ids() ) : [],
				'one_time_duration' => 'purchase' === $access_method ? self::derive_one_time_duration( $plan ) : null,
			];
		}

		return $plan_groups;
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
	 * Product variations keep the behavior this command has always had: they are
	 * dropped, because gates reference parent products only.
	 *
	 * @param array[] $group Plan descriptors, each carrying a 'product_ids' key.
	 *
	 * @return array 'product_ids' are the surviving parent product IDs, in the order
	 *               they appeared; 'subscription_ids' and 'one_time_ids' partition them
	 *               by the gate rule that can carry each; 'dropped' holds 'invalid' (did
	 *               not normalize to a positive integer — a non-numeric meta value
	 *               therefore appears as 0), 'unresolvable' (no product post with that
	 *               ID) and 'variations'.
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
			} else {
				$product_ids[] = $product_id;
			}
		}

		$classified = self::classify_product_ids( $product_ids );

		return [
			'product_ids'      => $product_ids,
			'subscription_ids' => $classified['subscription'],
			'one_time_ids'     => $classified['one_time'],
			'dropped'          => [
				'invalid'      => $invalid,
				'unresolvable' => $unresolvable,
				'variations'   => $variations,
			],
		];
	}

	/**
	 * Warn about the product IDs resolve_product_ids() refused to write.
	 *
	 * Plain warnings rather than WARN rows: a dropped ID does not stop the gate being
	 * written, and a group that loses every product is caught separately by
	 * compute_pre_write_issues() and verify_migrated_gate().
	 *
	 * Every one of them describes a paid access rule, so all are silent for a group
	 * that writes none. A mixed group still collects product IDs, and warning that its
	 * gate would have granted access to every subscriber describes a rule that was
	 * never written.
	 *
	 * @param string $gate_title   The gate title, for the message.
	 * @param array  $dropped      The 'dropped' element of a resolve_product_ids() result.
	 * @param bool   $has_purchase Whether every plan behind this gate requires a purchase,
	 *                             and therefore whether a paid access rule is written.
	 *
	 * @return void
	 */
	private static function report_dropped_product_ids( string $gate_title, array $dropped, bool $has_purchase ): void {
		if ( ! $has_purchase ) {
			return;
		}
		if ( ! empty( $dropped['invalid'] ) ) {
			WP_CLI::warning(
				sprintf(
					'"%s": dropped product ID(s) %s, which are not positive integers. Writing one would grant the gate to every reader with an active subscription, because a subscription line item matches a rule value of 0. Check the plan\'s products.',
					$gate_title,
					implode( ', ', $dropped['invalid'] )
				)
			);
		}
		if ( ! empty( $dropped['unresolvable'] ) ) {
			WP_CLI::warning(
				sprintf(
					'"%s": dropped product ID(s) %s, which resolve to no product (deleted?). A rule naming them could never be satisfied, so the gate would be stricter than the plan was. Check the plan\'s products.',
					$gate_title,
					implode( ', ', $dropped['unresolvable'] )
				)
			);
		}
		if ( ! empty( $dropped['variations'] ) ) {
			WP_CLI::warning(
				sprintf(
					'"%s": dropped product variation ID(s) %s. Gates restrict access by parent product, not by variation, so a plan that required one of these specific variations no longer has that restriction from this gate. Check the plan\'s products.',
					$gate_title,
					implode( ', ', $dropped['variations'] )
				)
			);
		}
	}

	/**
	 * Warn when a group's plans granted one-time access for different lengths.
	 *
	 * The gate stores one duration, so the command picks the longest and says so.
	 * Staying silent would leave an operator to discover at cutover that a gate
	 * grants longer than the plan they are reading it against.
	 *
	 * @param string      $gate_title The gate title, for the message.
	 * @param string|null $conflict   The 'conflict' element of a resolve_group_duration()
	 *                                result; null when the group's plans agree.
	 *
	 * @return void
	 */
	private static function report_duration_conflict( string $gate_title, ?string $conflict ): void {
		if ( empty( $conflict ) ) {
			return;
		}
		WP_CLI::warning(
			sprintf(
				'"%s": its plans grant one-time access for different lengths — %s. WooCommerce Memberships grants access from any one of them, so the shortest would have taken the content from readers the plans admitted.',
				$gate_title,
				$conflict
			)
		);
	}

	/**
	 * Build the paid access rules a group's products call for.
	 *
	 * Two rule groups rather than one, when a plan grants on both kinds of product.
	 * Groups are OR'd and the rules inside one are AND'd, so a reader satisfies the
	 * gate by holding the subscription or by having bought the one-time product —
	 * which is what the plan granted. Flattening them into a single group would demand
	 * both and admit nobody.
	 *
	 * A one-time product with no duration writes no rule: the caller refuses such a
	 * run before the first write, so this is the shape that never reaches a gate
	 * rather than a silent drop.
	 *
	 * @param array      $products A resolve_product_ids() result.
	 * @param array|null $duration The group's one-time duration, or null when none is
	 *                             available.
	 *
	 * @return array[] Access rule groups, in the shape custom_access settings store.
	 */
	private static function build_access_rules( array $products, ?array $duration ): array {
		$access_rules = [];
		if ( ! empty( $products['subscription_ids'] ) ) {
			$access_rules[] = [
				[
					'slug'  => 'subscription',
					'value' => $products['subscription_ids'],
				],
			];
		}
		if ( ! empty( $products['one_time_ids'] ) && null !== $duration ) {
			$access_rules[] = [
				[
					'slug'  => 'one_time_purchase',
					'value' => array_merge(
						[ 'product_ids' => $products['one_time_ids'] ],
						$duration
					),
				],
			];
		}
		return $access_rules;
	}

	/**
	 * Whether a plan group should migrate to a purchase-gated gate.
	 *
	 * True only when every plan in the group requires a purchase. The two gate modes
	 * compose with AND for a logged-in reader — registration mode passes them, then
	 * custom_access restricts them unless they hold a subscription — so activating
	 * paid access on a group that also holds a signup plan would demand the
	 * subscription from everyone. WooCommerce Memberships grants access to a holder of
	 * *either* plan (OR semantics), so the signup plan's free-registration members
	 * would silently lose access at cutover. Keeping the most-permissive plan's
	 * requirement (registration-gate a mixed group) is the faithful migration.
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
	 * originals stay published and keep restricting the same content. Naming them
	 * lets the operator retire them.
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
	 * Create or update a gate layout post and point the gate's registration or
	 * custom_access settings at it.
	 *
	 * An empty $content never overwrites an existing layout: nothing was extracted to
	 * migrate, and blanking the layout the gate already points at (for a new gate, the
	 * default seeded by Content_Gate::create_gate()) would leave readers a truncated
	 * article with an empty gate under it.
	 *
	 * The custom_access access rules are built by the caller ({@see build_access_rules()}),
	 * where the group's products and its one-time duration are both in scope. Writing
	 * them here would mean deciding which rule a product belongs in from a flat list of
	 * IDs, which is the very distinction the plan does not record.
	 *
	 * @param int        $gate_id      The content gate post ID.
	 * @param string     $gate_title   The gate title (used to name new layout posts).
	 * @param string     $mode         Either 'registration' or 'custom_access'.
	 * @param string     $content      The block markup for the layout.
	 * @param array|null $access_rules Access rule groups for the custom_access mode.
	 *
	 * @return bool True when the mode was activated against a usable layout post. False
	 *              when no layout could be written — the mode is then left untouched,
	 *              since activating it with no layout yields a gate that never restricts.
	 */
	private static function apply_layout( int $gate_id, string $gate_title, string $mode, string $content, ?array $access_rules = null ): bool {
		if ( 'custom_access' === $mode ) {
			$settings  = \Newspack\Content_Gate::get_custom_access_settings( $gate_id );
			$layout_id = $settings['gate_layout_id'] ?? 0;
			$label     = 'Paid Access Layout';
		} else {
			$settings  = \Newspack\Content_Gate::get_registration_settings( $gate_id );
			$layout_id = $settings['gate_layout_id'] ?? 0;
			$label     = 'Registration Layout';
		}

		if ( $layout_id && '' === $content ) {
			// Content_Gate::create_gate() seeds both layout posts with a default block
			// pattern, so this update path is the normal one, not the exception — writing
			// empty content here would blank a working layout and leave the reader a
			// truncated article with nothing underneath it. create_gate_layout()
			// substitutes the default for empty content; keep the two paths in agreement
			// by leaving whatever the layout already holds in place.
			WP_CLI::warning(
				sprintf(
					'No %s content could be extracted for "%s" — keeping the existing layout (post %d) rather than blanking it. Review it before cutover.',
					strtolower( $label ),
					$gate_title,
					$layout_id
				)
			);
		} else {
			// Gate content authored by an admin with unfiltered_html can legitimately
			// contain iframes/embeds/Custom HTML. A WP-CLI run has no user, so kses would
			// strip those on re-save and the migrated layout would not match its source.
			$kses_was_active = \has_filter( 'content_save_pre', 'wp_filter_post_kses' );
			if ( $kses_was_active ) {
				\kses_remove_filters();
			}

			if ( $layout_id ) {
				$updated = \wp_update_post(
					[
						'ID'           => $layout_id,
						'post_content' => $content,
					],
					true // Return WP_Error on failure.
				);
				if ( \is_wp_error( $updated ) || ! $updated ) {
					// A stale gate_layout_id pointing at a deleted post makes this a no-op.
					WP_CLI::warning(
						sprintf(
							'Could not update %s (post %d) for "%s": %s',
							strtolower( $label ),
							$layout_id,
							$gate_title,
							\is_wp_error( $updated ) ? $updated->get_error_message() : 'the layout post no longer exists'
						)
					);
					$layout_id = 0;
				}
			} else {
				$layout_id = \Newspack\Content_Gate::create_gate_layout(
					sprintf( '%s — %s', $gate_title, $label ),
					$content
				);
				if ( \is_wp_error( $layout_id ) ) {
					WP_CLI::warning( sprintf( 'Could not create %s for "%s": %s', strtolower( $label ), $gate_title, $layout_id->get_error_message() ) );
					$layout_id = 0;
				}
			}

			if ( $kses_was_active ) {
				\kses_init_filters();
			}
		}

		// Without a layout post the mode must stay as it is: Content_Restriction_Control
		// requires a truthy gate_layout_id, so activating it here would publish a gate
		// that silently never restricts.
		if ( ! $layout_id ) {
			return false;
		}

		if ( 'custom_access' === $mode ) {
			\Newspack\Content_Gate::update_custom_access_settings(
				$gate_id,
				[
					'active'         => true,
					'gate_layout_id' => $layout_id,
					'access_rules'   => $access_rules ?? [],
				]
			);
		} else {
			\Newspack\Content_Gate::update_registration_settings(
				$gate_id,
				[
					'active'         => true,
					'gate_layout_id' => $layout_id,
				]
			);
		}

		return true;
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
	 * Map WooCommerce Membership content restriction rules to the Access Control
	 * content_rules format.
	 *
	 * This is the rule-mapping seam for NPPD-2063 (content-rule slug mistranslation):
	 * the slug is taken verbatim from WC's `get_content_type_name()` (e.g. 'post',
	 * 'page', 'post_tag'), whereas Access Control keys post-type rules under
	 * 'post_types' and individual posts under 'specific_posts'. The remapping lands
	 * in the stacked NPPD-2063 PR; this port preserves the drop-in behavior.
	 *
	 * @param \WC_Memberships_Membership_Plan_Rule[] $wc_rules Array of WC Memberships rules.
	 *
	 * @return array[] AC-format content rules: [ [ 'slug' => string, 'value' => string[] ], ... ].
	 */
	private static function map_rules_to_ac_format( array $wc_rules ): array {
		$ac_rules = [];
		foreach ( $wc_rules as $rule ) {
			$slug = $rule->get_content_type_name(); // E.g. 'post', 'category', 'post_tag'.
			if ( empty( $slug ) ) {
				continue;
			}
			// Newsletter-list rules migrate through `migrate-premium-newsletters`
			// (NPPD-2079), which writes them to the premium newsletter gate bucket.
			// Mapped here they would be inert — Content_Restriction_Control judges a
			// list post against the newsletter bucket, never this one — while still
			// entering the rule fingerprint, splitting two plans that restrict
			// identical content into two gates.
			if ( self::get_newsletter_list_cpt() === $slug ) {
				continue;
			}
			$existing_key = array_search( $slug, array_column( $ac_rules, 'slug' ), true );
			if ( false !== $existing_key ) {
				// Merge object IDs into the existing rule for this slug.
				$ac_rules[ $existing_key ]['value'] = array_map(
					'strval',
					array_values(
						array_unique(
							array_merge( $ac_rules[ $existing_key ]['value'], $rule->get_object_ids() )
						)
					)
				);
			} else {
				$ac_rules[] = [
					'slug'  => $slug,
					'value' => array_map( 'strval', array_values( $rule->get_object_ids() ) ),
				];
			}
		}
		return $ac_rules;
	}

	/**
	 * Find the np_memberships_gate post for a given plan ID.
	 *
	 * Looks for a plan-specific gate first, then optionally falls back to the
	 * "Primary" gate recorded in the `newspack_memberships_gate_post_id` option
	 * (and, if that is unset or stale, to the newest gate with no `plans` meta).
	 *
	 * @param int  $plan_id          The membership plan post ID.
	 * @param bool $primary_fallback Whether to fall back to the Primary gate.
	 *
	 * @return \WP_Post|null The gate post, or null if none found.
	 */
	private static function get_memberships_gate_for_plan( int $plan_id, bool $primary_fallback = true ): ?\WP_Post {
		// Look for a plan-specific gate first.
		$plan_gates = \get_posts(
			[
				'post_type'      => 'np_memberships_gate',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => 'plans',
						'value'   => sprintf( ';i:%d;', $plan_id ),
						'compare' => 'LIKE',
					],
				],
			]
		);
		if ( ! empty( $plan_gates ) ) {
			return $plan_gates[0];
		}

		if ( ! $primary_fallback ) {
			return null;
		}

		// Fall back to the Primary gate, which the plugin records by option. Prefer
		// that over inferring it from the absence of `plans` meta — "no plans meta"
		// is incidental, and the meta query below just returns the newest plan-less
		// gate, which diverges as soon as more than one exists.
		$primary_gate_id = \Newspack\Memberships::get_gate_post_id();
		if ( $primary_gate_id ) {
			$primary_gate = \get_post( $primary_gate_id );
			if (
				$primary_gate instanceof \WP_Post
				&& \Newspack\Memberships::GATE_CPT === $primary_gate->post_type
				&& 'publish' === $primary_gate->post_status
			) {
				return $primary_gate;
			}
		}

		// The option is unset or stale — fall back to the newest plan-less gate.
		$primary_gates = \get_posts(
			[
				'post_type'      => 'np_memberships_gate',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => 'plans',
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);
		return ! empty( $primary_gates ) ? $primary_gates[0] : null;
	}

	/**
	 * Serialize inner blocks, excluding any WooCommerce Memberships wrapper blocks.
	 *
	 * Membership wrapper blocks (member-content and non-member-content) are
	 * conditional and should not be included in the migrated gate layout content.
	 *
	 * Part of the layout-extraction seam for NPPD-2058 (empty layouts for
	 * reusable-block / nested gate layouts).
	 *
	 * @param array $inner_blocks The innerBlocks array from a parsed block.
	 *
	 * @return string Serialized block markup.
	 */
	private static function serialize_gate_inner_blocks( array $inner_blocks ): string {
		$membership_block_types = [
			'woocommerce-memberships/member-content',
			'woocommerce-memberships/non-member-content',
		];
		$filtered = array_filter(
			$inner_blocks,
			fn( $b ) => ! in_array( $b['blockName'], $membership_block_types, true )
		);
		return \serialize_blocks( array_values( $filtered ) );
	}

	/**
	 * Extract registration and custom_access layout block content from an
	 * np_memberships_gate post.
	 *
	 * - Registration layout: inner block content of the top-level
	 *   `woocommerce-memberships/non-member-content` block(s) (the gate/upsell shown to
	 *   non-members).
	 * - Custom access layout: inner block content of the top-level
	 *   `woocommerce-memberships/member-content` block(s) (shown to paying members).
	 *
	 * A gate post may interleave several top-level wrappers of the same type — a post
	 * mixing public and members-only sections. Each type's wrappers are concatenated in
	 * document order so no authored content is dropped.
	 *
	 * This is the layout-extraction seam for NPPD-2058: only top-level wrapper
	 * blocks are inspected, so gates whose wrappers are nested (e.g. inside a group
	 * or a reusable `core/block`) yield empty layouts. The stacked NPPD-2058 PR
	 * walks nested/reusable blocks; this port preserves the top-level-only behavior.
	 *
	 * @param \WP_Post $gate_post The np_memberships_gate post.
	 *
	 * @return array{registration: string, custom_access: string|null}
	 */
	private static function extract_gate_layouts( \WP_Post $gate_post ): array {
		$blocks               = \parse_blocks( $gate_post->post_content );
		$registration_markup  = null;
		$custom_access_markup = null;

		foreach ( $blocks as $block ) {
			if ( 'woocommerce-memberships/non-member-content' === $block['blockName'] ) {
				$registration_markup = ( $registration_markup ?? '' ) . self::serialize_gate_inner_blocks( $block['innerBlocks'] );
			}
			if ( 'woocommerce-memberships/member-content' === $block['blockName'] ) {
				$custom_access_markup = ( $custom_access_markup ?? '' ) . self::serialize_gate_inner_blocks( $block['innerBlocks'] );
			}
		}

		return [
			'registration'  => $registration_markup ?? '',
			'custom_access' => $custom_access_markup,
		];
	}

	/**
	 * Compute a canonical fingerprint string for a set of AC content rules.
	 *
	 * Used to group Membership plans that restrict the same content so they can
	 * share a single Access Control gate. Rules are sorted by slug and each rule's
	 * object-ID array is sorted numerically, so two equivalent rule sets always
	 * produce the same fingerprint regardless of the order WC Memberships returned
	 * them in.
	 *
	 * Supports the NPPD-2064 grouping work, but note the split decision itself lives
	 * in group_plans_by_fingerprint(), not here. Only this function's canonicality
	 * (order-independence) is unit-tested; that property is preserved by the 2064 fix,
	 * so those tests will not flip red.
	 *
	 * @param array[] $ac_rules AC-format content rules: [ [ 'slug' => string, 'value' => int[] ], ... ].
	 *
	 * @return string Canonical fingerprint.
	 */
	private static function compute_rules_fingerprint( array $ac_rules ): string {
		// Normalise: sort each rule's values, then sort rules by slug.
		$normalised = array_map(
			function ( $rule ) {
				$values = $rule['value'];
				sort( $values, SORT_NUMERIC );
				return [
					'slug'  => $rule['slug'],
					'value' => $values,
				];
			},
			$ac_rules
		);
		usort( $normalised, fn( $a, $b ) => strcmp( $a['slug'], $b['slug'] ) );
		$fingerprint = \wp_json_encode( $normalised );
		// Fallback only if JSON encoding fails; the fingerprint is an internal
		// grouping key, never unserialized.
		return $fingerprint ? $fingerprint : serialize( $normalised ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}

	/**
	 * The newsletter list post type.
	 *
	 * Read from Newspack Newsletters when it is loaded, with a literal fallback so
	 * this command keeps skipping newsletter rules on sites where that plugin is
	 * inactive but its rules are still recorded on the plans.
	 *
	 * @return string The list post type.
	 */
	private static function get_newsletter_list_cpt(): string {
		if ( class_exists( 'Newspack\Newsletters\Subscription_Lists' ) ) {
			$cpt = \Newspack\Newsletters\Subscription_Lists::CPT;
			if ( $cpt ) {
				return $cpt;
			}
		}
		return 'newspack_nl_list';
	}

	/**
	 * Whether any of a plan's rules restricts a newsletter list.
	 *
	 * Used to tell a plan that restricts nothing apart from a plan whose whole
	 * restriction migrates through migrate-premium-newsletters instead.
	 *
	 * @param \WC_Memberships_Membership_Plan_Rule[] $wc_rules Array of WC Memberships rules.
	 *
	 * @return bool
	 */
	private static function plan_has_newsletter_rules( array $wc_rules ): bool {
		$list_cpt = self::get_newsletter_list_cpt();
		foreach ( $wc_rules as $rule ) {
			if ( $list_cpt === $rule->get_content_type_name() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Record Access Control exemptions for the posts WooCommerce Memberships forced public.
	 *
	 * Memberships' "This content is public" checkbox writes
	 * `_wc_memberships_force_public = 'yes'` and short-circuits every restriction rule.
	 * Access Control's equivalent is the "Disable access control restrictions for this
	 * post" toggle. Nothing bridges the two, so without this step those posts are gated
	 * the moment Memberships is deactivated.
	 *
	 * A falsy exemption row is left alone and reported. Turning the toggle off is what
	 * records one, so overwriting it would undo a decision. Pass --overwrite-falsy for
	 * rows that predate the toggle, where the falsy value is only the editor echoing the
	 * registered default back on save.
	 *
	 * Migrates only the post types the exemption toggle is offered on. Posts on any other
	 * type are counted and warned about rather than written: they can still be gated by a
	 * taxonomy access rule, so they need a human decision this command cannot make.
	 *
	 * Idempotent, but additive: an exemption recorded by an earlier run is never removed.
	 * A post whose Memberships flag has since been unchecked is reported so it can be
	 * reviewed — it would otherwise stay public after cutover. Publishers keep using the
	 * checkbox up to cutover, so the run that counts is the one immediately before
	 * Memberships is deactivated.
	 *
	 * Dry-run by default; pass --live to write.
	 *
	 * ## OPTIONS
	 *
	 * [--live]
	 * : Apply the changes. Without this flag the command runs as a dry-run and writes nothing.
	 *
	 * [--overwrite-falsy]
	 * : Also record an exemption on posts whose exemption row is already set to a falsy
	 * value. Only for rows known to predate the exemption toggle.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack migrate-post-exemptions
	 *     wp newspack migrate-post-exemptions --live
	 *     wp newspack migrate-post-exemptions --live --overwrite-falsy
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 *
	 * @return void
	 */
	public function migrate_post_exemptions( $args, $assoc_args ) {
		$dry_run         = ! (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'live', false );
		$overwrite_falsy = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'overwrite-falsy', false );

		if ( ! class_exists( 'Newspack\Content_Restriction_Control' ) ) {
			WP_CLI::error( 'Newspack\Content_Restriction_Control class not found, so the exemption meta key and its post types cannot be resolved. Aborting.' );
		}

		if ( $dry_run ) {
			WP_CLI::line( '' );
			WP_CLI::line( '*** DRY RUN MODE — no data will be modified. Pass --live to write. ***' );
			WP_CLI::line( '' );
		}

		if ( ! \Newspack\Content_Gate::is_newspack_feature_enabled() ) {
			WP_CLI::warning( 'Content gates are disabled on this site (NEWSPACK_CONTENT_GATES). The exemptions will be recorded, but nothing reads them until the feature is enabled.' );
		}

		$flagged = self::get_force_public_posts();
		if ( null === $flagged ) {
			WP_CLI::error( 'Could not read the force-public flags. Aborting rather than reporting an empty set to an operator about to deactivate Memberships.' );
		}

		$post_types   = array_column( (array) \Newspack\Content_Restriction_Control::get_available_post_types(), 'value' );
		$in_scope     = [];
		$out_of_scope = [];
		foreach ( $flagged as $post ) {
			if ( in_array( $post['post_type'], $post_types, true ) ) {
				$in_scope[] = $post;
			} else {
				$out_of_scope[ $post['post_type'] ] = ( $out_of_scope[ $post['post_type'] ] ?? 0 ) + 1;
			}
		}

		if ( empty( $in_scope ) ) {
			WP_CLI::line( 'No posts carry the WooCommerce Memberships force-public flag on a post type the exemption applies to.' );
			self::report_out_of_scope_force_public( $out_of_scope );
			self::report_revoked_force_public();
			return;
		}

		$missing   = [];
		$falsy     = [];
		$preserved = [];
		$failed    = [];
		$breakdown = [];
		// Classify and write a chunk at a time, so the meta cache neither grows with the
		// site nor is thrown away between reading a post and writing it.
		foreach ( array_chunk( $in_scope, 200 ) as $chunk ) {
			\update_meta_cache( 'post', array_column( $chunk, 'ID' ) );
			foreach ( $chunk as $post ) {
				$post_id = (int) $post['ID'];
				$state   = self::classify_exemption_state( $post_id );

				$key                 = $post['post_type'] . '|' . $post['post_status'];
				$breakdown[ $key ] ??= [
					'post_type'   => $post['post_type'],
					'post_status' => $post['post_status'],
					'missing'     => 0,
					'falsy'       => 0,
					'already'     => 0,
				];
				++$breakdown[ $key ][ $state ];

				if ( 'already' === $state ) {
					continue;
				}
				if ( 'falsy' === $state && ! $overwrite_falsy ) {
					$preserved[] = $post_id;
					continue;
				}
				if ( ! $dry_run && ! \update_post_meta( $post_id, \Newspack\Content_Restriction_Control::IS_EXEMPT_META_KEY, true ) ) {
					$failed[] = $post_id;
					continue;
				}
				if ( 'missing' === $state ) {
					$missing[] = $post_id;
				} else {
					$falsy[] = $post_id;
				}
			}
			\WP_CLI\Utils\wp_clear_object_cache();
		}

		WP_CLI::line( '' );
		WP_CLI::line( $dry_run ? '=== DRY RUN SUMMARY ===' : '=== MIGRATION SUMMARY ===' );
		WP_CLI::line( '' );

		\WP_CLI\Utils\format_items(
			'table',
			array_map(
				fn( $row ) => [
					'Post Type'      => $row['post_type'],
					'Status'         => $row['post_status'],
					'No Row'         => $row['missing'],
					'Falsy Row'      => $row['falsy'],
					'Already Exempt' => $row['already'],
				],
				array_values( $breakdown )
			),
			[ 'Post Type', 'Status', 'No Row', 'Falsy Row', 'Already Exempt' ]
		);

		WP_CLI::line( '' );

		// The command never removes an exemption, so this list is the only record of what a
		// live run touched. Printing it is what makes an over-broad run undoable without
		// re-deriving the set from the database afterwards.
		if ( ! empty( $missing ) || ! empty( $falsy ) ) {
			WP_CLI::line(
				sprintf(
					'Exemption %s for: %s',
					$dry_run ? 'would be recorded' : 'recorded',
					self::format_post_id_list( array_merge( $missing, $falsy ) )
				)
			);
		}

		if ( ! empty( $preserved ) ) {
			WP_CLI::warning(
				sprintf(
					'%d post(s) are forced public by Memberships but carry a falsy exemption row, so they stay gated after cutover. That row is what turning the toggle off records, so it is left alone. Re-run with --overwrite-falsy for any that predate the toggle: %s',
					count( $preserved ),
					self::format_post_id_list( $preserved )
				)
			);
		}

		if ( ! empty( $falsy ) ) {
			WP_CLI::warning(
				sprintf(
					'%d falsy exemption row(s) %s, per --overwrite-falsy: %s',
					count( $falsy ),
					$dry_run ? 'would be overwritten' : 'were overwritten',
					self::format_post_id_list( $falsy )
				)
			);
		}

		if ( ! empty( $failed ) ) {
			WP_CLI::warning( sprintf( '%d exemption(s) could not be written, and are still counted in the table above: %s', count( $failed ), self::format_post_id_list( $failed ) ) );
		}

		self::report_out_of_scope_force_public( $out_of_scope );
		self::report_revoked_force_public();

		WP_CLI::success(
			sprintf(
				'Done. %d exemption(s) %s (%d with no row, %d overwriting a falsy row). %d post(s) were already exempt, %d falsy row(s) left alone.',
				count( $missing ) + count( $falsy ),
				$dry_run ? 'would be recorded' : 'recorded',
				count( $missing ),
				count( $falsy ),
				count( $in_scope ) - count( $missing ) - count( $falsy ) - count( $failed ) - count( $preserved ),
				count( $preserved )
			)
		);

		if ( ! $dry_run ) {
			WP_CLI::line( 'Keep the `_wc_memberships_force_public` meta until this has run for the last time — it is what this command reads.' );
		}

		// A warning line is invisible to whatever chained this command with the Memberships
		// deactivation that follows it, and an unwritten exemption cannot be recovered once
		// the flag it came from is gone. Exit non-zero so the cutover stops here.
		if ( ! empty( $failed ) ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Every post carrying the Memberships force-public flag, whatever its post type.
	 *
	 * The `meta_value = 'yes'` filter is not optional: the checkbox writes a `no` row for
	 * every post it was ever rendered on, outnumbering the real ones by an order of
	 * magnitude.
	 *
	 * @return array[]|null Rows of ID / post_type / post_status ordered by ID, or null
	 *                      if the query failed.
	 */
	private static function get_force_public_posts(): ?array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT DISTINCT p.ID, p.post_type, p.post_status
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE pm.meta_key = %s AND pm.meta_value = 'yes'
				ORDER BY p.ID",
				\Newspack\Content_Restriction_Control::WC_FORCE_PUBLIC_META_KEY
			),
			ARRAY_A
		);

		// get_results() reports a failed query as an empty set, which here would read as
		// "no post was ever forced public" to an operator about to deactivate Memberships.
		return $wpdb->last_error ? null : (array) $rows;
	}

	/**
	 * Report posts that are exempt while their Memberships flag says otherwise.
	 *
	 * An earlier live run records an exemption; the publisher then unchecks the box. This
	 * command never revisits that post — it selects on `meta_value = 'yes'` — so the
	 * exemption outlives the flag and the post stays public after cutover. The same shape
	 * is also a legitimate editor-set exemption on a post Memberships never forced public,
	 * so these are named for review, never removed.
	 *
	 * @return void
	 */
	private static function report_revoked_force_public(): void {
		global $wpdb;

		$post_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} fp ON fp.post_id = p.ID AND fp.meta_key = %s AND fp.meta_value = 'no'
				INNER JOIN {$wpdb->postmeta} ex ON ex.post_id = p.ID AND ex.meta_key = %s AND ex.meta_value NOT IN ( '', '0' )
				ORDER BY p.ID",
				\Newspack\Content_Restriction_Control::WC_FORCE_PUBLIC_META_KEY,
				\Newspack\Content_Restriction_Control::IS_EXEMPT_META_KEY
			)
		);

		// Same hazard as the census query: get_col() reports a failure as an empty set, and
		// an empty review list is what an operator about to deactivate Memberships reads as
		// "nothing stays wrongly public".
		if ( $wpdb->last_error ) {
			WP_CLI::warning( 'Could not check for exemptions whose Memberships flag was since revoked. Nothing was reviewed — treat this as "unknown", not "none".' );
			return;
		}

		if ( empty( $post_ids ) ) {
			return;
		}

		WP_CLI::warning(
			sprintf(
				'%d post(s) are exempt while the Memberships flag says they are not, so they stay public after cutover. Review and clear any the publisher no longer wants free: %s',
				count( $post_ids ),
				self::format_post_id_list( $post_ids )
			)
		);
	}

	/**
	 * Report the force-public posts on post types the exemption toggle is not offered on.
	 *
	 * A warning rather than a plain line, because "the exemption does not apply" is not the
	 * same as "these posts cannot be gated". Nothing in the restriction check is scoped by
	 * post type: the exemption is honoured on any post ID, and a category or tag access rule
	 * matches a post through its terms. So a public post type the exemption toggle is not
	 * offered on — it is absent from `get_available_post_types()` — can still be gated at
	 * cutover, and these posts are exactly the ones the publisher marked free.
	 *
	 * Counts posts, not meta rows: the census query selects DISTINCT post IDs, so duplicate
	 * force-public rows on one post collapse to a single entry.
	 *
	 * @param array<string,int> $out_of_scope Post type => post count.
	 *
	 * @return void
	 */
	private static function report_out_of_scope_force_public( array $out_of_scope ): void {
		if ( empty( $out_of_scope ) ) {
			return;
		}
		arsort( $out_of_scope );
		$parts = [];
		foreach ( $out_of_scope as $post_type => $count ) {
			$parts[] = sprintf( '%s (%d)', $post_type, $count );
		}
		WP_CLI::warning(
			sprintf(
				'Skipped %d force-public post(s) on post types the exemption toggle is not offered on: %s. A category or tag access rule can still gate them, and no exemption was recorded — check these by hand before deactivating Memberships.',
				array_sum( $out_of_scope ),
				implode( ', ', $parts )
			)
		);
	}

	/**
	 * Render a list of post IDs for a single CLI line, capped.
	 *
	 * These lists are what an operator acts on before deactivating Memberships. Imploding
	 * every ID scrolls the rest of the summary out of the terminal buffer once the list runs
	 * to thousands, so the tail is summarised instead.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @param int   $limit    How many IDs to name before summarising the rest.
	 *
	 * @return string
	 */
	private static function format_post_id_list( array $post_ids, int $limit = 100 ): string {
		if ( count( $post_ids ) <= $limit ) {
			return implode( ', ', $post_ids );
		}
		return sprintf(
			'%s (and %d more)',
			implode( ', ', array_slice( $post_ids, 0, $limit ) ),
			count( $post_ids ) - $limit
		);
	}

	/**
	 * Where a post stands with respect to the Access Control exemption.
	 *
	 * Reads through metadata_exists() rather than get_post_meta(). The exemption meta is
	 * registered with a default, so get_post_meta() cannot tell "no row" from "row set
	 * falsy" — and on a build carrying the `default_post_metadata` fallback for the exemption
	 * key (which answers from the Memberships flag), it answers true for exactly the posts
	 * this command selects, which would leave it with nothing to write. metadata_exists()
	 * reads the row itself, past both.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string 'missing' (no row), 'falsy' (a row set falsy), or 'already' (a real
	 *                exemption, left alone).
	 */
	private static function classify_exemption_state( int $post_id ): string {
		$meta_key = \Newspack\Content_Restriction_Control::IS_EXEMPT_META_KEY;
		if ( ! \metadata_exists( 'post', $post_id, $meta_key ) ) {
			return 'missing';
		}
		return \get_post_meta( $post_id, $meta_key, true ) ? 'already' : 'falsy';
	}
}
