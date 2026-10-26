<?php
/**
 * Case 2: docblocks in a file that guards nothing.
 *
 * @package Newspack_Workspace
 */

/**
 * Orphaned entry: documented here, guarded in orphan-guard.php.
 *
 * @constant NEWSPACK_FIXTURE_ORPHAN
 * @type     bool
 * @default  false
 * @status   draft
 *
 * @example define( 'NEWSPACK_FIXTURE_ORPHAN', true );
 */

/**
 * Documented but guarded nowhere at all, so it must stay out of the catalog.
 *
 * @constant NEWSPACK_FIXTURE_DOC_ONLY
 * @type     bool
 * @default  false
 * @status   draft
 *
 * @example define( 'NEWSPACK_FIXTURE_DOC_ONLY', true );
 */
