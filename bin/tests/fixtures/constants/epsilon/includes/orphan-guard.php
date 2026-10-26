<?php
/**
 * Case 2: the guard for a constant documented in another file.
 *
 * @package Newspack_Workspace
 */

if ( defined( 'NEWSPACK_FIXTURE_ORPHAN' ) ) {
	echo 'on';
}
