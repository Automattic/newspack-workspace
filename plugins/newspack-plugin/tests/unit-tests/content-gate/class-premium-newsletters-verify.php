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
	 * The argument vector PHPUnit was invoked with, restored after each test.
	 *
	 * @var array|null
	 */
	private $original_argv;

	/**
	 * Save the argument vector so tear_down() can restore it.
	 */
	public function set_up() {
		parent::set_up();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw argv, kept verbatim so tear_down() can restore it.
		$this->original_argv = $_SERVER['argv'] ?? null;
	}

	/**
	 * Put back the argument vector the bare-flag tests overwrite, so this file
	 * cannot leak $_SERVER['argv'] state into another test class.
	 */
	public function tear_down() {
		if ( null === $this->original_argv ) {
			unset( $_SERVER['argv'] );
		} else {
			$_SERVER['argv'] = $this->original_argv;
		}
		parent::tear_down();
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

	/**
	 * Build a gate array shaped like Content_Gate::get_gate() returns.
	 *
	 * @param int   $id            Gate ID.
	 * @param bool  $paid_active   Whether the paid access mode is active.
	 * @param array $access_rules  Grouped access rules.
	 *
	 * @return array
	 */
	private function make_gate( int $id, bool $paid_active, array $access_rules = [] ): array {
		return [
			'id'            => $id,
			'title'         => 'Gate ' . $id,
			'registration'  => [ 'active' => true ],
			'custom_access' => [
				'active'       => $paid_active,
				'access_rules' => $access_rules,
			],
		];
	}

	/**
	 * Product IDs are read out of the grouped access-rule structure, across groups,
	 * deduplicated, and cast to int — the value is stored as strings on some paths.
	 */
	public function test_product_ids_from_access_rules_reads_across_groups() {
		$rules = [
			[
				[
					'slug'  => 'subscription',
					'value' => [ '46', 47 ],
				],
			],
			[
				[
					'slug'  => 'subscription',
					'value' => [ 47, 48 ],
				],
			],
		];

		$this->assertSame( [ 46, 47, 48 ], $this->invoke_private_static( 'product_ids_from_access_rules', [ $rules ] ) );
	}

	/**
	 * Only subscription rules name products. Another rule type in the same group
	 * must not contribute its values, which are not product IDs.
	 */
	public function test_product_ids_from_access_rules_ignores_other_rule_types() {
		$rules = [
			[
				[
					'slug'  => 'institution',
					'value' => [ 900 ],
				],
				[
					'slug'  => 'subscription',
					'value' => [ 46 ],
				],
			],
		];

		$this->assertSame( [ 46 ], $this->invoke_private_static( 'product_ids_from_access_rules', [ $rules ] ) );
	}

	/**
	 * A gate with an active paid mode and products is what this command can check.
	 */
	public function test_partition_gates_selects_paid_gates_as_verifiable() {
		$gate = $this->make_gate(
			10,
			true,
			[
				[
					[
						'slug'  => 'subscription',
						'value' => [ 46 ],
					],
				],
			] 
		);

		$partitioned = $this->invoke_private_static( 'partition_gates', [ [ $gate ] ] );

		$this->assertCount( 1, $partitioned['verifiable'] );
		$this->assertSame( 10, $partitioned['verifiable'][0]['id'] );
		$this->assertSame( [ 46 ], $partitioned['verifiable'][0]['product_ids'] );
		$this->assertSame( [], $partitioned['registration_only'] );
	}

	/**
	 * A registration-only gate has no exclusion to test: every registered reader is
	 * entitled. It is reported rather than dropped, so the operator can see it was
	 * considered.
	 */
	public function test_partition_gates_reports_registration_only_gates_separately() {
		$gate = $this->make_gate( 11, false );

		$partitioned = $this->invoke_private_static( 'partition_gates', [ [ $gate ] ] );

		$this->assertSame( [], $partitioned['verifiable'] );
		$this->assertCount( 1, $partitioned['registration_only'] );
		$this->assertSame( 11, $partitioned['registration_only'][0]['id'] );
	}

	/**
	 * A paid mode with no products constrains nothing, so there is no population to
	 * enumerate and nothing this command can check. It belongs with the
	 * registration-only gates, not with the verifiable ones.
	 */
	public function test_partition_gates_treats_a_paid_gate_with_no_products_as_unverifiable() {
		$gate = $this->make_gate( 12, true, [] );

		$partitioned = $this->invoke_private_static( 'partition_gates', [ [ $gate ] ] );

		$this->assertSame( [], $partitioned['verifiable'] );
		$this->assertCount( 1, $partitioned['registration_only'] );
	}

	/**
	 * Expected state is meaningless while WooCommerce Memberships is active: the
	 * evaluator hands the decision back to Memberships and every list reads as
	 * unrestricted, so a run would report every entitled reader as a gap and every
	 * leak as clean. The command refuses rather than producing that.
	 */
	public function test_preflight_blocks_while_memberships_is_active() {
		$blocked = $this->invoke_private_static( 'describe_blocking_preflight', [ true, true, true, true ] );

		$this->assertIsString( $blocked );
		$this->assertStringContainsString( 'WooCommerce Memberships', $blocked );
	}

	/**
	 * With gating inactive nothing enforces, so there is no expected state to
	 * compare against.
	 */
	public function test_preflight_blocks_when_gating_is_inactive() {
		$blocked = $this->invoke_private_static( 'describe_blocking_preflight', [ false, false, true, true ] );

		$this->assertIsString( $blocked );
		$this->assertStringContainsString( 'gating', $blocked );
	}

	/**
	 * Without WooCommerce Subscriptions the command cannot enumerate who holds a
	 * gate's products, so population_for_gate() would return an empty population for
	 * every gate and the run would report a false-clean zero-leak result. The
	 * command refuses instead of silently checking nobody.
	 */
	public function test_preflight_blocks_when_woocommerce_subscriptions_is_unavailable() {
		$blocked = $this->invoke_private_static( 'describe_blocking_preflight', [ false, true, false, true ] );

		$this->assertIsString( $blocked );
		$this->assertStringContainsString( 'WooCommerce Subscriptions', $blocked );
	}

	/**
	 * On a provider that does not support subscription management (Campaign Monitor,
	 * Letterhead), Newspack_Newsletters_Subscription::get_contact_lists() returns a
	 * WP_Error for every reader, so an unguarded run would report the whole
	 * population as unresolved rather than comparing anything. The command refuses
	 * instead of dumping the entire population into the attention table.
	 */
	public function test_preflight_blocks_when_subscription_management_is_unavailable() {
		$blocked = $this->invoke_private_static( 'describe_blocking_preflight', [ false, true, true, false ] );

		$this->assertIsString( $blocked );
		$this->assertStringContainsString( 'subscription management', $blocked );
	}

	/**
	 * After cutover, with gating live, WooCommerce Subscriptions active and the ESP
	 * supporting subscription management, the run may proceed.
	 */
	public function test_preflight_allows_a_post_cutover_site() {
		$this->assertNull( $this->invoke_private_static( 'describe_blocking_preflight', [ false, true, true, true ] ) );
	}

	/**
	 * Subscription_List's constructor throws only when the post does not exist, not
	 * when it is the wrong type, so a stale or mistyped list ID pointing at an
	 * ordinary post must be rejected before it ever reaches the constructor.
	 * Without the post-type check this returns the mock's public ID for any post.
	 */
	public function test_public_id_for_list_rejects_a_post_of_the_wrong_type() {
		$post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );

		$this->assertNull( $this->invoke_private_static( 'public_id_for_list', [ $post_id ] ) );
	}

	/**
	 * A post of the newsletter list type resolves normally.
	 */
	public function test_public_id_for_list_resolves_a_list_post() {
		$post_id = self::factory()->post->create( [ 'post_type' => \Newspack\Newsletters\Subscription_Lists::CPT ] );

		$this->assertSame( 'list-' . $post_id, $this->invoke_private_static( 'public_id_for_list', [ $post_id ] ) );
	}

	/**
	 * Mailchimp's, Active Campaign's and Constant Contact's dedicated not-found
	 * codes all end in this suffix, so a genuine miss on any of the three reads as
	 * "no lists" rather than a failed lookup.
	 */
	public function test_is_contact_not_found_error_matches_the_shared_not_found_suffix() {
		$this->assertTrue(
			$this->invoke_private_static(
				'is_contact_not_found_error',
				[ new \WP_Error( 'newspack_newsletters_mailchimp_contact_not_found', 'Contact not found' ) ]
			)
		);
		$this->assertTrue(
			$this->invoke_private_static(
				'is_contact_not_found_error',
				[ new \WP_Error( 'newspack_newsletters_contact_not_found', 'Contact not found' ) ]
			)
		);
		$this->assertTrue(
			$this->invoke_private_static(
				'is_contact_not_found_error',
				[ new \WP_Error( 'newspack_newsletters_constant_contact_contact_not_found', 'Contact not found' ) ]
			)
		);
	}

	/**
	 * Any other error code — a genuine API failure such as Mailchimp's
	 * search-members error, Constant Contact's SDK-level get_contact() failure
	 * code, or an unrelated generic code — must not be read as "no lists", or a
	 * provider outage would misreport as a clean run.
	 */
	public function test_is_contact_not_found_error_rejects_other_codes() {
		$this->assertFalse(
			$this->invoke_private_static(
				'is_contact_not_found_error',
				[ new \WP_Error( 'newspack_newsletters_mailchimp_search_members', 'Error reaching to search-members endpoint' ) ]
			)
		);
		$this->assertFalse(
			$this->invoke_private_static(
				'is_contact_not_found_error',
				[ new \WP_Error( 'newspack_newsletter_error_get_contact', 'Some SDK failure' ) ]
			)
		);
		$this->assertFalse(
			$this->invoke_private_static(
				'is_contact_not_found_error',
				[ new \WP_Error( 'newspack_newsletters_error', 'Some unrelated generic error' ) ]
			)
		);
	}

	/**
	 * Only 'newsletters' rules name lists to check. A rule of another type in the
	 * same gate must not contribute its value, which is not a list ID.
	 */
	public function test_restricted_list_ids_for_gate_ignores_non_newsletters_rules() {
		$gate = [
			'content_rules' => [
				[
					'slug'  => 'category',
					'value' => [ 5 ],
				],
				[
					'slug'  => 'newsletters',
					'value' => [ 10 ],
				],
			],
		];

		$this->assertSame( [ 10 ], $this->invoke_private_static( 'restricted_list_ids_for_gate', [ $gate ] ) );
	}

	/**
	 * A rule's value can arrive as a single scalar rather than an array on some
	 * write paths, and must still resolve to a one-element list of IDs.
	 */
	public function test_restricted_list_ids_for_gate_casts_a_scalar_value() {
		$gate = [
			'content_rules' => [
				[
					'slug'  => 'newsletters',
					'value' => '7',
				],
			],
		];

		$this->assertSame( [ 7 ], $this->invoke_private_static( 'restricted_list_ids_for_gate', [ $gate ] ) );
	}

	/**
	 * A duplicate ID (as an int or as the equal string) and a zero/empty value must
	 * not produce a duplicate or a phantom list to check.
	 */
	public function test_restricted_list_ids_for_gate_dedupes_and_drops_zero() {
		$gate = [
			'content_rules' => [
				[
					'slug'  => 'newsletters',
					'value' => [ 5, '5', 0, '' ],
				],
			],
		];

		$this->assertSame( [ 5 ], $this->invoke_private_static( 'restricted_list_ids_for_gate', [ $gate ] ) );
	}

	/**
	 * WP-CLI strips a bare `--gate` before the command runs, so the command sees no
	 * gate at all and the run would widen to every gate on the site — and under
	 * --live, remove readers from ESP lists across the whole site rather than the one
	 * gate the operator named. The raw argv is the only place the mistake is still
	 * visible.
	 */
	public function test_get_valueless_value_flags_reports_a_bare_gate() {
		$this->assertSame(
			[ '--gate' ],
			$this->invoke_private_static(
				'get_valueless_value_flags',
				[ [ 'wp', 'newspack', 'verify-premium-newsletters', '--gate', '--live' ] ]
			)
		);
	}

	/**
	 * All three value-requiring flags are checked, not just --gate.
	 */
	public function test_get_valueless_value_flags_reports_all_three_bare_flags() {
		$this->assertSame(
			[ '--gate', '--batch-size', '--max-batches' ],
			$this->invoke_private_static(
				'get_valueless_value_flags',
				[ [ 'wp', 'newspack', 'verify-premium-newsletters', '--gate', '--batch-size', '--max-batches' ] ]
			)
		);
	}

	/**
	 * A flag that carries its value is the ordinary invocation and must pass.
	 */
	public function test_get_valueless_value_flags_ignores_flags_with_values() {
		$this->assertSame(
			[],
			$this->invoke_private_static(
				'get_valueless_value_flags',
				[ [ 'wp', 'newspack', 'verify-premium-newsletters', '--gate=90', '--batch-size=50', '--max-batches=3', '--live' ] ]
			)
		);
	}

	/**
	 * The guard has to be wired into the command, not merely available: a bare
	 * --gate with --live would otherwise remove readers from ESP lists across every
	 * gate on the site rather than the one the operator named.
	 */
	public function test_verify_premium_newsletters_aborts_on_a_bare_gate_flag() {
		$_SERVER['argv'] = [ 'wp', 'newspack', 'verify-premium-newsletters', '--gate', '--live' ];
		$verify           = new Premium_Newsletters_Verify();

		$this->expectException( \WP_CLI_Mock_Exception::class );
		$this->expectExceptionMessage( 'require a value but arrived without one' );

		$verify->verify_premium_newsletters( [], [ 'live' => true ] );
	}

	/**
	 * (int) '-1' is truthy, so an unvalidated negative --max-batches would satisfy
	 * verify_gate()'s `$max_batches && $batches >= $max_batches` guard on the very
	 * first gate and skip the whole run without checking a single reader.
	 */
	public function test_verify_premium_newsletters_aborts_on_a_negative_max_batches() {
		$_SERVER['argv'] = [ 'wp', 'newspack', 'verify-premium-newsletters' ];
		$verify           = new Premium_Newsletters_Verify();

		$this->expectException( \WP_CLI_Mock_Exception::class );
		$this->expectExceptionMessage( 'Invalid --max-batches value' );

		$verify->verify_premium_newsletters( [], [ 'max-batches' => '-1' ] );
	}

	/**
	 * An explicit 0 is rejected too, rather than silently reinterpreted as the same
	 * "unlimited" meaning as the flag being entirely absent.
	 */
	public function test_verify_premium_newsletters_aborts_on_a_zero_max_batches() {
		$_SERVER['argv'] = [ 'wp', 'newspack', 'verify-premium-newsletters' ];
		$verify           = new Premium_Newsletters_Verify();

		$this->expectException( \WP_CLI_Mock_Exception::class );
		$this->expectExceptionMessage( 'Invalid --max-batches value' );

		$verify->verify_premium_newsletters( [], [ 'max-batches' => '0' ] );
	}

	/**
	 * A positive --max-batches must pass this guard. What happens next is not this
	 * test's concern, and is not even stable to assert on: several sibling test
	 * files require the shared newsletters mock at file scope (not inside their own
	 * set_up_before_class()), so by the time any test in this suite runs,
	 * Newspack_Newsletters_Subscription already exists as whatever that shared mock
	 * currently supports — this file deliberately does not touch it (see the class
	 * docblock). So this only proves the --max-batches guard itself did not reject a
	 * value it should accept: any WP_CLI_Mock_Exception it might still hit is
	 * asserted not to be about --max-batches, and any other failure only proves
	 * execution got past the guard in the first place. Without this test, the guard
	 * above could pass by rejecting every value, positive included.
	 */
	public function test_verify_premium_newsletters_accepts_a_positive_max_batches() {
		$_SERVER['argv'] = [ 'wp', 'newspack', 'verify-premium-newsletters' ];
		$verify           = new Premium_Newsletters_Verify();

		try {
			$verify->verify_premium_newsletters( [], [ 'max-batches' => '3' ] );
		} catch ( \WP_CLI_Mock_Exception $exception ) {
			$this->assertStringNotContainsString( 'Invalid --max-batches', $exception->getMessage() );
			return;
		} catch ( \Throwable $throwable ) {
			// Whatever broke, it broke past the --max-batches guard, which is what
			// this test exists to prove.
			$this->assertTrue( true );
			return;
		}
		// No exception at all also means the guard did not reject the value.
		$this->assertTrue( true );
	}

	/**
	 * The subscription's user ID no longer resolves to a WP_User, so there is no
	 * email to report — only the ID the subscription still carries. The row must
	 * still be readable and must still be 'unresolved', the same status a failed ESP
	 * lookup gets, so it counts toward the run's failure condition instead of being
	 * silently dropped.
	 */
	public function test_make_missing_user_row_is_unresolved_and_readable() {
		$gate = $this->make_gate( 30, true );

		$row = $this->invoke_private_static( 'make_missing_user_row', [ $gate, 10, 456 ] );

		$this->assertSame( 'unresolved', $row['status'] );
		$this->assertSame( 456, $row['user_id'] );
		$this->assertSame( 10, $row['list_id'] );
		$this->assertSame( 30, $row['gate_id'] );
		$this->assertStringContainsString( '456', $row['email'] );
	}

	/**
	 * Mailchimp's get_contact_lists() builds its audience IDs with array_keys() over
	 * the contact's raw list data, and PHP silently casts an all-digit array key to
	 * int. public_id_for_list() always returns a string, so an unnormalized strict
	 * comparison against an int-keyed audience ID would never match — reading a real
	 * leak as clean. This is the regression this extraction guards.
	 */
	public function test_is_subscribed_to_list_matches_an_int_list_id_against_a_string_public_id() {
		$this->assertTrue(
			$this->invoke_private_static( 'is_subscribed_to_list', [ '123', [ 123 ] ] )
		);
	}

	/**
	 * A contact-lists array that already carries strings (Active Campaign, Constant
	 * Contact) must still match: the normalization is a no-op for the common case.
	 */
	public function test_is_subscribed_to_list_matches_a_string_list_id() {
		$this->assertTrue(
			$this->invoke_private_static( 'is_subscribed_to_list', [ '123', [ '123' ] ] )
		);
	}

	/**
	 * A public ID absent from the contact's lists, in both int and string form, is
	 * not a match. Proves normalization does not create a false positive.
	 */
	public function test_is_subscribed_to_list_rejects_an_absent_public_id() {
		$this->assertFalse(
			$this->invoke_private_static( 'is_subscribed_to_list', [ '999', [ 123, '123' ] ] )
		);
	}

	/**
	 * Below the batch size, the loop simply keeps going: no count, no pause.
	 */
	public function test_next_batch_action_continues_below_batch_size() {
		$this->assertSame(
			'continue',
			$this->invoke_private_static( 'next_batch_action', [ 3, 10, 0, 0, true ] )
		);
	}

	/**
	 * At the batch boundary, with more readers left to check and no --max-batches
	 * limit, the run pauses to space out ESP traffic.
	 */
	public function test_next_batch_action_pauses_at_the_boundary_with_more_work() {
		$this->assertSame(
			'pause',
			$this->invoke_private_static( 'next_batch_action', [ 10, 10, 0, 0, true ] )
		);
	}

	/**
	 * At the batch boundary, with more readers left, and this boundary's batch would
	 * meet or exceed --max-batches, the run stops rather than pausing.
	 */
	public function test_next_batch_action_stops_when_max_batches_is_reached() {
		$this->assertSame(
			'stop',
			$this->invoke_private_static( 'next_batch_action', [ 10, 10, 2, 3, true ] )
		);
	}

	/**
	 * --max-batches of 0 means unlimited: even with many batches already completed,
	 * the run never stops on that account.
	 */
	public function test_next_batch_action_never_stops_when_max_batches_is_zero() {
		$this->assertSame(
			'pause',
			$this->invoke_private_static( 'next_batch_action', [ 10, 10, 1000, 0, true ] )
		);
	}

	/**
	 * At the batch boundary but with no more work left in this gate's population,
	 * there is no next batch to space out, so the run does not pause — even when a
	 * --max-batches limit would otherwise have been reached.
	 */
	public function test_next_batch_action_does_not_pause_when_no_work_remains() {
		$this->assertSame(
			'continue',
			$this->invoke_private_static( 'next_batch_action', [ 10, 10, 0, 0, false ] )
		);
		$this->assertSame(
			'continue',
			$this->invoke_private_static( 'next_batch_action', [ 10, 10, 2, 3, false ] )
		);
	}
}
