<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FileComment.Missing

/**
 * Fake SkyVerge Teams Subscriptions integration for unit tests.
 *
 * @package Newspack\Tests
 */

namespace SkyVerge\WooCommerce\Memberships\Teams\Integrations;

if ( ! class_exists( __NAMESPACE__ . '\\Subscriptions' ) ) {
	class Subscriptions {
		public function get_teams_from_subscription( $subscription_id ) {
			return $GLOBALS['teams_mock_teams_for_sub'][ $subscription_id ] ?? [];
		}
	}
}
