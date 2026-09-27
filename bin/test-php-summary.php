<?php
/**
 * Condenses a PHPUnit JUnit log into a short summary for agents.
 *
 * Usage: php test-php-summary.php <junit.xml> <full-log> <project-dir> <test-db> <exit-code> [phpunit args...]
 *
 * The summary always names what ran (project path, test database, arguments,
 * test count), because a bare pass/fail verdict hides the cases where the wrong
 * code or no code was tested. Failures are listed with a trimmed message; the
 * full output stays in <full-log>.
 */

list( , $junit, $log, $project, $db, $exit ) = array_pad( $argv, 6, '' );
// Shown as a host path: /newspack-monorepo is the main checkout, which `n` names
// in NEWSPACK_HOST_ROOT. A relative path would resolve against a worktree's root.
$host_root = rtrim( (string) getenv( 'NEWSPACK_HOST_ROOT' ), '/' );
$log_shown = preg_replace( '#^/newspack-monorepo/#', '' === $host_root ? '' : $host_root . '/', $log );
$args = implode( ' ', array_slice( $argv, 6 ) );

echo "project: $project\n";
// Set by `n` on the host, which can resolve a worktree's branch; git in the container cannot.
echo 'code:    ' . ( getenv( 'NEWSPACK_TEST_CODE' ) ?: 'unknown' ) . "\n";
echo "test db: $db\n";
echo 'args:    ' . ( '' === $args ? '(none)' : $args ) . "\n";

$xml = is_readable( $junit ) && filesize( $junit ) > 0 ? @simplexml_load_file( $junit ) : false;
if ( false !== $xml && ! isset( $xml->testsuite ) ) {
	// PHPUnit 9 writes an empty <testsuites/> and exits 0 when a filter or path matches nothing.
	echo "result:  NO TESTS RAN (phpunit exit $exit) - check --filter, --group or the path\n";
	echo "full log: $log_shown\n";
	exit;
}
if ( false === $xml ) {
	// PHPUnit died before writing a report: bootstrap error, fatal, bad argument.
	echo "result:  NO REPORT (phpunit exit $exit) - last lines of output:\n";
	$lines = is_readable( $log ) ? file( $log, FILE_IGNORE_NEW_LINES ) : [];
	foreach ( array_slice( $lines, -25 ) as $line ) {
		echo "  $line\n";
	}
	echo "full log: $log_shown\n";
	exit;
}

$suite    = $xml->testsuite[0];
$tests    = (int) $suite['tests'];
$failures = (int) $suite['failures'];
$errors   = (int) $suite['errors'];
$warnings = (int) $suite['warnings'];
$skipped  = (int) $suite['skipped'];

if ( 0 === $tests ) {
	$verdict = 'NO TESTS RAN';
} elseif ( $failures + $errors > 0 || '0' !== $exit ) {
	$verdict = 'FAIL';
} else {
	$verdict = 'PASS';
}

printf(
	"result:  %s - %d tests, %d assertions, %d failures, %d errors, %d warnings, %d skipped, %.1fs (phpunit exit %s)\n",
	$verdict,
	$tests,
	(int) $suite['assertions'],
	$failures,
	$errors,
	$warnings,
	$skipped,
	(float) $suite['time'],
	$exit
);

$shown = 0;
foreach ( $xml->xpath( '//testcase[failure or error or warning]' ) as $case ) {
	if ( ++$shown > 10 ) {
		echo "  ... more in the full log\n";
		break;
	}
	// A missing SimpleXML child is an empty element, not null, so `??` cannot pick one.
	foreach ( [ 'failure', 'error', 'warning' ] as $type ) {
		if ( count( $case->$type ) ) {
			$problem = $case->$type;
			break;
		}
	}
	$test_id = "{$case['class']}::{$case['name']}";
	echo "- {$type}: {$test_id}\n";
	$detail = array_filter( array_map( 'rtrim', explode( "\n", trim( (string) $problem ) ) ) );
	// PHPUnit opens each message with the test id, which the line above already gives.
	if ( reset( $detail ) === $test_id ) {
		array_shift( $detail );
	}
	foreach ( array_slice( $detail, 0, 8 ) as $line ) {
		echo "    $line\n";
	}
}

echo "full log: $log_shown\n";
