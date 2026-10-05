<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Test stub for the \Newspack_Sponsors functions newspack-blocks calls.
 *
 * The newspack-blocks test suite runs without newspack-sponsors loaded, so
 * Newspack_Blocks::get_all_sponsors() would return false before reaching the
 * sponsor payload. This stub lets a test supply sponsors; left unset, it
 * returns false exactly as the absent plugin does, so no other test changes.
 *
 * @package Newspack_Blocks
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Test stub deliberately impersonates the newspack-sponsors API.
namespace Newspack_Sponsors;

/**
 * Holds the sponsors the stubbed functions return.
 */
class Sponsors_Stub {
	/**
	 * Sponsors returned by the stubbed functions. Set by the test; null means
	 * "behave as if newspack-sponsors is absent".
	 *
	 * @var array|null
	 */
	public static $stub_sponsors = null;
}

/**
 * Stub of newspack-sponsors' get_sponsors_for_post().
 *
 * @param int|null    $post_id      Post ID (ignored by the stub).
 * @param string|null $scope        Sponsorship scope (ignored by the stub).
 * @param array       $logo_options Logo size options (ignored by the stub).
 * @return array|false Stubbed sponsors, or false when none are set.
 */
function get_sponsors_for_post( $post_id = null, $scope = null, $logo_options = [] ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Signature parity with the real function; the stub ignores its arguments.
	return null === Sponsors_Stub::$stub_sponsors ? false : Sponsors_Stub::$stub_sponsors;
}

/**
 * Stub of newspack-sponsors' get_all_sponsors().
 *
 * @param int|null    $id           Object ID (ignored by the stub).
 * @param string|null $scope        Sponsorship scope (ignored by the stub).
 * @param string|null $type         Object type (ignored by the stub).
 * @param array       $logo_options Logo size options (ignored by the stub).
 * @return array|false Stubbed sponsors, or false when none are set.
 */
function get_all_sponsors( $id = null, $scope = null, $type = null, $logo_options = [] ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Signature parity with the real function; the stub ignores its arguments.
	return get_sponsors_for_post();
}
