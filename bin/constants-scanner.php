#!/usr/bin/env php
<?php
/**
 * Newspack constants scanner CLI.
 *
 * Usage:
 *   php bin/constants-scanner.php --source=NAME=PATH [--source=NAME=PATH ...] [options]
 *
 * Options:
 *   --source=NAME=PATH    A checkout to scan. Repeatable. Required.
 *   --branch=NAME=BRANCH  Branch recorded for source NAME. Repeatable. Optional.
 *   --sha=NAME=SHA        Commit recorded for source NAME. Repeatable. Optional.
 *   --format=md|json      Output format. Default md.
 *   --output=FILE         Write to FILE instead of stdout.
 *   --undocumented        Markdown list of constants lacking a @constant docblock.
 *   --help, -h            This message.
 *
 * @package Newspack_Workspace
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents

if ( PHP_SAPI !== 'cli' ) {
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	error_log( 'This script must be run from the command line.' );
	exit( 1 );
}

require_once __DIR__ . '/class-newspack-constants-scanner.php';

/**
 * Parse argv.
 *
 * @param array $argv Arguments.
 * @return array
 */
function newspack_constants_scanner_parse_args( array $argv ): array {
	$options = [
		'sources'      => [],
		'format'       => 'md',
		'output'       => null,
		'undocumented' => false,
		'help'         => false,
	];

	foreach ( array_slice( $argv, 1 ) as $arg ) {
		if ( preg_match( '/^--(source|branch|sha)=([^=]+)=(.+)$/', $arg, $m ) ) {
			$key = 'source' === $m[1] ? 'path' : $m[1];
			$options['sources'][ $m[2] ][ $key ] = $m[3];
		} elseif ( 0 === strpos( $arg, '--format=' ) ) {
			$options['format'] = substr( $arg, 9 );
		} elseif ( 0 === strpos( $arg, '--output=' ) ) {
			$options['output'] = substr( $arg, 9 );
		} elseif ( '--undocumented' === $arg ) {
			$options['undocumented'] = true;
		} elseif ( '--help' === $arg || '-h' === $arg ) {
			$options['help'] = true;
		} else {
			fwrite( STDERR, "Unknown argument: {$arg}\n" );
			exit( 1 );
		}
	}

	return $options;
}

$options = newspack_constants_scanner_parse_args( $argv );

if ( $options['help'] ) {
	echo <<<'HELP'
Newspack constants scanner

Usage:
  php bin/constants-scanner.php --source=NAME=PATH [--source=NAME=PATH ...] [options]

Options:
  --source=NAME=PATH    A checkout to scan. Repeatable. Required.
  --branch=NAME=BRANCH  Branch recorded for source NAME. Optional.
  --sha=NAME=SHA        Commit recorded for source NAME. Optional.
  --format=md|json      Output format. Default md.
  --output=FILE         Write to FILE instead of stdout.
  --undocumented        Markdown list of constants lacking a @constant docblock.
  --help, -h            This message.

HELP;
	exit( 0 );
}

foreach ( $options['sources'] as $name => $source ) {
	if ( empty( $source['path'] ) ) {
		fwrite( STDERR, "Source {$name} has --branch or --sha but no --source=NAME=PATH.\n" );
		exit( 1 );
	}
	if ( ! is_dir( $source['path'] ) ) {
		fwrite( STDERR, "Source {$name}: {$source['path']} is not a directory.\n" );
		exit( 1 );
	}
}
if ( empty( $options['sources'] ) ) {
	fwrite( STDERR, "At least one --source=NAME=PATH is required.\n" );
	exit( 1 );
}

$scanner   = new Newspack_Constants_Scanner( $options['sources'] );
$constants = $scanner->scan();

if ( 'json' === $options['format'] ) {
	$output = $scanner->to_json();
} else {
	$output = $scanner->to_markdown( $options['undocumented'] );
}

if ( $options['output'] ) {
	if ( false === file_put_contents( $options['output'], $output ) ) {
		fwrite( STDERR, "Failed to write {$options['output']}\n" );
		exit( 1 );
	}
	fwrite( STDERR, sprintf( "Wrote %s: %d documented, %d undocumented\n", $options['output'], count( $constants ), count( $scanner->get_undocumented() ) ) );
} else {
	echo $output;
}
