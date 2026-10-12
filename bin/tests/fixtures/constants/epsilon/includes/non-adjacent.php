<?php
/**
 * Case 3: real code between the docblock and the guard.
 *
 * @package Newspack_Workspace
 */

/**
 * Flag documented above a multi-line condition.
 *
 * @constant NEWSPACK_FIXTURE_NON_ADJACENT
 * @type     bool
 * @default  false
 * @status   draft
 *
 * @example define( 'NEWSPACK_FIXTURE_NON_ADJACENT', true );
 */
if (
	defined( 'NEWSPACK_FIXTURE_NON_ADJACENT' ) &&
	NEWSPACK_FIXTURE_NON_ADJACENT
) {
	echo 'on';
}
