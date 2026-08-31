<?php
/**
 * WP-CLI commands to migrate WooCommerce Memberships (Teams and manual plans) to
 * Newspack Access Control group subscriptions, plus a backfill for group managers.
 *
 * Ported from the standalone `migrate-memberships` drop-in so the tooling ships
 * with the plugin and writes through the real Group_Subscription data layer. Note
 * that CLI\Initializer::init() includes this file on every request; only the
 * WP_CLI::add_command() registration is gated behind WP_CLI, so nothing here should
 * assume a CLI-only execution context.
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

// phpcs:disable WordPressVIPMinimum.Performance.NoPaging -- Operator-run CLI command; unbounded by design.

namespace Newspack\CLI;

use Newspack\Content_Gate;
use Newspack\Emails;
use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Invite;
use Newspack\Reader_Activation;
use Newspack\WooCommerce_Connection;
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
	 * Re-invite table labels for rehearsal outcomes. Constants because each is
	 * read at multiple sites — invitation_outcome_label() produces them and the
	 * summary's would-be-sent / would-resend counters filter on them — and a
	 * reworded literal at one site would silently zero the counters under a
	 * table that still shows every row.
	 *
	 * @var string
	 */
	private const OUTCOME_WOULD_SEND   = 'would send (dry run)';
	private const OUTCOME_WOULD_RESEND = 'would send again — earlier invite lapsed (dry run)';

	/**
	 * Subscription statuses that count as "live" for the
	 * --only-without-live-subscription member filter. Dead statuses deliberately
	 * do not count — an active membership over a lapsed subscription is the
	 * comp/legacy residual the filter exists to include. `pending` is dead too:
	 * a checkout that never completed grants no access, so such a member loses
	 * out at the flip exactly like one with no subscription at all.
	 *
	 * `on-hold` is counted live BY DESIGN, not because the gates always honour
	 * it: Access_Rules::has_active_subscription() grants on-hold only during
	 * payment recovery (WooCommerce_Connection::ACTIVE_SUBSCRIPTION_STATUSES is
	 * active/pending-cancel). The dunning cohort is deliberately out of this
	 * command's scope (NPPD-2052 owns it — minting a $0 subscription for a
	 * reader whose payment is mid-failure would convert a recoverable paying
	 * customer into a permanently free one), and On_Hold_Duration auto-expires
	 * an on-hold subscription with no pending retry after the configured window,
	 * at which point a re-run includes the member. The run's reconciliation
	 * output tallies on-hold skips separately so a parity diff computed with
	 * gate semantics has a named explanation for the delta.
	 *
	 * @var string[]
	 */
	const LIVE_SUBSCRIPTION_STATUSES = [ 'active', 'on-hold', 'pending-cancel' ];

	/**
	 * Subscription meta key stamping the source team a group subscription was migrated
	 * from. migrate-teams keys reuse on this marker so one owner's several teams each
	 * migrate to their own group subscription instead of merging into one.
	 *
	 * @var string
	 */
	const MIGRATED_TEAM_ID_META_KEY = '_newspack_migrated_team_id';

	/**
	 * The "nothing to reuse" group-subscription resolution, and so the shape every
	 * resolution returns: a match fills in only the fields that apply to it. See
	 * find_reusable_group_subscription() for what each field means.
	 *
	 * @var array
	 */
	private const NO_REUSE = [
		'subscription'              => null,
		'used_owner_fallback'       => false,
		'reused_without_access'     => false,
		'disabled_marked_group_ids' => [],
	];

	/**
	 * Migrate all active team memberships to Newspack group subscriptions.
	 *
	 * For teams already linked to an active subscription: enables the group
	 * subscription settings, adds all team members as group members, and promotes
	 * any team member whose Teams role is `manager` to a group manager.
	 *
	 * For teams with no linked subscription: reuses the group subscription this team
	 * was previously migrated to (stamped with MIGRATED_TEAM_ID_META_KEY), falling
	 * back to an unmarked group subscription owned by the team owner for groups from
	 * migrator runs predating per-team marking. If none is found, creates a new $0
	 * subscription on the given product.
	 *
	 * The command is idempotent — re-running it updates existing group
	 * subscriptions in place rather than creating duplicates. Reuse keys on the
	 * source team, so an owner who owns several teams migrates to one group
	 * subscription per team rather than a single merged group. When --product-id is
	 * supplied, every re-used $0 subscription has its line items replaced with the
	 * migration product.
	 *
	 * A team whose linked subscription is one the publisher actually bills is
	 * migrated in place: it gains the group settings and keeps its own product,
	 * price, taxes and billing schedule. Forcing a subscription to $0 is only
	 * correct where the access being carried over was free in Memberships. Because
	 * such a subscription keeps its own schedule, the team's Teams end date is not
	 * carried onto it, so the group can outlive the membership it came from.
	 *
	 * A subscription already cancelled but paid through the end of its term
	 * (pending-cancel) is migrated in place for the same reason and keeps its
	 * scheduled end date, whatever its total. Its members hold access for the rest
	 * of the paid term and lose it when the subscription self-cancels, which is
	 * what the reader asked for; re-aligning it onto the migration product would
	 * overwrite that end date and extend their access indefinitely.
	 *
	 * Two cases are skipped rather than migrated, and reported as errors in the
	 * summary: a paid team whose own product no published gate accepts, since
	 * granting access would mean rewriting what the publisher charges; and a team
	 * whose paid subscription is on hold for payment recovery and which has no
	 * migrated group to update, since creating one would hand the owner permanent
	 * free access and remove their reason to fix their payment method.
	 *
	 * Dry-run by default; pass --live to write.
	 *
	 * ## OPTIONS
	 *
	 * [--product-id=<id>]
	 * : Product to assign to newly-created subscriptions. Accepts a product ID or a variation ID — pass the variation when a publisher sells seat tiers as variations of one variable subscription product. Must be published and accepted by a published gate's "Active subscription" rule. Also re-aligns any re-used $0 subscription onto this product; a re-used subscription the team pays for keeps its own product, price, taxes and billing schedule. Required unless --skip-unlinked is passed.
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
	 * [--migrate-invitations]
	 * : Also carry each team's pending (unaccepted) WooCommerce Teams invitations over as group-subscription invites, which SENDS an invitation email to every pending invitee. Off by default because it emails readers; the pending invitees are always listed at the end of the run regardless of this flag. In dry-run mode nothing is sent, but the flag still shapes the rehearsal: per-invitee outcomes preview a live run's already-invited skips, lapsed re-sends (labelled as the second email they would be), and existing-member/non-reader rejections, and any dry run with pending invitees warns when the invitation email is not sendable. The preview stops short of live-only checks: the seat limit, inactive-group guards, and --limit apply on the live run only, and a team with no existing subscription to reuse gets no per-invitee preview. The run asks for confirmation before the first email goes out, and aborts up front if the invitation email is not sendable on this site while there is something to send. Re-running is safe while the invites it wrote are still live (30 days by default) — an invitee whose invite has lapsed is invited again, and those are reported separately. To recover an invitee reported as FAILED: fix the cause and re-run — or cancel the reader's pending invitation from the group subscription's panel on the WooCommerce subscription screen and re-invite from there. That manual cancel is also the escape hatch for a stored invite the automatic rollback could not remove.
	 *
	 * [--limit[=<n>]]
	 * : With --migrate-invitations --live, cap how many send attempts (delivered or failed) this run makes — failures count because each one still hits the mail relay and costs a write/rollback cycle. Must be a positive integer; any other value — including a bare --limit with no value, as an unset shell variable leaves behind — aborts the run rather than silently dropping the cap. (The value is declared optional in the synopsis for exactly that reason: it lets the bare flag reach this command's validator instead of being stripped by WP-CLI with only a stderr warning.) Invitees beyond the cap stay listed with a re-run note, and the already-invited gate makes the next run resume where this one stopped — use it to drain a large site in operator-sized batches instead of one long burst. Not applied to dry-run previews; the cap has no effect without --migrate-invitations, though an invalid value still aborts any run, so a rehearsal of the exact live command line fails early.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt shown before invitation emails are sent. Auto-handled by WP_CLI::confirm.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack migrate-teams --product-id=519858
	 *     wp newspack migrate-teams --product-id=519858 --live
	 *     wp newspack migrate-teams --skip-unlinked --live
	 *     wp newspack migrate-teams --product-id=519858 --only-unlinked --live
	 *     wp newspack migrate-teams --product-id=519858 --migrate-invitations --live
	 *     wp newspack migrate-teams --product-id=519858 --migrate-invitations --limit=100 --live
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 *
	 * @return void
	 */
	public function migrate_teams( $args, $assoc_args ) {
		$product_id          = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'product-id', 0 );
		$dry_run             = ! (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'live', false );
		$skip_unlinked       = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-unlinked', false );
		$only_unlinked       = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'only-unlinked', false );
		$migrate_invitations = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'migrate-invitations', false );
		// A present-but-invalid --limit must abort, never silently become "no cap":
		// the scripted shape --limit=$BATCH --yes --live with an unset variable
		// would otherwise run the full burst with the confirm prompt (the only
		// disclosure of the true count) suppressed by --yes.
		$send_limit = self::validate_send_limit( \WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', null ) );
		if ( \is_wp_error( $send_limit ) ) {
			WP_CLI::error( $send_limit->get_error_message() );
		}

		// Sending is gated behind the opt-in flag and never happens in a dry-run — but
		// the pending-invitation list is always reported (below) so the data is never
		// silently dropped at migration.
		$send_invitations = $migrate_invitations && ! $dry_run;

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

		// Derived independently of --product-id: the paid-team guard below needs it
		// in --skip-unlinked runs, which take no --product-id and process only
		// linked teams — exactly the teams that can be paid ones. Deriving it inside
		// the migration-product block would leave that guard dead in the one mode
		// where every team it protects is in scope.
		$access_product_ids = self::get_gate_access_product_ids();
		if ( $product_id && ! $migration_product ) {
			WP_CLI::error( sprintf( 'Product ID %d not found. Aborting.', $product_id ) );
		}

		if ( $migration_product ) {
			// Name which shape was passed: a variation and its parent read alike in
			// the summary, and which one the operator meant is the crux of NPPD-1876.
			WP_CLI::line(
				sprintf(
					'Using %s: "%s" (ID: %d)',
					$migration_product->is_type( 'variation' ) ? sprintf( 'variation of product %d', $migration_product->get_parent_id() ) : 'product',
					$migration_product->get_name(),
					$migration_product->get_id()
				)
			);
			// For a variable-subscription parent these meta live on the variation, not
			// the parent, and come back empty; default them the same way
			// create_group_subscription()/create_individual_subscription() do so an
			// empty period/interval never flows into wcs_create_subscription().
			$period_meta      = \get_post_meta( $product_id, '_subscription_period', true );
			$interval_meta    = \get_post_meta( $product_id, '_subscription_period_interval', true );
			$billing_period   = '' !== $period_meta ? $period_meta : 'month';
			$billing_interval = '' !== $interval_meta ? (int) $interval_meta : 1;

			// An unpublished product fails the same way the migration used to fail on
			// a variation: WC_Subscription::can_be_updated_to( 'active' ) rejects a
			// line item whose product is unavailable, and unavailable includes any
			// status other than publish. Checked here because a dry run cannot
			// surface it — every write is behind ! $dry_run — so without this the
			// first the operator hears of it is a fatal on team one of a live run.
			if ( ! self::product_is_published( $migration_product ) ) {
				WP_CLI::error(
					sprintf(
						'Product %d is not published, so subscriptions created against it cannot be activated. Publish it (or its parent product, for a variation) and re-run.',
						$product_id
					)
				);
			}

			// A group subscription grants access only where a published gate lists
			// its product: enforcement runs through WC_Subscription::has_product().
			// Migrating against a product no gate accepts would report every team as
			// migrated while granting access to nobody, and on the reuse path the
			// subscription's original line items are replaced in the same pass, so
			// there is nothing left to fall back on. Only checkable once the gates
			// name some products; with none configured they are presumably still to
			// be built around this product.
			if ( ! empty( $access_product_ids ) && ! self::product_grants_gate_access( $migration_product, $access_product_ids ) ) {
				WP_CLI::error(
					sprintf(
						'Product %d is not among the products that grant access (%s), so migrating teams onto it would grant no access. Pass --product-id for a product the gates accept, or add %d to a gate\'s "Active subscription" rule first.',
						$product_id,
						implode( ', ', $access_product_ids ),
						$product_id
					)
				);
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

		// Read every team's pending invitations up front, before the loop's skip
		// branches: a team the flags skip still has its invitees reported (below), and
		// the confirmation prompt needs a truthful recipient count before the first
		// email goes out. One bulk query rather than one per team, so a
		// several-hundred-team site doesn't sit silent here before the progress bar.
		WP_CLI::line( 'Reading pending team invitations… (nothing has been written yet; interrupting here is safe)' );
		$invitation_drop_count = 0;
		$pending_invitations   = self::get_pending_team_invitation_emails_for_teams( $teams, $invitation_drop_count );
		if ( $invitation_drop_count ) {
			WP_CLI::warning( sprintf( '%d pending team invitation(s) hold a stored address that is not a valid email. They cannot be listed or re-invited by this command; find them by looking for wc_team_invitation posts whose title is not an email address.', $invitation_drop_count ) );
		}

		// Upper bound: an invitee who is already invited, already a member, or over
		// the group's seat limit is rejected without an email. Computed on every run:
		// the dry-run sendability warning below fires whenever pending invitees
		// exist, not only when --migrate-invitations is being rehearsed, so the
		// tool's own suggested workflow (rehearse plain, then send live) passes
		// through the check.
		$recipient_count = 0;
		foreach ( $pending_invitations as $team_id => $team_emails ) {
			if ( ! self::team_is_skipped_by_flags( $team_id, $skip_unlinked, $only_unlinked ) ) {
				$recipient_count += count( $team_emails );
			}
		}

		// Gate on the send-precondition before anything is written, the same way
		// card_expiry_warning_backfill() does: generate_invite() stores the invite
		// whether or not the email goes out, so without this an unsendable site
		// would report hundreds of invitations sent with nobody contacted. Gated on
		// the recipient count so a site whose invitation email is broken can still
		// migrate its teams when there is nothing to send anyway. Only the live
		// branch may use the repairing check: can_send_email()'s miss path
		// publishes the email post and rewrites newsletter palette keys, so the
		// dry-run branch asks via the read-only probe — a rehearsal that wrote
		// would break its own no-writes banner, and would silence this very
		// warning by repairing the condition it reports.
		$sendability_message = 'The group subscription invitation email is not currently sendable. The email post may be in draft status, Newspack Newsletters may be inactive, or the Access Control feature (NEWSPACK_CONTENT_GATES) may be off — which leaves the invitation email unregistered.';
		if ( $recipient_count ) {
			if ( $send_invitations && ! Emails::can_send_email( Group_Subscription_Invite::EMAIL_TYPE ) ) {
				WP_CLI::error( $sendability_message . ' Fix that and re-run, or drop --migrate-invitations to migrate without sending.' );
			} elseif ( $dry_run && ! Emails::can_send_email( Group_Subscription_Invite::EMAIL_TYPE, false ) ) {
				WP_CLI::warning( $sendability_message . ' Review and publish the invitation email before running with --migrate-invitations --live.' );
			}
		}

		if ( $send_invitations && $recipient_count ) {
			// Name the shared relay in the prompt: the burst rides the same mail path
			// as reader sign-in links and purchase receipts, and that is the one fact
			// an operator needs to pick a quiet hour — the recipient count alone
			// doesn't show it.
			if ( $send_limit > 0 && $recipient_count > $send_limit ) {
				$prompt = sprintf( 'This will send up to %1$d of %2$d pending group subscription invitation email(s) (--limit=%1$d) through the site\'s mail relay — the same relay that carries reader sign-in links and purchase receipts. Re-run to send the rest. Continue?', $send_limit, $recipient_count );
			} else {
				$prompt = sprintf( 'This will send up to %d group subscription invitation email(s) in one uninterrupted burst through the site\'s mail relay — the same relay that carries reader sign-in links and purchase receipts, so consider a quiet hour for a large batch. Continue?', $recipient_count );
			}
			WP_CLI::confirm( $prompt, $assoc_args );
		}

		$summary               = [];
		$skipped               = [];
		$invitation_rows       = []; // Re-invite list rows: team → invitee email → outcome.
		$invites_sent          = 0;
		$invites_resent        = 0;
		$invites_skipped       = 0;
		$invites_failed        = 0;
		$invite_skip_reasons   = []; // Reason => count, so "already invited" and "group full" stay distinguishable.
		$invitation_teams_seen = []; // Teams whose invitees already have rows, so the skipped-team pass doesn't double-report.
		$progress              = \WP_CLI\Utils\make_progress_bar( 'Migrating teams', $total );

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

			$subscription   = null;
			$created_new    = false;
			$errors         = [];
			$members_added  = 0;
			$recovering_sub = null;

			// Attempt to reuse the team's linked subscription if it still grants
			// access. That is active *or* pending-cancel: a team riding out a period
			// it has already paid for keeps its access until the subscription
			// self-cancels, and both Access_Rules and Group_Subscription gate member
			// access on WooCommerce_Connection::ACTIVE_SUBSCRIPTION_STATUSES. Reusing
			// it in place carries that expiry across the migration, so the owner and
			// the group's members lose access exactly when the subscription ends;
			// creating a $0 group for such a team instead would hand them permanent
			// access after they had cancelled.
			if ( $raw_sub_id ) {
				$existing_sub = \wcs_get_subscription( $raw_sub_id );
				if ( $existing_sub && $existing_sub->has_status( WooCommerce_Connection::ACTIVE_SUBSCRIPTION_STATUSES ) ) {
					$subscription = $existing_sub;
					if ( 'yes' === $existing_sub->get_meta( '_newspack_group_subscription_enabled' ) ) {
						WP_CLI::line( sprintf( 'Team %d: subscription %d is already a group subscription — re-updating.', $team_id, $raw_sub_id ) );
					}
				} else {
					// A paid subscription in payment recovery is not reusable, since reuse
					// requires active. Remember it so the create branch can refuse to mint
					// a free replacement; that decision has to wait until after the reuse
					// lookup below, because a team already migrated on an earlier run has
					// a group to re-update and the harm cannot arise for it.
					if ( $existing_sub && 'on-hold' === $existing_sub->get_status() && self::subscription_is_paid( $existing_sub ) ) {
						$recovering_sub = $existing_sub;
					}
					$status_label = $existing_sub ? $existing_sub->get_status() : 'not found';
					WP_CLI::warning( sprintf( 'Team %d: linked subscription %d is not active (status: %s) — searching for the group subscription this team was previously migrated to.', $team_id, $raw_sub_id, $status_label ) );
				}
			}

			// If no linked subscription was reusable, reuse the group subscription this
			// team was previously migrated to (stamped with MIGRATED_TEAM_ID_META_KEY),
			// so re-running the command keys on the team and does not create duplicate
			// subscriptions. An owner who owns several teams therefore keeps one group
			// subscription per team rather than merging them all into one.
			if ( ! $subscription ) {
				$reuse        = self::find_reusable_group_subscription( $team_id, $owner_id );
				$reusable_sub = $reuse['subscription'];
				// This team's own group exists but the publisher has turned group
				// subscriptions off on it. Migrating would re-enable a flag they set
				// deliberately, and carrying on without it would create a second group
				// stamped with this team's ID — so leave the team alone and let the operator
				// decide, keeping the one-group-per-team invariant intact either way.
				if ( $reuse['disabled_marked_group_ids'] ) {
					$disabled_list = implode( ', ', $reuse['disabled_marked_group_ids'] );
					$errors[]      = sprintf( 'group subscription(s) %s migrated from this team have group subscriptions disabled — re-enable one or clear its %s meta, then re-run', $disabled_list, self::MIGRATED_TEAM_ID_META_KEY );
					$summary[]     = self::summary_row( $team_id, 'ERROR', 0, 0, $group_limit, false, $errors );
					WP_CLI::warning( sprintf( 'Team %d ("%s"): group subscription(s) %s migrated from this team have group subscriptions disabled — skipping so the migration does not re-enable them or create a duplicate. Re-enable one, or clear the %s meta to let a fresh group be created, then re-run.', $team_id, $team->post_title, $disabled_list, self::MIGRATED_TEAM_ID_META_KEY ) );
					// This path has just loaded every subscription of the owner's, which is what
					// the per-team cache clear exists to bound.
					\WP_CLI\Utils\wp_clear_object_cache();
					continue;
				}
				if ( $reusable_sub ) {
					$subscription = $reusable_sub;
					if ( $reuse['used_owner_fallback'] ) {
						// A group carrying no team marker is usually one from a migrator run
						// predating per-team marking. Adopt it for this team (it is marked
						// below) and warn — the operator has to confirm it is not a group
						// that belongs elsewhere, since adopting it renames it and merges
						// this team's members into it.
						$linked_team_id = self::find_team_linked_to_subscription( $reusable_sub->get_id() );
						$linked_note    = '';
						if ( $linked_team_id && $linked_team_id !== $team_id ) {
							$linked_team = \get_post( $linked_team_id );
							$linked_note = sprintf( ' It is currently linked to team %d ("%s") — check this is not a cross-team merge before running --live.', $linked_team_id, $linked_team ? $linked_team->post_title : '?' );
						}
						WP_CLI::warning( sprintf( 'Team %d ("%s"): no group subscription marked for this team; adopting unmarked group subscription %d owned by owner %d and marking it for this team.%s', $team_id, $team->post_title, $reusable_sub->get_id(), $owner_id, $linked_note ) );
					} elseif ( $reuse['reused_without_access'] ) {
						// Reusing the team's own group even though its status grants no
						// access keeps one group per team; creating a second one would split
						// the team's members across two groups. It is not reactivated here.
						// Recorded as an issue rather than only warned about, so the team
						// does not read as a clean success in the end-of-run summary — this
						// is the one outcome where the members are written into a group that
						// grants nobody access.
						$errors[] = sprintf( 'reused group subscription %d migrated from this team, but its status is "%s" — the members added to it have no access until it is active again', $reusable_sub->get_id(), $reusable_sub->get_status() );
					} else {
						WP_CLI::line( sprintf( 'Team %d: found existing group subscription %d migrated from this team — re-updating.', $team_id, $reusable_sub->get_id() ) );
					}
				}
			}

			// Nothing was reusable and the team's own subscription is a paid one in
			// payment recovery, so creating here would mint a new, active, $0 group
			// subscription alongside it: the owner would get permanent free access
			// and no reason to fix their card, turning a recoverable paying customer
			// into a permanently free one. That is the harm LIVE_SUBSCRIPTION_STATUSES
			// records as deliberately out of scope for migrate-manual-members
			// (NPPD-2052 owns the dunning cohort). On_Hold_Duration expires an
			// unrecovered subscription on its own, and a re-run picks the team up
			// once it is active again or gone. Checked here rather than at the reuse
			// lookup so a team that already has a group to re-update is unaffected.
			if ( ! $subscription && $recovering_sub ) {
				$errors[]  = sprintf( 'linked subscription %d is a paid subscription in payment recovery ("on-hold") and no migrated group exists for this team — skipped so the migration does not mint a free parallel subscription', $recovering_sub->get_id() );
				$summary[] = self::summary_row( $team_id, 'ERROR', 0, 0, $group_limit, false, $errors );
				WP_CLI::warning(
					sprintf(
						'Team %d ("%s"): linked subscription %d is a paid subscription (%s) on hold for payment recovery, and this team has no migrated group to update — skipping so the owner is not granted a free subscription mid-recovery. Re-run once it is active again, or once it has expired.',
						$team_id,
						$team->post_title,
						$recovering_sub->get_id(),
						self::format_subscription_total( $recovering_sub )
					)
				);
				\WP_CLI\Utils\wp_clear_object_cache();
				continue;
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
				$summary[] = self::summary_row( $team_id, 'ERROR', 0, 0, $group_limit, false, $errors );
				continue;
			}

			// Create a new subscription when none resolved above.
			if ( ! $subscription ) {
				$created_new = true;
				if ( ! $dry_run ) {
					$new_sub = self::create_migration_subscription( $owner_id, $migration_product, $billing_period, $billing_interval, $start_date, $end_date, $errors, $team_id );
					if ( ! $new_sub ) {
						$summary[] = self::summary_row( $team_id, 'ERROR', 0, 0, $group_limit, true, $errors );
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

			// A migration converts free Memberships access into a $0 subscription. A
			// team that pays for its seats is a different case: the publisher still
			// sells that subscription, so it keeps its product, price, taxes and
			// billing schedule and gains only the group settings.
			$reused_is_paid = ! $created_new && self::subscription_is_paid( $subscription );

			// A pending-cancel subscription keeps its terms too, whatever its total.
			// Its dates are the point: the scheduled end is what makes the owner and
			// the group's members lose access when the subscription self-cancels, and
			// re-aligning it would overwrite those dates with migration-derived ones
			// and extend access past the cancellation the reader asked for.
			$reused_is_ending  = ! $created_new && $subscription->has_status( 'pending-cancel' );
			$reuse_keeps_terms = $reused_is_paid || $reused_is_ending;

			// Access for a paid team therefore rests on its own product, since we no
			// longer swap in --product-id. If no published gate accepts that product
			// the migration cannot grant access without rewriting what the publisher
			// charges — so leave the team untouched and let the operator decide,
			// rather than silently converting a paying subscription to $0.
			if ( $reuse_keeps_terms && ! empty( $access_product_ids ) && ! self::subscription_covers_access_products( $subscription, $access_product_ids ) ) {
				$own_product_ids = self::subscription_product_ids( $subscription );
				$own_list        = ! empty( $own_product_ids ) ? implode( ', ', $own_product_ids ) : 'none';
				$errors[]        = sprintf( 'subscription %d is paid and holds product(s) %s, which no published gate accepts (accepted: %s) — migrating it would either grant no access or rewrite what the publisher charges', $subscription->get_id(), $own_list, implode( ', ', $access_product_ids ) );
				$summary[]       = self::summary_row( $team_id, 'ERROR', 0, 0, $group_limit, false, $errors );
				WP_CLI::warning(
					sprintf(
						'Team %d ("%s"): linked subscription %d is a paid subscription (%s) holding product(s) %s, which no published gate accepts (accepted: %s) — skipping so the migration does not zero out what the publisher bills. Add %s to a gate\'s "Active subscription" rule, then re-run.',
						$team_id,
						$team->post_title,
						$subscription->get_id(),
						self::format_subscription_total( $subscription ),
						$own_list,
						implode( ', ', $access_product_ids ),
						$own_list
					)
				);
				\WP_CLI\Utils\wp_clear_object_cache();
				continue;
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
				// Stamp the source team so re-runs reuse this subscription by team (see
				// find_reusable_group_subscription() for the reuse rules and the dry-run
				// caveat).
				$subscription->update_meta_data( self::MIGRATED_TEAM_ID_META_KEY, $team_id );
				$subscription->save();
			}

			// When re-using a $0 subscription with --product-id, overwrite its line
			// items with the migration product so re-running aligns every migrated
			// subscription with the product passed via --product-id. Paid
			// subscriptions are exempt: rewriting their line items would replace what
			// the publisher sells with a $0 product and overwrite the billing
			// schedule, and for a pending-cancel subscription would push its expiry
			// out past the cancellation the reader asked for (see $reuse_keeps_terms).
			if ( ! $created_new && ! $reuse_keeps_terms && $migration_product && ! $dry_run ) {
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

			// Carry over pending (unaccepted) team invitations. The re-invite list is
			// always collected so it survives into the summary; invites are only sent
			// when opted in via --migrate-invitations on a --live run. A throw escaping
			// the invite path is recorded as a team error rather than aborting the run,
			// which would take every summary table below down with it.
			$team_emails                       = $pending_invitations[ $team_id ] ?? [];
			$invitation_teams_seen[ $team_id ] = true;
			// Hand each team the remainder of --limit's budget. Sent AND failed
			// counts accumulate across teams — both are relay attempts — so the
			// cap holds run-wide.
			$attempts_remaining = ( $send_invitations && $send_limit > 0 ) ? max( 0, $send_limit - $invites_sent - $invites_failed ) : null;
			try {
				$invitation_result = self::migrate_team_invitations( $subscription, $team_id, $send_invitations, $team_emails, $attempts_remaining );
			} catch ( \Throwable $e ) {
				$errors[]          = sprintf( 'invitations: %s', $e->getMessage() );
				$invitation_result = [
					'emails'       => $team_emails,
					'sent'         => [],
					'resent'       => [],
					'skipped'      => [],
					'failed'       => [],
					'would_resend' => [],
					'errored'      => true,
				];
			}
			$invites_sent    += count( $invitation_result['sent'] );
			$invites_resent  += count( $invitation_result['resent'] );
			$invites_skipped += count( $invitation_result['skipped'] );
			$invites_failed  += count( $invitation_result['failed'] );
			foreach ( $invitation_result['skipped'] as $skip_reason ) {
				$invite_skip_reasons[ $skip_reason ] = ( $invite_skip_reasons[ $skip_reason ] ?? 0 ) + 1;
			}
			foreach ( $invitation_result['emails'] as $invitee_email ) {
				$invitation_rows[] = [
					'team_id' => $team_id,
					'sub'     => $subscription_id,
					'invitee' => $invitee_email,
					'outcome' => self::invitation_outcome_label( $invitation_result, $invitee_email, $migrate_invitations ),
				];
			}
			if ( ! empty( $invitation_result['failed'] ) ) {
				WP_CLI::warning( sprintf( 'Team %d: %d invitation(s) could not be delivered — see the re-invite list below; re-run to retry them.', $team_id, count( $invitation_result['failed'] ) ) );
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

		// Invitees of teams the run never reached — skipped by --skip-unlinked or
		// --only-unlinked, or errored out for having no subscription to migrate into.
		// They are listed too, so "the pending invitees are always listed" holds for
		// every team rather than only the processed ones.
		// The flag check, not the SKIPPED TEAMS table, is what tells the two cases apart:
		// only --skip-unlinked records a row there, so --only-unlinked's teams would
		// otherwise be reported as failures rather than as the deliberate filter they are.
		foreach ( $pending_invitations as $team_id => $team_emails ) {
			if ( isset( $invitation_teams_seen[ $team_id ] ) ) {
				continue;
			}
			$outcome = self::team_is_skipped_by_flags( $team_id, $skip_unlinked, $only_unlinked )
				? 'not processed (team skipped)'
				: 'not processed (team not migrated)';
			foreach ( $team_emails as $invitee_email ) {
				$invitation_rows[] = [
					'team_id' => $team_id,
					'sub'     => '—',
					'invitee' => $invitee_email,
					'outcome' => $outcome,
				];
			}
		}

		// Pending-invitation re-invite list. Emitted whenever any team carried pending
		// invitations, whether or not --migrate-invitations was passed, so the invitees
		// are never lost silently — an operator can act on this list even if they chose
		// not to send during the migration.
		if ( ! empty( $invitation_rows ) ) {
			WP_CLI::line( '' );
			WP_CLI::line( sprintf( '=== PENDING TEAM INVITATIONS (re-invite list) — %d total ===', count( $invitation_rows ) ) );
			if ( ! $send_invitations ) {
				WP_CLI::line( $migrate_invitations ? 'Dry run: no invites were sent. Re-run with --live to send.' : 'These invitees were NOT re-invited. Re-run with --migrate-invitations --live to send group-subscription invites.' );
			}
			WP_CLI::line( '' );
			\WP_CLI\Utils\format_items( 'table', $invitation_rows, [ 'team_id', 'sub', 'invitee', 'outcome' ] );
		}

		$new_count = count( array_filter( $summary, fn( $r ) => $r['created_new'] ) );
		WP_CLI::line( '' );
		WP_CLI::success( sprintf( 'Done. %d team(s) processed: %d used existing subscriptions, %d had new subscriptions created, %d skipped, %d had error(s).', count( $summary ), count( $summary ) - $new_count, $new_count, count( $skipped ), count( $errored_rows ) ) );
		if ( ! empty( $invitation_rows ) ) {
			// On a dry run with the flag, nothing is attempted, so the send total is the
			// number of rows the table shows as "would send" rather than 0 — which would
			// contradict the table it sits under.
			$would_send = $dry_run && $migrate_invitations;
			$sent_count = $would_send
				? count( array_filter( $invitation_rows, fn( $row ) => in_array( $row['outcome'], [ self::OUTCOME_WOULD_SEND, self::OUTCOME_WOULD_RESEND ], true ) ) )
				: $invites_sent;
			WP_CLI::success(
				sprintf(
					'Pending invitations: %d %s, %d skipped, %d failed, %d listed only.',
					$sent_count,
					$would_send ? 'would be sent' : 'sent',
					$invites_skipped,
					$invites_failed,
					count( $invitation_rows ) - $sent_count - $invites_skipped - $invites_failed
				)
			);
			if ( $invites_resent ) {
				WP_CLI::warning( sprintf( '%d of the invitations sent went to readers whose earlier invitation had lapsed (invites expire after %s) — they were emailed a second time.', $invites_resent, Group_Subscription_Invite::get_expiration_label() ) );
			}
			// The rehearsal twin of the warning above: surface the double-email count
			// BEFORE the emails exist, while the information can still change the
			// decision — otherwise a 500-row rehearsal folds it invisibly into the
			// "would be sent" total and the live run discloses it only after sending.
			if ( $would_send ) {
				$would_resend_count = count( array_filter( $invitation_rows, fn( $row ) => self::OUTCOME_WOULD_RESEND === $row['outcome'] ) );
				if ( $would_resend_count ) {
					WP_CLI::warning( sprintf( '%d of those would be emailed a second time — their earlier invitation lapsed (invites expire after %s).', $would_resend_count, Group_Subscription_Invite::get_expiration_label() ) );
				}
			}
			// Break the skipped total down: "already invited, nothing to do" and "the
			// group had no seats left, so these people were dropped" are the same number
			// otherwise, and only one of them needs the operator's attention.
			if ( ! empty( $invite_skip_reasons ) ) {
				arsort( $invite_skip_reasons );
				foreach ( $invite_skip_reasons as $skip_reason => $skip_count ) {
					WP_CLI::line( sprintf( '  skipped — %s: %d', $skip_reason, $skip_count ) );
				}
			}
		}
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
	 * Create free subscriptions for users with active memberships that no live
	 * subscription backs.
	 *
	 * By default, iterates through membership plans with manual-only access and
	 * creates free WooCommerce Subscriptions for active members who do not have
	 * the `edit_others_posts` capability (i.e. are not administrators/editors).
	 *
	 * Plans with purchase/signup access can only be targeted with a member
	 * selection flag — --only-without-live-subscription and/or
	 * --user-ids/--user-ids-file — because blanket-processing them would create
	 * $0 subscriptions for every active member, including real paying
	 * subscribers. The comp/legacy residual class both flags target is
	 * "membership active, but no subscription in a live status": that includes
	 * members with no subscription at all AND members whose subscriptions exist
	 * only in dead states (cancelled/expired — and `pending`, since a checkout
	 * that never completed grants no access) — the lapsed cohort is often the
	 * larger one.
	 *
	 * Teams sites: run migrate-teams BEFORE a broad sweep. A live group
	 * subscription a member belongs to counts as live (no redundant personal $0
	 * subscription), but that only holds once migrate-teams has created the
	 * group subscriptions; before that, team members look like residuals.
	 * Constrain with --plan-ids if unsure.
	 *
	 * Group mode (--as-group) is NOT idempotent: it creates a new group
	 * subscription on every run. Individual mode is re-run safe — members who
	 * already hold an active migration-created subscription for the product are
	 * skipped. Dry-run by default; pass --live to write.
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
	 * : Comma-delimited list of membership plan IDs to process. If omitted, all published plans with _access_method = manual-only are used — or ALL published plans when --only-without-live-subscription or --user-ids/--user-ids-file is passed. Parsing is strict: a malformed token aborts the run.
	 *
	 * [--only-without-live-subscription]
	 * : Only process members who do NOT own (or belong to a group on) a subscription in a live status (active, on-hold, pending-cancel) FOR A PRODUCT THE GATES ACCEPT. Members whose subscriptions are all in dead states (cancelled, expired, pending, ...), or are only for products no gate accepts, are included, same as members with no subscription at all. Skipped members are counted per user so the output reconciles against a parity diff.
	 *
	 * [--access-product-ids=<ids>]
	 * : Comma-delimited product IDs that grant access under the new gates — the audit's ACCESS_PRODUCT_IDS. Scopes which subscriptions count as covering a member, and is the pre-flight's reference set: EVERY mode (including the legacy manual-only default) refuses a --product-id outside it, so pass this to unblock a run whose gates do not yet list the migration product. Defaults to the products named by the `subscription` access rules of published gates with custom access switched on. With --only-without-live-subscription, an empty resolved list aborts a --live run (any live subscription would count as covering).
	 *
	 * [--user-ids=<ids>]
	 * : Comma-delimited list of user IDs to process (explicit input mode). Only active members of the processed plans whose user ID is on this list are handled; list entries never matched are reported at the end. Combines with --user-ids-file.
	 *
	 * [--user-ids-file=<path>]
	 * : Path to a file of user IDs to process (comma-, space-, or newline-delimited). Combines with --user-ids.
	 *
	 * [--skip-domains=<domains>]
	 * : Comma-delimited list of email domains to skip (e.g. example.com,example.org). Any user whose email address belongs to one of these domains will be skipped.
	 *
	 * [--as-group]
	 * : Instead of creating one subscription per member, create a single $0 group subscription per plan and add all qualifying members as group members. Requires --group-owner-id, and explicit --plan-ids when combined with a member selection flag.
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
	 *     wp newspack migrate-manual-members --product-id=519858 --plan-ids=78 --only-without-live-subscription --live
	 *     wp newspack migrate-manual-members --product-id=519858 --user-ids=101,102,103 --live
	 *     wp newspack migrate-manual-members --product-id=519858 --user-ids-file=/tmp/residual-user-ids.txt --live
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 *
	 * @return void
	 */
	public function migrate_manual_members( $args, $assoc_args ) {
		$dry_run                        = ! (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'live', false );
		$as_group                       = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'as-group', false );
		$group_owner_id                 = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'group-owner-id', 0 );
		$product_id                     = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'product-id', 0 );
		$plan_ids                       = \WP_CLI\Utils\get_flag_value( $assoc_args, 'plan-ids', '' );
		$only_without_live_subscription = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'only-without-live-subscription', false );
		$user_ids_csv                   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'user-ids', '' );
		$user_ids_file                  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'user-ids-file', '' );
		$access_product_ids_csv         = \WP_CLI\Utils\get_flag_value( $assoc_args, 'access-product-ids', '' );
		$skip_domains                   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-domains', '' );
		$skip_domains                   = ! empty( $skip_domains )
			? array_filter( array_map( 'trim', explode( ',', strtolower( $skip_domains ) ) ) )
			: [];

		// A value-requiring flag passed bare (`--user-ids` with no `=value`) never
		// reaches this method: WP-CLI validates against the synopsis first, warns,
		// strips the flag, and hands over the empty default — so the strict-parse
		// guards below cannot see it, and the run would silently fall back to a
		// broader scope than the operator asked for. Read the raw command line to
		// catch it before any other work.
		$bare_flags = self::get_valueless_value_flags();
		if ( ! empty( $bare_flags ) ) {
			WP_CLI::error( sprintf( 'The following flag(s) require a value but arrived without one: %s. WP-CLI strips a bare flag and the run would proceed with a different scope than intended — fix the invocation and re-run.', implode( ', ', $bare_flags ) ) );
		}

		if ( ! function_exists( 'wcs_create_subscription' ) ) {
			WP_CLI::error( 'WooCommerce Subscriptions is not active. Aborting.' );
		}

		// Without WooCommerce Memberships the wcm-active post status is
		// unregistered, so the member queries return zero rows and the run would
		// masquerade as a clean no-op.
		if ( ! class_exists( 'WC_Memberships_User_Membership' ) ) {
			WP_CLI::error( 'WooCommerce Memberships is not active. Aborting.' );
		}

		if ( ! $product_id ) {
			WP_CLI::error( 'Missing required option: --product-id=<id>.' );
		}

		$target_user_ids = self::parse_user_ids( $user_ids_csv, $user_ids_file );
		if ( \is_wp_error( $target_user_ids ) ) {
			WP_CLI::error( $target_user_ids->get_error_message() );
		}
		$explicit_users_mode = ! empty( $target_user_ids );
		if ( ! $explicit_users_mode && ( ( is_string( $user_ids_csv ) && '' !== trim( $user_ids_csv ) ) || ( is_string( $user_ids_file ) && '' !== trim( $user_ids_file ) ) ) ) {
			WP_CLI::error( 'The --user-ids/--user-ids-file input resolved to no user IDs.' );
		}
		// Keyed set for the per-membership check below: O(1) per row where
		// in_array() would be O(list) — a reviewed list can run to the thousands.
		$target_user_lookup = array_fill_keys( $target_user_ids, true );

		// --plan-ids gets the same strict parse as the other ID flags: it is now a
		// load-bearing safety input (it constrains the widened all-plans default
		// and satisfies the --as-group guard), so a typo'd token must halt the run
		// rather than silently narrow or empty the plan scope.
		if ( ! is_string( $plan_ids ) ) {
			WP_CLI::error( 'The --plan-ids flag requires a value (e.g. --plan-ids=12,34).' );
		}
		$plan_ids = self::parse_id_tokens( $plan_ids, 'plan ID', '--plan-ids' );
		if ( \is_wp_error( $plan_ids ) ) {
			WP_CLI::error( $plan_ids->get_error_message() );
		}

		// Which subscriptions count as covering a member's access after the flip.
		// An explicit list wins so a run can be pinned to the products the parity
		// diff was computed with; otherwise read it off the gates themselves.
		if ( ! is_string( $access_product_ids_csv ) ) {
			WP_CLI::error( 'The --access-product-ids flag requires a value (e.g. --access-product-ids=519858).' );
		}
		$access_product_ids = self::parse_id_tokens( $access_product_ids_csv, 'product ID', '--access-product-ids' );
		if ( \is_wp_error( $access_product_ids ) ) {
			WP_CLI::error( $access_product_ids->get_error_message() );
		}
		$access_products_source = 'given';
		if ( empty( $access_product_ids ) ) {
			$access_product_ids     = self::get_gate_access_product_ids();
			$access_products_source = 'derived from published gates';
		}

		$product = \wc_get_product( $product_id );
		if ( ! $product ) {
			WP_CLI::error( sprintf( 'Product %d could not be found.', $product_id ) );
		}

		// A $0 subscription for a product no gate accepts restores no access: the
		// run would report "created" while the reader stays locked out. Only
		// checkable when the accepted products are known — with none configured
		// yet, the gates are presumably still to be built around this product.
		// Matched through product_grants_gate_access() rather than a flat
		// comparison because enforcement runs through has_product(), which accepts
		// a line item's product ID or its variation ID: a gate naming a variable
		// subscription's parent does accept a seat-tier variation, and refusing one
		// here would block a --product-id that works.
		if ( ! empty( $access_product_ids ) && ! self::product_grants_gate_access( $product, $access_product_ids ) ) {
			WP_CLI::error(
				sprintf(
					'Product %d is not among the products that grant access (%s), so a subscription to it grants no access. Pass --product-id for a product the gates accept, or add %d to a gate\'s "Active subscription" rule first.',
					$product_id,
					implode( ', ', $access_product_ids ),
					$product_id
				)
			);
		}

		// Readers granted a $0 subscription to a limited product cannot purchase
		// it again until that subscription is cancelled — and the lapsed cohort
		// this command targets is exactly who a win-back campaign would send to
		// that checkout. Named in the header so the operator knows before writing.
		$product_limitation = function_exists( 'wcs_get_product_limitation' ) ? \wcs_get_product_limitation( $product ) : 'no';
		if ( 'no' !== $product_limitation ) {
			WP_CLI::warning( sprintf( 'Product %d limits customers to one %s subscription: readers granted a $0 subscription cannot buy it again until that subscription is cancelled. Plan any win-back outreach to the migrated cohort accordingly.', $product_id, $product_limitation ) );
		}

		if ( $as_group ) {
			if ( ! $group_owner_id ) {
				WP_CLI::error( '--as-group requires --group-owner-id=<id>.' );
			}
			if ( ! \get_userdata( $group_owner_id ) ) {
				WP_CLI::error( sprintf( 'User %d (--group-owner-id) could not be found.', $group_owner_id ) );
			}
			// The selection flags widen the default plan scope to every published
			// plan, and group mode creates one group subscription per processed plan
			// before members are filtered — a broad sweep would leave an orphan empty
			// group subscription on every plan without qualifying members.
			if ( ( $explicit_users_mode || $only_without_live_subscription ) && empty( $plan_ids ) ) {
				WP_CLI::error( '--as-group combined with --only-without-live-subscription or --user-ids/--user-ids-file requires explicit --plan-ids.' );
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

		if ( empty( $plan_ids ) ) {
			if ( $explicit_users_mode || $only_without_live_subscription ) {
				// The residuals the selection flags target live on purchase plans, so
				// the default scope widens to every published plan.
				$plan_ids = self::get_published_plan_ids();
			} else {
				$plan_ids = self::get_manual_only_plan_ids();
			}
		}

		if ( empty( $plan_ids ) ) {
			WP_CLI::warning( 'No membership plans found to process.' );
			return;
		}

		// Refuse to blanket-process a plan members can purchase or sign up for:
		// without a member selection flag, every active member would get a $0
		// subscription — including real paying subscribers.
		if ( ! $explicit_users_mode && ! $only_without_live_subscription ) {
			$non_manual_plan_ids = array_values(
				array_filter(
					$plan_ids,
					function ( $plan_id ) {
						$plan_post = \get_post( $plan_id );
						return $plan_post && 'wc_membership_plan' === $plan_post->post_type && 'manual-only' !== \get_post_meta( $plan_id, '_access_method', true );
					}
				)
			);
			if ( ! empty( $non_manual_plan_ids ) ) {
				WP_CLI::error( sprintf( 'Plan(s) %s are not manual-only. Pass --only-without-live-subscription and/or --user-ids/--user-ids-file to target them without granting $0 subscriptions to paying members.', implode( ', ', $non_manual_plan_ids ) ) );
			}
		}

		// The product scope only changes which members the live-subscription filter
		// treats as covered, so report it exactly where it applies.
		if ( $only_without_live_subscription ) {
			if ( empty( $access_product_ids ) ) {
				// An empty list turns the coverage test into "any live subscription
				// counts" — the setting that skips the most members, and skipping is
				// the direction that costs readers their access. Previewable in
				// dry-run; a live run has to name the covered set explicitly.
				if ( $dry_run ) {
					WP_CLI::warning( 'Access products: none — no access products could be determined, so ANY live subscription counts as covering a member. That matches a gate whose subscription rule lists no products; if the gates do list products, pass --access-product-ids or this run will skip members whose only subscription is to a product the gates do not accept (a recurring donation, say) and they will lose access at the flip. A --live run refuses this state.' );
				} else {
					WP_CLI::error( 'No access products could be determined, so ANY live subscription would count as covering a member — members whose only live subscription is to a product the gates do not accept (a recurring donation, say) would be skipped and lose access at the flip. Pass --access-product-ids to pin the covered set (the audit\'s ACCESS_PRODUCT_IDS), or preview without --live.' );
				}
			} else {
				WP_CLI::line( sprintf( 'Access products: %s (%s).', implode( ', ', $access_product_ids ), $access_products_source ) );
			}
			WP_CLI::line( '' );
		}

		if ( $explicit_users_mode ) {
			// Named in the header so a reviewed list that was lost or truncated at
			// the shell is visible in the first lines of output, not only in the
			// plan/member counts.
			WP_CLI::line( sprintf( 'Reviewed list: %d user id(s).', count( $target_user_ids ) ) );
		}

		WP_CLI::line( sprintf( 'Processing %d plan(s): %s', count( $plan_ids ), implode( ', ', $plan_ids ) ) );
		WP_CLI::line( '' );

		$summary                            = [];
		$skipped_live_subscription_user_ids = [];
		$granted_user_ids                   = [];
		$matched_user_ids                   = [];
		// Liveness per user for this run's product scope. A member on several
		// plans would otherwise repeat two full subscription traversals per plan
		// against a deliberately cold object cache.
		$liveness_by_user = [];

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

			// Group mode: one shared group subscription per plan, created lazily on
			// the first qualifying member — a plan where every member is filtered
			// out (the expected outcome on a purchase plan swept with
			// --only-without-live-subscription) must not leave an orphan empty
			// active $0 group subscription behind.
			$group_subscription = null;

			foreach ( $memberships as $membership_id ) {
				// Free the per-request object cache accumulated by prior iterations so
				// memory stays bounded across a large (unbounded) membership list. The
				// held $group_subscription / $product objects are unaffected.
				\WP_CLI\Utils\wp_clear_object_cache();
				// The group data layer memoizes full subscription objects per user in
				// a class static the object-cache clear cannot reach; reset it so the
				// sweep's peak memory stays at one member's worth.
				Group_Subscription::reset_cache();

				$membership_post = \get_post( $membership_id );
				$user_id         = (int) $membership_post->post_author;

				// Explicit input mode: only members on the reviewed user-ID list are
				// processed. Skipping the rest silently keeps the output proportional to
				// the list, not the plan size; matches are tracked so unmatched list
				// entries can be reported at the end.
				if ( $explicit_users_mode ) {
					if ( ! isset( $target_user_lookup[ $user_id ] ) ) {
						continue;
					}
					$matched_user_ids[ $user_id ] = true;
				}

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

				// A member can hold active memberships on several in-scope plans, but
				// each user gets at most one grant per run — subscription or group
				// membership. Checked before the migration/live filters so a
				// cross-plan repeat reports as already-granted instead of inflating
				// those tallies: in live group mode the member would otherwise test
				// live against the group subscription this very run just added them
				// to, making dry-run and live counts disagree for the same input.
				if ( isset( $granted_user_ids[ $user_id ] ) ) {
					if ( $as_group ) {
						WP_CLI::line( sprintf( '  Membership %d (user %d, %s): skipped — a group membership for this user was already %s in this run.', $membership_id, $user_id, $user->user_email, $dry_run ? 'planned' : 'added' ) );
					} else {
						WP_CLI::line( sprintf( '  Membership %d (user %d, %s): skipped — a subscription for this user was already %s in this run.', $membership_id, $user_id, $user->user_email, $dry_run ? 'planned' : 'created' ) );
					}
					continue;
				}

				// Individual mode: skip a member who already owns an active subscription
				// this migration created for the same product, so a re-run is safe and
				// doesn't stack duplicate $0 subscriptions. Checked before the
				// live-subscription filter so a re-run reports these as already-migrated
				// instead of inflating the live-subscription count.
				if ( ! $as_group && self::member_has_migration_subscription( $user_id, $product_id ) ) {
					WP_CLI::line( sprintf( '  Membership %d (user %d, %s): skipped — already has an active migration subscription for this product.', $membership_id, $user_id, $user->user_email ) );
					continue;
				}

				// Skip members who already own a subscription in a live status. Dead
				// statuses (cancelled/expired) do NOT count — a membership left active
				// over a lapsed subscription is exactly the residual this flag targets.
				// Tracked per user, not per membership (and memoized per user — the
				// matched status feeds the reconciliation breakdown), so the reported
				// count reconciles against a per-reader parity diff.
				if ( $only_without_live_subscription ) {
					if ( ! array_key_exists( $user_id, $liveness_by_user ) ) {
						$liveness_by_user[ $user_id ] = self::member_live_subscription_status( $user_id, $access_product_ids );
					}
					if ( $liveness_by_user[ $user_id ] ) {
						WP_CLI::line( sprintf( '  Membership %d (user %d, %s): skipped — holds a live subscription.', $membership_id, $user_id, $user->user_email ) );
						$skipped_live_subscription_user_ids[ $user_id ] = $liveness_by_user[ $user_id ];
						continue;
					}
				}

				// Group mode: add the user as a group member.
				if ( $as_group ) {
					if ( $dry_run ) {
						$granted_user_ids[ $user_id ] = true;
						WP_CLI::line( sprintf( '  [DRY RUN] Would add user %d (%s) as group member.', $user_id, $user->user_email ) );
					} else {
						// Created here, on the first qualifying member, so a plan with no
						// qualifying members creates nothing.
						if ( null === $group_subscription ) {
							$group_subscription = self::create_group_subscription( $product_id, $product, $plan->post_title, $group_owner_id );
							if ( \is_wp_error( $group_subscription ) ) {
								WP_CLI::warning( sprintf( '  Plan %d: failed to create group subscription — %s. Skipping plan.', $plan_id, $group_subscription->get_error_message() ) );
								$group_subscription = null;
								break;
							}
							WP_CLI::success( sprintf( '  Created group subscription %d for plan "%s".', $group_subscription->get_id(), $plan->post_title ) );
						}
						$status = self::add_group_member( $group_subscription, $user_id );
						$note   = \is_wp_error( $status ) ? ' (error: ' . $status->get_error_message() . ')' : ( 'added' === $status ? '' : ' (' . $status . ' — skipped)' );
						if ( 'added' === $status ) {
							$granted_user_ids[ $user_id ] = true;
						}
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

				// Individual mode: resolve dates.
				$start_date_raw = \get_post_meta( $membership_id, '_start_date', true );
				$end_date_raw   = \get_post_meta( $membership_id, '_end_date', true );
				$start_date     = ! empty( $start_date_raw ) ? gmdate( 'Y-m-d H:i:s', strtotime( $start_date_raw ) ) : \current_time( 'mysql', true );
				$end_date       = ! empty( $end_date_raw ) ? gmdate( 'Y-m-d H:i:s', strtotime( $end_date_raw ) ) : '';
				$has_end_date   = ! empty( $end_date ) && strtotime( $end_date ) > time();

				if ( $dry_run ) {
					$granted_user_ids[ $user_id ] = true;
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

				$granted_user_ids[ $user_id ] = true;

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

		// Reconciliation output — printed even when nothing was created, so the run
		// can be checked against the parity diff that produced its inputs.
		if ( $only_without_live_subscription ) {
			WP_CLI::line( sprintf( 'Skipped %d member(s) holding a live (%s) subscription.', count( $skipped_live_subscription_user_ids ), implode( '/', self::LIVE_SUBSCRIPTION_STATUSES ) ) );
			$skipped_status_counts = array_count_values( $skipped_live_subscription_user_ids );
			if ( ! empty( $skipped_status_counts ) ) {
				$breakdown_parts = [];
				foreach ( self::LIVE_SUBSCRIPTION_STATUSES as $live_status ) {
					if ( ! empty( $skipped_status_counts[ $live_status ] ) ) {
						$breakdown_parts[] = sprintf( '%s: %d', $live_status, $skipped_status_counts[ $live_status ] );
					}
				}
				WP_CLI::line( sprintf( 'Live-status breakdown: %s.', implode( ', ', $breakdown_parts ) ) );
			}
			if ( ! empty( $skipped_status_counts['on-hold'] ) ) {
				// The gates grant on-hold access only during payment recovery, so a
				// parity diff computed with gate semantics may list some of these
				// members as losing access — name the delta so it reconciles.
				WP_CLI::line( 'Note: on-hold members are counted live by design — the dunning cohort is tracked separately (NPPD-2052). An on-hold subscription with no pending payment retry is auto-expired after the configured on-hold window, after which a re-run includes the member.' );
			}
			WP_CLI::line( '' );
		}
		if ( $explicit_users_mode ) {
			$unmatched_user_ids = array_values( array_diff( $target_user_ids, array_keys( $matched_user_ids ) ) );
			if ( ! empty( $unmatched_user_ids ) ) {
				WP_CLI::warning( sprintf( '%d of %d requested user id(s) not found among active members of the processed plan(s): %s.', count( $unmatched_user_ids ), count( $target_user_ids ), implode( ', ', $unmatched_user_ids ) ) );
			} else {
				WP_CLI::line( sprintf( 'All %d requested user id(s) were found among active members of the processed plan(s).', count( $target_user_ids ) ) );
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
	 * subscription (the team's linked active subscription, else the group this team
	 * was migrated to by team marker, else an unmarked group owned by the team owner)
	 * and promotes each member whose Teams role is `manager` — and who is already a
	 * group member — to a group manager.
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

			// Free the per-request object cache accumulated by prior iterations so
			// memory stays bounded across a large team list.
			\WP_CLI\Utils\wp_clear_object_cache();

			$result = self::backfill_team_managers_for_team( $team_id, $live );

			if ( ! $result['resolved'] ) {
				$owner        = \get_user_by( 'id', $result['owner_id'] );
				$unresolved[] = [
					'team_id' => $team_id,
					'owner'   => $owner ? $owner->user_email : "user:{$result['owner_id']}",
					'reason'  => $result['reason'],
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
	 *     @type string     $reason          Why nothing resolved, for the operator; empty when `resolved` is true.
	 * }
	 */
	public static function backfill_team_managers_for_team( $team_id, $live ) {
		$team_id  = absint( $team_id );
		$team     = \get_post( $team_id );
		$owner_id = $team ? (int) $team->post_author : 0;

		$reuse        = self::resolve_backfill_reuse( $team_id, $owner_id );
		$subscription = $reuse['subscription'];
		if ( ! $subscription ) {
			// A group of this team's that the publisher disabled is the actionable fact
			// here: the generic "nothing found" reads as false twice over, since a group
			// was found and it is active.
			$reason = $reuse['disabled_marked_group_ids']
				? sprintf( 'Group subscription(s) %s migrated from this team have group subscriptions disabled — re-enable one, then re-run.', implode( ', ', $reuse['disabled_marked_group_ids'] ) )
				: 'No active group subscription found (linked or owner-owned).';
			return [
				'resolved'        => false,
				'owner_id'        => $owner_id,
				'subscription_id' => '—',
				'found'           => 0,
				'promoted'        => [],
				'already'         => [],
				'not_member'      => [],
				'reason'          => $reason,
			];
		}

		$member_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', (array) \get_post_meta( $team_id, '_member_id', false ) )
				)
			)
		);

		$result                    = self::promote_managers_from_team_roles( $subscription, $team_id, $member_ids, ! $live );
		$result['resolved']        = true;
		$result['owner_id']        = $owner_id;
		$result['subscription_id'] = $subscription->get_id();
		$result['found']           = count( $result['promoted'] ) + count( $result['already'] ) + count( $result['not_member'] );
		$result['reason']          = '';
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
	 * Whether --skip-unlinked / --only-unlinked will move past this team.
	 *
	 * Mirrors the two flag gates at the top of the migration loop, for the pre-pass that
	 * counts the readers a run is about to email. It deliberately does not model the
	 * "no reusable subscription and no --product-id" error path, which needs the
	 * subscription lookup the loop performs — so the count is an upper bound, as the
	 * prompt says.
	 *
	 * @param int  $team_id       The team post ID.
	 * @param bool $skip_unlinked Whether --skip-unlinked was passed.
	 * @param bool $only_unlinked Whether --only-unlinked was passed.
	 *
	 * @return bool
	 */
	private static function team_is_skipped_by_flags( $team_id, $skip_unlinked, $only_unlinked ) {
		$raw_sub_id = (int) \get_post_meta( $team_id, '_subscription_id', true );
		return ( $skip_unlinked && ! $raw_sub_id ) || ( $only_unlinked && $raw_sub_id );
	}

	/**
	 * Carry a team's pending invitations over to its group subscription.
	 *
	 * Always returns the list of pending invitee emails so the migration can surface a
	 * re-invite list even when nothing is sent. When $send is true and a subscription is
	 * resolved, each invitee is invited via Group_Subscription_Invite::generate_invite(),
	 * which stores the invite and emails the invitee. Invitees generate_invite() rejects
	 * (already a member, non-reader account, group at its member limit) are recorded as
	 * skipped with the reason rather than fataling. Exposed for testing.
	 *
	 * Only an invitee whose invitation email actually went out is counted as sent.
	 * generate_invite() writes the invite row before it dispatches the email and reports
	 * the delivery result in `email_sent`; when that is false the invite here is rolled
	 * back with cancel_invite(), because a stored invite would otherwise make the
	 * already-invited gate below answer "Already invited." forever and leave a reader who
	 * was never contacted unreachable by a corrective re-run.
	 *
	 * A per-invitee try/catch keeps one throwing address (a mail plugin throwing off
	 * `phpmailer_init`, a save failing inside WooCommerce) from aborting the run and
	 * destroying the report for the hundreds of invitees behind it. A throw out of the
	 * send path lands after the invite row is written — Emails::send_email() propagates
	 * throws by design — so the catch rolls the stored invite back exactly like a false
	 * `email_sent`; without that, the row would answer "Already invited." to the
	 * corrective re-run for its 30-day life while the reader was never contacted. The
	 * rollback saves the subscription and can itself throw, so it carries its own
	 * guard, and a rollback that fails is surfaced in the failure reason so the
	 * operator knows to cancel the pending invitation manually before re-running.
	 *
	 * One audit-fidelity caveat on that rollback: generate_invite() deletes every prior
	 * invite for the address — including a lapsed one — before writing the new row, so
	 * a rollback after a failed send consumes the lapsed invite's history with it. The
	 * eventual successful retry is then reported as a plain `invite sent` rather than a
	 * lapsed re-invite, and the double-email warning undercounts by that invitee. A
	 * lapsed invite is already unusable, so nothing functional is lost.
	 *
	 * An email that already holds a live (non-expired) invite on the subscription is
	 * skipped without re-inviting: generate_invite() replaces and re-sends unconditionally,
	 * so this gate is what keeps a re-run (the endorsed recovery path) — and two teams
	 * merged into one subscription sharing a pending invitee — from re-emailing a reader
	 * who was already invited. The gate reads live invites only, so it holds for as long
	 * as an invite lives (30 days by default, filterable via
	 * `newspack_group_subscription_invite_expiration_time`): past that window a re-run
	 * legitimately re-invites the lapsed invitee, and those addresses are reported
	 * separately in `resent` so the operator can see who is being emailed a second time.
	 *
	 * The gate is read even when not sending, so a dry rehearsal run after a completed
	 * live run reports the already-invited readers as skipped rather than everyone as
	 * still waiting — the rehearsal previews what a live run would actually send. The
	 * rehearsal also applies generate_invite()'s cheap, side-effect-free rejections
	 * (existing member, non-reader account) with the live run's wording, and labels a
	 * lapsed invitee's re-send as the second email it would be. The preview's limits,
	 * deliberately: the seat limit and generate_invite()'s subscription-state guards
	 * (an inactive group) are checked live only, --limit's budget is not rehearsed,
	 * and a team with no subscription to reuse returns above before any per-invitee
	 * preview.
	 *
	 * Addresses are compared case-insensitively but invited in their original casing:
	 * the acceptance handler compares the invite address strictly against the reader's
	 * stored `user_email`, which WordPress keeps in whatever case the reader registered
	 * with, so a lowercased invite would be unacceptable for a mixed-case account.
	 *
	 * @param \WC_Subscription|null $subscription The resolved group subscription, or null (e.g. a dry-run new subscription).
	 * @param int                   $team_id      The team post ID.
	 * @param bool                  $send         Whether to actually create and send invites.
	 * @param string[]|null         $emails       Pre-read pending invitee emails; read from the team when null.
	 * @param int|null              $max_attempts Cap on how many send attempts (delivered + failed) this call
	 *                                            may make (null = no cap). Failures count because each one is
	 *                                            a relay attempt plus a write/rollback cycle — the load the
	 *                                            cap exists to bound. Invitees beyond the cap are skipped with
	 *                                            a re-run note; the already-invited gate makes the follow-up
	 *                                            run resume where this one stopped. Carries the remainder of
	 *                                            --limit's budget.
	 *
	 * @return array {
	 *     @type string[]              $emails       Pending invitee emails for the team.
	 *     @type string[]              $sent         Emails an invite was created and emailed for.
	 *     @type string[]              $resent       Subset of $sent that was invited again because an earlier invite had lapsed.
	 *     @type array<string, string> $skipped      Email => skip reason for invitees that were not sent.
	 *     @type array<string, string> $failed       Email => failure reason for invitees whose invite could not be delivered.
	 *     @type string[]              $would_resend Rehearsal only: invitees whose lapsed invite a live run would re-send (a second email to that reader).
	 * }
	 */
	public static function migrate_team_invitations( $subscription, $team_id, $send, $emails = null, $max_attempts = null ) {
		$emails = null === $emails ? self::get_pending_team_invitation_emails( $team_id ) : $emails;
		$result = [
			'emails'       => $emails,
			'sent'         => [],
			'resent'       => [],
			'skipped'      => [],
			'failed'       => [],
			'would_resend' => [],
		];

		if ( ! $subscription ) {
			return $result;
		}

		// Emails that already hold a live invite on the subscription (from a prior run, or
		// an earlier team merged into the same subscription this run), and those whose
		// invite has lapsed and so will be sent again. Keyed lowercase so case variants of
		// the same mailbox collapse. Read even when not sending, so a dry rehearsal after
		// a live run reports who a live run would actually email.
		$already_invited = [];
		$lapsed_invites  = [];
		foreach ( Group_Subscription_Invite::get_invites( $subscription ) as $invite ) {
			if ( empty( $invite['email'] ) ) {
				continue;
			}
			if ( Group_Subscription_Invite::is_invite_expired( $invite ) ) {
				$lapsed_invites[ strtolower( $invite['email'] ) ] = true;
			} else {
				$already_invited[ strtolower( $invite['email'] ) ] = true;
			}
		}

		foreach ( $emails as $email ) {
			$key = strtolower( $email );
			if ( isset( $already_invited[ $key ] ) ) {
				$result['skipped'][ $email ] = __( 'Already invited.', 'newspack-plugin' );
				continue;
			}
			if ( ! $send ) {
				// Rehearse the cheap, side-effect-free rejections generate_invite()
				// would apply on a live run — same checks, same wording — so a
				// rehearsal's per-invitee outcome matches the live run for every
				// reason except the seat limit, which depends on the order invites
				// land within a run. Invitees none of these catch fall through to
				// the outcome chain's "would send (dry run)" / "not sent" labels.
				$existing_user = \get_user_by( 'email', $email );
				if ( $existing_user && ! Reader_Activation::is_user_reader( $existing_user ) ) {
					$result['skipped'][ $email ] = __( 'Not a valid reader account.', 'newspack-plugin' );
					continue;
				}
				if ( $existing_user && in_array( (int) $existing_user->ID, array_map( 'absint', Group_Subscription::get_members( $subscription ) ), true ) ) {
					$result['skipped'][ $email ] = __( 'User is already a member of this group subscription.', 'newspack-plugin' );
					continue;
				}
				if ( isset( $lapsed_invites[ $key ] ) ) {
					// A lapsed invitee would be emailed a SECOND time by a live run —
					// the one preview gap that under-warns if labelled as a plain
					// send, since the double-email warning only fires on real sends.
					$result['would_resend'][] = $email;
				}
				continue;
			}
			if ( null !== $max_attempts && count( $result['sent'] ) + count( $result['failed'] ) >= $max_attempts ) {
				// The --limit budget for this run is spent: keep the invitee listed
				// with an actionable reason instead of a send. The already-invited
				// gate makes the follow-up run resume exactly here. Failed attempts
				// consume the budget too — each failure is a relay attempt plus a
				// write/rollback cycle (two subscription saves), which is exactly
				// the load the cap exists to bound; counting only successes would
				// let a struggling relay turn --limit=100 into unbounded attempts.
				$result['skipped'][ $email ] = __( 'Not sent this run (--limit reached); re-run to send.', 'newspack-plugin' );
				continue;
			}
			try {
				$invite = Group_Subscription_Invite::generate_invite( $subscription, $email );
				if ( \is_wp_error( $invite ) ) {
					$result['skipped'][ $email ] = $invite->get_error_message();
					continue;
				}
				if ( empty( $invite['email_sent'] ) ) {
					// Roll the invite back so it can't answer "Already invited." on the
					// re-run that is meant to reach this reader. Guarded and checked
					// like the throw-path rollback below: cancel_invites() reports
					// failure as a WP_Error return, not only a throw, so the success
					// wording is gated on `true ===` — and a throwing rollback here
					// would otherwise land in the outer catch and drop the
					// send-failure fact that explains the row.
					try {
						$rolled_back = true === Group_Subscription_Invite::cancel_invite( $subscription, $email );
					} catch ( \Throwable $rollback_error ) {
						$rolled_back = false;
					}
					$result['failed'][ $email ] = $rolled_back
						? __( 'Invitation email was not sent — the invite was rolled back so a re-run can retry.', 'newspack-plugin' )
						: __( 'Invitation email was not sent, and the stored invite could not be rolled back afterwards. If the subscription\'s group panel shows a pending invitation for this reader, cancel it before re-running, or the re-run will answer "Already invited."', 'newspack-plugin' );
					continue;
				}
			} catch ( \Throwable $e ) {
				// The send path propagates throws after the invite row is written, so
				// roll the row back like a false `email_sent` — guarded on its own,
				// because cancel_invite() saves the subscription and can throw too.
				$rolled_back = false;
				try {
					$rolled_back = true === Group_Subscription_Invite::cancel_invite( $subscription, $email );
				} catch ( \Throwable $rollback_error ) {
					$rolled_back = false;
				}
				// "Any stored invite" / "if the panel shows": generate_invite() can
				// throw at its own save, before the row persists, and cancel_invites()
				// returns true whether or not it matched anything — so neither branch
				// can claim a row certainly existed.
				$result['failed'][ $email ] = $rolled_back
					? sprintf(
						/* translators: %s: the error thrown by the send path. */
						__( '%s — any stored invite was rolled back so a re-run can retry.', 'newspack-plugin' ),
						$e->getMessage()
					)
					: sprintf(
						/* translators: %s: the error thrown by the send path. */
						__( '%s — the invite could not be rolled back. If the subscription\'s group panel shows a pending invitation for this reader, cancel it before re-running, or the re-run will answer "Already invited."', 'newspack-plugin' ),
						$e->getMessage()
					);
				continue;
			}
			$result['sent'][]        = $email;
			$already_invited[ $key ] = true;
			if ( isset( $lapsed_invites[ $key ] ) ) {
				$result['resent'][] = $email;
			}
		}

		return $result;
	}

	/**
	 * Read the emails of a team's pending (unaccepted) WooCommerce Teams invitations.
	 *
	 * Invitations are stored as `wc_team_invitation` posts parented to the team, with the
	 * invitee email in the post title and a `wcmti-pending` status while unaccepted. Read
	 * directly (rather than through the Teams API) so the migration does not depend on the
	 * Teams plugin being active. Malformed addresses are dropped and case variants of one
	 * mailbox dedupe to a single entry (first one wins) — matching the case-insensitive
	 * already-invited gate in migrate_team_invitations(). The address itself is returned in
	 * its original casing: it is what gets stored on the invite and emailed, and the
	 * acceptance handler compares it strictly against the reader's stored `user_email`, so
	 * lowercasing it would strand every invitee whose account email carries uppercase
	 * characters. Exposed for testing.
	 *
	 * The `post_status` query filter only narrows the result when the status is
	 * registered: WooCommerce Teams registers `wcmti-pending` on `init`, so during a
	 * migration (Teams still active) the database returns pending invitations directly.
	 * This must not depend on Teams being active, though — once Teams is deactivated the
	 * status is unregistered and WP_Query silently drops the status clause, returning
	 * every status. So the pending filter is re-applied in PHP on each post's actual
	 * status as the guarantee that holds either way.
	 *
	 * @param int $team_id The team post ID.
	 *
	 * @return string[] Sanitised pending invitee emails in their original casing, deduped case-insensitively.
	 */
	public static function get_pending_team_invitation_emails( $team_id ) {
		$invitations = \get_posts(
			[
				'post_type'              => 'wc_team_invitation',
				'post_status'            => 'wcmti-pending',
				'post_parent'            => absint( $team_id ),
				'posts_per_page'         => -1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		return self::extract_pending_invitation_emails( $invitations );
	}

	/**
	 * Read the pending invitation emails of many teams in a single query.
	 *
	 * Bulk twin of get_pending_team_invitation_emails(), used by the migrate-teams
	 * pre-pass: chunked `post_parent__in` round trips bucketed by team in PHP, where
	 * the per-team helper would front-load one query per team into the silent stretch
	 * before the progress bar appears. Both share extract_pending_invitation_emails(),
	 * so their filtering guarantees cannot drift; the per-team helper remains the
	 * entry point for reading one team.
	 *
	 * @param int[] $team_ids   Team post IDs.
	 * @param int   $dropped    Out-param: incremented once per pending invitation dropped
	 *                          because its stored address is not a valid email, so the
	 *                          caller can warn instead of losing those invitees silently.
	 * @param int   $chunk_size Teams per query. The status clause only narrows the query
	 *                          while WooCommerce Teams (which registers `wcmti-pending`)
	 *                          is active; on the post-migration configuration this command
	 *                          supports, WP_Query drops the unregistered status entirely
	 *                          and each round trip returns the chunk's full invitation
	 *                          history — accepted and cancelled included, the bulk of the
	 *                          table. Chunking bounds that peak while keeping the query
	 *                          count low; the PHP re-filter does the narrowing either way.
	 *
	 * @return array<int, string[]> Team ID => pending invitee emails, in their original
	 *                              casing and deduped case-insensitively per team. Teams
	 *                              with no pending invitations are omitted.
	 */
	public static function get_pending_team_invitation_emails_for_teams( $team_ids, &$dropped = 0, $chunk_size = 100 ) {
		$team_ids = array_values( array_filter( array_map( 'absint', (array) $team_ids ) ) );
		if ( empty( $team_ids ) ) {
			return [];
		}

		$result = [];
		foreach ( array_chunk( $team_ids, max( 1, (int) $chunk_size ) ) as $chunk ) {
			$invitations = \get_posts(
				[
					'post_type'              => 'wc_team_invitation',
					'post_status'            => 'wcmti-pending',
					'post_parent__in'        => $chunk,
					'posts_per_page'         => -1,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					// Without this, get_posts() stores every fetched post in the
					// long-lived posts object cache (cache_results defaults true),
					// so the peak would grow monotonically across chunks and the
					// chunking would bound nothing.
					'cache_results'          => false,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				]
			);

			// Bucket and reduce inside the chunk loop, so each chunk's post objects
			// are released before the next chunk loads.
			$by_team = [];
			foreach ( $invitations as $invitation ) {
				$by_team[ (int) $invitation->post_parent ][] = $invitation;
			}
			foreach ( $by_team as $team_id => $team_invitations ) {
				$emails = self::extract_pending_invitation_emails( $team_invitations, $dropped );
				if ( ! empty( $emails ) ) {
					$result[ $team_id ] = $emails;
				}
			}
		}

		return $result;
	}

	/**
	 * Filter one team's invitation posts down to pending, valid, deduped invitee emails.
	 *
	 * The shared reduction behind the single-team and bulk readers: re-applies the
	 * pending-status filter in PHP (the query-level clause silently vanishes when the
	 * Teams plugin — which registers the status — is inactive), drops titles that are
	 * not valid emails, and dedupes case variants of one mailbox (first one wins,
	 * keeping its original casing).
	 *
	 * @param \WP_Post[] $invitations One team's `wc_team_invitation` posts, in ID order.
	 * @param int        $dropped     Out-param: incremented once per pending invitation
	 *                                whose title is_email() rejects. Those invitees can
	 *                                appear in no table or count, so the caller should
	 *                                surface the tally rather than lose them silently.
	 *
	 * @return string[] Pending invitee emails in their original casing, deduped case-insensitively.
	 */
	private static function extract_pending_invitation_emails( $invitations, &$dropped = 0 ) {
		$emails = [];
		$seen   = [];
		foreach ( $invitations as $invitation ) {
			if ( 'wcmti-pending' !== $invitation->post_status ) {
				continue;
			}
			$email = \is_email( $invitation->post_title );
			if ( ! $email ) {
				++$dropped;
				continue;
			}
			$key = strtolower( $email );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$emails[]     = $email;
		}

		return $emails;
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
	 * Mirrors migrate-teams: prefer the team's linked active group subscription, else
	 * the group subscription this team was migrated to (by team marker, so a multi-team
	 * owner's managers are never promoted into a sibling team's group — including a
	 * marked group that is no longer active, which would otherwise report as unresolved),
	 * else an unmarked group subscription owned by the team owner. Never creates one.
	 *
	 * A marked group the publisher has disabled group subscriptions on resolves as
	 * unresolved — group manager meta on it would be inert — and is reported through
	 * `disabled_marked_group_ids` so the caller can name it instead of telling the operator
	 * nothing was found (see find_reusable_group_subscription()).
	 *
	 * @param int $team_id  The team post ID.
	 * @param int $owner_id The team owner user ID.
	 *
	 * @return array The find_reusable_group_subscription() shape.
	 */
	private static function resolve_backfill_reuse( $team_id, $owner_id ) {
		$raw_sub_id = (int) \get_post_meta( $team_id, '_subscription_id', true );
		if ( $raw_sub_id ) {
			$subscription = \wcs_get_subscription( $raw_sub_id );
			if ( $subscription && 'active' === $subscription->get_status() && Group_Subscription::is_group_subscription( $subscription ) ) {
				return array_merge( self::NO_REUSE, [ 'subscription' => $subscription ] );
			}
		}
		return self::find_reusable_group_subscription( $team_id, $owner_id );
	}

	/**
	 * Resolve the group subscription to reuse for a team whose linked subscription was
	 * not reusable.
	 *
	 * Prefers the group subscription this team was previously migrated to (stamped with
	 * MIGRATED_TEAM_ID_META_KEY), so reuse keys on the team and a multi-team owner keeps
	 * one group subscription per team. A marker match wins whatever its status: a team
	 * whose group has since expired or been put on hold must reuse that group rather
	 * than get a second, duplicate one on the next run (when the reused group's status
	 * withholds access the caller is told via `reused_without_access` so it can warn).
	 * Falls back to an active group subscription owned by the team owner that carries no
	 * marker, and flags that fallback so the caller can warn.
	 *
	 * The fallback additionally requires the subscription's *own*
	 * `_newspack_group_subscription_enabled` meta, not just
	 * Group_Subscription::is_group_subscription(): that reads through to product-level
	 * settings, and migrate-team-products stamps group meta on every team product, so
	 * after the companion product migration a sibling team's live linked subscription
	 * would otherwise look adoptable and get merged into this team's group. Only a group
	 * an earlier migrator run (or a publisher) explicitly enabled carries the meta on
	 * the subscription itself.
	 *
	 * A marked group the publisher has since turned group subscriptions off on is neither
	 * reused nor ignored: when the team has no usable group left, those groups are reported
	 * via `disabled_marked_group_ids` and the caller refuses the team. Reusing one would
	 * re-enable a flag the publisher deliberately set (migrate-teams stamps
	 * `_newspack_group_subscription_enabled` unconditionally), while ignoring it would create
	 * a second group stamped with the same team ID — so the call belongs to the operator,
	 * who either re-enables the group or clears its marker. A team that also has a usable
	 * marked group (the split state an earlier duplicating run could leave behind) is
	 * migrated into that one and reports nothing, since refusing a team whose group is
	 * there to be updated would block a migration that has somewhere valid to go.
	 *
	 * Both lookups run over a single pass of the owner's subscriptions. Discovery is
	 * scoped to the team's current owner (migrated groups are owned by their team owner),
	 * which assumes the owner has not changed since the team was migrated; if it has, the
	 * prior group is owned by the old owner and is not found here, so a re-run creates a
	 * fresh group for the team rather than reusing the original.
	 *
	 * Dry-run caveat: migrate-teams stamps the marker only on a live run, so a dry-run
	 * preview of an owner whose only group carries no marker shows each of their teams
	 * adopting that one group (the live run avoids the merge by marking it on the first
	 * team). The preview over-reports the merge rather than hiding one, so the live
	 * outcome is always safe.
	 *
	 * @param int $team_id  The team post ID.
	 * @param int $owner_id The team owner user ID.
	 *
	 * @return array {
	 *     @type \WC_Subscription|null $subscription              The subscription to reuse, or null.
	 *     @type bool                  $used_owner_fallback       Whether the unmarked owner fallback matched.
	 *     @type bool                  $reused_without_access     Whether the marker match holds a status that grants members no access.
	 *     @type int[]                 $disabled_marked_group_ids IDs of this team's marked groups that have group subscriptions disabled, populated only when no usable group resolved — the caller refuses the team. Empty otherwise, including when a usable group resolved alongside a disabled one.
	 * }
	 */
	public static function find_reusable_group_subscription( $team_id, $owner_id ) {
		$team_id  = absint( $team_id );
		$owner_id = absint( $owner_id );
		if ( ! $team_id || ! $owner_id || ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return self::NO_REUSE;
		}

		$non_active_marked_sub = null;
		$disabled_marked_ids   = [];
		$unmarked_group_sub    = null;
		foreach ( \wcs_get_users_subscriptions( $owner_id ) as $subscription ) {
			// wcs_get_users_subscriptions is filtered to include member-only groups; require ownership.
			if ( (int) $subscription->get_user_id() !== $owner_id ) {
				continue;
			}
			// The marker is read before the group-enabled gate: a group this team was
			// migrated to still belongs to the team once the publisher disables group
			// subscriptions on it, and the caller has to hear about it rather than pass
			// over it and duplicate the team's group.
			$marked_team_id = (int) $subscription->get_meta( self::MIGRATED_TEAM_ID_META_KEY );
			$is_group_sub   = Group_Subscription::is_group_subscription( $subscription );
			if ( ! $is_group_sub && $marked_team_id !== $team_id ) {
				continue;
			}
			// A group already migrated from this team wins outright — reuse keys on the
			// source team, so a sibling team of the same owner never merges into it, and
			// an unmarked group appearing earlier in the iteration never pre-empts it.
			if ( $marked_team_id === $team_id ) {
				if ( ! $is_group_sub ) {
					// Collect them all, but keep scanning: a team that also has a group of
					// its own with groups still enabled (the state an earlier duplicating
					// run could leave behind) is migrated into that one instead of refused,
					// and naming every disabled group at once saves the operator a run per
					// group when there is more than one.
					$disabled_marked_ids[] = $subscription->get_id();
					continue;
				}
				if ( 'active' === $subscription->get_status() ) {
					return array_merge( self::NO_REUSE, [ 'subscription' => $subscription ] );
				}
				// Hold on to it in case the team has no active marked group at all, but
				// keep scanning — an active one, if it exists, is the better match.
				$non_active_marked_sub = $non_active_marked_sub ?? $subscription;
				continue;
			}
			// Remember the first unmarked group as the owner fallback. A group already
			// marked for a different team is skipped so it is never merged into.
			if ( null === $unmarked_group_sub && 0 === $marked_team_id && 'active' === $subscription->get_status() && self::has_own_group_enabled_meta( $subscription ) ) {
				$unmarked_group_sub = $subscription;
			}
		}

		if ( $non_active_marked_sub ) {
			return array_merge(
				self::NO_REUSE,
				[
					'subscription'          => $non_active_marked_sub,
					// Only flag statuses that actually withhold access. `pending-cancel` is
					// not `active`, but group membership reads through
					// ACTIVE_SUBSCRIPTION_STATUSES, so its members do have access until the
					// period ends — warning about it would push an operator to reactivate a
					// subscription a reader deliberately cancelled.
					'reused_without_access' => ! $non_active_marked_sub->has_status( WooCommerce_Connection::ACTIVE_SUBSCRIPTION_STATUSES ),
				]
			);
		}

		// Reported ahead of the owner fallback: while this team has a group of its own,
		// adopting a different group of the owner's would merge the team's members into a
		// group that is not theirs on the strength of a flag the publisher set.
		if ( $disabled_marked_ids ) {
			return array_merge( self::NO_REUSE, [ 'disabled_marked_group_ids' => $disabled_marked_ids ] );
		}

		if ( $unmarked_group_sub ) {
			return array_merge(
				self::NO_REUSE,
				[
					'subscription'        => $unmarked_group_sub,
					'used_owner_fallback' => true,
				]
			);
		}

		return self::NO_REUSE;
	}

	/**
	 * Whether a subscription carries group-enabled meta of its own.
	 *
	 * Group_Subscription::is_group_subscription() falls through to the product's
	 * settings when the subscription has no meta of its own, so it is true for every
	 * subscription on a group-enabled product. This is the stricter test: only a
	 * subscription explicitly enabled as a group in its own right passes.
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 *
	 * @return bool
	 */
	private static function has_own_group_enabled_meta( $subscription ) {
		$enabled_meta = $subscription->get_meta( '_newspack_group_subscription_enabled' );
		return '' !== $enabled_meta && \wc_string_to_bool( $enabled_meta );
	}

	/**
	 * Find the team, if any, currently linked to a subscription.
	 *
	 * Used to name the other team in the owner-fallback warning, so an operator reading
	 * a dry-run preview can tell a stale pre-marker group from a sibling team's live one.
	 *
	 * @param int $subscription_id The subscription ID.
	 *
	 * @return int The team post ID, or 0 if none is linked.
	 */
	private static function find_team_linked_to_subscription( $subscription_id ) {
		$team_ids = \get_posts(
			[
				'post_type'      => 'wc_memberships_team',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_subscription_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (int) $subscription_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
		return ! empty( $team_ids ) ? (int) $team_ids[0] : 0;
	}

	/**
	 * Product and variation IDs a subscription's line items hold.
	 *
	 * Named in the skip messages so the operator can act without opening the
	 * subscription: these are the IDs that would have to appear in a gate's
	 * "Active subscription" rule for the team's own subscription to grant access.
	 *
	 * @param \WC_Subscription $subscription Subscription to read.
	 *
	 * @return int[]
	 */
	private static function subscription_product_ids( $subscription ) {
		$ids = [];
		foreach ( $subscription->get_items() as $item ) {
			if ( ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}
			$ids[] = (int) $item->get_product_id();
			if ( method_exists( $item, 'get_variation_id' ) && $item->get_variation_id() ) {
				$ids[] = (int) $item->get_variation_id();
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Whether the publisher bills this subscription.
	 *
	 * Decides whether a reused subscription keeps its commercial terms through
	 * the migration or is re-aligned onto --product-id as a $0 group subscription.
	 * Erring toward "paid" is the safe direction: the cost of a false positive is
	 * a subscription that keeps the product it already had, while a false negative
	 * deletes the product line the publisher sells and rewrites the schedule.
	 *
	 * `WC_Subscription::get_total()` is the recurring total, so a free trial, a
	 * sign-up fee, a synced first payment and a pending-cancel all still report
	 * the per-period amount. It is discounted, though: a 100% recurring coupon
	 * stores a total of 0 on a subscription the publisher does intend to bill
	 * again once the coupon's payment count runs out. The pre-discount subtotal is
	 * immune to that, and is 0 on every subscription this migration creates
	 * (link_migration_product() and create_group_subscription() both set it), so
	 * checking both keeps old $0 groups re-alignable without reading a fully
	 * discounted subscription as free.
	 *
	 * @param \WC_Subscription $subscription Subscription to test.
	 *
	 * @return bool
	 */
	private static function subscription_is_paid( $subscription ) {
		$total = method_exists( $subscription, 'get_total' ) ? (float) $subscription->get_total() : 0.0;
		if ( $total > 0 ) {
			return true;
		}
		$subtotal = method_exists( $subscription, 'get_subtotal' ) ? (float) $subscription->get_subtotal() : 0.0;
		return $subtotal > 0;
	}

	/**
	 * A subscription's recurring total as plain text for CLI output.
	 *
	 * Formatted here rather than with wc_price(), which returns markup and renders
	 * the USD symbol as the HTML entity `&#36;` — both land literally in a
	 * terminal, in the one message whose job is to make the amount at risk legible.
	 *
	 * @param \WC_Subscription $subscription Subscription to format.
	 *
	 * @return string
	 */
	private static function format_subscription_total( $subscription ) {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? \wc_get_price_decimals() : 2;
		return trim( sprintf( '%s %s', \number_format( (float) $subscription->get_total(), $decimals ), $subscription->get_currency() ) );
	}

	/**
	 * Whether a product is published, resolving a variation to its parent.
	 *
	 * WC_Subscription::contains_unavailable_product() checks the parent's status
	 * for a variation, so the parent's status is what decides whether a migration
	 * subscription can be activated.
	 *
	 * @param \WC_Product $product Product or variation.
	 *
	 * @return bool
	 */
	private static function product_is_published( $product ) {
		$subject = $product;
		if ( $product->is_type( 'variation' ) ) {
			$parent = \wc_get_product( $product->get_parent_id() );
			if ( ! $parent ) {
				return false;
			}
			$subject = $parent;
		}
		return 'publish' === $subject->get_status();
	}

	/**
	 * Whether the site's published gates would accept a subscription to a product.
	 *
	 * Enforcement runs through WC_Subscription::has_product(), which matches a
	 * line item's product ID *or* its variation ID. So a gate naming a variable
	 * subscription's parent accepts any of its variations, while a gate naming a
	 * sibling variation accepts none of the others. Comparing the product's own
	 * ID alone would refuse a variation the gates do in fact accept.
	 *
	 * @param \WC_Product $product            Product or variation.
	 * @param int[]       $access_product_ids Product IDs the published gates accept.
	 *
	 * @return bool
	 */
	private static function product_grants_gate_access( $product, $access_product_ids ) {
		$candidate_ids = [ (int) $product->get_id() ];
		if ( $product->is_type( 'variation' ) ) {
			$candidate_ids[] = (int) $product->get_parent_id();
		}
		foreach ( $candidate_ids as $candidate_id ) {
			if ( $candidate_id && in_array( $candidate_id, $access_product_ids, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Point a $0 migration line item at the product the operator chose.
	 *
	 * `set_product()` is the only setter that records a variation correctly,
	 * splitting it into parent product ID + variation ID; assigning the
	 * variation's own ID to `product_id` is rejected outright. Publishers selling
	 * seat tiers hold them as variations of one variable subscription product, so
	 * `--product-id` is routinely given a variation ID (see NPPD-1876).
	 *
	 * `create_group_subscription()` and `create_individual_subscription()` link
	 * their items the same way inline rather than through this helper: they were
	 * already correct, and each builds a differently-shaped item.
	 *
	 * @param \WC_Order_Item_Product $line_item The line item to populate.
	 * @param \WC_Product            $product   The product or variation to link.
	 *
	 * @return void
	 */
	private static function link_migration_product( $line_item, $product ) {
		$line_item->set_product( $product );
		$line_item->set_quantity( 1 );
		$line_item->set_subtotal( 0 );
		$line_item->set_total( 0 );
	}

	/**
	 * Create a new $0 migration subscription for a team owner and set its dates.
	 *
	 * @param int         $owner_id         The owner user ID.
	 * @param \WC_Product $migration_product The migration product.
	 * @param string      $billing_period   The billing period.
	 * @param int         $billing_interval The billing interval.
	 * @param string      $start_date       The subscription start date.
	 * @param string      $end_date         The subscription end date, or ''.
	 * @param array       $errors           Errors array, passed by reference.
	 * @param int         $team_id          The team post ID (for error context).
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
		self::link_migration_product( $line_item, $migration_product );
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
		self::link_migration_product( $line_item, $migration_product );

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
	 * Return IDs of all published membership plans, regardless of access method.
	 *
	 * The default scope for the member selection flags
	 * (--only-without-live-subscription, --user-ids), whose residuals live on
	 * purchase plans.
	 *
	 * @return int[]
	 */
	private static function get_published_plan_ids() {
		return \get_posts(
			[
				'post_type'      => 'wc_membership_plan',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			]
		);
	}

	/**
	 * Whether a user's access is backed by a subscription in a live status (see
	 * LIVE_SUBSCRIPTION_STATUSES): either a subscription they own, or a
	 * group-enabled subscription they are a member of (a team member migrated by
	 * migrate-teams rides the owner's subscription and must not also get a
	 * personal $0 subscription). Subscriptions existing only in dead states
	 * (cancelled, expired, ...) return false.
	 *
	 * Liveness is scoped to $product_ids because that is what grants access after
	 * the flip: Access_Rules::has_active_subscription() only accepts a
	 * subscription to one of the gate's configured products. A member whose only
	 * live subscription is to some other product — a recurring donation is the
	 * common case, and a gift recipient can hold one — would lose access, so they
	 * are a residual, not a covered member. An empty $product_ids means no product
	 * constraint, matching a gate whose subscription rule lists no products.
	 *
	 * @param int   $user_id     The member user ID.
	 * @param int[] $product_ids Products that grant access under the gates. Empty
	 *                           means any product counts.
	 *
	 * @return bool
	 */
	public static function member_has_live_subscription( $user_id, $product_ids = [] ) {
		return (bool) self::member_live_subscription_status( $user_id, $product_ids );
	}

	/**
	 * The live status backing a member's access, or null when nothing live backs
	 * it. Same classification as member_has_live_subscription(); the matched
	 * status feeds the run's reconciliation breakdown (on-hold skips carry a
	 * different post-flip meaning than active ones — see
	 * LIVE_SUBSCRIPTION_STATUSES).
	 *
	 * Gifted subscriptions follow the gates' rule
	 * (WooCommerce_Connection::get_active_subscriptions_for_user()): a gifted
	 * subscription covers only its recipient. The purchaser owns it, but the
	 * gate denies them — counting it here would skip them as covered and cost
	 * them their access at the flip; conversely the recipient does not own it,
	 * but the gate grants them, so a redundant $0 subscription would be minted
	 * if ownership were required.
	 *
	 * @param int   $user_id     The member user ID.
	 * @param int[] $product_ids Products that grant access under the gates. Empty
	 *                           means any product counts.
	 *
	 * @return string|null The matched live status, or null.
	 */
	public static function member_live_subscription_status( $user_id, $product_ids = [] ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return null;
		}
		$product_ids = array_values( array_filter( array_map( 'absint', (array) $product_ids ) ) );
		foreach ( \wcs_get_users_subscriptions( $user_id ) as $subscription ) {
			// A gifted subscription counts only for its recipient (the gates' rule).
			// Otherwise require ownership — wcs_get_users_subscriptions can be
			// filtered to include member-only groups, and group memberships are
			// evaluated explicitly below, in every context.
			$is_gifted = class_exists( 'WCS_Gifting' ) && \WCS_Gifting::is_gifted_subscription( $subscription );
			if ( $is_gifted ) {
				if ( (int) \WCS_Gifting::get_recipient_user( $subscription ) !== $user_id ) {
					continue;
				}
			} elseif ( (int) $subscription->get_user_id() !== $user_id ) {
				continue;
			}
			if ( ! in_array( $subscription->get_status(), self::LIVE_SUBSCRIPTION_STATUSES, true ) ) {
				continue;
			}
			if ( self::subscription_covers_access_products( $subscription, $product_ids ) ) {
				return $subscription->get_status();
			}
		}
		// Group memberships: the My Account injection filter doesn't run on CLI, so
		// ask the group data layer directly.
		foreach ( Group_Subscription::get_group_subscriptions_for_user( $user_id ) as $group_subscription ) {
			if ( ! in_array( $group_subscription->get_status(), self::LIVE_SUBSCRIPTION_STATUSES, true ) ) {
				continue;
			}
			if ( self::subscription_covers_access_products( $group_subscription, $product_ids ) ) {
				return $group_subscription->get_status();
			}
		}
		return null;
	}

	/**
	 * Whether a subscription is for one of the products that grant access.
	 *
	 * A subscription that can't be asked (no has_product()) counts as NOT
	 * covering: that errs towards granting the member a $0 subscription, which
	 * costs the publisher a redundant record, where the opposite error costs a
	 * reader their access silently.
	 *
	 * @param object $subscription Subscription object.
	 * @param int[]  $product_ids  Products that grant access. Empty means any.
	 *
	 * @return bool
	 */
	private static function subscription_covers_access_products( $subscription, $product_ids ) {
		if ( empty( $product_ids ) ) {
			return true;
		}
		if ( ! method_exists( $subscription, 'has_product' ) ) {
			return false;
		}
		foreach ( $product_ids as $product_id ) {
			if ( $subscription->has_product( $product_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Products that grant access under the site's published content gates: the
	 * `value` of every `subscription` access rule across them.
	 *
	 * This is the same set the pre-migration parity audit calls ACCESS_PRODUCT_IDS
	 * when it computes who loses access at the flip, so deriving it here keeps the
	 * command's member selection and that diff on one definition of "covered".
	 * Premium newsletter gates are excluded — they gate newsletters, not content.
	 *
	 * @return int[]
	 */
	private static function get_gate_access_product_ids() {
		if ( ! class_exists( 'Newspack\Content_Gate' ) ) {
			return [];
		}
		$product_ids = [];
		foreach ( Content_Gate::get_gates( Content_Gate::GATE_CPT, 'publish' ) as $gate ) {
			// Enforcement (User_Gate_Access) evaluates only gates whose custom
			// access is switched on. A published gate with rules retained but the
			// toggle off grants nothing — its products would widen the covered set
			// here, skipping members the gates will actually deny.
			if ( empty( $gate['custom_access']['active'] ) ) {
				continue;
			}
			if ( empty( $gate['custom_access']['access_rules'] ) ) {
				continue;
			}
			foreach ( $gate['custom_access']['access_rules'] as $group ) {
				foreach ( (array) $group as $rule ) {
					if ( ! isset( $rule['slug'] ) || 'subscription' !== $rule['slug'] ) {
						continue;
					}
					$product_ids = array_merge( $product_ids, array_map( 'absint', (array) ( $rule['value'] ?? [] ) ) );
				}
			}
		}
		return array_values( array_unique( array_filter( $product_ids ) ) );
	}

	/**
	 * Value-requiring migrate-manual-members flags found bare (no `=value`) on
	 * the raw command line.
	 *
	 * WP-CLI validates flags against the command synopsis before invoking the
	 * command: a bare `--user-ids` draws only a warning, then the flag is
	 * stripped and the command receives the flag's default — so in-method guards
	 * against a boolean flag value can never fire on a real invocation, and the
	 * run would silently proceed with a different scope than the operator
	 * intended (a reviewed list falling back to blanket plan processing is the
	 * worst case). Reading the raw argv is the only place the mistake is still
	 * visible.
	 *
	 * @param string[]|null $argv Raw argument vector; defaults to $_SERVER['argv'].
	 *
	 * @return string[] The value-requiring flags present without a value.
	 */
	public static function get_valueless_value_flags( $argv = null ) {
		if ( null === $argv ) {
			$argv = isset( $_SERVER['argv'] ) ? (array) $_SERVER['argv'] : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		$value_flags = [
			'--product-id',
			'--plan-ids',
			'--access-product-ids',
			'--user-ids',
			'--user-ids-file',
			'--skip-domains',
			'--group-owner-id',
		];
		$bare_flags  = [];
		foreach ( $argv as $token ) {
			if ( in_array( $token, $value_flags, true ) ) {
				$bare_flags[] = $token;
			}
		}
		return array_values( array_unique( $bare_flags ) );
	}

	/**
	 * Label one invitee's outcome for the re-invite table.
	 *
	 * Pure, so every branch is pinned by tests: the audit table's wording is the
	 * operator's record of the run, and mislabels here — a live run's rows wearing
	 * a dry-run label — have repeatedly been review blockers. Branch order is
	 * load-bearing in two places: `errored` must outrank the dry-run fallbacks so
	 * a team-level error on a live run never reads as a rehearsal, and the
	 * would-resend rehearsal label must rank BELOW `! $migrate_invitations` so a
	 * flagless run's lapsed invitee reads "not sent (pass --migrate-invitations to
	 * send)" — consistent with the table's own header — rather than claiming a
	 * rehearsal happened. Exposed for testing.
	 *
	 * @param array  $invitation_result   A migrate_team_invitations() result (see its return shape).
	 * @param string $invitee_email       The invitee to label.
	 * @param bool   $migrate_invitations Whether --migrate-invitations was passed.
	 *
	 * @return string The outcome-column label.
	 */
	public static function invitation_outcome_label( $invitation_result, $invitee_email, $migrate_invitations ) {
		if ( in_array( $invitee_email, $invitation_result['resent'], true ) ) {
			return 'invite sent (earlier invite had lapsed)';
		}
		if ( in_array( $invitee_email, $invitation_result['sent'], true ) ) {
			return 'invite sent';
		}
		if ( isset( $invitation_result['skipped'][ $invitee_email ] ) ) {
			return 'skipped — ' . $invitation_result['skipped'][ $invitee_email ];
		}
		if ( isset( $invitation_result['failed'][ $invitee_email ] ) ) {
			return 'FAILED — ' . $invitation_result['failed'][ $invitee_email ];
		}
		if ( ! empty( $invitation_result['errored'] ) ) {
			return 'not attempted (team error — see errors above)';
		}
		if ( ! $migrate_invitations ) {
			return 'not sent (pass --migrate-invitations to send)';
		}
		if ( in_array( $invitee_email, $invitation_result['would_resend'] ?? [], true ) ) {
			// The rehearsal twin of 'invite sent (earlier invite had lapsed)':
			// this reader would be emailed a second time by a live run.
			return self::OUTCOME_WOULD_RESEND;
		}
		return self::OUTCOME_WOULD_SEND;
	}

	/**
	 * Validate the --limit flag's raw value into a send-attempt cap.
	 *
	 * Strict on the same principle as parse_user_ids(): a present-but-malformed
	 * value fails the run rather than silently degrading. `max( 0, (int) $raw )`
	 * would collapse `--limit=` (an unset shell variable), `--limit=abc`,
	 * `--limit=0`, and `--limit=-5` into the no-cap sentinel — turning the one
	 * flag an operator passes to keep an email burst small into an unlimited
	 * burst, with the disclosing confirm prompt suppressed by --yes in exactly
	 * the scripted runs where the mistake happens. A bare `--limit` (boolean
	 * true from WP-CLI) is malformed too. Exposed for testing.
	 *
	 * @param mixed $raw The flag value from get_flag_value(), or null when the flag was not passed.
	 *
	 * @return int|\WP_Error 0 when the flag was not passed (no cap), the positive
	 *                       integer cap otherwise, or a WP_Error for any present
	 *                       value that is not a positive integer.
	 */
	public static function validate_send_limit( $raw ) {
		if ( null === $raw ) {
			return 0;
		}
		if ( ! is_numeric( $raw ) || (int) $raw != $raw || (int) $raw < 1 ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Deliberate: rejects non-integer numerics ("5.5") while accepting integer strings ("5").
			return new \WP_Error(
				'newspack_migrate_teams_invalid_limit',
				sprintf( '--limit expects a positive integer, got "%s". Refusing to run without the cap you asked for: fix the value (or drop the flag to send with no cap).', is_bool( $raw ) ? '(bare --limit)' : ( is_scalar( $raw ) ? (string) $raw : gettype( $raw ) ) )
			);
		}
		return (int) $raw;
	}

	/**
	 * Parse the --user-ids CSV and/or --user-ids-file input into a unique list of
	 * user IDs.
	 *
	 * Tokens may be separated by commas and/or whitespace (so a one-ID-per-line
	 * file works as-is). Parsing is strict: a non-numeric token fails the whole
	 * input rather than being dropped, since a malformed reviewed list should
	 * halt the run, not silently shrink it.
	 *
	 * @param string $user_ids_csv  Comma-delimited user IDs, '' for none.
	 * @param string $user_ids_file Path to a file of user IDs, '' for none.
	 *
	 * @return int[]|\WP_Error Unique user IDs in input order, or an error.
	 */
	public static function parse_user_ids( $user_ids_csv, $user_ids_file ) {
		// A bare `--user-ids` (no `=value`) arrives as boolean true, which a string
		// cast would turn into '1' — silently targeting user ID 1.
		if ( ! is_string( $user_ids_csv ) || ! is_string( $user_ids_file ) ) {
			return new \WP_Error( 'newspack_migration_user_ids_flag', 'The --user-ids/--user-ids-file flags require a value (e.g. --user-ids=101,102).' );
		}
		$raw_input = trim( $user_ids_csv );
		if ( '' !== trim( (string) $user_ids_file ) ) {
			if ( ! is_readable( $user_ids_file ) || is_dir( $user_ids_file ) ) {
				return new \WP_Error( 'newspack_migration_user_ids_file', sprintf( 'User IDs file %s could not be read.', $user_ids_file ) );
			}
			$file_contents = file_get_contents( $user_ids_file ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
			// Spreadsheet exports — where reviewed lists tend to come from — often
			// lead with a UTF-8 BOM; stripped here so the first ID doesn't fail the
			// strict parse with an error showing an apparently-valid token.
			$raw_input .= ' ' . preg_replace( '/^\xEF\xBB\xBF/', '', (string) $file_contents );
		}
		return self::parse_id_tokens( $raw_input, 'user ID', '--user-ids/--user-ids-file' );
	}

	/**
	 * Parse a comma- and/or whitespace-delimited list of positive integer IDs.
	 *
	 * Strict by design: a non-numeric token fails the whole input rather than
	 * being dropped, since a malformed reviewed list should halt the run, not
	 * silently shrink it.
	 *
	 * @param string $raw_input  The raw list.
	 * @param string $id_label   What the IDs are, for the error message ('user ID').
	 * @param string $flag_label The flag(s) the input came from, for the error message.
	 *
	 * @return int[]|\WP_Error Unique IDs in input order, or an error.
	 */
	private static function parse_id_tokens( $raw_input, $id_label, $flag_label ) {
		$raw_input = trim( $raw_input );
		if ( '' === $raw_input ) {
			return [];
		}
		$ids = [];
		foreach ( preg_split( '/[\s,]+/', $raw_input ) as $token ) {
			if ( '' === $token ) {
				continue;
			}
			if ( ! ctype_digit( $token ) || 0 === (int) $token ) {
				return new \WP_Error( 'newspack_migration_id_token', sprintf( '"%s" is not a valid %s — fix the %s input.', $token, $id_label, $flag_label ) );
			}
			$ids[] = (int) $token;
		}
		return array_values( array_unique( $ids ) );
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
			// has_product() matches a line item's product ID *or* its variation ID.
			// Comparing get_product_id() alone would never recognise this command's
			// own output for a variation --product-id, since create_individual_subscription()
			// links through set_product() and so stores the parent in product_id —
			// and an unrecognised prior run means every re-run grants another $0
			// subscription rather than skipping the member.
			if ( method_exists( $subscription, 'has_product' ) && $subscription->has_product( $product_id ) ) {
				return true;
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
