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
	 * ## OPTIONS
	 *
	 * [--live]
	 * : Apply the changes. Without this flag the command runs as a dry-run and writes nothing.
	 *
	 * [--plan=<id>]
	 * : Only process the plan with this post ID. Useful for testing; never writes the site-wide auto-signup setting.
	 *
	 * [--yes]
	 * : Answer yes to the confirmation prompt shown when a gate would be created alongside gates the same plans were migrated to individually.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack migrate-premium-newsletters
	 *     wp newspack migrate-premium-newsletters --live
	 *     wp newspack migrate-premium-newsletters --plan=711923
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 *
	 * @return void
	 */
	public function migrate_premium_newsletters( $args, $assoc_args ) {
		$dry_run = ! (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'live', false );

		// A bare --plan never reaches the validation below: WP-CLI warns and strips it,
		// so the command sees no plan at all and would run against every plan on the
		// site. The raw command line is the only place the mistake is still visible.
		$bare_flags = self::get_valueless_value_flags();
		if ( ! empty( $bare_flags ) ) {
			WP_CLI::error( sprintf( 'The following flag(s) require a value but arrived without one: %s. WP-CLI strips a bare flag before the command runs, so the run would widen to every plan on the site — fix the invocation and re-run.', implode( ', ', $bare_flags ) ) );
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

		if ( ! \Newspack\Content_Gate::is_newspack_feature_enabled() ) {
			WP_CLI::warning( 'The content gates feature (NEWSPACK_CONTENT_GATES) is not enabled on this site: premium newsletter gates will be created but will remain dormant until it is enabled.' );
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
		$existing_gates = [];
		foreach ( \Newspack\Content_Gate::get_gates( \Newspack\Content_Gate::GATE_CPT, 'publish', true ) as $gate ) {
			$existing_gates[ trim( strtolower( $gate['title'] ) ) ] = $gate['id'];
		}

		$summary        = [];
		$skipped        = [];
		$titles_written = [];
		$migrated_lists = [];

		$plan_groups = self::group_plans_by_lists( $plan_ids, $skipped );

		$group_count = count( $plan_groups );
		if ( $group_count < ( $total - count( $skipped ) ) ) {
			WP_CLI::line( sprintf( 'Grouped into %d gate(s) after deduplication.', $group_count ) );
			WP_CLI::line( '' );
		}
		$progress = \WP_CLI\Utils\make_progress_bar( 'Migrating premium newsletter gates', $group_count );

		foreach ( $plan_groups as $group ) {
			$progress->tick();

			$payload      = self::build_gate_payload( $group );
			$list_ids     = $payload['list_ids'];
			$gate_title   = $payload['title'];
			$gate_key     = trim( strtolower( $gate_title ) );
			$has_purchase = $payload['has_purchase'];
			$access_type  = $payload['access_type'];

			self::report_product_id_issues( $payload );

			// Gate identity is the title, but groups are keyed by list fingerprint — so
			// two same-named plans restricting different lists land in different groups
			// and would silently overwrite each other's rules.
			if ( isset( $titles_written[ $gate_key ] ) ) {
				WP_CLI::warning(
					sprintf(
						'Two plan groups resolve to the gate title "%s" (same plan name(s), different lists). The later group overwrites the earlier one — rename one of the plans and re-run.',
						$gate_title
					)
				);
			}
			$titles_written[ $gate_key ] = true;

			$action  = array_key_exists( $gate_key, $existing_gates ) ? 'updated' : 'created';
			$gate_id = $existing_gates[ $gate_key ] ?? null;

			// Regrouping can merge plans a previous run migrated separately — most
			// likely after a --plan run, which writes a gate titled for that one plan.
			// Gate identity is the title, so the merged title matches no existing gate
			// and this run would write a new one while the originals stay published.
			// is_post_restricted() stops at the first gate that restricts, so a stale
			// stricter gate wins over the merged, more permissive one. Name them, and
			// let the operator stop before the duplicate is created.
			if ( 'created' === $action ) {
				$superseded = self::find_superseded_gates( $group, $gate_key, $existing_gates );
				if ( ! empty( $superseded ) ) {
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
					if ( ! $dry_run ) {
						WP_CLI::confirm(
							sprintf( 'Create "%s" anyway? Answering no stops the whole run; gates already written stay written, and re-running is safe.', $gate_title ),
							$assoc_args
						);
					}
				}
			}

			// Keep $existing_gates consistent across both passes so a later group with the
			// same title is reported as 'updated'. A null value means "claimed by this run
			// but not created yet", which the summary prints as '(pending)'.
			if ( ! array_key_exists( $gate_key, $existing_gates ) ) {
				$existing_gates[ $gate_key ] = null;
			}

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
				if ( '' === $write_error ) {
					$existing_gates[ $gate_key ] = $gate_id;
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
				$verification_issues = self::compute_pre_write_issues( $list_ids, $has_purchase, $payload['product_ids'] );
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
		self::report_auto_signup( array_values( array_unique( $migrated_lists ) ), $dry_run, (bool) $plan_id );

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
		$value_flags = [ '--plan' ];
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
	 * - The paid access mode carries a subscription rule only when the group requires a
	 *   purchase AND at least one product ID survives. An active mode with no rules asks
	 *   for no purchase at all, so the empty shape is left empty deliberately for
	 *   verify_migrated_gate() and compute_pre_write_issues() to flag, rather than
	 *   papered over with a rule that would grant more than the plan did.
	 *
	 * @param array[] $group Plan descriptors from group_plans_by_lists(), each carrying
	 *                       'pid', 'name', 'access_method', 'list_ids' and 'product_ids'.
	 *
	 * @return array The gate payload: 'title', 'list_ids', 'has_purchase', 'access_type',
	 *               'content_rules', 'content_rules_match', 'registration' and
	 *               'custom_access' are what the create and update paths write;
	 *               'product_ids', 'variation_ids' and 'dropped_product_ids' are what
	 *               the pre-write check and the warnings report on.
	 */
	private static function build_gate_payload( array $group ): array {
		$list_ids     = $group[0]['list_ids'] ?? [];
		$has_purchase = self::group_requires_purchase( $group );

		$products    = self::resolve_product_ids( $group );
		$product_ids = $products['product_ids'];

		$content_rules = [
			[
				'slug'  => 'newsletters',
				'value' => array_map( 'strval', $list_ids ),
			],
		];

		$access_rules = $has_purchase && ! empty( $product_ids )
			? [
				[
					[
						'slug'  => 'subscription',
						'value' => $product_ids,
					],
				],
			]
			: [];

		return [
			'title'               => implode( ' | ', array_column( $group, 'name' ) ),
			'list_ids'            => $list_ids,
			'has_purchase'        => $has_purchase,
			'access_type'         => $has_purchase ? 'purchase' : 'signup',
			'content_rules'       => $content_rules,
			'content_rules_match' => 'any',
			'registration'        => [ 'active' => true ],
			'custom_access'       => [
				'active'       => $has_purchase,
				'access_rules' => $access_rules,
			],
			'product_ids'         => $product_ids,
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
	 *               'variations' is the subset of them that are product variations;
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

		return [
			'product_ids' => $product_ids,
			'variations'  => $variations,
			'dropped'     => [
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
	 * @param array $payload A build_gate_payload() result.
	 *
	 * @return void
	 */
	private static function report_product_id_issues( array $payload ) {
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
	 * Entries whose value is null are skipped: those titles were claimed by this run
	 * rather than found on the site, so there is no prior gate to supersede.
	 *
	 * @param array[] $group          Plan descriptors, each carrying a 'name' key.
	 * @param string  $gate_key       The group's own lower-cased gate title.
	 * @param array   $existing_gates Map of lower-cased gate title => gate ID.
	 *
	 * @return array<string,int> Map of gate title => gate ID, excluding the group's own title.
	 */
	private static function find_superseded_gates( array $group, string $gate_key, array $existing_gates ): array {
		$superseded = [];
		foreach ( $group as $plan ) {
			$plan_key = trim( strtolower( $plan['name'] ) );
			if ( $plan_key === $gate_key ) {
				continue;
			}
			if ( isset( $existing_gates[ $plan_key ] ) ) {
				$superseded[ $plan_key ] = $existing_gates[ $plan_key ];
			}
		}
		return $superseded;
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
	 * used to decide which lists to leave to reader opt-in. With custom lists off the
	 * modal offers every list rather than a chosen set, so the saved selection is not
	 * a carve-out and the set is empty.
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
	 * Re-read a freshly written gate and report why it would fail to restrict.
	 *
	 * Mirrors the conditions Content_Restriction_Control::get_post_gates() and
	 * is_post_restricted() apply to a newsletter list post, so a gate that passes
	 * here is one the evaluator can act on for the readers the source plan
	 * restricted. Migrated gates stay dormant until WooCommerce Memberships is
	 * deactivated, so without this an unenforceable gate would look migrated for as
	 * long as it takes someone to notice at cutover.
	 *
	 * Layout checks are deliberately absent, but not because layouts do not matter:
	 * is_post_restricted() ends on `if ( $is_restricted && $gate_layout_id )`, and
	 * get_registration_settings() defaults gate_layout_id to 0, so a gate with no
	 * layout restricts nothing — premium newsletter gates included. The check is safe
	 * to omit because Content_Gate::create_gate() seeds both layout posts, and every
	 * path that creates one of these gates — this command and the Premium Newsletters
	 * wizard — goes through it.
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
				// `! empty( $product_ids )` guard without handling it here.
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
	 * @param int[] $list_ids     The group's restricted list IDs.
	 * @param bool  $has_purchase Whether every plan in the group requires a purchase.
	 * @param int[] $product_ids  The product IDs build_gate_payload() kept for the paid access mode.
	 *
	 * @return string[] Human-readable problems; empty when no issues are predicted.
	 */
	private static function compute_pre_write_issues( array $list_ids, bool $has_purchase, array $product_ids ): array {
		$issues = [];

		$unresolvable = self::describe_unresolvable_lists( $list_ids, self::list_ids_that_do_not_resolve( $list_ids ) );
		if ( null !== $unresolvable ) {
			$issues[] = $unresolvable;
		}

		if ( $has_purchase && empty( $product_ids ) ) {
			$issues[] = 'its paid access mode will have no access rules (no usable product IDs remain), so it will ask for no purchase — any registered reader would keep the list';
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
	 * Group published plans by the set of newsletter lists they restrict.
	 *
	 * Manual-only plans (which have no content gates) and plans that restrict no
	 * newsletter list are collected into $skipped instead of grouped. Plans
	 * restricting the same lists share a group, and therefore a single gate.
	 *
	 * @param int[] $plan_ids Plan post IDs.
	 * @param array $skipped  Skipped-plan summary rows, appended to by reference.
	 *
	 * @return array<string,array> Map of fingerprint => list of plan descriptors, each
	 *                             [ 'pid', 'name', 'access_method', 'list_ids', 'product_ids' ].
	 */
	private static function group_plans_by_lists( array $plan_ids, array &$skipped ): array {
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

			if ( 'manual-only' === $access_method ) {
				$skipped[] = [
					'plan_name'   => $plan_name,
					'action'      => 'skipped (manual-only)',
					'gate_id'     => '—',
					'lists'       => '—',
					'access_type' => '—',
				];
				continue;
			}

			$list_ids = self::extract_list_ids( $plan->get_content_restriction_rules() );
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
				'pid'           => $pid,
				'name'          => $plan_name,
				'access_method' => $access_method,
				'list_ids'      => $list_ids,
				'product_ids'   => 'purchase' === $access_method ? array_values( $plan->get_product_ids() ) : [],
			];
		}

		return $plan_groups;
	}

	/**
	 * Derive, report, and (in live mode) write the site-wide auto-signup setting.
	 *
	 * The option is written rather than left alone because the whole point is a
	 * zero-touch migration — but the transition is always printed, so a change to a
	 * setting a publisher may have chosen is visible rather than silent.
	 *
	 * A --plan run is the exception: the option is site-wide, but the derivation only
	 * sees the lists that one plan restricts. If the site's other lists sit on the
	 * other side of the modal split, writing it would flip a global setting from a
	 * partial view — turning auto-signup on for readers who declined those lists at
	 * checkout, or off for readers who expected them. So a --plan run reports the
	 * derivation and says why it is not written; settling the setting takes a full run.
	 *
	 * @param int[] $list_ids    All newsletter list IDs this run migrated.
	 * @param bool  $dry_run     Whether this is a dry run.
	 * @param bool  $plan_scoped Whether the run was narrowed to a single plan with --plan.
	 *
	 * @return void
	 */
	private static function report_auto_signup( array $list_ids, bool $dry_run, bool $plan_scoped = false ) {
		$derived = self::derive_auto_signup( $list_ids );
		$current = (bool) \get_option( 'newspack_premium_newsletters_auto_signup', 1 );

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
			}
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
		if ( $dry_run ) {
			WP_CLI::line( sprintf( 'Auto-signup would be set to %s (currently %s).', $derived_label, $current_label ) );
			return;
		}
		\update_option( 'newspack_premium_newsletters_auto_signup', $derived['value'] ? 1 : 0, false );
		WP_CLI::line( sprintf( 'Auto-signup set to %s (was %s).', $derived_label, $current_label ) );
	}
}
