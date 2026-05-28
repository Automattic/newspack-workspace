<?php
/**
 * Newspack Hub Accepted Actions.
 *
 * @package Newspack
 */

namespace Newspack_Network;

/**
 * This class holds the actions this Hub will accept from other sites.
 *
 * The class names will be used to instantiate the appropriate classes for each action type.
 */
class Accepted_Actions {

	/**
	 * Get the accepted actions
	 *
	 * Actions are both events fired from the Newspack Data Events API and the events that are stored in the hub's Event Log. They share the same name.
	 *
	 * In the Node, the webhooks will be fired once one of these events is triggered and they will be sent to the Hub.
	 *
	 * In the Hub, the plugin will also listen to these events, but they will be stored directly in the Event Log table.
	 *
	 * Then, the Nodes will pull the events from the hub and process them locally.
	 *
	 * @var array Array where the keys are the supported events and the values are the Incoming Events class names
	 */
	const ACTIONS = [
		'reader_registered'                        => 'Reader_Registered',
		'newspack_node_order_changed'              => 'Order_Changed',
		'newspack_node_subscription_changed'       => 'Subscription_Changed',
		'canonical_url_updated'                    => 'Canonical_Url_Updated',
		'donation_new'                             => 'Donation_New',
		'donation_subscription_cancelled'          => 'Donation_Subscription_Cancelled',
		'network_user_updated'                     => 'User_Updated',
		'network_user_deleted'                     => 'User_Deleted',
		'newspack_network_woo_membership_updated'  => 'Woocommerce_Membership_Updated',
		'network_manual_sync_user'                 => 'User_Manually_Synced',
		'network_nodes_synced'                     => 'Nodes_Synced',
		'newspack_network_membership_plan_updated' => 'Membership_Plan_Updated',
		'network_post_updated'                     => 'Network_Post_Updated',
		'network_post_deleted'                     => 'Network_Post_Deleted',
		'network_incoming_post_deleted'            => 'Network_Incoming_Post_Deleted',
		'newspack_network_distributor_migrate_incoming_posts' => 'Distributor_Migrate_Incoming_Posts',
		'network_hub_name_updated'                 => 'Hub_Name_Updated',
		'newspack_network_product_updated'         => 'Product_Updated',
	];

	/**
	 * The list of actions that Nodes will pull from the Hub
	 *
	 * A subset of the actions above. Nodes are not interested in all events. Some of them are only used to populate the centralized dashboards in the Hub.
	 *
	 * @var array
	 */
	const ACTIONS_THAT_NODES_PULL = [
		'reader_registered',
		'canonical_url_updated',
		'donation_new',
		'donation_subscription_cancelled',
		'network_user_updated',
		'network_user_deleted',
		'newspack_network_woo_membership_updated',
		'network_manual_sync_user',
		'network_nodes_synced',
		'newspack_node_subscription_changed',
		'newspack_network_membership_plan_updated',
		'network_post_updated',
		'network_post_deleted',
		'network_incoming_post_deleted',
		'newspack_network_distributor_migrate_incoming_posts',
		'network_hub_name_updated',
		'newspack_network_product_updated',
	];
}
