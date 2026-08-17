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
 * Version:         3.39.0
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
// Load the Composer autoloader. Prefer the jetpack-autoloader package loader
// (which negotiates shared package versions across plugins); fall back to the
// plain Composer autoloader if it is unavailable.
if ( file_exists( NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/vendor/autoload_packages.php' ) ) {
	require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/vendor/autoload_packages.php';
} elseif ( file_exists( NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/vendor/autoload.php' ) ) {
	require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/vendor/autoload.php';
} else {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Newspack Newsletters is missing its Composer dependencies. Please run "composer install" in the plugin directory.', 'newspack-newsletters' );
			echo '</p></div>';
		}
	);
	return;
}
// Include main plugin resources.
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/class-newspack-newsletters-logger.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/email-renderers/class-feature-flag.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/email-renderers/class-fonts.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/email-renderers/class-email-defaults.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/email-renderers/class-full-bleed-sections.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/email-renderers/class-renderer-controller.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/email-renderers/class-editor-bootstrap.php';
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
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/email-renderers/class-theme-json-builder.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/ads/class-ads.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/tracking/class-utils.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/tracking/class-ad-stats.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/tracking/class-pixel.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/tracking/class-click.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/tracking/class-admin.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/class-newspack-newsletters.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/plugins/woocommerce-memberships/class-woocommerce-memberships.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/admin/class-admin-page.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/admin/pages/class-react-list-page.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/admin/pages/class-hidden-react-list-page.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/admin/pages/class-newsletters-list-page.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/admin/pages/class-ads-list-page.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/admin/pages/class-advertisers-list-page.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/admin/pages/class-layouts-list-page.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/admin/pages/class-settings-page.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/admin/trait-rest-status-field.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/admin/trait-status-filter-builder.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/admin/class-newsletters-list-rest.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/admin/class-ads-list-rest.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/admin/class-advertisers-list-rest.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/admin/class-settings-rest.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/admin/class-admin-shell-preferences.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/admin/class-asset-loader.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/admin/class-admin-shell-menu.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/admin/class-admin-shell-legacy-redirect.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/admin/class-admin-shell-assets.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/admin/class-admin-shell.php';
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/class-wizard-bridge.php';

// Boot the WooCommerce Email Editor package. Must run before the `init` hook
// so the editor's own `init` callbacks (CPT, templates) are registered in time.
//
// The boot itself is unconditional (one boot path for both flag states), but the
// newsletters CPT is opted into the package only when the renderer flag is on — see
// Editor_Bootstrap::add_post_type(). That opt-in is what the package's front-end
// `single_template` takeover (load_email_preview_template) keys off, so gating it
// keeps a flag-off site's *public* newsletters rendering in the theme's standard
// single template (legacy/MJML behavior) rather than the package's email-preview
// template. With the flag off the boot still registers site-wide machinery, but it is
// either additive or post-type-gated: a `sent` post status, the email-editor preview
// REST routes, an `email-contents` block-pattern category, a `post_types` field on the
// wp_template REST response, and a `safe_style_css` KSES allow-list widening (`display`
// + `mso-*`). None of it engages the front-end template path — that is what the opt-in
// gate closes. Full rationale in includes/email-renderers/README.md.
\Newspack\Newsletters\Email_Renderers\Editor_Bootstrap::init();

// This MUST be initialized after Newspack_Newsletter class.
\Newspack\Newsletters\Subscription_Lists::init();
\Newspack\Newsletters\Send_Lists::init();
\Newspack\Newsletters\Admin\Admin_Shell::init();
\Newspack\Newsletters\Wizard_Bridge::init();
\Newspack\Newsletters\Admin\Newsletters_List_REST::init();
\Newspack\Newsletters\Admin\Ads_List_REST::init();
\Newspack\Newsletters\Admin\Advertisers_List_REST::init();
\Newspack\Newsletters\Admin\Settings_REST::init();
\Newspack\Newsletters\Admin\Admin_Shell_Preferences::init();
