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
	 * Both modes surface predictable migration issues as WARN rows. Purchase-mode
	 * gaps (no custom_access layout found, no product IDs after stripping variations)
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

			// Cast to int for parity with the REST write path, which stores subscription
			// access-rule values as ints; raw `_product_ids` meta can hold strings.
			$merged_product_ids = array_values(
				array_unique(
					array_map( 'absint', array_merge( ...array_column( $group, 'product_ids' ) ) )
				)
			);
			// Drop product variations — gates should reference parent products only.
			$merged_product_ids = array_values(
				array_filter(
					$merged_product_ids,
					fn( $id ) => 'product_variation' !== \get_post_type( $id )
				)
			);

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
				if ( $has_purchase && null !== $layouts['custom_access'] ) {
					if ( ! self::apply_layout( $gate_id, $gate_title, 'custom_access', $layouts['custom_access'], $merged_product_ids ) ) {
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
	 * layout extracted, or no product IDs after stripping variations). Called in
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
				$issues[] = 'its paid access mode will have no access rules (no product IDs remain after stripping product variations), so it will ask for no purchase — any registered reader would get in';
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
	 * Create or update a gate layout post and point the gate's registration or
	 * custom_access settings at it.
	 *
	 * An empty $content never overwrites an existing layout: nothing was extracted to
	 * migrate, and blanking the layout the gate already points at (for a new gate, the
	 * default seeded by Content_Gate::create_gate()) would leave readers a truncated
	 * article with an empty gate under it.
	 *
	 * @param int        $gate_id     The content gate post ID.
	 * @param string     $gate_title  The gate title (used to name new layout posts).
	 * @param string     $mode        Either 'registration' or 'custom_access'.
	 * @param string     $content     The block markup for the layout.
	 * @param int[]|null $product_ids Merged parent product IDs for custom_access purchase rules.
	 *
	 * @return bool True when the mode was activated against a usable layout post. False
	 *              when no layout could be written — the mode is then left untouched,
	 *              since activating it with no layout yields a gate that never restricts.
	 */
	private static function apply_layout( int $gate_id, string $gate_title, string $mode, string $content, ?array $product_ids = null ): bool {
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
					'access_rules'   => ! empty( $product_ids )
						? [
							[
								[
									'slug'  => 'subscription',
									'value' => $product_ids,
								],
							],
						]
						: [],
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
}
