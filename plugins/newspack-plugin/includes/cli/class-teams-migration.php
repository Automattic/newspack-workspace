<?php
/**
 * WP-CLI commands to migrate WooCommerce Memberships (Teams and manual plans) to
 * Newspack Access Control group subscriptions, plus a backfill for group managers.
 *
 * Ported from the standalone `migrate-memberships` drop-in so the tooling ships
 * with the plugin (CLI classes load only under WP_CLI, so there is no web-request
 * cost) and writes through the real Group_Subscription data layer.
 *
 * Member and manager writes go through update_members()/add_manager(), which fire
 * only the group cache-reset hook — no data events, ESP sync, or emails. WooCommerce
 * transactional emails are suppressed during runs that create subscriptions. Note
 * that activating created subscriptions still fires the standard
 * `woocommerce_subscription_status_*` actions, which downstream ESP/network sync may
 * listen to; on a large migration prefer a low-traffic window.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use Newspack\Group_Subscription;
use Newspack\Reader_Activation;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Teams / Memberships migration CLI commands.
 */
class Teams_Migration {

	/**
	 * The WooCommerce Memberships for Teams per-member role user meta key template.
	 * Interpolated with the team post ID: `_wc_memberships_for_teams_team_{id}_role`.
	 *
	 * @var string
	 */
	const TEAM_ROLE_META_KEY_TEMPLATE = '_wc_memberships_for_teams_team_%d_role';

	/**
	 * Migrate all active team memberships to Newspack group subscriptions.
	 *
	 * For teams already linked to an active subscription: enables the group
	 * subscription settings, adds all team members as group members, and promotes
	 * any team member whose Teams role is `manager` to a group manager.
	 *
	 * For teams with no linked subscription: searches for an existing active group
	 * subscription owned by the team owner and re-uses it. If none is found,
	 * creates a new $0 subscription on the given product.
	 *
	 * The command is idempotent — re-running it updates existing group
	 * subscriptions in place rather than creating duplicates. When --product-id is
	 * supplied, every re-used subscription has its line items replaced with the
	 * migration product.
	 *
	 * Dry-run by default; pass --live to write.
	 *
	 * ## OPTIONS
	 *
	 * [--product-id=<id>]
	 * : Product ID to assign to newly-created subscriptions. When supplied, also overwrites the product on any existing subscription that is re-used. Required unless --skip-unlinked is passed.
	 *
	 * [--live]
	 * : Apply the changes. Without this flag the command runs as a dry-run and writes nothing.
	 *
	 * [--skip-unlinked]
	 * : Skip teams that have no linked subscription. Skipped teams are listed in a separate table at the end.
	 *
	 * [--only-unlinked]
	 * : Only process teams that have no linked subscription. Use to safely re-run the command for previously skipped teams.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack migrate-teams --product-id=519858
	 *     wp newspack migrate-teams --product-id=519858 --live
	 *     wp newspack migrate-teams --skip-unlinked --live
	 *     wp newspack migrate-teams --product-id=519858 --only-unlinked --live
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 *
	 * @return void
	 */
	public function migrate_teams( $args, $assoc_args ) {
		$product_id    = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'product-id', 0 );
		$dry_run       = ! (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'live', false );
		$skip_unlinked = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-unlinked', false );
		$only_unlinked = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'only-unlinked', false );

		// Pre-flight checks.
		if ( ! $product_id && ! $skip_unlinked ) {
			WP_CLI::error( 'Missing required option: --product-id=<id>. Pass --skip-unlinked if you only want to process teams already linked to a subscription.' );
		}

		if ( ! function_exists( 'wcs_create_subscription' ) || ! function_exists( 'wcs_get_subscription' ) ) {
			WP_CLI::error( 'WooCommerce Subscriptions is not active. Aborting.' );
		}

		$migration_product = $product_id ? \wc_get_product( $product_id ) : null;
		$billing_period    = 'month';
		$billing_interval  = 1;
		if ( $product_id && ! $migration_product ) {
			WP_CLI::error( sprintf( 'Product ID %d not found. Aborting.', $product_id ) );
		}

		if ( $migration_product ) {
			WP_CLI::line( sprintf( 'Using product: "%s" (ID: %d)', $migration_product->get_name(), $migration_product->get_id() ) );
			// For a variable-subscription parent these meta live on the variation, not
			// the parent, and come back empty; default them the same way
			// create_group_subscription()/create_individual_subscription() do so an
			// empty period/interval never flows into wcs_create_subscription().
			$period_meta      = \get_post_meta( $product_id, '_subscription_period', true );
			$interval_meta    = \get_post_meta( $product_id, '_subscription_period_interval', true );
			$billing_period   = '' !== $period_meta ? $period_meta : 'month';
			$billing_interval = '' !== $interval_meta ? (int) $interval_meta : 1;
		}

		if ( $dry_run ) {
			WP_CLI::line( '' );
			WP_CLI::line( '*** DRY RUN MODE — no data will be modified. Pass --live to apply. ***' );
			WP_CLI::line( '' );
		}

		// Suppress WooCommerce emails and Newspack data-event dispatches (ESP/webhooks/
		// network sync) so this data backfill doesn't masquerade as real new-subscription
		// activity during the run.
		self::suppress_woocommerce_emails();
		self::suppress_data_events();

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

		$total = count( $teams );
		WP_CLI::line( sprintf( 'Found %d active team membership(s). Starting migration…', $total ) );
		WP_CLI::line( '' );

		$summary  = [];
		$skipped  = [];
		$progress = \WP_CLI\Utils\make_progress_bar( 'Migrating teams', $total );

		foreach ( $teams as $team_id ) {
			$team = \get_post( $team_id );
			$progress->tick();

			$owner_id   = (int) $team->post_author;
			$seat_count = (int) \get_post_meta( $team_id, '_seat_count', true ); // 0 = unlimited.
			$raw_sub_id = (int) \get_post_meta( $team_id, '_subscription_id', true );
			$end_date   = self::normalise_date( \get_post_meta( $team_id, '_membership_end_date', true ) );
			$start_date = self::normalise_date( $team->post_date_gmt );
			$start_date = '' !== $start_date ? $start_date : gmdate( 'Y-m-d H:i:s' );

			// _member_id is repeatable meta — one entry per seat-holding member. The owner
			// is present only when they take a seat (governed by WC Teams' global
			// owners_must_take_seat option and the per-order team_owner_takes_seat flag);
			// an owner who takes no seat holds no _member_id entry.
			$member_ids = array_values(
				array_unique(
					array_filter(
						array_map( 'absint', (array) \get_post_meta( $team_id, '_member_id', false ) )
					)
				)
			);

			// Map the Teams seat count to the owner-inclusive group limit. Whether
			// _seat_count already counts the owner depends on WC Teams' "owner takes a
			// seat" configuration, recorded exactly by whether the owner holds a _member_id
			// entry (see above). See map_team_seats_to_group_limit() for the mapping.
			$owner_is_team_member = in_array( $owner_id, $member_ids, true );
			$group_limit          = self::map_team_seats_to_group_limit( $seat_count, $owner_is_team_member );

			// --skip-unlinked: skip teams with no linked subscription.
			if ( $skip_unlinked && ! $raw_sub_id ) {
				$owner     = \get_user_by( 'id', $owner_id );
				$skipped[] = [
					'team_id'    => $team_id,
					'owner'      => $owner ? $owner->user_email : "user:{$owner_id}",
					'seat_limit' => 0 === $seat_count ? 'Unlimited' : $seat_count,
					'created'    => $start_date,
					'expires'    => '' !== $end_date ? $end_date : '—',
				];
				continue;
			}

			// --only-unlinked: skip teams that already have a linked subscription.
			if ( $only_unlinked && $raw_sub_id ) {
				continue;
			}

			$subscription  = null;
			$created_new   = false;
			$errors        = [];
			$members_added = 0;

			// Attempt to reuse the team's linked subscription if it is active.
			if ( $raw_sub_id ) {
				$existing_sub = \wcs_get_subscription( $raw_sub_id );
				if ( $existing_sub && 'active' === $existing_sub->get_status() ) {
					$subscription = $existing_sub;
					if ( 'yes' === $existing_sub->get_meta( '_newspack_group_subscription_enabled' ) ) {
						WP_CLI::line( sprintf( 'Team %d: subscription %d is already a group subscription — re-updating.', $team_id, $raw_sub_id ) );
					}
				} else {
					$status_label = $existing_sub ? $existing_sub->get_status() : 'not found';
					WP_CLI::warning( sprintf( 'Team %d: linked subscription %d is not active (status: %s) — searching for an existing group subscription owned by the team owner.', $team_id, $raw_sub_id, $status_label ) );
				}
			}

			// If no linked subscription was reusable, look for an existing group
			// subscription owned by the team owner so re-running the command does not
			// create duplicate subscriptions.
			if ( ! $subscription ) {
				$owner_group_sub = self::find_group_subscription_for_owner( $owner_id );
				if ( $owner_group_sub ) {
					$subscription = $owner_group_sub;
					WP_CLI::line( sprintf( 'Team %d: found existing group subscription %d for owner %d — re-updating.', $team_id, $owner_group_sub->get_id(), $owner_id ) );
					// An owner who owns several unlinked teams reuses the same group
					// subscription for each, so their members merge into one group and
					// the name/limit take the last team processed. Warn when the reused
					// subscription already carries a different group name so the operator
					// can spot an unintended merge.
					$existing_name = $owner_group_sub->get_meta( '_newspack_group_subscription_name' );
					if ( '' !== $existing_name && $existing_name !== $team->post_title ) {
						WP_CLI::warning( sprintf( 'Team %d ("%s"): merging into subscription %d already named "%s" (owner %d owns multiple teams). Members will be combined and the group renamed.', $team_id, $team->post_title, $owner_group_sub->get_id(), $existing_name, $owner_id ) );
					}
				}
			}

			// Create a new subscription when none resolved above. This needs a
			// migration product; with --skip-unlinked and no --product-id a team
			// whose linked subscription is inactive/missing (so it is not skipped)
			// can reach this point with no product to create against. Record the
			// team as an error and move on rather than fataling on a null product
			// inside create_migration_subscription().
			if ( ! $subscription && ! $migration_product ) {
				$errors[] = 'no reusable subscription and no --product-id supplied to create one';
				WP_CLI::warning( sprintf( 'Team %d: linked subscription is inactive/missing and no --product-id was supplied to create a replacement — skipping. Re-run with --product-id to migrate these teams.', $team_id ) );
				$summary[] = self::summary_row( $team_id, 'ERROR', 0, 0, $seat_count, false, $errors );
				continue;
			}

			// Create a new subscription when none resolved above.
			if ( ! $subscription ) {
				$created_new = true;
				if ( ! $dry_run ) {
					$new_sub = self::create_migration_subscription( $owner_id, $migration_product, $billing_period, $billing_interval, $start_date, $end_date, $errors, $team_id );
					if ( ! $new_sub ) {
						$summary[] = self::summary_row( $team_id, 'ERROR', 0, 0, $seat_count, true, $errors );
						continue;
					}
					$subscription = $new_sub;
				}
			}

			if ( $created_new && $dry_run ) {
				$subscription_id = '(dry-run)';
				$sub_owner_id    = $owner_id;
			} else {
				$subscription_id = $subscription->get_id();
				$sub_owner_id    = (int) $subscription->get_user_id();
			}

			// Enable the group and set its name up front. The seat limit is deferred
			// until after members are added (below) so update_members()' limit gate
			// can't reject existing team members mid-migration — a new subscription
			// starts with no limit meta (unlimited), so the adds are never gated. A
			// reused subscription that already carries a limit is still gated by it
			// during adds; any rejected member is surfaced in the errors below.
			if ( ! $dry_run ) {
				$subscription->update_meta_data( '_newspack_group_subscription_enabled', 'yes' );
				$subscription->update_meta_data( '_newspack_group_subscription_name', $team->post_title );
				$subscription->save();
			}

			// When re-using an existing subscription with --product-id, overwrite its
			// line items with the migration product so re-running aligns every
			// subscription with the product passed via --product-id.
			if ( ! $created_new && $migration_product && ! $dry_run ) {
				self::replace_subscription_product( $subscription, $migration_product, $billing_period, $billing_interval, $start_date, $end_date, $errors, $team_id );
			}

			// Add team members as group members. If the team owner differs from the
			// subscription owner, add them as a group member too so they retain access.
			$users_to_add = $member_ids;
			if ( $owner_id !== $sub_owner_id && ! in_array( $owner_id, $users_to_add, true ) ) {
				$users_to_add[] = $owner_id;
			}

			$non_reader_skips = 0;
			foreach ( $users_to_add as $member_id ) {
				if ( ! $member_id || $member_id === $sub_owner_id ) {
					continue;
				}
				if ( $dry_run ) {
					// A member would be added if they are a reader and not already a member.
					if ( Reader_Activation::is_user_reader( $member_id ) && ! Group_Subscription::user_is_member( $member_id, $subscription ) ) {
						++$members_added;
					}
					continue;
				}
				$status = self::add_group_member( $subscription, $member_id );
				if ( \is_wp_error( $status ) ) {
					$errors[] = sprintf( 'add member %d: %s', $member_id, $status->get_error_message() );
				} elseif ( 'added' === $status ) {
					++$members_added;
				} elseif ( 'not_reader' === $status ) {
					++$non_reader_skips;
				}
			}
			if ( $non_reader_skips ) {
				WP_CLI::warning( sprintf( 'Team %d: %d team member(s) skipped — not readers (e.g. administrators/editors), who already have full access.', $team_id, $non_reader_skips ) );
			}

			// Set the seat limit now that members are in, using the owner-inclusive
			// $group_limit derived from the team's seat count above.
			if ( ! $dry_run ) {
				$subscription->update_meta_data( '_newspack_group_subscription_limit', $group_limit );
				$subscription->save();

				// The team's seat count is treated as authoritative, so a reused
				// subscription that already carried more people than the team allows is
				// saved in an over-limit state. Warn so an operator can notice the shrink.
				$member_count = Group_Subscription::get_member_count( $subscription );
				if ( $group_limit > 0 && $member_count > $group_limit ) {
					WP_CLI::warning( sprintf( 'Team %d: seat limit set to %d but the group holds %d people (owner-inclusive) — the group is now over its limit.', $team_id, $group_limit, $member_count ) );
				}
			}

			// Promote team members whose Teams role is `manager` to group managers.
			if ( $dry_run ) {
				$managers_promoted = self::count_dry_run_manager_promotions( $subscription, $team_id, $member_ids, $sub_owner_id );
			} else {
				$manager_result    = self::promote_managers_from_team_roles( $subscription, $team_id, $member_ids, false );
				$managers_promoted = count( $manager_result['promoted'] );
			}

			$verb = $created_new ? 'new' : 'existing';
			// Downgrade to a warning when anything went wrong, so a green success line
			// never masks members that silently didn't migrate. The message is generic
			// because $errors also collects non-member issues, such as a date-set
			// failure on a reused subscription; the specifics are printed below.
			$report = sprintf( 'Team %d: Migrated team membership to %s subscription %s, added %d group member(s), promoted %d manager(s).', $team_id, $verb, $subscription_id, $members_added, $managers_promoted );
			if ( empty( $errors ) ) {
				WP_CLI::success( $report );
			} else {
				WP_CLI::warning( $report . sprintf( ' %d issue(s) encountered — see errors below.', count( $errors ) ) );
			}

			foreach ( $errors as $err ) {
				WP_CLI::warning( sprintf( 'Team %d: ERROR — %s', $team_id, $err ) );
			}

			$summary[] = self::summary_row( $team_id, $subscription_id, $members_added, $managers_promoted, $group_limit, $created_new, $errors );

			// Free the per-request object cache accumulated by the saves above so memory
			// stays bounded across a large team list.
			\WP_CLI\Utils\wp_clear_object_cache();
		}

		$progress->finish();

		// Summary table.
		WP_CLI::line( '' );
		WP_CLI::line( $dry_run ? '=== DRY RUN SUMMARY ===' : '=== MIGRATION SUMMARY ===' );
		WP_CLI::line( '' );

		\WP_CLI\Utils\format_items(
			'table',
			array_map(
				function ( $row ) {
					return [
						'Team'     => $row['team_id'],
						'Sub'      => $row['subscription_id'],
						'Members'  => $row['members_added'],
						'Managers' => $row['managers_promoted'],
						'Limit'    => 0 === $row['seat_limit'] ? 'Unlimited' : $row['seat_limit'],
						'New?'     => $row['created_new'] ? 'Y' : 'N',
					];
				},
				$summary
			),
			[ 'Team', 'Sub', 'Members', 'Managers', 'Limit', 'New?' ]
		);

		// Errors section.
		$errored_rows = array_filter( $summary, fn( $r ) => ! empty( $r['errors'] ) );
		if ( ! empty( $errored_rows ) ) {
			WP_CLI::line( '' );
			WP_CLI::line( '=== ERRORS ===' );
			foreach ( $errored_rows as $row ) {
				WP_CLI::warning( sprintf( 'Team %d (sub %s): %s', $row['team_id'], $row['subscription_id'], implode( '; ', $row['errors'] ) ) );
			}
		}

		// Skipped teams section.
		if ( ! empty( $skipped ) ) {
			WP_CLI::line( '' );
			WP_CLI::line( sprintf( '=== SKIPPED TEAMS (no linked subscription) — %d total ===', count( $skipped ) ) );
			WP_CLI::line( '' );
			\WP_CLI\Utils\format_items( 'table', $skipped, [ 'team_id', 'owner', 'seat_limit', 'created', 'expires' ] );
		}

		$new_count = count( array_filter( $summary, fn( $r ) => $r['created_new'] ) );
		WP_CLI::line( '' );
		WP_CLI::success( sprintf( 'Done. %d team(s) processed: %d used existing subscriptions, %d had new subscriptions created, %d skipped, %d had error(s).', count( $summary ), count( $summary ) - $new_count, $new_count, count( $skipped ), count( $errored_rows ) ) );
	}

	/**
	 * Enable group subscription settings on WooCommerce team membership products.
	 *
	 * Updates all published subscription products that have the "Team membership"
	 * option enabled, setting their group subscription `enabled` meta to `yes` and
	 * their `limit` meta to match the product's "Maximum member count". For variable
	 * subscriptions, both the parent product and each subscription variation are
	 * updated so the setting is available at whichever level WooCommerce Subscriptions
	 * resolves the product ID.
	 *
	 * Dry-run by default; pass --live to write.
	 *
	 * ## OPTIONS
	 *
	 * [--live]
	 * : Apply the changes. Without this flag the command runs as a dry-run and writes nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack migrate-team-products
	 *     wp newspack migrate-team-products --live
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 *
	 * @return void
	 */
	public function migrate_team_products( $args, $assoc_args ) {
		$dry_run = ! (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'live', false );

		if ( $dry_run ) {
			WP_CLI::line( '' );
			WP_CLI::line( '*** DRY RUN MODE — no data will be modified. Pass --live to apply. ***' );
			WP_CLI::line( '' );
		}

		$product_ids = \get_posts(
			[
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'   => '_wc_memberships_for_teams_has_team_membership',
						'value' => 'yes',
					],
				],
			]
		);

		$total = count( $product_ids );
		if ( ! $total ) {
			WP_CLI::warning( 'No published products with the "Team membership" option enabled were found.' );
			return;
		}

		WP_CLI::line( sprintf( 'Found %d product(s) with team membership enabled. Starting update…', $total ) );
		WP_CLI::line( '' );

		$summary  = [];
		$progress = \WP_CLI\Utils\make_progress_bar( 'Updating products', $total );

		foreach ( $product_ids as $product_id ) {
			$progress->tick();

			$product = \wc_get_product( $product_id );
			if ( ! $product ) {
				WP_CLI::warning( sprintf( 'Product %d could not be loaded — skipping.', $product_id ) );
				continue;
			}

			$max_members = (int) $product->get_meta( '_wc_memberships_for_teams_max_member_count', true );

			// Collect the IDs to update: always the parent; plus any
			// subscription_variation children for variable subscriptions.
			$ids_to_update = [ $product_id ];
			if ( $product->is_type( 'variable-subscription' ) || $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $variation_id ) {
					$variation = \wc_get_product( $variation_id );
					if ( $variation && $variation->is_type( 'subscription_variation' ) ) {
						$ids_to_update[] = $variation_id;
					}
				}
			}

			if ( ! $dry_run ) {
				foreach ( $ids_to_update as $id ) {
					$p = \wc_get_product( $id );
					if ( ! $p ) {
						continue;
					}
					$p->update_meta_data( '_newspack_group_subscription_enabled', 'yes' );
					$p->update_meta_data( '_newspack_group_subscription_limit', $max_members );
					$p->save();
				}
			}

			$variation_count = count( $ids_to_update ) - 1;
			WP_CLI::success( sprintf( 'Product %d ("%s"): %s enabled=yes, limit=%s%s.', $product_id, $product->get_name(), $dry_run ? 'would set' : 'set', 0 === $max_members ? 'Unlimited' : $max_members, $variation_count > 0 ? sprintf( ' (+ %d variation(s))', $variation_count ) : '' ) );

			$summary[] = [
				'product_id'   => $product_id,
				'product_name' => $product->get_name(),
				'limit'        => 0 === $max_members ? 'Unlimited' : $max_members,
				'variations'   => $variation_count,
			];

			// Free the per-request object cache so memory stays bounded across a large
			// product list.
			\WP_CLI\Utils\wp_clear_object_cache();
		}

		$progress->finish();

		WP_CLI::line( '' );
		WP_CLI::line( $dry_run ? '=== DRY RUN SUMMARY ===' : '=== UPDATE SUMMARY ===' );
		WP_CLI::line( '' );

		\WP_CLI\Utils\format_items(
			'table',
			array_map(
				fn( $row ) => [
					'Product'    => $row['product_id'],
					'Name'       => $row['product_name'],
					'Limit'      => $row['limit'],
					'Variations' => $row['variations'],
				],
				$summary
			),
			[ 'Product', 'Name', 'Limit', 'Variations' ]
		);

		WP_CLI::line( '' );
		WP_CLI::success( sprintf( 'Done. %d product(s) %s.', count( $summary ), $dry_run ? 'would be updated' : 'updated' ) );
	}

	/**
	 * Create free subscriptions for users with manually-assigned memberships.
	 *
	 * Iterates through membership plans with manual-only access and creates free
	 * WooCommerce Subscriptions for active members who do not have the
	 * `edit_others_posts` capability (i.e. are not administrators/editors).
	 *
	 * Unlike migrate-teams, this command is NOT idempotent: it creates a new
	 * subscription on every run (a per-plan group subscription under --as-group,
	 * one per member otherwise). Run it once per plan set; re-running duplicates
	 * subscriptions. Dry-run by default; pass --live to write.
	 *
	 * Under --as-group, members are added through the group data layer, which adds
	 * readers only — a member on a non-reader role is skipped (reported inline),
	 * whereas individual mode gives every member their own subscription.
	 *
	 * ## OPTIONS
	 *
	 * --product-id=<id>
	 * : The product ID to use when creating the new subscriptions.
	 *
	 * [--plan-ids=<ids>]
	 * : Comma-delimited list of membership plan IDs to process. If omitted, all published plans with _access_method = manual-only are used.
	 *
	 * [--skip-domains=<domains>]
	 * : Comma-delimited list of email domains to skip (e.g. example.com,example.org). Any user whose email address belongs to one of these domains will be skipped.
	 *
	 * [--as-group]
	 * : Instead of creating one subscription per member, create a single $0 group subscription per plan and add all qualifying members as group members. Requires --group-owner-id.
	 *
	 * [--group-owner-id=<id>]
	 * : User ID to set as the owner of each group subscription created when --as-group is used. Required when --as-group is present.
	 *
	 * [--live]
	 * : Apply the changes. Without this flag the command runs as a dry-run and writes nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack migrate-manual-members --product-id=519858
	 *     wp newspack migrate-manual-members --product-id=519858 --live
	 *     wp newspack migrate-manual-members --product-id=519858 --plan-ids=12,34,56 --live
	 *     wp newspack migrate-manual-members --product-id=519858 --as-group --group-owner-id=1 --live
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 *
	 * @return void
	 */
	public function migrate_manual_members( $args, $assoc_args ) {
		$dry_run        = ! (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'live', false );
		$as_group       = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'as-group', false );
		$group_owner_id = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'group-owner-id', 0 );
		$product_id     = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'product-id', 0 );
		$plan_ids       = \WP_CLI\Utils\get_flag_value( $assoc_args, 'plan-ids', '' );
		$skip_domains   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-domains', '' );
		$skip_domains   = ! empty( $skip_domains )
			? array_filter( array_map( 'trim', explode( ',', strtolower( $skip_domains ) ) ) )
			: [];

		if ( ! function_exists( 'wcs_create_subscription' ) ) {
			WP_CLI::error( 'WooCommerce Subscriptions is not active. Aborting.' );
		}

		if ( ! $product_id ) {
			WP_CLI::error( 'Missing required option: --product-id=<id>.' );
		}

		$product = \wc_get_product( $product_id );
		if ( ! $product ) {
			WP_CLI::error( sprintf( 'Product %d could not be found.', $product_id ) );
		}

		if ( $as_group ) {
			if ( ! $group_owner_id ) {
				WP_CLI::error( '--as-group requires --group-owner-id=<id>.' );
			}
			if ( ! \get_userdata( $group_owner_id ) ) {
				WP_CLI::error( sprintf( 'User %d (--group-owner-id) could not be found.', $group_owner_id ) );
			}
		}

		if ( $dry_run ) {
			WP_CLI::line( '' );
			WP_CLI::line( '*** DRY RUN MODE — no data will be modified. Pass --live to apply. ***' );
			WP_CLI::line( '' );
		}

		// Suppress WooCommerce emails and Newspack data-event dispatches (ESP/webhooks/
		// network sync) so this data backfill doesn't masquerade as real new-subscription
		// activity during the run.
		self::suppress_woocommerce_emails();
		self::suppress_data_events();

		if ( ! empty( $plan_ids ) ) {
			$plan_ids = array_filter( array_map( 'absint', explode( ',', $plan_ids ) ) );
		} else {
			$plan_ids = self::get_manual_only_plan_ids();
		}

		if ( empty( $plan_ids ) ) {
			WP_CLI::warning( 'No membership plans found to process.' );
			return;
		}

		WP_CLI::line( sprintf( 'Processing %d plan(s): %s', count( $plan_ids ), implode( ', ', $plan_ids ) ) );
		WP_CLI::line( '' );

		$summary = [];

		foreach ( $plan_ids as $plan_id ) {
			$plan = \get_post( $plan_id );
			if ( ! $plan || 'wc_membership_plan' !== $plan->post_type ) {
				WP_CLI::warning( sprintf( 'Plan %d is not a valid membership plan — skipping.', $plan_id ) );
				continue;
			}

			WP_CLI::line( sprintf( '── Plan %d: "%s" ──', $plan_id, $plan->post_title ) );

			$memberships = \get_posts(
				[
					'post_type'      => 'wc_user_membership',
					'post_status'    => 'wcm-active',
					'post_parent'    => $plan_id,
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				]
			);

			if ( empty( $memberships ) ) {
				WP_CLI::line( sprintf( '  No active memberships found for plan %d.', $plan_id ) );
				WP_CLI::line( '' );
				continue;
			}

			WP_CLI::line( sprintf( '  Found %d active membership(s).', count( $memberships ) ) );

			// Group mode: create one shared group subscription for this plan.
			$group_subscription = null;
			if ( $as_group && ! $dry_run ) {
				$group_subscription = self::create_group_subscription( $product_id, $product, $plan->post_title, $group_owner_id );
				if ( \is_wp_error( $group_subscription ) ) {
					WP_CLI::warning( sprintf( '  Plan %d: failed to create group subscription — %s. Skipping plan.', $plan_id, $group_subscription->get_error_message() ) );
					WP_CLI::line( '' );
					continue;
				}
				WP_CLI::success( sprintf( '  Created group subscription %d for plan "%s".', $group_subscription->get_id(), $plan->post_title ) );
			}

			foreach ( $memberships as $membership_id ) {
				// Free the per-request object cache accumulated by prior iterations so
				// memory stays bounded across a large (unbounded) membership list. The
				// held $group_subscription / $product objects are unaffected.
				\WP_CLI\Utils\wp_clear_object_cache();

				$membership_post = \get_post( $membership_id );
				$user_id         = (int) $membership_post->post_author;

				// Skip users with edit_others_posts (admins/editors).
				if ( \user_can( $user_id, 'edit_others_posts' ) ) {
					WP_CLI::line( sprintf( '  Membership %d (user %d): skipped — user has edit_others_posts.', $membership_id, $user_id ) );
					continue;
				}

				$user = \get_userdata( $user_id );
				if ( ! $user ) {
					WP_CLI::warning( sprintf( '  Membership %d: user %d not found — skipping.', $membership_id, $user_id ) );
					continue;
				}

				// Skip users whose email domain is in the skip-domains list. strrchr
				// returns false for an address with no `@`; guard it so substr( false, … )
				// doesn't trip a PHP 8 deprecation.
				if ( ! empty( $skip_domains ) ) {
					$at_and_domain = strrchr( $user->user_email, '@' );
					$user_domain   = $at_and_domain ? strtolower( substr( $at_and_domain, 1 ) ) : '';
					if ( in_array( $user_domain, $skip_domains, true ) ) {
						WP_CLI::line( sprintf( '  Membership %d (user %d, %s): skipped — domain in skip list.', $membership_id, $user_id, $user->user_email ) );
						continue;
					}
				}

				// Group mode: add the user as a group member.
				if ( $as_group ) {
					if ( $dry_run ) {
						WP_CLI::line( sprintf( '  [DRY RUN] Would add user %d (%s) as group member.', $user_id, $user->user_email ) );
					} else {
						$status = self::add_group_member( $group_subscription, $user_id );
						$note   = \is_wp_error( $status ) ? ' (error: ' . $status->get_error_message() . ')' : ( 'added' === $status ? '' : ' (' . $status . ' — skipped)' );
						WP_CLI::line( sprintf( '  Membership %d → user %d (%s) added as group member%s.', $membership_id, $user_id, $user->user_email, $note ) );
					}
					$summary[] = [
						'membership_id' => $membership_id,
						'user_id'       => $user_id,
						'user_email'    => $user->user_email,
						'start_date'    => '—',
						'end_date'      => '—',
						'sub_id'        => $dry_run ? '(dry run - group)' : $group_subscription->get_id(),
					];
					continue;
				}

				// Individual mode: skip a member who already owns an active subscription
				// this migration created for the same product, so a re-run is safe and
				// doesn't stack duplicate $0 subscriptions.
				if ( self::member_has_migration_subscription( $user_id, $product_id ) ) {
					WP_CLI::line( sprintf( '  Membership %d (user %d, %s): skipped — already has an active migration subscription for this product.', $membership_id, $user_id, $user->user_email ) );
					continue;
				}

				// Individual mode: resolve dates.
				$start_date_raw = \get_post_meta( $membership_id, '_start_date', true );
				$end_date_raw   = \get_post_meta( $membership_id, '_end_date', true );
				$start_date     = ! empty( $start_date_raw ) ? gmdate( 'Y-m-d H:i:s', strtotime( $start_date_raw ) ) : \current_time( 'mysql', true );
				$end_date       = ! empty( $end_date_raw ) ? gmdate( 'Y-m-d H:i:s', strtotime( $end_date_raw ) ) : '';
				$has_end_date   = ! empty( $end_date ) && strtotime( $end_date ) > time();

				if ( $dry_run ) {
					WP_CLI::line( sprintf( '  [DRY RUN] Would create subscription for user %d (%s): start=%s%s', $user_id, $user->user_email, $start_date, $has_end_date ? ', end=' . $end_date : ' (no end date)' ) );
					$summary[] = [
						'membership_id' => $membership_id,
						'user_id'       => $user_id,
						'user_email'    => $user->user_email,
						'start_date'    => $start_date,
						'end_date'      => $has_end_date ? $end_date : '—',
						'sub_id'        => '(dry run)',
					];
					continue;
				}

				$subscription = self::create_individual_subscription( $user_id, $product, $product_id, $start_date, $end_date, $has_end_date, $membership_id );
				if ( \is_wp_error( $subscription ) ) {
					WP_CLI::warning( sprintf( '  Membership %d (user %d): failed to create subscription — %s', $membership_id, $user_id, $subscription->get_error_message() ) );
					continue;
				}

				$sub_id = $subscription->get_id();
				WP_CLI::success( sprintf( '  Membership %d → subscription %d created for user %d (%s).', $membership_id, $sub_id, $user_id, $user->user_email ) );

				$summary[] = [
					'membership_id' => $membership_id,
					'user_id'       => $user_id,
					'user_email'    => $user->user_email,
					'start_date'    => $start_date,
					'end_date'      => $has_end_date ? $end_date : '—',
					'sub_id'        => $sub_id,
				];
			}

			WP_CLI::line( '' );
		}

		if ( empty( $summary ) ) {
			WP_CLI::line( 'No subscriptions were created.' );
			return;
		}

		WP_CLI::line( $dry_run ? '=== DRY RUN SUMMARY ===' : '=== MIGRATION SUMMARY ===' );
		WP_CLI::line( '' );

		\WP_CLI\Utils\format_items(
			'table',
			array_map(
				fn( $row ) => [
					'Membership' => $row['membership_id'],
					'User'       => $row['user_id'],
					'Email'      => $row['user_email'],
					'Start'      => $row['start_date'],
					'End'        => $row['end_date'],
					'Sub'        => $row['sub_id'],
				],
				$summary
			),
			[ 'Membership', 'User', 'Email', 'Start', 'End', 'Sub' ]
		);

		WP_CLI::line( '' );
		if ( $as_group ) {
			WP_CLI::success( sprintf( 'Done. %d member(s) %s group subscription(s).', count( $summary ), $dry_run ? 'would be added to' : 'added to' ) );
		} else {
			WP_CLI::success( sprintf( 'Done. %d subscription(s) %s.', count( $summary ), $dry_run ? 'would be created' : 'created' ) );
		}
	}

	/**
	 * Backfill group managers from WooCommerce Teams manager roles.
	 *
	 * The `migrate-teams` command flattened every Teams member to a plain group
	 * member on already-migrated sites, dropping the manager designation. This
	 * re-designates managers: for every published team, it resolves the group
	 * subscription (the team's linked active subscription, else an active group
	 * subscription owned by the team owner) and promotes each member whose Teams
	 * role is `manager` — and who is already a group member — to a group manager.
	 *
	 * Dry-run by default; pass --live to write. Idempotent — members already
	 * managing are left untouched.
	 *
	 * ## OPTIONS
	 *
	 * [--live]
	 * : Apply the changes. Without this flag the command runs as a dry-run and writes nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack backfill-team-managers
	 *     wp newspack backfill-team-managers --live
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 *
	 * @return void
	 */
	public function backfill_team_managers( $args, $assoc_args ) {
		$live = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'live', false );

		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			WP_CLI::error( 'WooCommerce Subscriptions is not active. Aborting.' );
		}

		if ( ! $live ) {
			WP_CLI::line( '' );
			WP_CLI::line( '*** DRY RUN MODE — no data will be modified. Pass --live to apply. ***' );
			WP_CLI::line( '' );
		}

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

		$total = count( $teams );
		WP_CLI::line( sprintf( 'Found %d team(s). Scanning for managers to backfill…', $total ) );
		WP_CLI::line( '' );

		$summary     = [];
		$unresolved  = [];
		$total_added = 0;
		$progress    = \WP_CLI\Utils\make_progress_bar( 'Backfilling managers', $total );

		foreach ( $teams as $team_id ) {
			$progress->tick();
			$result = self::backfill_team_managers_for_team( $team_id, $live );

			if ( ! $result['resolved'] ) {
				$owner        = \get_user_by( 'id', $result['owner_id'] );
				$unresolved[] = [
					'team_id' => $team_id,
					'owner'   => $owner ? $owner->user_email : "user:{$result['owner_id']}",
					'reason'  => 'No active group subscription found (linked or owner-owned).',
				];
				continue;
			}

			$total_added += count( $result['promoted'] );
			$summary[]    = [
				'team_id'         => $team_id,
				'subscription_id' => $result['subscription_id'],
				'managers_found'  => $result['found'],
				'already_manager' => count( $result['already'] ),
				'added'           => count( $result['promoted'] ),
				'not_a_member'    => count( $result['not_member'] ),
			];
		}

		$progress->finish();

		WP_CLI::line( '' );
		WP_CLI::line( $live ? '=== BACKFILL SUMMARY ===' : '=== DRY RUN SUMMARY ===' );
		WP_CLI::line( '' );

		if ( ! empty( $summary ) ) {
			\WP_CLI\Utils\format_items(
				'table',
				array_map(
					fn( $row ) => [
						'Team'                        => $row['team_id'],
						'Sub'                         => $row['subscription_id'],
						'Found'                       => $row['managers_found'],
						'Already'                     => $row['already_manager'],
						$live ? 'Added' : 'Would add' => $row['added'],
						'Not a member'                => $row['not_a_member'],
					],
					$summary
				),
				[ 'Team', 'Sub', 'Found', 'Already', $live ? 'Added' : 'Would add', 'Not a member' ]
			);
		}

		if ( ! empty( $unresolved ) ) {
			WP_CLI::line( '' );
			WP_CLI::line( sprintf( '=== UNRESOLVED TEAMS — %d total ===', count( $unresolved ) ) );
			WP_CLI::line( '' );
			\WP_CLI\Utils\format_items( 'table', $unresolved, [ 'team_id', 'owner', 'reason' ] );
		}

		WP_CLI::line( '' );
		WP_CLI::success( sprintf( 'Done. %d manager(s) %s across %d resolved team(s); %d team(s) unresolved.', $total_added, $live ? 'promoted' : 'would be promoted', count( $summary ), count( $unresolved ) ) );
	}

	/**
	 * Backfill managers for a single team. Resolves the group subscription and
	 * promotes any member with the Teams `manager` role.
	 *
	 * Exposed for the command loop and for testing.
	 *
	 * @param int  $team_id The team post ID.
	 * @param bool $live    Whether to write (false = dry-run, resolves and reports only).
	 *
	 * @return array {
	 *     @type bool       $resolved        Whether a group subscription was resolved.
	 *     @type int        $owner_id        The team owner user ID.
	 *     @type int|string $subscription_id The resolved subscription ID, or '—'.
	 *     @type int        $found           Number of members with the manager role.
	 *     @type int[]      $promoted        User IDs promoted (or that would be).
	 *     @type int[]      $already         User IDs already managing.
	 *     @type int[]      $not_member      Manager-role user IDs that are not group members.
	 * }
	 */
	public static function backfill_team_managers_for_team( $team_id, $live ) {
		$team_id  = absint( $team_id );
		$team     = \get_post( $team_id );
		$owner_id = $team ? (int) $team->post_author : 0;

		$subscription = self::resolve_backfill_subscription( $team_id, $owner_id );
		if ( ! $subscription ) {
			return [
				'resolved'        => false,
				'owner_id'        => $owner_id,
				'subscription_id' => '—',
				'found'           => 0,
				'promoted'        => [],
				'already'         => [],
				'not_member'      => [],
			];
		}

		$member_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', (array) \get_post_meta( $team_id, '_member_id', false ) )
				)
			)
		);

		$result             = self::promote_managers_from_team_roles( $subscription, $team_id, $member_ids, ! $live );
		$result['resolved'] = true;
		$result['owner_id'] = $owner_id;
		$result['subscription_id'] = $subscription->get_id();
		$result['found']    = count( $result['promoted'] ) + count( $result['already'] ) + count( $result['not_member'] );
		return $result;
	}

	/**
	 * Promote team members whose Teams role is `manager` to group managers.
	 *
	 * Reflects the group's current membership (a candidate must already hold group
	 * membership to be promoted, mirroring add_manager()). Shared by migrate-teams
	 * (after members are added) and the manager backfill. Exposed for testing.
	 *
	 * @param \WC_Subscription $subscription The group subscription.
	 * @param int              $team_id      The team post ID (for the role meta lookup).
	 * @param int[]            $member_ids   Candidate member user IDs (the team member list).
	 * @param bool             $dry_run      When true, tally promotions without writing.
	 *
	 * @return array {
	 *     @type int[] $promoted   User IDs promoted (or that would be).
	 *     @type int[] $already    User IDs already managing.
	 *     @type int[] $not_member Manager-role user IDs that are not group members.
	 * }
	 */
	public static function promote_managers_from_team_roles( $subscription, $team_id, $member_ids, $dry_run ) {
		$owner_id = (int) $subscription->get_user_id();
		$result   = [
			'promoted'   => [],
			'already'    => [],
			'not_member' => [],
		];

		$existing_managers = array_map( 'intval', Group_Subscription::get_managers( $subscription ) );

		foreach ( array_values( array_unique( array_map( 'absint', (array) $member_ids ) ) ) as $user_id ) {
			// The owner is always a manager by virtue of ownership; never promote them.
			if ( ! $user_id || $user_id === $owner_id ) {
				continue;
			}

			$role = \get_user_meta( $user_id, sprintf( self::TEAM_ROLE_META_KEY_TEMPLATE, $team_id ), true );
			if ( 'manager' !== $role ) {
				continue;
			}

			if ( in_array( $user_id, $existing_managers, true ) ) {
				$result['already'][] = $user_id;
				continue;
			}

			if ( ! Group_Subscription::user_is_member( $user_id, $subscription ) ) {
				$result['not_member'][] = $user_id;
				continue;
			}

			if ( ! $dry_run ) {
				$promoted = Group_Subscription::add_manager( $subscription, $user_id );
				if ( \is_wp_error( $promoted ) ) {
					$result['not_member'][] = $user_id;
					continue;
				}
			}
			$result['promoted'][] = $user_id;
		}

		return $result;
	}

	/**
	 * Add a user as a group member via the Group_Subscription data layer.
	 *
	 * Routing through update_members() (rather than a raw user-meta write) records
	 * the joined-at timestamp and auto-enables the group. Readers only — the data
	 * layer skips administrators/editors and non-readers, who already have access.
	 * Exposed for testing.
	 *
	 * @param \WC_Subscription $subscription The group subscription.
	 * @param int              $user_id      The user to add.
	 *
	 * @return string|\WP_Error 'added', 'already', 'not_reader', or a WP_Error (e.g. member limit reached).
	 */
	public static function add_group_member( $subscription, $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return new \WP_Error( 'newspack_migrate_add_member', 'Invalid user ID.' );
		}
		if ( ! Reader_Activation::is_user_reader( $user_id ) ) {
			return 'not_reader';
		}
		if ( Group_Subscription::user_is_member( $user_id, $subscription ) ) {
			return 'already';
		}
		$result = Group_Subscription::update_members( $subscription, [ $user_id ] );
		if ( \is_wp_error( $result ) ) {
			return $result;
		}
		return isset( $result['members_added'][ $user_id ] ) ? 'added' : 'already';
	}

	/**
	 * Count the managers migrate-teams would promote in a dry-run.
	 *
	 * During a dry-run no members are added, so membership can't be read from the
	 * data layer. A candidate would be promoted if their Teams role is `manager`,
	 * they are a reader (so they would be added as a member), and they are not the
	 * owner or an existing manager.
	 *
	 * @param \WC_Subscription $subscription The group subscription.
	 * @param int              $team_id      The team post ID.
	 * @param int[]            $member_ids   The team member user IDs.
	 * @param int              $owner_id     The subscription owner ID.
	 *
	 * @return int
	 */
	private static function count_dry_run_manager_promotions( $subscription, $team_id, $member_ids, $owner_id ) {
		$existing_managers = array_map( 'intval', Group_Subscription::get_managers( $subscription ) );
		$count             = 0;
		foreach ( array_values( array_unique( array_map( 'absint', (array) $member_ids ) ) ) as $user_id ) {
			if ( ! $user_id || $user_id === $owner_id || in_array( $user_id, $existing_managers, true ) ) {
				continue;
			}
			$role = \get_user_meta( $user_id, sprintf( self::TEAM_ROLE_META_KEY_TEMPLATE, $team_id ), true );
			if ( 'manager' === $role && Reader_Activation::is_user_reader( $user_id ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Resolve the group subscription to backfill managers into for a team.
	 *
	 * Mirrors migrate-teams: prefer the team's linked active group subscription,
	 * else an active group subscription owned by the team owner. Never creates one.
	 *
	 * @param int $team_id  The team post ID.
	 * @param int $owner_id The team owner user ID.
	 *
	 * @return \WC_Subscription|null
	 */
	private static function resolve_backfill_subscription( $team_id, $owner_id ) {
		$raw_sub_id = (int) \get_post_meta( $team_id, '_subscription_id', true );
		if ( $raw_sub_id ) {
			$subscription = \wcs_get_subscription( $raw_sub_id );
			if ( $subscription && 'active' === $subscription->get_status() && Group_Subscription::is_group_subscription( $subscription ) ) {
				return $subscription;
			}
		}
		return self::find_group_subscription_for_owner( $owner_id );
	}

	/**
	 * Find the first active group subscription owned by a user, if any.
	 *
	 * @param int $owner_id User ID to search.
	 *
	 * @return \WC_Subscription|null
	 */
	private static function find_group_subscription_for_owner( $owner_id ) {
		$owner_id = absint( $owner_id );
		if ( ! $owner_id || ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return null;
		}
		foreach ( \wcs_get_users_subscriptions( $owner_id ) as $subscription ) {
			// wcs_get_users_subscriptions is filtered to include member-only groups; require ownership.
			if ( (int) $subscription->get_user_id() !== $owner_id ) {
				continue;
			}
			if ( 'active' !== $subscription->get_status() ) {
				continue;
			}
			if ( Group_Subscription::is_group_subscription( $subscription ) ) {
				return $subscription;
			}
		}
		return null;
	}

	/**
	 * Create a new $0 migration subscription for a team owner and set its dates.
	 *
	 * @param int              $owner_id         The owner user ID.
	 * @param \WC_Product|null $migration_product The migration product.
	 * @param string           $billing_period   The billing period.
	 * @param int              $billing_interval The billing interval.
	 * @param string           $start_date       The subscription start date.
	 * @param string           $end_date         The subscription end date, or ''.
	 * @param array            $errors           Errors array, passed by reference.
	 * @param int              $team_id          The team post ID (for error context).
	 *
	 * @return \WC_Subscription|null The subscription, or null on failure.
	 */
	private static function create_migration_subscription( $owner_id, $migration_product, $billing_period, $billing_interval, $start_date, $end_date, &$errors, $team_id ) {
		$new_sub = \wcs_create_subscription(
			[
				'customer_id'      => $owner_id,
				'status'           => 'pending',
				'billing_period'   => $billing_period,
				'billing_interval' => $billing_interval,
				'start_date'       => $start_date,
				'created_via'      => 'migration',
				'currency'         => \get_woocommerce_currency(),
			]
		);

		if ( \is_wp_error( $new_sub ) ) {
			$errors[] = 'create_subscription: ' . $new_sub->get_error_message();
			WP_CLI::warning( sprintf( 'Team %d: could not create subscription — %s', $team_id, $new_sub->get_error_message() ) );
			return null;
		}

		$line_item = new \WC_Order_Item_Product();
		$line_item->set_props(
			[
				'product_id' => $migration_product->get_id(),
				'name'       => $migration_product->get_name(),
				'quantity'   => 1,
				'subtotal'   => 0,
				'total'      => 0,
			]
		);
		$line_item->set_taxes( [] );
		$new_sub->add_item( $line_item );

		$owner = \get_user_by( 'id', $owner_id );
		if ( $owner ) {
			$billing_first = \get_user_meta( $owner_id, 'billing_first_name', true );
			$billing_last  = \get_user_meta( $owner_id, 'billing_last_name', true );
			$new_sub->set_address(
				[
					'first_name' => '' !== $billing_first ? $billing_first : $owner->first_name,
					'last_name'  => '' !== $billing_last ? $billing_last : $owner->last_name,
					'email'      => $owner->user_email,
					'country'    => \get_user_meta( $owner_id, 'billing_country', true ),
				],
				'billing'
			);
		}

		$dates_to_set = self::build_subscription_dates( $start_date, $end_date, $billing_interval, $billing_period );

		try {
			$new_sub->update_dates( $dates_to_set );
		} catch ( \Exception $e ) {
			$errors[] = 'update_dates: ' . $e->getMessage();
			WP_CLI::warning( sprintf( 'Team %d: could not set subscription dates — %s', $team_id, $e->getMessage() ) );
		}

		$new_sub->calculate_totals();
		$new_sub->save();
		$new_sub->update_status( 'active' );

		return $new_sub;
	}

	/**
	 * Overwrite a reused subscription's line items with the migration product.
	 *
	 * @param \WC_Subscription $subscription     The subscription to update.
	 * @param \WC_Product      $migration_product The migration product.
	 * @param string           $billing_period   The billing period.
	 * @param int              $billing_interval The billing interval.
	 * @param string           $start_date       The subscription start date.
	 * @param string           $end_date         The subscription end date, or ''.
	 * @param array            $errors           Errors array, passed by reference.
	 * @param int              $team_id          The team post ID (for error context).
	 *
	 * @return void
	 */
	private static function replace_subscription_product( $subscription, $migration_product, $billing_period, $billing_interval, $start_date, $end_date, &$errors, $team_id ) {
		foreach ( array_keys( $subscription->get_items() ) as $item_id ) {
			$subscription->remove_item( $item_id );
		}
		$line_item = new \WC_Order_Item_Product();
		$line_item->set_props(
			[
				'product_id' => $migration_product->get_id(),
				'name'       => $migration_product->get_name(),
				'quantity'   => 1,
				'subtotal'   => 0,
				'total'      => 0,
			]
		);

		$subscription->set_billing_period( $billing_period );
		$subscription->set_billing_interval( $billing_interval );
		$dates_to_set = self::build_subscription_dates( $start_date, $end_date, $billing_interval, $billing_period );

		try {
			$subscription->update_dates( $dates_to_set );
		} catch ( \Exception $e ) {
			$errors[] = 'update_dates: ' . $e->getMessage();
			WP_CLI::warning( sprintf( 'Team %d: could not set subscription dates — %s', $team_id, $e->getMessage() ) );
		}

		$line_item->set_taxes( [] );
		$subscription->add_item( $line_item );
		$subscription->calculate_totals();
		$subscription->save();
	}

	/**
	 * Create a single $0 group subscription (owned by $owner_id) for a plan.
	 *
	 * @param int         $product_id Product post ID.
	 * @param \WC_Product $product    Product object (used for the line item).
	 * @param string      $plan_name  Human-readable plan name (used as group name).
	 * @param int         $owner_id   User ID to assign as the subscription owner.
	 *
	 * @return \WC_Subscription|\WP_Error New subscription on success, WP_Error on failure.
	 */
	private static function create_group_subscription( $product_id, $product, $plan_name, $owner_id ) {
		$period_meta      = \get_post_meta( $product_id, '_subscription_period', true );
		$interval_meta    = \get_post_meta( $product_id, '_subscription_period_interval', true );
		$billing_period   = '' !== $period_meta ? $period_meta : 'month';
		$billing_interval = '' !== $interval_meta ? (int) $interval_meta : 1;

		$subscription = \wcs_create_subscription(
			[
				'customer_id'      => $owner_id,
				'status'           => 'pending',
				'billing_period'   => $billing_period,
				'billing_interval' => $billing_interval,
				'currency'         => \get_woocommerce_currency(),
				'created_via'      => 'manual migration',
			]
		);

		if ( \is_wp_error( $subscription ) ) {
			return $subscription;
		}

		$item = new \WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 1 );
		$item->set_subtotal( 0 );
		$item->set_total( 0 );
		$subscription->add_item( $item );
		$subscription->set_total( 0 );

		$subscription->update_meta_data( '_newspack_group_subscription_enabled', 'yes' );
		$subscription->update_meta_data( '_newspack_group_subscription_limit', 0 );
		$subscription->update_meta_data( '_newspack_group_subscription_name', $plan_name );

		$subscription->add_order_note( sprintf( 'This group subscription was created from manual-only membership plan: "%s".', $plan_name ) );
		$subscription->update_status( 'active' );
		$subscription->save();

		return $subscription;
	}

	/**
	 * Create a free individual subscription for a manually-assigned membership.
	 *
	 * @param int         $user_id      The member user ID.
	 * @param \WC_Product $product      The product object.
	 * @param int         $product_id   The product post ID.
	 * @param string      $start_date   The subscription start date.
	 * @param string      $end_date     The subscription end date.
	 * @param bool        $has_end_date Whether a future end date should be set.
	 * @param int         $membership_id The source membership post ID (for the order note).
	 *
	 * @return \WC_Subscription|\WP_Error
	 */
	private static function create_individual_subscription( $user_id, $product, $product_id, $start_date, $end_date, $has_end_date, $membership_id ) {
		$period_meta      = \get_post_meta( $product_id, '_subscription_period', true );
		$interval_meta    = \get_post_meta( $product_id, '_subscription_period_interval', true );
		$billing_period   = '' !== $period_meta ? $period_meta : 'month';
		$billing_interval = '' !== $interval_meta ? (int) $interval_meta : 1;

		$subscription = \wcs_create_subscription(
			[
				'customer_id'      => $user_id,
				'status'           => 'pending',
				'billing_period'   => $billing_period,
				'billing_interval' => $billing_interval,
				'start_date'       => $start_date,
				'currency'         => \get_woocommerce_currency(),
				'created_via'      => 'manual migration',
			]
		);

		if ( \is_wp_error( $subscription ) ) {
			return $subscription;
		}

		// Set the end date explicitly via update_dates() — more reliable than
		// passing schedule_end to wcs_create_subscription().
		if ( $has_end_date ) {
			$subscription->update_dates( [ 'end' => $end_date ] );
		}

		$item = new \WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 1 );
		$item->set_subtotal( 0 );
		$item->set_total( 0 );
		$subscription->add_item( $item );
		$subscription->set_total( 0 );

		$subscription->add_order_note( sprintf( 'This subscription was created from a manually-assigned membership with ID %d.', $membership_id ) );
		$subscription->update_status( 'active' );
		$subscription->save();

		return $subscription;
	}

	/**
	 * Return IDs of all published membership plans with _access_method = manual-only.
	 *
	 * @return int[]
	 */
	private static function get_manual_only_plan_ids() {
		return \get_posts(
			[
				'post_type'      => 'wc_membership_plan',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'   => '_access_method',
						'value' => 'manual-only',
					],
				],
			]
		);
	}

	/**
	 * Suppress WooCommerce transactional emails during migration.
	 *
	 * @return void
	 */
	private static function suppress_woocommerce_emails() {
		\add_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false' );
		\add_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false' );
		\add_filter( 'woocommerce_email_enabled_new_order', '__return_false' );
		\add_filter( 'wcs_send_auto_renewal_emails', '__return_false' );
	}

	/**
	 * Suppress Newspack data-event dispatches for the rest of this CLI process.
	 *
	 * Member/manager writes already fire no data events, but activating a created
	 * subscription fires `woocommerce_subscription_status_updated`, which Newspack's
	 * listeners turn into dispatched data events (e.g. `woo_subscription_updated`).
	 * Those reach the ESP contact sync, the Webhooks dispatcher, and — on a Network
	 * Node — the Hub subscription-sync listener, making a data backfill look like a
	 * burst of real new-subscription activity. Cancelling the dispatch at
	 * `newspack_data_events_dispatch_body` (a WP_Error return is the documented cancel
	 * path) stops that external traffic. Scoped to this process only, so concurrent
	 * requests are unaffected.
	 *
	 * @return void
	 */
	private static function suppress_data_events() {
		\add_filter(
			'newspack_data_events_dispatch_body',
			function () {
				return new \WP_Error( 'newspack_migration_suppressed', 'Data event dispatch suppressed during membership migration.' );
			}
		);
	}

	/**
	 * Build the update_dates() payload for a migration subscription.
	 *
	 * Rolls next_payment forward to the first future occurrence rather than
	 * start + one interval, so migrating a team older than a single billing period
	 * never stores a past-due next_payment on a live subscription (which WooCommerce
	 * Subscriptions can treat as overdue and process immediately). An end date is set
	 * only when it is in the future — mirroring migrate-manual-members — so an already
	 * expired team migrates as an ongoing subscription instead of erroring on a
	 * past end date. next_payment is dropped when it would fall on or after the end.
	 *
	 * @param string $start_date       The subscription start date ('Y-m-d H:i:s' UTC).
	 * @param string $end_date         The subscription end date ('Y-m-d H:i:s' UTC), or ''.
	 * @param int    $billing_interval The billing interval.
	 * @param string $billing_period   The billing period (day/week/month/year).
	 *
	 * @return array The dates payload for WC_Subscription::update_dates().
	 */
	private static function build_subscription_dates( $start_date, $end_date, $billing_interval, $billing_period ) {
		$dates_to_set = [
			'start'        => $start_date,
			'next_payment' => self::next_future_payment_date( $start_date, $billing_interval, $billing_period ),
		];
		if ( $end_date && strtotime( $end_date ) > time() ) {
			$dates_to_set['end'] = $end_date;
			if ( strtotime( $dates_to_set['next_payment'] ) >= strtotime( $end_date ) ) {
				unset( $dates_to_set['next_payment'] );
			}
		}
		return $dates_to_set;
	}

	/**
	 * Compute the first future payment date, rolling forward from the start date by
	 * the billing interval.
	 *
	 * @param string $start_date       The subscription start date ('Y-m-d H:i:s' UTC).
	 * @param int    $billing_interval The billing interval.
	 * @param string $billing_period   The billing period (day/week/month/year).
	 *
	 * @return string The next future payment date ('Y-m-d H:i:s' UTC).
	 */
	private static function next_future_payment_date( $start_date, $billing_interval, $billing_period ) {
		$interval = max( 1, (int) $billing_interval );
		$period   = $billing_period ? $billing_period : 'month';
		$now      = time();
		$start    = strtotime( $start_date );
		$next     = strtotime( "+$interval $period", $start );
		// Guard against a period that fails to advance the timestamp (defensive — the
		// callers default $billing_period), so the loop below can't spin forever.
		if ( ! $next || $next <= $start ) {
			return gmdate( 'Y-m-d H:i:s', strtotime( '+1 month', max( $start, $now ) ) );
		}
		while ( $next <= $now ) {
			$next = strtotime( "+$interval $period", $next );
		}
		return gmdate( 'Y-m-d H:i:s', $next );
	}

	/**
	 * Whether a user already owns an active subscription this migration created for a
	 * product.
	 *
	 * Lets migrate-manual-members' individual mode skip a member already processed on a
	 * prior run, so re-running doesn't stack duplicate $0 subscriptions.
	 *
	 * @param int $user_id    The member user ID.
	 * @param int $product_id The migration product ID.
	 *
	 * @return bool
	 */
	private static function member_has_migration_subscription( $user_id, $product_id ) {
		$user_id    = absint( $user_id );
		$product_id = absint( $product_id );
		if ( ! $user_id || ! $product_id || ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return false;
		}
		foreach ( \wcs_get_users_subscriptions( $user_id ) as $subscription ) {
			// wcs_get_users_subscriptions is filtered to include member-only groups; require ownership.
			if ( (int) $subscription->get_user_id() !== $user_id ) {
				continue;
			}
			if ( 'active' !== $subscription->get_status() ) {
				continue;
			}
			if ( 'manual migration' !== $subscription->get_created_via() ) {
				continue;
			}
			foreach ( $subscription->get_items() as $item ) {
				if ( method_exists( $item, 'get_product_id' ) && (int) $item->get_product_id() === $product_id ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Normalise a date string to 'Y-m-d H:i:s' UTC. Returns '' if unparseable.
	 *
	 * @param string $date_string Date string to normalise.
	 *
	 * @return string
	 */
	private static function normalise_date( $date_string ) {
		if ( empty( $date_string ) ) {
			return '';
		}
		$timestamp = strtotime( $date_string );
		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : '';
	}

	/**
	 * Map a WC Teams seat count to the owner-inclusive group subscription limit.
	 *
	 * The group limit counts the owner as one of its seats, so a team whose owner
	 * already holds a seat (and is therefore counted in _seat_count) maps straight
	 * across, while a team whose owner takes no seat needs one added for their group
	 * seat. The result is floored to the 2-seat minimum (owner + one member); a seat
	 * count of 0 (unlimited) passes through unchanged.
	 *
	 * @param int  $seat_count           The team's _seat_count (0 = unlimited).
	 * @param bool $owner_is_team_member Whether the owner holds a team seat (a _member_id entry).
	 *
	 * @return int The owner-inclusive group limit (0 = unlimited).
	 */
	public static function map_team_seats_to_group_limit( $seat_count, $owner_is_team_member ) {
		$seat_count = (int) $seat_count;
		if ( 0 === $seat_count ) {
			return 0;
		}
		return max( $seat_count + ( $owner_is_team_member ? 0 : 1 ), 2 );
	}

	/**
	 * Build a migrate-teams summary row array.
	 *
	 * @param int   $team_id           Team post ID.
	 * @param mixed $subscription_id   Subscription ID or placeholder string.
	 * @param int   $members_added     Number of group members added.
	 * @param int   $managers_promoted Number of managers promoted.
	 * @param int   $seat_limit        The owner-inclusive group limit set on the subscription (0 = unlimited).
	 * @param bool  $created_new       Whether a new subscription was created.
	 * @param array $errors            Any error messages encountered.
	 *
	 * @return array
	 */
	private static function summary_row( $team_id, $subscription_id, $members_added, $managers_promoted, $seat_limit, $created_new, $errors ) {
		return compact( 'team_id', 'subscription_id', 'members_added', 'managers_promoted', 'seat_limit', 'created_new', 'errors' );
	}
}
