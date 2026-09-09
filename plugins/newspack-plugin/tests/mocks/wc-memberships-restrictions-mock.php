<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.PrefixAllGlobals, Universal.Files.SeparateFunctionsFromOO.Mixed
/**
 * Stand-in for an active WooCommerce Memberships install that also exposes the
 * handler chain Newspack walks to reach the content restriction callbacks:
 * wc_memberships()->get_restrictions_instance()->get_posts_restrictions_instance().
 *
 * Both handlers are returned as the same instance every call. Code under test
 * resolves them again on each call and hands them to remove_action(), which
 * matches callbacks by object identity — a fresh instance per call would never
 * match what was registered, and a removal test would pass for the wrong reason.
 *
 * Require this only from isolated (separate-process) tests: declaring
 * wc_memberships() for the whole suite would flip
 * Newspack\Memberships::is_active() true everywhere and break every test that
 * assumes Memberships is inactive.
 *
 * @package Newspack\Tests
 */

if ( ! class_exists( 'Newspack_Test_WC_Memberships_Posts_Restrictions' ) ) {
	/**
	 * The three callbacks Memberships registers and Newspack may remove. The
	 * bodies are irrelevant — the tests assert on hook registration, not output.
	 */
	class Newspack_Test_WC_Memberships_Posts_Restrictions {
		public function restrict_post( $post ) {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found

		public function handle_restricted_post_content_filtering( $content ) {
			return $content;
		}

		public function display_restricted_taxonomy_term_notice() {}
	}
}

if ( ! class_exists( 'Newspack_Test_WC_Memberships_Restrictions' ) ) {
	class Newspack_Test_WC_Memberships_Restrictions {
		private $posts_restrictions;

		public function get_posts_restrictions_instance() {
			if ( ! $this->posts_restrictions instanceof Newspack_Test_WC_Memberships_Posts_Restrictions ) {
				$this->posts_restrictions = new Newspack_Test_WC_Memberships_Posts_Restrictions();
			}
			return $this->posts_restrictions;
		}
	}
}

if ( ! class_exists( 'Newspack_Test_WC_Memberships_Plugin' ) ) {
	class Newspack_Test_WC_Memberships_Plugin {
		private $restrictions;

		public function get_restrictions_instance() {
			if ( ! $this->restrictions instanceof Newspack_Test_WC_Memberships_Restrictions ) {
				$this->restrictions = new Newspack_Test_WC_Memberships_Restrictions();
			}
			return $this->restrictions;
		}
	}
}

if ( ! function_exists( 'wc_memberships' ) ) {
	function wc_memberships() {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new Newspack_Test_WC_Memberships_Plugin();
		}
		return $instance;
	}
}

// Last, and load-bearing: the active mock supplies the `WC_Memberships` class
// that Memberships::is_active() checks for. Requiring it after the declaration
// above leaves its own function_exists() guard satisfied, so it contributes the
// class and skips its bare wc_memberships(), which returns no handler chain.
// Declaring WC_Memberships here instead would be a duplicate class name across
// mock files, which PHPCS fails.
require_once __DIR__ . '/wc-memberships-active-mock.php';

// A test that loaded another wc_memberships() double first gets that one, and
// the handler chain this file exists to provide is silently absent. Say so here
// rather than letting it surface as an undefined-method fatal several frames
// away, inside the code under test.
if ( ! method_exists( wc_memberships(), 'get_restrictions_instance' ) ) {
	throw new \RuntimeException(
		'wc_memberships() was already declared by another mock and returns no restrictions handler. Require wc-memberships-restrictions-mock.php before any other WC Memberships mock, in an isolated (separate-process) test.'
	);
}
