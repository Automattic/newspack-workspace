<?php // phpcs:ignoreFile
/**
 * Fixture: a heredoc body quotes a guard pattern.
 *
 * @package Newspack_Manager_Admin\Tests
 */

$message = <<<MSG
Check it with defined( 'NEWSPACK_FIXTURE_INSIDE_HEREDOC' ) before use.
MSG;

echo $message;
