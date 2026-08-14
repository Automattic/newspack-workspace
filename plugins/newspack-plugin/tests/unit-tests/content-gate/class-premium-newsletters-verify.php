<?php
/**
 * Tests for the verify-premium-newsletters CLI (NPPD-2079).
 *
 * The command's two edges — the WooCommerce Subscriptions population query and
 * the ESP read — are not available in this harness. Everything between them is,
 * and that is what these tests cover: which gates are verifiable, what each
 * reader's state means, and whether the run should fail.
 *
 * @package Newspack\Tests\Content_Gate
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\CLI\Premium_Newsletters_Verify;

/**
 * Tests for the verify-premium-newsletters helpers.
 */
class Test_Premium_Newsletters_Verify extends \WP_UnitTestCase {

	/**
	 * Load the CLI class and the mocks it needs.
	 *
	 * Required here rather than at file scope: a file-scope require defines the
	 * newsletters classes for every test in the run, which changes the branch
	 * `class_exists()` guards elsewhere in the plugin take.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 2 ) . '/mocks/wp-cli-mocks.php';
		require_once dirname( __DIR__, 2 ) . '/mocks/newsletters-namespaced-mocks.php';
		require_once dirname( __DIR__, 3 ) . '/includes/cli/class-premium-newsletters-verify.php';
	}

	/**
	 * Invoke a private static method on the CLI class via reflection.
	 *
	 * @param string $method_name The method name.
	 * @param array  $arguments   Positional arguments.
	 *
	 * @return mixed The method return value.
	 */
	private function invoke_private_static( string $method_name, array $arguments ) {
		$reflected_method = new \ReflectionMethod( Premium_Newsletters_Verify::class, $method_name );
		$reflected_method->setAccessible( true );
		return $reflected_method->invoke( null, ...$arguments );
	}

	/**
	 * A reader the gate restricts, who is on the list anyway, is the case this
	 * command exists to find: paid content reaching someone with no entitlement.
	 */
	public function test_classify_reader_reports_a_restricted_subscriber_as_a_leak() {
		$this->assertSame( 'leak', $this->invoke_private_static( 'classify_reader', [ true, true, true ] ) );
		$this->assertSame( 'leak', $this->invoke_private_static( 'classify_reader', [ true, true, false ] ) );
	}

	/**
	 * A restricted reader who is not subscribed is the correct outcome, whatever
	 * auto-signup is set to.
	 */
	public function test_classify_reader_accepts_a_restricted_non_subscriber() {
		$this->assertSame( 'ok', $this->invoke_private_static( 'classify_reader', [ true, false, true ] ) );
		$this->assertSame( 'ok', $this->invoke_private_static( 'classify_reader', [ true, false, false ] ) );
	}

	/**
	 * An entitled reader who is subscribed is correct regardless of auto-signup:
	 * with it off they opted in themselves, which is equally valid.
	 */
	public function test_classify_reader_accepts_an_entitled_subscriber() {
		$this->assertSame( 'ok', $this->invoke_private_static( 'classify_reader', [ false, true, true ] ) );
		$this->assertSame( 'ok', $this->invoke_private_static( 'classify_reader', [ false, true, false ] ) );
	}

	/**
	 * With auto-signup on, the site promises to subscribe entitled readers, so one
	 * who is missing is a gap worth reporting.
	 */
	public function test_classify_reader_reports_a_missing_entitled_reader_as_a_gap_when_auto_signup_is_on() {
		$this->assertSame( 'gap', $this->invoke_private_static( 'classify_reader', [ false, false, true ] ) );
	}

	/**
	 * With auto-signup off the reader opts in, so an entitled reader who is not
	 * subscribed has simply not opted in. Calling that a defect would report every
	 * such site as broken.
	 */
	public function test_classify_reader_does_not_assert_a_missing_reader_when_auto_signup_is_off() {
		$this->assertSame( 'not_asserted', $this->invoke_private_static( 'classify_reader', [ false, false, false ] ) );
	}

	/**
	 * The summary counts every bucket, including rows the ESP could not answer for.
	 */
	public function test_summarize_rows_counts_every_bucket() {
		$rows = [
			[ 'status' => 'leak' ],
			[ 'status' => 'leak' ],
			[ 'status' => 'gap' ],
			[ 'status' => 'ok' ],
			[ 'status' => 'not_asserted' ],
			[ 'status' => 'unresolved' ],
		];

		$this->assertSame(
			[
				'leak'         => 2,
				'gap'          => 1,
				'ok'           => 1,
				'not_asserted' => 1,
				'unresolved'   => 1,
			],
			$this->invoke_private_static( 'summarize_rows', [ $rows ] )
		);
	}

	/**
	 * An empty run counts zero of everything rather than returning a short array,
	 * so callers can read any bucket without checking it exists first.
	 */
	public function test_summarize_rows_returns_every_key_for_an_empty_run() {
		$this->assertSame(
			[
				'leak'         => 0,
				'gap'          => 0,
				'ok'           => 0,
				'not_asserted' => 0,
				'unresolved'   => 0,
			],
			$this->invoke_private_static( 'summarize_rows', [ [] ] )
		);
	}

	/**
	 * A leak fails the run: that is the assertion the command exists to make.
	 */
	public function test_verification_fails_on_a_leak() {
		$summary = [
			'leak'         => 1,
			'gap'          => 0,
			'ok'           => 5,
			'not_asserted' => 0,
			'unresolved'   => 0,
		];

		$this->assertTrue( $this->invoke_private_static( 'verification_failed', [ $summary ] ) );
	}

	/**
	 * A contact the ESP could not answer for fails too. An unread contact is not
	 * evidence of safety, and treating it as clean would let a provider outage
	 * report a site as ready to flip.
	 */
	public function test_verification_fails_on_an_unresolved_row() {
		$summary = [
			'leak'         => 0,
			'gap'          => 0,
			'ok'           => 5,
			'not_asserted' => 0,
			'unresolved'   => 1,
		];

		$this->assertTrue( $this->invoke_private_static( 'verification_failed', [ $summary ] ) );
	}

	/**
	 * A gap does not fail the run. It means someone entitled is missing a list,
	 * which is worth reporting but is not paid content leaking, and this command
	 * deliberately never writes an addition to fix it.
	 */
	public function test_verification_passes_with_gaps_but_no_leaks() {
		$summary = [
			'leak'         => 0,
			'gap'          => 3,
			'ok'           => 5,
			'not_asserted' => 2,
			'unresolved'   => 0,
		];

		$this->assertFalse( $this->invoke_private_static( 'verification_failed', [ $summary ] ) );
	}
}
