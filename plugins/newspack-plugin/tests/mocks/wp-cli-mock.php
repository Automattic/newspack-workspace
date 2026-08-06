<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Squiz.Commenting.FileComment.Missing

/**
 * Compatibility shim: the WP_CLI stub this file used to define moved into the
 * shared `wp-cli-mocks.php` recording mock (a superset of this file's surface —
 * $logs / $successes / $warnings / $tables / $halt_code / halt() are all preserved).
 * Only one WP_CLI class can exist per PHPUnit process, so both require paths
 * must resolve to the same definition; requires of this file keep working.
 */
require_once __DIR__ . '/wp-cli-mocks.php';
