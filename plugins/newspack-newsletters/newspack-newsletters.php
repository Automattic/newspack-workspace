<?php
/**
 * Plugin Name:     Newspack Newsletters
 * Plugin URI:      https://newspack.com
 * Description:     Newsletter authoring using the Gutenberg editor.
 * Author:          Automattic
 * Author URI:      https://newspack.com/
 * License: GPL2
 * Text Domain:     newspack-newsletters
 * Domain Path:     /languages
 * Version:         3.33.4
 *
 * @package         Newspack_Newsletters
 */

defined( 'ABSPATH' ) || exit;

// Define NEWSPACK_NEWSLETTERS_PLUGIN_FILE.
if ( ! defined( 'NEWSPACK_NEWSLETTERS_PLUGIN_FILE' ) ) {
	define( 'NEWSPACK_NEWSLETTERS_PLUGIN_FILE', plugin_dir_path( __FILE__ ) );
}

/**
 * API endpoint for Letterhead integration.
 * Override for development or to use a different instance.
 *
 * @constant NEWSPACK_NEWSLETTERS_LETTERHEAD_ENDPOINT
 * @type     string
 * @default  https://api.tryletterhead.com
 * @status   draft
 *
 * @example define( 'NEWSPACK_NEWSLETTERS_LETTERHEAD_ENDPOINT', 'https://custom-api.example.com' );
 */
if ( ! defined( 'NEWSPACK_NEWSLETTERS_LETTERHEAD_ENDPOINT' ) ) {
	define( 'NEWSPACK_NEWSLETTERS_LETTERHEAD_ENDPOINT', 'https://api.tryletterhead.com' );
}
// Include main plugin resources.
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/vendor/autoload.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/class-newspack-newsletters-logger.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/service-providers/interface-newspack-newsletters-esp-service.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/service-providers/interface-newspack-newsletters-wp-hookable.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/service-providers/class-newspack-newsletters-service-provider.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/service-providers/class-newspack-newsletters-service-provider-controller.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/service-providers/class-newspack-newsletters-service-provider-usage-report.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/service-providers/mailchimp/class-newspack-newsletters-mailchimp-groups.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/service-providers/mailchimp/class-newspack-newsletters-mailchimp.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/service-providers/mailchimp/class-newspack-newsletters-mailchimp-controller.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/service-providers/mailchimp/class-newspack-newsletters-mailchimp-cached-data.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/service-providers/mailchimp/class-newspack-newsletters-mailchimp-usage-reports.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/service-providers/mailchimp/class-newspack-newsletters-mailchimp-notes.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/service-providers/mailchimp/class-newspack-newsletters-mailchimp-subscription-list-trait.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/service-providers/constant_contact/class-newspack-newsletters-constant-contact.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/service-providers/constant_contact/class-newspack-newsletters-constant-contact-controller.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/service-providers/constant_contact/class-newspack-newsletters-constant-contact-sdk.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/service-providers/constant_contact/class-newspack-newsletters-constant-contact-usage-reports.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/service-providers/active_campaign/class-newspack-newsletters-active-campaign.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/service-providers/active_campaign/class-newspack-newsletters-active-campaign-controller.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/service-providers/active_campaign/class-newspack-newsletters-active-campaign-usage-reports.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/service-providers/letterhead/class-newspack-newsletters-letterhead.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/service-providers/letterhead/dtos/class-newspack-newsletters-letterhead-promotion-dto.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/service-providers/letterhead/models/class-newspack-newsletters-letterhead-promotion.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/class-newspack-newsletters-subscription.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/class-newspack-newsletters-blocks.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/class-newspack-newsletters-editor.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/class-newspack-newsletters-layouts.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/class-newspack-newsletters-settings.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/class-newspack-newsletters-renderer.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/class-newspack-newsletters-bulk-actions.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/class-newspack-newsletters-quick-edit.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/class-newspack-newsletters-embed.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/class-newspack-newsletters-subscription-attempts.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/ads/class-ads.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/tracking/class-utils.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/tracking/class-pixel.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/tracking/class-click.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/tracking/class-admin.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/class-newspack-newsletters.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/plugins/woocommerce-memberships/class-woocommerce-memberships.php';

// This MUST be initialized after Newspack_Newsletter class.
\Newspack\Newsletters\Subscription_Lists::init();
\Newspack\Newsletters\Send_Lists::init();
