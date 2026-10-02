<?php // phpcs:ignoreFile
/**
 * Fixture: docblock quotes another constant's guard pattern in prose.
 *
 * This constant is unrelated, but its description illustrates a sibling
 * guard for documentation purposes: if ( defined( 'NEWSPACK_FIXTURE_ALLOW_SYNC' ) ) enables sync.
 *
 * @constant NEWSPACK_FIXTURE_QUOTES_GUARD
 * @type     bool
 */
if ( defined( 'NEWSPACK_FIXTURE_QUOTES_GUARD' ) ) {
	echo 'x';
}
