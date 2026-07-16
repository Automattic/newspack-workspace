<?php
/**
 * WP-CLI command to migrate WooCommerce Membership plans and their content gate
 * layouts to Newspack Access Control content gates.
 *
 * Ported from the standalone `migrate-memberships` drop-in so the tooling ships
 * with the plugin (the CLI class loads only under WP_CLI, so there is no
 * web-request cost). The command reads each published Membership plan's content
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
	 * - Enables registration settings (always) and custom_access settings (if any
	 *   plan in the group requires a purchase).
	 * - Copies block content from the first plan's np_memberships_gate post (falling
	 *   back to the Primary gate) into the gate's registration / paid-access layouts.
	 *
	 * Dry-run by default; pass --live to write.
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
		$plan_id = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'plan', 0 );

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

		// The standalone `migrate-memberships` drop-in registers the same command
		// name (defaulting to WRITE — the inverse of this port's dry-run default).
		// This port's `init` registration overrides the drop-in's `plugins_loaded`
		// one, so this callback is the one running — but the ambiguity is a footgun,
		// so warn loudly and tell the operator to deactivate the drop-in.
		if ( class_exists( 'Newspack_Migrate_Membership_Gates_Command' ) ) {
			WP_CLI::warning( 'The standalone `migrate-memberships` drop-in is also active and registers this same command (with the opposite, write-by-default flag convention). This in-plugin command is running, but deactivate/delete the drop-in to avoid confusion.' );
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

		// Pre-load existing gates indexed by lower-cased title.
		$existing_gates = [];
		foreach ( \Newspack\Content_Gate::get_gates() as $gate ) {
			$existing_gates[ trim( strtolower( $gate['title'] ) ) ] = $gate['id'];
		}

		$summary = [];
		$skipped = [];

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

			$ac_rules     = $group[0]['ac_rules'];
			$gate_title   = implode( ' | ', array_column( $group, 'name' ) );
			$gate_key     = trim( strtolower( $gate_title ) );
			$has_purchase = ! empty(
				array_filter( $group, fn( $g ) => 'purchase' === $g['access_method'] )
			);
			$access_type = $has_purchase ? 'purchase' : 'signup';

			$merged_product_ids = array_values(
				array_unique(
					array_merge( ...array_column( $group, 'product_ids' ) )
				)
			);
			// Drop product variations — gates should reference parent products only.
			$merged_product_ids = array_values(
				array_filter(
					$merged_product_ids,
					fn( $id ) => 'product_variation' !== \get_post_type( $id )
				)
			);

			$action  = isset( $existing_gates[ $gate_key ] ) ? 'updated' : 'created';
			$gate_id = $existing_gates[ $gate_key ] ?? null;

			// Keep $existing_gates consistent for both live and dry-run passes so
			// subsequent groups with the same title (theoretically impossible given
			// unique fingerprints, but defensive) are correctly detected as 'updated'.
			if ( null === $gate_id ) {
				$existing_gates[ $gate_key ] = -1; // Sentinel: gate does not exist yet.
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

				// Set content rules.
				\Newspack\Content_Rules::update_gate_content_rules( $gate_id, $ac_rules );

				// Registration layout (always).
				self::apply_layout( $gate_id, $gate_title, 'registration', $layouts['registration'] );

				// Custom access layout (purchase plans only).
				if ( $has_purchase && null !== $layouts['custom_access'] ) {
					self::apply_layout( $gate_id, $gate_title, 'custom_access', $layouts['custom_access'], $merged_product_ids );
				}
			}

			$summary[] = [
				'plan_name'     => $gate_title,
				'action'        => $dry_run ? $action . ' (dry-run)' : $action,
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
		WP_CLI::success(
			sprintf(
				'Done. %d gate(s) %s.',
				$processed,
				$dry_run ? 'would be created/updated' : 'created/updated'
			)
		);
	}

	/**
	 * Group published plans by the fingerprint of their mapped content rules.
	 *
	 * Manual-only plans (no content gate) and non-signup plans with no restrictions
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
			$plan          = new \WC_Memberships_Membership_Plan( $pid );
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

			// Allow signup plans with no explicit content rules to proceed — a
			// free-registration gate may gate all content implicitly without needing
			// specific rule entries.
			if ( empty( $ac_rules ) && 'signup' !== $access_method ) {
				$skipped[] = [
					'plan_name'     => $plan_name,
					'action'        => 'skipped (no restrictions)',
					'gate_id'       => '—',
					'content_rules' => '0',
					'access_type'   => $access_method,
				];
				continue;
			}

			// Note: signup plans with no content rules all share the same empty
			// fingerprint and are merged into a single gate with a combined title.
			// This is intentional — a free-registration gate that restricts no
			// specific content applies site-wide.
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
	 * Create or update a gate layout post and point the gate's registration or
	 * custom_access settings at it.
	 *
	 * @param int        $gate_id     The content gate post ID.
	 * @param string     $gate_title  The gate title (used to name new layout posts).
	 * @param string     $mode        Either 'registration' or 'custom_access'.
	 * @param string     $content     The block markup for the layout.
	 * @param int[]|null $product_ids Merged parent product IDs for custom_access purchase rules.
	 *
	 * @return void
	 */
	private static function apply_layout( int $gate_id, string $gate_title, string $mode, string $content, ?array $product_ids = null ): void {
		if ( 'custom_access' === $mode ) {
			$settings  = \Newspack\Content_Gate::get_custom_access_settings( $gate_id );
			$layout_id = $settings['gate_layout_id'] ?? 0;
			$label     = 'Paid Access Layout';
		} else {
			$settings  = \Newspack\Content_Gate::get_registration_settings( $gate_id );
			$layout_id = $settings['gate_layout_id'] ?? 0;
			$label     = 'Registration Layout';
		}

		if ( $layout_id ) {
			// The overwrite is unconditional even when $content is '' — mirroring the
			// drop-in. Content_Gate::create_gate() seeds a default block-pattern layout,
			// which this replaces with the migrated markup; an empty $content (no
			// np_memberships_gate found for the group, or a nested/reusable wrapper) thus
			// blanks that default. Preserving those defaults on empty content is the
			// empty-layout fix tracked in NPPD-2058; kept faithful here.
			\wp_update_post(
				[
					'ID'           => $layout_id,
					'post_content' => $content,
				]
			);
		} else {
			$layout_id = \Newspack\Content_Gate::create_gate_layout(
				sprintf( '%s — %s', $gate_title, $label ),
				$content
			);
			if ( \is_wp_error( $layout_id ) ) {
				WP_CLI::warning( sprintf( 'Could not create %s for "%s": %s', strtolower( $label ), $gate_title, $layout_id->get_error_message() ) );
			}
		}

		$resolved_layout_id = \is_wp_error( $layout_id ) ? 0 : $layout_id;

		if ( 'custom_access' === $mode ) {
			\Newspack\Content_Gate::update_custom_access_settings(
				$gate_id,
				[
					'active'         => true,
					'gate_layout_id' => $resolved_layout_id,
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
					'gate_layout_id' => $resolved_layout_id,
				]
			);
		}
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
			'posts_per_page' => -1,
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
	 * WC and AC key content restrictions differently. A WC rule carries a content
	 * type kind (`get_content_type()`, either 'post_type' or 'taxonomy') plus a name
	 * (`get_content_type_name()`, e.g. 'post', 'page', 'guest-author', 'category',
	 * 'post_tag') and optional object IDs. AC enforcement only honours these slugs:
	 * 'post_types' (value = post-type slugs), 'specific_posts' (value = post IDs),
	 * 'newsletters', and taxonomy slugs (value = term IDs). The mapping is therefore:
	 *
	 * - taxonomy rule                        → slug = taxonomy name, value = term IDs.
	 * - post-type rule, no object IDs        → slug = 'post_types',    value = [ post-type name ].
	 * - post-type rule, with object IDs      → slug = 'specific_posts', value = post IDs.
	 *
	 * The post_type vs. taxonomy split uses the rule's own `get_content_type()`
	 * discriminator rather than string-matching the name against a hardcoded list, so
	 * custom post types (e.g. 'guest-author') map correctly.
	 *
	 * @param \WC_Memberships_Membership_Plan_Rule[] $wc_rules Array of WC Memberships rules.
	 *
	 * @return array[] AC-format content rules: [ [ 'slug' => string, 'value' => string[] ], ... ].
	 */
	private static function map_rules_to_ac_format( array $wc_rules ): array {
		$ac_rules = [];
		foreach ( $wc_rules as $rule ) {
			$content_type_name = $rule->get_content_type_name(); // A post-type or taxonomy name such as post, page, category or guest-author.
			if ( empty( $content_type_name ) ) {
				continue;
			}

			$object_ids = array_map( 'strval', array_values( $rule->get_object_ids() ) );

			if ( 'taxonomy' === $rule->get_content_type() ) {
				// Taxonomy rules key under the taxonomy slug; the value is the term IDs.
				$slug  = $content_type_name;
				$value = $object_ids;
			} elseif ( empty( $object_ids ) ) {
				// A whole-post-type rule keys under 'post_types'; the value is the
				// post-type slug.
				$slug  = 'post_types';
				$value = [ $content_type_name ];
			} else {
				// A rule targeting individual objects keys under 'specific_posts'; the
				// value is the post IDs.
				$slug  = 'specific_posts';
				$value = $object_ids;
			}

			$existing_key = array_search( $slug, array_column( $ac_rules, 'slug' ), true );
			if ( false !== $existing_key ) {
				// Merge values into the existing rule for this slug (post-type slugs or
				// object IDs), de-duplicated.
				$ac_rules[ $existing_key ]['value'] = array_values(
					array_unique(
						array_merge( $ac_rules[ $existing_key ]['value'], $value )
					)
				);
			} else {
				$ac_rules[] = [
					'slug'  => $slug,
					'value' => $value,
				];
			}
		}

		// Canonicalize 'post_types' value ordering. Post-type slugs are the only
		// non-numeric rule values, and compute_rules_fingerprint() orders values with
		// SORT_NUMERIC (under which every non-numeric string compares as 0, leaving
		// them unsorted). Sorting the slugs here keeps two plans that restrict the
		// same post types in a different rule order from producing different
		// fingerprints and splitting into duplicate gates.
		foreach ( $ac_rules as &$ac_rule ) {
			if ( 'post_types' === $ac_rule['slug'] ) {
				sort( $ac_rule['value'], SORT_STRING );
			}
		}
		unset( $ac_rule );

		return $ac_rules;
	}

	/**
	 * Find the np_memberships_gate post for a given plan ID.
	 *
	 * Looks for a plan-specific gate first, then optionally falls back to the
	 * "Primary" gate (the one with no `plans` meta).
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

		// Fall back to the Primary gate (no `plans` meta).
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
	 *   `woocommerce-memberships/non-member-content` block (the gate/upsell shown to
	 *   non-members).
	 * - Custom access layout: inner block content of the top-level
	 *   `woocommerce-memberships/member-content` block (shown to paying members).
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
			if ( 'woocommerce-memberships/non-member-content' === $block['blockName'] && null === $registration_markup ) {
				$registration_markup = self::serialize_gate_inner_blocks( $block['innerBlocks'] );
			}
			if ( 'woocommerce-memberships/member-content' === $block['blockName'] ) {
				$custom_access_markup = self::serialize_gate_inner_blocks( $block['innerBlocks'] );
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
