<?php
/**
 * My Account navigation menu.
 *
 * @author   Newspack
 * @category WooCommerce Subscriptions/Templates
 * @package  Newspack
 */

use Newspack\My_Account_UI_V1;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

do_action( 'woocommerce_before_account_navigation' );

// Teams for WooCommerce Memberships.
$team                = null;
$teams_area_sections = [];
if ( function_exists( 'wc_memberships_for_teams' ) ) {
	$teams_area          = wc_memberships_for_teams()->get_frontend_instance()->get_teams_area_instance();
	$team                = $teams_area->get_teams_area_team();
	$teams_area_sections = $teams_area->get_teams_area_navigation_items( $team );
}

$back_link = [
	'url'   => home_url( '/' ),
	'label' => __( 'Back to homepage', 'newspack-plugin' ),
];
$nav_items = [];
if ( $team ) {
	$back_section = array_shift( $teams_area_sections );
	$back_link    = [
		'url'   => $back_section['url'],
		'label' => $back_section['label'],
	];
	foreach ( $teams_area_sections as $section_id => $section_data ) {
		$classes     = wc_get_account_menu_item_classes( $section_id ) . ' ' . $section_data['class'];
		$nav_items[] = [
			'url'        => $section_data['url'],
			'label'      => $section_data['label'],
			'classes'    => $classes,
			'is_current' => false !== strpos( $classes, 'is-active' ),
		];
	}
} else {
	foreach ( wc_get_account_menu_items() as $endpoint => $label ) {
		$nav_items[] = [
			'url'        => \Newspack\My_Account::get_endpoint_url( $endpoint ),
			'label'      => $label,
			'classes'    => wc_get_account_menu_item_classes( $endpoint ),
			// Highlight subscriptions menu item if viewing a single subscription.
			'is_current' => wc_is_current_account_menu_item( $endpoint ) || My_Account_UI_V1::is_subscription_page( $endpoint ),
		];
	}
}
$sign_out_url     = wp_logout_url( wc_get_account_endpoint_url( 'customer-logout' ) );
$sign_out_label   = __( 'Sign out', 'newspack-plugin' );
$sign_out_classes = wc_get_account_menu_item_classes( 'customer-logout' );

require __DIR__ . '/navigation-menu.php';

do_action( 'woocommerce_after_account_navigation' );
