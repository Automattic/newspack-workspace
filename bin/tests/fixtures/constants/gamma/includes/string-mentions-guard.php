<?php // phpcs:ignoreFile
/**
 * Fixture: a string literal, not a docblock, quotes a guard pattern.
 *
 * @package Newspack_Manager_Admin\Tests
 */

$doc = "Check it with defined( 'NEWSPACK_FIXTURE_INSIDE_STRING' ) before use.";
$doc_fq = "Or with \\defined( 'NEWSPACK_FIXTURE_INSIDE_STRING_FQ' ) before use.";
$doc_mc = "Or with Defined( 'NEWSPACK_FIXTURE_INSIDE_STRING_MIXEDCASE' ) before use.";
echo $doc . $doc_fq . $doc_mc;
