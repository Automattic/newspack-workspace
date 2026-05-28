<?php
/**
 * Newspack Hub plugin initialization.
 *
 * @package Newspack
 */

namespace Newspack_Network;

/**
 * Class to handle the plugin initialization
 */
class Initializer {

	/**
	 * Runs the initialization.
	 */
	public static function init() {
		Admin::init();
		Users::init();

		if ( Site_Role::is_hub() ) {
			Hub\Admin::init();
			Hub\Nodes::init();
			Hub\Webhook::init();
			Hub\Pull_Endpoint::init();
			Hub\Event_Listeners::init();
			Hub\Database\Subscriptions::init();
			Hub\Database\Orders::init();
			Hub\Newspack_Ads_GAM::init();
			Hub\Connect_Node::init();
		}

		// Allow to access node settings before the site has a role, so it can be set via URL.
		if ( Site_Role::is_node() || ! Site_Role::get() ) {
			Node\Settings::init();
		}
		if ( Site_Role::is_node() ) {
			if ( Node\Settings::get_hub_url() ) {
				Node\Webhook::init();
				Node\Info_Endpoints::init();
				Node\Integrity_Check_Endpoints::init();
				Node\Pulling::init();
				Rest_Authenticaton::init_node_filters();
			}
		}

		Data_Listeners::init();
		Reader_Roles_Filter::init();
		User_Update_Watcher::init();
		User_Manual_Sync::init();
		Distributor_Customizations::init();
		Esp_Metadata_Sync::init();
		Content_Distribution::init();
		Story_Budget::init();

		Synchronize_All::init();
		Data_Backfill::init();
		Membership_Dedupe::init();
		CLI\Integrity_Check::init();

		Woocommerce\Events::init();
		Woocommerce\Product_Admin::init();
		Woocommerce_Subscriptions\My_Account::init();
		Woocommerce_Memberships\Admin::init();
		Woocommerce_Memberships\Events::init();
		Woocommerce_Memberships\Subscriptions_Integration::init();
		Woocommerce_Memberships\Limit_Purchase::init();
		Content_Gate\Access::init();
		Content_Gate\Limit_Purchase::init();

		register_activation_hook( NEWSPACK_NETWORK_PLUGIN_FILE, [ __CLASS__, 'activation_hook' ] );
	}

	/**
	 * Runs on plugin activation.
	 *
	 * @return void
	 */
	public static function activation_hook() {
		add_role( NEWSPACK_NETWORK_READER_ROLE, __( 'Network Reader', 'newspack-network' ) ); // phpcs:ignore
	}
}
