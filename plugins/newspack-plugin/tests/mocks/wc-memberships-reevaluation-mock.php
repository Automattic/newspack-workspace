<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.PrefixAllGlobals, Universal.Files.SeparateFunctionsFromOO.Mixed
/**
 * Stand-in for the WooCommerce Memberships pieces the "Reevaluate status" meta
 * box action walks: wc_memberships_get_user_membership() and the Subscriptions
 * integration reached through wc_memberships()->get_integrations_instance().
 *
 * Tests register memberships on wc_memberships()->user_memberships, keyed by
 * ID, and link them through the integration's `links` map, membership ID =>
 * subscription ID. Each status update is recorded on the integration's
 * `updates` as [ subscription ID, membership ID, status ] instead of being
 * applied. Subscriptions come from tests/mocks/wc-mocks.php.
 *
 * Require this only from isolated (separate-process) tests: declaring
 * wc_memberships() for the whole suite would leak into every other test.
 *
 * @package Newspack\Tests
 */

if ( ! class_exists( 'Newspack_Test_WCM_Reevaluation_User_Membership' ) ) {
	class Newspack_Test_WCM_Reevaluation_User_Membership {
		private $id;
		private $status;

		public function __construct( $id, $status ) {
			$this->id     = (int) $id;
			$this->status = $status;
		}

		public function get_id() {
			return $this->id;
		}

		public function get_status() {
			return $this->status;
		}
	}
}

if ( ! class_exists( 'Newspack_Test_WCM_Reevaluation_Subscriptions_Integration' ) ) {
	class Newspack_Test_WCM_Reevaluation_Subscriptions_Integration {
		public $links   = [];
		public $updates = [];

		public function get_subscription_from_membership( $user_membership ) {
			$membership_id = is_object( $user_membership ) ? $user_membership->get_id() : (int) $user_membership;
			return isset( $this->links[ $membership_id ] ) ? wcs_get_subscription( $this->links[ $membership_id ] ) : null;
		}

		public function has_subscription_same_status( $subscription, $user_membership ) {
			return $subscription->get_status() === $user_membership->get_status();
		}

		public function update_related_membership_status( $subscription, $user_membership, $status ) {
			$this->updates[] = [ $subscription->get_id(), $user_membership->get_id(), $status ];
		}
	}
}

if ( ! class_exists( 'Newspack_Test_WCM_Reevaluation_Plugin' ) ) {
	/**
	 * Stands in for both the plugin and its integrations registry.
	 */
	class Newspack_Test_WCM_Reevaluation_Plugin {
		public $user_memberships = [];
		private $subscriptions;

		public function get_integrations_instance() {
			return $this;
		}

		public function get_subscriptions_instance() {
			if ( ! $this->subscriptions instanceof Newspack_Test_WCM_Reevaluation_Subscriptions_Integration ) {
				$this->subscriptions = new Newspack_Test_WCM_Reevaluation_Subscriptions_Integration();
			}
			return $this->subscriptions;
		}
	}
}

if ( ! function_exists( 'wc_memberships' ) ) {
	function wc_memberships() {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new Newspack_Test_WCM_Reevaluation_Plugin();
		}
		return $instance;
	}
}

if ( ! function_exists( 'wc_memberships_get_user_membership' ) ) {
	function wc_memberships_get_user_membership( $user_membership_id ) {
		return wc_memberships()->user_memberships[ (int) $user_membership_id ] ?? null;
	}
}

// A test that loaded another wc_memberships() double first gets that one, and
// the integration this file exists to provide is absent. Say so here rather
// than as an undefined-method fatal inside the code under test.
if ( ! wc_memberships() instanceof Newspack_Test_WCM_Reevaluation_Plugin ) {
	throw new \RuntimeException(
		'wc_memberships() was already declared by another mock. Require wc-memberships-reevaluation-mock.php before any other WC Memberships mock, in an isolated (separate-process) test.'
	);
}
