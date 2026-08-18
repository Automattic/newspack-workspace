<?php
/**
 * WP-CLI command to migrate WooCommerce Membership plans and their content gate
 * layouts to Newspack Access Control content gates.
 *
 * Ported from the standalone `migrate-memberships` drop-in so the tooling ships
 * with the plugin. The class file is included on every request (like the other
 * CLI classes), but only the command registration is gated on WP_CLI, so the
 * runtime cost outside CLI is a class definition. The command reads each
 * published Membership plan's content
 * restriction rules plus the `np_memberships_gate` layout posts and writes the
 * equivalent Access Control gate(s), content rules, and gate layouts through the
 * Content_Gate / Content_Rules data layer.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Membership plan → Access Control content gate migration CLI command.
 */
class Membership_Gates_Migration {

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
	 * - Sets content rules from the shared restriction rules.
	 * - Enables registration settings (always) and custom_access settings (only
	 *   when every plan in the group requires a purchase — a group that also holds a
	 *   signup plan is registration-gated, since either plan grants access in WCM).
	 * - Copies block content from the first plan's np_memberships_gate post (falling
	 *   back to the Primary gate) into the gate's registration / paid-access layouts.
	 *
	 * Dry-run by default; pass --live to write.
	 *
	 * Paid access rules are mapped from the plan's granting products: subscription
	 * products go to a 'subscription' rule, simple (one-time) products to a
	 * 'one_time_purchase' rule whose duration comes from the plan's membership length.
	 *
	 * Both modes surface predictable migration issues as WARN rows. Purchase-mode
	 * gaps (no custom_access layout found, no products mappable to a paid access rule)
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
	 * ## OPTIONS
	 *
	 * [--live]
	 * : Apply the changes. Without this flag the command runs as a dry-run and writes nothing.
	 *
	 * [--plan=<id>]
	 * : Only process the plan with this post ID. Useful for testing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack migrate-membership-gates
	 *     wp newspack migrate-membership-gates --live
	 *     wp newspack migrate-membership-gates --plan=711923
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 *
	 * @return void
	 */
	public function migrate_membership_gates( $args, $assoc_args ) {
		$dry_run = ! (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'live', false );

		// A mistyped --plan must never silently widen the run to every plan, so an
		// unusable value is a hard error rather than a fallback to "no filter".
		$plan_arg = \WP_CLI\Utils\get_flag_value( $assoc_args, 'plan', null );
		$plan_id  = 0;
		if ( null !== $plan_arg ) {
			if ( ! is_numeric( $plan_arg ) || (int) $plan_arg <= 0 ) {
				WP_CLI::error( sprintf( 'Invalid --plan value "%s". Pass a positive membership plan post ID.', $plan_arg ) );
			}
			$plan_id = (int) $plan_arg;
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
		$existing_gates = [];
		foreach ( \Newspack\Content_Gate::get_gates( \Newspack\Content_Gate::GATE_CPT, 'publish' ) as $gate ) {
			$existing_gates[ trim( strtolower( $gate['title'] ) ) ] = $gate['id'];
		}

		$summary        = [];
		$skipped        = [];
		$titles_written = [];

		// Phase 1: group plans by content-rule fingerprint.
		$plan_groups = self::group_plans_by_fingerprint( $plan_ids, $skipped );

		// Phase 2: create/update one gate per group.
		$group_count = count( $plan_groups );
		if ( $group_count < ( $total - count( $skipped ) ) ) {
			WP_CLI::line( sprintf( 'Grouped into %d gate(s) after deduplication.', $group_count ) );
			WP_CLI::line( '' );
		}
		$progress = \WP_CLI\Utils\make_progress_bar( 'Migrating gates', $group_count );

		foreach ( $plan_groups as $group ) {
			$progress->tick();

			$layout_errors = [];
			$ac_rules      = $group[0]['ac_rules'];
			$gate_title    = implode( ' | ', array_column( $group, 'name' ) );
			$gate_key      = trim( strtolower( $gate_title ) );
			$has_purchase = self::group_requires_purchase( $group );
			$access_type  = $has_purchase ? 'purchase' : 'signup';

			// Paid-access mapping: subscription products map to the 'subscription' rule,
			// simple (one-time) products to a 'one_time_purchase' rule whose duration
			// comes from the plan's membership length (NPPD-2106). Only purchase groups
			// carry paid rules; a signup or mixed group is registration-gated, so it is
			// skipped here — matching apply_paid_access()/verify_migrated_gate().
			$paid_access_rules = $has_purchase
				? self::build_paid_access_rules( self::get_group_paid_descriptors( $group ) )
				: [];

			// Gate identity is the title, but groups are keyed by rule fingerprint —
			// so two same-named plans with different rules land in different groups
			// and would silently overwrite each other's rules and layouts.
			if ( isset( $titles_written[ $gate_key ] ) ) {
				WP_CLI::warning(
					sprintf(
						'Two plan groups resolve to the gate title "%s" (same plan name(s), different content rules). The later group overwrites the earlier one — rename one of the plans and re-run.',
						$gate_title
					)
				);
			}
			$titles_written[ $gate_key ] = true;

			$action  = array_key_exists( $gate_key, $existing_gates ) ? 'updated' : 'created';
			$gate_id = $existing_gates[ $gate_key ] ?? null;

			// Keep $existing_gates consistent for both live and dry-run passes so a
			// later group with the same title is reported as 'updated'. A null value
			// means "claimed by this run but not created yet" (dry-run, or an earlier
			// group in the same pass), which the summary prints as '(pending)'.
			if ( ! array_key_exists( $gate_key, $existing_gates ) ) {
				$existing_gates[ $gate_key ] = null;
			}

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
				$existing_gates[ $gate_key ] = $gate_id;

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
				// apply_paid_access() leaves the mode untouched when no rules mapped, so
				// the paid mode is never activated with an empty (unrestricted) rule set.
				if ( $has_purchase && 'error' === self::apply_paid_access( $gate_id, $gate_title, $layouts['custom_access'], $paid_access_rules ) ) {
					$layout_errors[] = 'paid access layout';
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
				$verification_issues = self::compute_pre_write_issues( $ac_rules, $has_purchase, $layouts, $paid_access_rules );
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
				'paid_rules'    => self::describe_paid_access_rules( $paid_access_rules ),
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
					'Paid Access'   => $row['paid_rules'] ?? '—',
				],
				array_merge( $summary, $skipped )
			),
			[ 'Plan Name', 'Action', 'Gate ID', 'Content Rules', 'Access Type', 'Paid Access' ]
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
		// plan whose paid access mode never activated turns into content any reader
		// can unlock by registering a free account.
		if ( $has_purchase && empty( $custom_access['active'] ) ) {
			$issues[] = 'it migrates a plan that requires a purchase, but its paid access mode is not active — any registered reader would get in';
		}

		// An active paid mode with no access rules is unrestricted regardless of how
		// the group was classified: both gate evaluators treat empty access rules as
		// "don't block", so every registered reader walks through. This is checked
		// unconditionally, not only for purchase groups, because a signup or mixed
		// group can still carry a leftover active-but-ruleless paid mode (a prior run,
		// or a hand-edit) that this migration does not itself write (NPPD-2106).
		if ( ! empty( $custom_access['active'] ) && empty( $custom_access['access_rules'] ) ) {
			$issues[] = 'its paid access mode is active but has no access rules, so it asks for no purchase — any registered reader would get in';
		}

		return $issues;
	}

	/**
	 * Predict migration issues from group data alone, without writing anything.
	 *
	 * A computable subset of verify_migrated_gate() that needs no written gate: slugs
	 * that the evaluator cannot resolve, and purchase-mode gaps (no custom_access
	 * layout extracted, or no products could be mapped to a paid access rule). Called
	 * in dry-run mode so the planning pass surfaces the same warnings --live would.
	 *
	 * @param array[] $ac_rules          AC-format content rules: [ [ 'slug' => string, 'value' => string[] ], ... ].
	 * @param bool    $has_purchase      Whether every plan in the group requires a purchase.
	 * @param array   $layouts           Extracted layout markup keyed by 'registration' and 'custom_access'.
	 * @param array[] $paid_access_rules Mapped paid-access rule groups from build_paid_access_rules().
	 *
	 * @return string[] Human-readable problems; empty when no issues are predicted.
	 */
	private static function compute_pre_write_issues( array $ac_rules, bool $has_purchase, array $layouts, array $paid_access_rules ): array {
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
			} elseif ( empty( $paid_access_rules ) ) {
				// No product mapped to a paid access rule (all variations, unmappable
				// one-time durations, or the one_time_purchase rule unregistered on this
				// build) → apply_paid_access() skips the write → the paid mode is never
				// activated, so any registered reader passes. Mirrors verify_migrated_gate()'s
				// "paid access mode is not active" check for the pre-write pass.
				$issues[] = 'its paid access mode will have no access rules (no products could be mapped to a paid access rule), so it will ask for no purchase — any registered reader would get in';
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
	 *                             [ 'pid', 'name', 'access_method', 'ac_rules', 'product_ids' ].
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
					'action'        => 'skipped (no restrictions)',
					'gate_id'       => '—',
					'content_rules' => '0',
					'access_type'   => $access_method,
				];
				continue;
			}

			$fingerprint                   = self::compute_rules_fingerprint( $ac_rules );
			$plan_groups[ $fingerprint ][] = [
				'pid'           => $pid,
				'name'          => $plan_name,
				'access_method' => $access_method,
				'ac_rules'      => $ac_rules,
				'product_ids'   => 'purchase' === $access_method ? array_values( $plan->get_product_ids() ) : [],
			];
		}

		return $plan_groups;
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
	 * Build the per-plan paid-access descriptors for a plan group: normalized product
	 * IDs partitioned into subscription vs. one-time products, plus the plan's
	 * membership length mapped onto the one_time_purchase duration schema.
	 *
	 * Warns (in both dry-run and live passes) when a plan's product variations are
	 * dropped, when a fixed-date length is approximated by an order-date-anchored
	 * duration, and when one-time products cannot be mapped at all (rule not
	 * registered on this build, or an unrecognized access length period). Dropped
	 * mappings fail closed: the products contribute no rule, and a group left with
	 * no paid rules skips paid access configuration entirely — the mode is never
	 * activated with an empty rule set (see apply_paid_access()).
	 *
	 * @param array $group Plan descriptors from group_plans_by_fingerprint().
	 *
	 * @return array[] Descriptors for build_paid_access_rules(), each
	 *                 [ 'subscription_product_ids', 'one_time_product_ids', 'duration' ].
	 */
	private static function get_group_paid_descriptors( array $group ): array {
		$descriptors = [];

		// A rule slug the evaluator does not recognize is treated as "don't block"
		// (Access_Rules::evaluate_rule() returns true for it), so writing a
		// one_time_purchase rule on a build that has not registered the rule yet
		// would leave the paid gate open to every logged-in reader. Drop the
		// mapping instead — the products contribute no rule, so paid access is
		// left unconfigured (or untouched on a re-run) and the gate row is
		// flagged, telling the operator to upgrade the plugin and re-run.
		$one_time_rule_registered = (bool) \Newspack\Access_Rules::get_rule( 'one_time_purchase' );

		foreach ( $group as $group_plan ) {
			if ( 'purchase' !== $group_plan['access_method'] ) {
				continue;
			}

			// Cast to int for parity with the REST write path, which stores access-rule
			// product IDs as ints; raw `_product_ids` meta can hold strings.
			$unique_product_ids = array_values(
				array_filter( array_unique( array_map( 'absint', $group_plan['product_ids'] ) ) )
			);
			// Gates reference parent products only, so variations are dropped — loudly,
			// since a plan granted via specific variations loses those grants here.
			$variation_ids = array_values(
				array_filter(
					$unique_product_ids,
					fn( $id ) => 'product_variation' === \get_post_type( $id )
				)
			);
			$product_ids   = array_values( array_diff( $unique_product_ids, $variation_ids ) );
			if ( ! empty( $variation_ids ) ) {
				WP_CLI::warning(
					sprintf(
						'"%s": dropped linked product variation(s) %s — access rules do not support specific variations. Add the parent product(s) to the gate manually if those variations should grant access.',
						$group_plan['name'],
						implode( ', ', $variation_ids )
					)
				);
			}

			$subscription_product_ids = [];
			$one_time_product_ids     = [];
			foreach ( $product_ids as $product_id ) {
				if ( self::is_subscription_product( $product_id ) ) {
					$subscription_product_ids[] = $product_id;
				} else {
					$one_time_product_ids[] = $product_id;
				}
			}

			$duration = self::map_access_length_to_duration( $group_plan['access_length_amount'], $group_plan['access_length_period'] );

			if ( ! empty( $one_time_product_ids ) && ! $one_time_rule_registered ) {
				WP_CLI::warning(
					sprintf(
						'"%s" grants access from one-time product(s) (%s), but this Newspack Plugin build does not register the one_time_purchase access rule. The product(s) were NOT mapped — update the plugin and re-run the migration.',
						$group_plan['name'],
						implode( ', ', $one_time_product_ids )
					)
				);
				$one_time_product_ids = [];
			}

			if ( ! empty( $one_time_product_ids ) ) {
				if ( null === $duration ) {
					WP_CLI::warning(
						sprintf(
							'"%s" has an access length period ("%s") the one_time_purchase rule cannot express. Its one-time product(s) (%s) were NOT mapped — configure a one_time_purchase rule on the gate manually.',
							$group_plan['name'],
							$group_plan['access_length_period'],
							implode( ', ', $one_time_product_ids )
						)
					);
				} elseif ( 'fixed' === $group_plan['access_length_type'] ) {
					// WCM reports a fixed-date plan's length as the days from its access
					// start date (or now, when no start is set) to its end date, so the
					// mapped duration approximates the plan's window length — but the
					// one_time_purchase rule re-anchors it on each order date, so a late
					// purchase can grant access past the plan's original calendar end.
					WP_CLI::warning(
						sprintf(
							'"%s" uses fixed access dates; the one_time_purchase rule instead grants %d %s from each order date. Adjust the gate manually if the fixed end date matters.',
							$group_plan['name'],
							$duration['duration_value'],
							$duration['duration_unit']
						)
					);
				}
			}

			$descriptors[] = [
				'subscription_product_ids' => $subscription_product_ids,
				'one_time_product_ids'     => $one_time_product_ids,
				'duration'                 => $duration,
			];
		}

		return $descriptors;
	}

	/**
	 * Whether a product grants access via a subscription (vs. a one-time purchase).
	 *
	 * Mirrors WC_Memberships_Membership_Plan::get_products(): prefer the WooCommerce
	 * Subscriptions detector (which accounts for custom subscription product types),
	 * falling back to the core subscription product types.
	 *
	 * @param int $product_id The product ID.
	 *
	 * @return bool
	 */
	private static function is_subscription_product( int $product_id ): bool {
		if ( is_callable( 'WC_Subscriptions_Product::is_subscription' ) ) {
			return (bool) \WC_Subscriptions_Product::is_subscription( $product_id );
		}
		$product = function_exists( 'wc_get_product' ) ? \wc_get_product( $product_id ) : false;
		return $product && $product->is_type( [ 'subscription', 'variable-subscription', 'subscription_variation' ] );
	}

	/**
	 * Map a WooCommerce Memberships access length onto the one_time_purchase rule's
	 * duration schema, which only supports 'days', 'months', and 'forever':
	 *
	 * - no length (unlimited plan) → forever
	 * - days → days; weeks → days × 7
	 * - months → months; years → months × 12
	 *
	 * @param int|string $amount WCM access length amount ('' when the plan is unlimited).
	 * @param string     $period WCM access length period ('' when the plan is unlimited).
	 *
	 * @return array|null [ 'duration_value' => int, 'duration_unit' => string ], or null
	 *                    for an unrecognized period — the caller must fail closed, since
	 *                    guessing here could widen a finite grant into a lifetime one.
	 */
	private static function map_access_length_to_duration( $amount, string $period ): ?array {
		$amount = (int) $amount;
		if ( $amount <= 0 || '' === $period ) {
			return [
				'duration_value' => 0,
				'duration_unit'  => 'forever',
			];
		}
		switch ( $period ) {
			case 'days':
				return [
					'duration_value' => $amount,
					'duration_unit'  => 'days',
				];
			case 'weeks':
				return [
					'duration_value' => $amount * 7,
					'duration_unit'  => 'days',
				];
			case 'months':
				return [
					'duration_value' => $amount,
					'duration_unit'  => 'months',
				];
			case 'years':
				return [
					'duration_value' => $amount * 12,
					'duration_unit'  => 'months',
				];
		}
		return null;
	}

	/**
	 * Build the gate's paid-access rule groups from per-plan descriptors.
	 *
	 * Subscription products across the group merge into a single 'subscription' rule.
	 * One-time products bucket by mapped duration into 'one_time_purchase' rules —
	 * plans sharing a duration share a rule; distinct durations get distinct rules.
	 * Every rule sits in its own OR group, so an active subscription OR any
	 * qualifying one-time purchase grants access. Descriptors with a null duration
	 * contribute no one_time_purchase rule (fail closed; the operator was warned).
	 *
	 * @param array[] $plan_descriptors Descriptors from get_group_paid_descriptors().
	 *
	 * @return array[] Access rule groups for the gate's custom_access settings.
	 */
	private static function build_paid_access_rules( array $plan_descriptors ): array {
		$subscription_product_ids = [];
		$one_time_rule_buckets    = [];

		foreach ( $plan_descriptors as $descriptor ) {
			$subscription_product_ids = array_merge( $subscription_product_ids, $descriptor['subscription_product_ids'] );

			if ( empty( $descriptor['one_time_product_ids'] ) || null === $descriptor['duration'] ) {
				continue;
			}

			$bucket_key = $descriptor['duration']['duration_value'] . ' ' . $descriptor['duration']['duration_unit'];
			if ( ! isset( $one_time_rule_buckets[ $bucket_key ] ) ) {
				$one_time_rule_buckets[ $bucket_key ] = [
					'product_ids'    => [],
					'duration_value' => $descriptor['duration']['duration_value'],
					'duration_unit'  => $descriptor['duration']['duration_unit'],
				];
			}
			$one_time_rule_buckets[ $bucket_key ]['product_ids'] = array_values(
				array_unique(
					array_merge( $one_time_rule_buckets[ $bucket_key ]['product_ids'], $descriptor['one_time_product_ids'] )
				)
			);
		}

		$access_rules             = [];
		$subscription_product_ids = array_values( array_unique( $subscription_product_ids ) );
		if ( ! empty( $subscription_product_ids ) ) {
			$access_rules[] = [
				[
					'slug'  => 'subscription',
					'value' => $subscription_product_ids,
				],
			];
		}
		foreach ( $one_time_rule_buckets as $bucket ) {
			$access_rules[] = [
				[
					'slug'  => 'one_time_purchase',
					'value' => $bucket,
				],
			];
		}

		return $access_rules;
	}

	/**
	 * Render the paid-access rule groups as a compact summary-table cell, so the
	 * product→rule mapping is visible in dry-run output before anything is written.
	 *
	 * @param array[] $access_rules Rule groups from build_paid_access_rules().
	 *
	 * @return string E.g. "subscription(12, 15) OR one_time_purchase(18; 12 months)"; '—' when empty.
	 */
	private static function describe_paid_access_rules( array $access_rules ): string {
		if ( empty( $access_rules ) ) {
			return '—';
		}
		$parts = [];
		foreach ( $access_rules as $group_rules ) {
			foreach ( $group_rules as $rule ) {
				if ( 'one_time_purchase' === $rule['slug'] ) {
					$duration = 'forever' === $rule['value']['duration_unit']
						? 'forever'
						: sprintf( '%d %s', $rule['value']['duration_value'], $rule['value']['duration_unit'] );
					$parts[]  = sprintf( '%s(%s; %s)', $rule['slug'], implode( ', ', $rule['value']['product_ids'] ), $duration );
				} else {
					$parts[] = sprintf( '%s(%s)', $rule['slug'], implode( ', ', (array) $rule['value'] ) );
				}
			}
		}
		return implode( ' OR ', $parts );
	}

	/**
	 * Configure the gate's paid access (custom_access) mode from the mapped rules.
	 *
	 * With no mappable rules the mode is left exactly as it is: it is never
	 * activated with an empty rule set — both gate evaluators treat empty access
	 * rules as unrestricted, so that would open the "paid" gate to every
	 * registered reader — and a re-run never overwrites rules a previous,
	 * correctly-equipped run wrote. A missing source layout also skips, matching
	 * the registration-side behavior of never activating a mode without a layout.
	 *
	 * @param int         $gate_id        The content gate post ID.
	 * @param string      $gate_title     The gate title (used to name new layout posts).
	 * @param string|null $layout_content Paid access layout markup, or null when the
	 *                                    source gate has no member-content wrapper.
	 * @param array       $access_rules   Rule groups from build_paid_access_rules().
	 *
	 * @return string 'configured', 'skipped_no_rules', 'skipped_no_layout', or 'error'.
	 */
	private static function apply_paid_access( int $gate_id, string $gate_title, ?string $layout_content, array $access_rules ): string {
		if ( empty( $access_rules ) ) {
			return 'skipped_no_rules';
		}
		if ( null === $layout_content ) {
			return 'skipped_no_layout';
		}
		return self::apply_layout( $gate_id, $gate_title, 'custom_access', $layout_content, $access_rules )
			? 'configured'
			: 'error';
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
	 * @param int          $gate_id      The content gate post ID.
	 * @param string       $gate_title   The gate title (used to name new layout posts).
	 * @param string       $mode         Either 'registration' or 'custom_access'.
	 * @param string       $content      The block markup for the layout.
	 * @param array[]|null $access_rules Paid-access rule groups (from build_paid_access_rules())
	 *                                  for the custom_access mode; ignored for registration.
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
}
