<?php
/**
 * Newspack plugin CLI initializer
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Initializer CLI commands
 */
class Initializer {

	/**
	 * Initialized this class and adds hooks to register CLI commands
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_comands' ] );
		include_once NEWSPACK_ABSPATH . 'includes/cli/class-ras.php';
		include_once NEWSPACK_ABSPATH . 'includes/cli/class-ras-contact-sync.php';
		include_once NEWSPACK_ABSPATH . 'includes/cli/class-co-authors-plus.php';
		include_once NEWSPACK_ABSPATH . 'includes/cli/class-mailchimp.php';
		include_once NEWSPACK_ABSPATH . 'includes/cli/class-optional-modules.php';
		include_once NEWSPACK_ABSPATH . 'includes/cli/class-woocommerce-subscriptions.php';
		include_once NEWSPACK_ABSPATH . 'includes/cli/class-ga4-dimensions.php';
		include_once NEWSPACK_ABSPATH . 'includes/cli/class-teams-for-memberships-diagnostics.php';
		include_once NEWSPACK_ABSPATH . 'includes/cli/class-export.php';
		include_once NEWSPACK_ABSPATH . 'includes/cli/class-teams-migration.php';
		include_once NEWSPACK_ABSPATH . 'includes/cli/class-institutions-migration.php';
		include_once NEWSPACK_ABSPATH . 'includes/cli/trait-one-time-purchase-migration.php';
		include_once NEWSPACK_ABSPATH . 'includes/cli/class-membership-gates-migration.php';
		include_once NEWSPACK_ABSPATH . 'includes/cli/class-premium-newsletters-migration.php';
		include_once NEWSPACK_ABSPATH . 'includes/cli/class-fix-memberships.php';
	}

	/**
	 * Adds CLI commands. Do not call directly or before init hooks
	 *
	 * @return void
	 */
	public static function register_comands() {
		if ( ! defined( 'WP_CLI' ) ) {
			return;
		}

		WP_CLI::add_command( 'newspack setup', 'Newspack\CLI\Setup' );
		WP_CLI::add_command( 'newspack remove-starter-content', [ 'Newspack\Starter_Content','remove_starter_content' ] );

		// Utility commands for managing RAS data via WP CLI.
		WP_CLI::add_command(
			'newspack ras setup',
			[ 'Newspack\CLI\RAS', 'cli_setup_ras' ]
		);

		WP_CLI::add_command(
			'newspack verify-reader',
			[ 'Newspack\CLI\RAS', 'cli_verify_reader' ]
		);

		WP_CLI::add_command(
			'newspack esp sync',
			[ 'Newspack\CLI\RAS_Contact_Sync', 'cli_sync_contacts' ]
		);

		WP_CLI::add_command(
			'newspack integrations backfill',
			[ 'Newspack\CLI\RAS_Contact_Sync', 'cli_backfill' ]
		);

		WP_CLI::add_command(
			'newspack mailchimp merge-fields list',
			[ 'Newspack\CLI\Mailchimp', 'cli_mailchimp_list_merge_fields' ]
		);

		WP_CLI::add_command(
			'newspack mailchimp merge-fields delete',
			[ 'Newspack\CLI\Mailchimp', 'cli_mailchimp_delete_merge_fields' ]
		);

		WP_CLI::add_command(
			'newspack mailchimp merge-fields fix-duplicates',
			[ 'Newspack\CLI\Mailchimp', 'cli_mailchimp_fix_duplicate_merge_fields' ]
		);

		WP_CLI::add_command( 'newspack migrate-co-authors-guest-authors', [ 'Newspack\CLI\Co_Authors_Plus', 'migrate_guest_authors' ] );
		WP_CLI::add_command( 'newspack backfill-non-editing-contributors', [ 'Newspack\CLI\Co_Authors_Plus', 'backfill_non_editing_contributor' ] );
		WP_CLI::add_command( 'newspack migrate-expired-subscriptions', [ 'Newspack\CLI\WooCommerce_Subscriptions', 'migrate_expired_subscriptions' ] );
		WP_CLI::add_command( 'newspack card-expiry-warning-backfill', [ 'Newspack\CLI\WooCommerce_Subscriptions', 'card_expiry_warning_backfill' ] );
		WP_CLI::add_command( 'newspack audit-subscription-products', [ 'Newspack\CLI\WooCommerce_Subscriptions', 'audit_subscription_products' ] );
		WP_CLI::add_command( 'newspack ga4-dimensions', 'Newspack\CLI\GA4_Dimensions' );
		WP_CLI::add_command( 'newspack export-subscriptions', [ 'Newspack\CLI\Export', 'export_subscriptions' ] );
		WP_CLI::add_command( 'newspack export-users', [ 'Newspack\CLI\Export', 'export_users' ] );

		// Only register the Teams for Memberships diagnostics command on sites where the
		// SkyVerge plugin is active. No reason to surface it in `wp help` otherwise.
		if ( class_exists( 'WC_Memberships_For_Teams_Loader' ) ) {
			WP_CLI::add_command(
				'newspack teams-for-memberships diagnostics',
				[ 'Newspack\CLI\Teams_For_Memberships_Diagnostics', 'diagnostics' ]
			);
		}

		// WooCommerce Memberships → Access Control group subscription migration commands.
		// Only register them where WooCommerce Memberships is active — they operate on
		// Memberships teams/plans and are useless (and clutter `wp help`) otherwise.
		if ( \Newspack\Memberships::is_active() ) {
			WP_CLI::add_command( 'newspack migrate-teams', [ 'Newspack\CLI\Teams_Migration', 'migrate_teams' ] );
			WP_CLI::add_command( 'newspack migrate-team-products', [ 'Newspack\CLI\Teams_Migration', 'migrate_team_products' ] );
			WP_CLI::add_command( 'newspack migrate-manual-members', [ 'Newspack\CLI\Teams_Migration', 'migrate_manual_members' ] );
			WP_CLI::add_command( 'newspack backfill-team-managers', [ 'Newspack\CLI\Teams_Migration', 'backfill_team_managers' ] );
			WP_CLI::add_command( 'newspack migrate-institutions', [ 'Newspack\CLI\Institutions_Migration', 'migrate_institutions' ] );
			// The standalone `migrate-memberships` drop-in registers the same command
			// name with the opposite, write-by-default flag convention. Registration is
			// last-wins with no error, so the warning is hooked here — at registration
			// time — rather than placed inside the callback, which only runs when this
			// registration happens to be the one that won. `before_invoke` fires for
			// whichever callback won, and only for this command, so the notice cannot
			// leak into unrelated `wp` invocations.
			if ( class_exists( 'Newspack_Migrate_Membership_Gates_Command' ) ) {
				WP_CLI::add_hook(
					'before_invoke:newspack migrate-membership-gates',
					function () {
						WP_CLI::warning( 'The standalone `migrate-memberships` drop-in is active and registers `newspack migrate-membership-gates` too, with the opposite (write-by-default) flag convention. Deactivate/delete the drop-in so it is unambiguous which command runs.' );
					}
				);
			}
			WP_CLI::add_command( 'newspack migrate-membership-gates', [ 'Newspack\CLI\Membership_Gates_Migration', 'migrate_membership_gates' ] );
			WP_CLI::add_command( 'newspack migrate-premium-newsletters', [ 'Newspack\CLI\Premium_Newsletters_Migration', 'migrate_premium_newsletters' ] );
		}

		// Only register the fix-memberships command when WC Memberships is active.
		if ( function_exists( 'wc_memberships' ) ) {
			WP_CLI::add_command(
				'newspack fix-memberships',
				[ 'Newspack\CLI\Fix_Memberships', 'run' ]
			);
		}

		Optional_Modules::register_commands();
	}
}
