<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.PrefixAllGlobals, Universal.Files.SeparateFunctionsFromOO.Mixed
/**
 * A stand-in for `wc_memberships_get_user_memberships()`, answering from a
 * registry of plan slugs keyed by user ID.
 *
 * Require this only from isolated (separate-process) tests: the function is
 * global, and once declared it tells every later test in the process that
 * WooCommerce Memberships is installed.
 *
 * @package Newspack\Tests
 */

// [ user_id => [ plan slug, ... ] ], set by the test.
global $newspack_test_user_plan_slugs;
$newspack_test_user_plan_slugs = [];

class Newspack_Mock_Plan_With_Slug {
	private $slug;
	public function __construct( $slug ) {
		$this->slug = $slug;
	}
	public function get_slug() {
		return $this->slug;
	}
}

class Newspack_Mock_Membership_With_Plan {
	private $plan;
	public function __construct( $slug ) {
		$this->plan = new Newspack_Mock_Plan_With_Slug( $slug );
	}
	public function get_plan() {
		return $this->plan;
	}
}

if ( ! function_exists( 'wc_memberships_get_user_memberships' ) ) {
	function wc_memberships_get_user_memberships( $user_id = null, $args = [] ) {
		global $newspack_test_user_plan_slugs;
		return array_map(
			fn( $slug ) => new Newspack_Mock_Membership_With_Plan( $slug ),
			$newspack_test_user_plan_slugs[ (int) $user_id ] ?? []
		);
	}
}
