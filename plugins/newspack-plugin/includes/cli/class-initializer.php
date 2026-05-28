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
		WP_CLI::add_command( 'newspack ga4-dimensions', 'Newspack\CLI\GA4_Dimensions' );

		// Only register the Teams for Memberships diagnostics command on sites where the
		// SkyVerge plugin is active. No reason to surface it in `wp help` otherwise.
		if ( class_exists( 'WC_Memberships_For_Teams_Loader' ) ) {
			WP_CLI::add_command(
				'newspack teams-for-memberships diagnostics',
				[ 'Newspack\CLI\Teams_For_Memberships_Diagnostics', 'diagnostics' ]
			);
		}

		Optional_Modules::register_commands();
	}
}
