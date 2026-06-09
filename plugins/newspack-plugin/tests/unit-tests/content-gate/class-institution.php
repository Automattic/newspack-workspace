<?php
/**
 * Tests for the Institution class.
 *
 * @package Newspack\Tests\Content_Gate
 */

use Newspack\Institution;

/**
 * Test Institution functionality.
 *
 * @group Access_Rules
 */
class Newspack_Test_Institution extends WP_UnitTestCase {

	/**
	 * Post IDs for cleanup.
	 *
	 * @var int[]
	 */
	private $post_ids = [];

	/**
	 * User IDs for cleanup.
	 *
	 * @var int[]
	 */
	private $user_ids = [];

	/**
	 * Original REMOTE_ADDR value to restore after tests that modify it.
	 *
	 * @var string|null
	 */
	private $original_remote_addr;

	/**
	 * Setup.
	 */
	public function set_up() {
		parent::set_up();
		$this->original_remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__
	}

	/**
	 * Teardown.
	 */
	public function tear_down() {
		foreach ( $this->post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->post_ids = [];
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->user_ids = [];
		delete_transient( Institution::TRANSIENT_KEY );
		// Restore REMOTE_ADDR to avoid polluting other test classes.
		if ( null === $this->original_remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] ); // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->original_remote_addr; // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__
		}
		parent::tear_down();
	}

	/**
	 * Create a reader user with verified email.
	 *
	 * @param string $email    Email address.
	 * @param bool   $verified Whether the email is verified. Default true.
	 * @return int User ID.
	 */
	private function create_reader( $email, $verified = true ) {
		$user_id = wp_insert_user(
			[
				'user_login' => 'reader-' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => $email,
				'role'       => 'subscriber',
			]
		);
		if ( ! is_wp_error( $user_id ) ) {
			if ( $verified ) {
				update_user_meta( $user_id, \Newspack\Reader_Activation::EMAIL_VERIFIED, true );
			}
			$this->user_ids[] = $user_id;
		}
		return $user_id;
	}

	/**
	 * Test creating an institution via the public API.
	 */
	public function test_create_institution() {
		$post_id = Institution::create(
			'API University',
			'API University description',
			[
				'email_domain' => 'api.edu',
				'ip_range'     => '10.0.0.0/8',
			]
		);
		$this->assertIsInt( $post_id );
		$this->post_ids[] = $post_id;

		$post = get_post( $post_id );
		$this->assertEquals( 'API University', $post->post_title );
		$this->assertEquals( 'API University description', $post->post_excerpt );
		$this->assertEquals( 'publish', $post->post_status );
		$this->assertEquals( 'api.edu', get_post_meta( $post_id, 'np_institution_email_domain', true ) );
		$this->assertEquals( '10.0.0.0/8', get_post_meta( $post_id, 'np_institution_ip_range', true ) );
		$this->assertEmpty( get_post_meta( $post_id, 'np_institution_reader_data', true ) );
	}

	/**
	 * Test CPT is registered.
	 */
	public function test_cpt_registered() {
		$this->assertTrue( post_type_exists( Institution::POST_TYPE ) );
	}

	/**
	 * Test get_options returns published institutions.
	 */
	public function test_get_options_returns_published_institutions() {
		$id = Institution::create( 'Test University' );
		$this->assertIsInt( $id );
		$this->post_ids[] = $id;

		$options = Institution::get_options();
		$this->assertCount( 1, $options );
		$this->assertEquals( 'Test University', $options[0]['label'] );
		$this->assertEquals( $id, $options[0]['value'] );
	}

	/**
	 * Test cache is built and can be invalidated.
	 */
	public function test_cache_built_and_invalidated() {
		$id = Institution::create(
			'Cached University',
			'',
			[ 'email_domain' => 'cached.edu' ]
		);
		$this->assertIsInt( $id );
		$this->post_ids[] = $id;
		delete_transient( Institution::TRANSIENT_KEY );

		$cached = Institution::get_cached_institutions();
		$this->assertArrayHasKey( $id, $cached );
		$this->assertEquals( 'cached.edu', $cached[ $id ]['email_domain'] );

		$this->assertNotFalse( get_transient( Institution::TRANSIENT_KEY ) );

		Institution::invalidate_cache();
		$this->assertFalse( get_transient( Institution::TRANSIENT_KEY ) );
	}

	/**
	 * Test institution with no rules matches nobody.
	 */
	public function test_institution_with_no_rules_matches_nobody() {
		$inst_id = Institution::create( 'Empty Institution' );
		$this->assertIsInt( $inst_id );
		$this->post_ids[] = $inst_id;
		$reader_id = $this->create_reader( 'reader@test.com' );

		delete_transient( Institution::TRANSIENT_KEY );
		$this->assertFalse( Institution::evaluate( $reader_id, [ $inst_id ] ) );
	}

	/**
	 * Test email domain matching.
	 */
	public function test_email_domain_match() {
		$inst_id = Institution::create(
			'University of Test',
			'',
			[ 'email_domain' => 'university.edu' ]
		);
		$this->assertIsInt( $inst_id );
		$this->post_ids[] = $inst_id;
		$match_reader    = $this->create_reader( 'student@university.edu' );
		$no_match_reader = $this->create_reader( 'student@other.com' );

		delete_transient( Institution::TRANSIENT_KEY );
		$this->assertTrue( Institution::evaluate( $match_reader, [ $inst_id ] ) );
		$this->assertFalse( Institution::evaluate( $no_match_reader, [ $inst_id ] ) );
	}

	/**
	 * Test email domain requires verified email.
	 */
	public function test_email_domain_requires_verification() {
		$inst_id = Institution::create(
			'Verified University',
			'',
			[ 'email_domain' => 'verified.edu' ]
		);
		$this->assertIsInt( $inst_id );
		$this->post_ids[] = $inst_id;
		$unverified_reader = $this->create_reader( 'student@verified.edu', false );

		delete_transient( Institution::TRANSIENT_KEY );
		$this->assertFalse(
			Institution::evaluate( $unverified_reader, [ $inst_id ] ),
			'Unverified email should not grant institutional access'
		);
	}

	/**
	 * Test IP range match via real IP.
	 */
	public function test_ip_range_match() {
		$inst_id = Institution::create(
			'IP Institution',
			'',
			[ 'ip_range' => '10.0.0.0/8' ]
		);
		$this->assertIsInt( $inst_id );
		$this->post_ids[] = $inst_id;
		$reader_id = $this->create_reader( 'reader@test.com' );
		// Log the reader in: user_matches_institution only treats requests as
		// uncached when $user_id matches the current user (or a cache-bypass
		// cookie is present). Without this, the IP check is skipped.
		wp_set_current_user( $reader_id );

		delete_transient( Institution::TRANSIENT_KEY );

		// phpcs:disable WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__

		// Logged-in users are always uncached — IP is checked directly, no cookie needed.

		// Matching IP.
		$_SERVER['REMOTE_ADDR'] = '10.1.2.3';
		$this->assertTrue( Institution::evaluate( $reader_id, [ $inst_id ] ) );

		// Non-matching IP.
		$_SERVER['REMOTE_ADDR'] = '192.168.1.1';
		$this->assertFalse( Institution::evaluate( $reader_id, [ $inst_id ] ) );

		// No IP set.
		unset( $_SERVER['REMOTE_ADDR'] );
		$this->assertFalse( Institution::evaluate( $reader_id, [ $inst_id ] ) );

		wp_set_current_user( 0 );

		// phpcs:enable
	}

	/**
	 * Test that the IP check does not leak the current visitor's IP to a
	 * different user (e.g., when ESP sync evaluates an institution rule for
	 * user A while admin/cron/webhook user B is the active request).
	 *
	 * Regression test: prior to the tightening of $is_uncached, any non-zero
	 * $user_id was treated as "uncached," so the visitor's IP was checked
	 * against the rule regardless of which user was being evaluated.
	 */
	public function test_ip_range_does_not_leak_across_users() {
		$inst_id = Institution::create(
			'Cross-User IP Institution',
			'',
			[ 'ip_range' => '10.0.0.0/8' ]
		);
		$this->assertIsInt( $inst_id );
		$this->post_ids[] = $inst_id;
		$evaluated_reader = $this->create_reader( 'evaluated@test.com' );
		$current_visitor  = $this->create_reader( 'visitor@test.com' );

		delete_transient( Institution::TRANSIENT_KEY );

		// phpcs:disable WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE

		// The current visitor (user B) is logged in, with a matching IP. We
		// then ask whether user A (a different user) matches the institution —
		// the IP check must NOT run for user A.
		wp_set_current_user( $current_visitor );
		$_SERVER['REMOTE_ADDR'] = '10.1.2.3';
		unset( $_COOKIE[ \Newspack\Content_Gate\IP_Access_Rule::COOKIE_NAME ] );

		$this->assertFalse(
			Institution::evaluate( $evaluated_reader, [ $inst_id ] ),
			'IP-based institution match should not transfer the current visitor\'s IP to a different user.'
		);

		// Sanity check: the current visitor themselves *does* match (same IP, same user).
		$this->assertTrue(
			Institution::evaluate( $current_visitor, [ $inst_id ] ),
			'The current visitor with a matching IP should still match.'
		);

		wp_set_current_user( 0 );
		unset( $_SERVER['REMOTE_ADDR'] );

		// phpcs:enable
	}

	/**
	 * Test anonymous user can match via IP range on uncached request.
	 */
	public function test_anonymous_ip_range_match() {
		$inst_id = Institution::create(
			'Anon IP Institution',
			'',
			[ 'ip_range' => '10.0.0.0/8' ]
		);
		$this->assertIsInt( $inst_id );
		$this->post_ids[] = $inst_id;

		delete_transient( Institution::TRANSIENT_KEY );

		// phpcs:disable WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE

		// Set cache-bypass cookie (presence-only signal; the actual access check is ip_matches_ranges()).
		$_COOKIE[ \Newspack\Content_Gate\IP_Access_Rule::COOKIE_NAME ] = '1'; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE

		$_SERVER['REMOTE_ADDR'] = '10.1.2.3';
		$this->assertTrue(
			\Newspack\Access_Rules::evaluate_rule( 'institution', [ $inst_id ], 0 ),
			'Anonymous user with matching IP on uncached request should get institutional access'
		);

		$_SERVER['REMOTE_ADDR'] = '192.168.1.1';
		$this->assertFalse(
			\Newspack\Access_Rules::evaluate_rule( 'institution', [ $inst_id ], 0 ),
			'Anonymous user with non-matching IP should not get access'
		);

		// Without cookie — no evaluation even with matching IP.
		unset( $_COOKIE[ \Newspack\Content_Gate\IP_Access_Rule::COOKIE_NAME ] );
		$_SERVER['REMOTE_ADDR'] = '10.1.2.3';
		$this->assertFalse(
			\Newspack\Access_Rules::evaluate_rule( 'institution', [ $inst_id ], 0 ),
			'Anonymous user without cache-bypass cookie should not trigger IP evaluation'
		);

		unset( $_SERVER['REMOTE_ADDR'] );

		// phpcs:enable
	}

	/**
	 * Test OR logic within an institution (any rule match is enough).
	 */
	public function test_or_logic_within_institution() {
		$inst_id = Institution::create(
			'OR Logic Institution',
			'',
			[
				'email_domain' => 'university.edu',
				'ip_range'     => '10.0.0.0/8',
			]
		);
		$this->assertIsInt( $inst_id );
		$this->post_ids[] = $inst_id;
		$reader_id = $this->create_reader( 'student@university.edu' );

		delete_transient( Institution::TRANSIENT_KEY );
		$this->assertTrue( Institution::evaluate( $reader_id, [ $inst_id ] ) );
	}

	/**
	 * Test multi-institution selection (any institution match is enough).
	 */
	public function test_multi_institution_selection() {
		$inst_a = Institution::create(
			'Institution A',
			'',
			[ 'email_domain' => 'a.edu' ]
		);
		$this->assertIsInt( $inst_a );
		$this->post_ids[] = $inst_a;

		$inst_b = Institution::create(
			'Institution B',
			'',
			[ 'email_domain' => 'b.edu' ]
		);
		$this->assertIsInt( $inst_b );
		$this->post_ids[] = $inst_b;
		$reader_id = $this->create_reader( 'reader@b.edu' );

		delete_transient( Institution::TRANSIENT_KEY );
		$this->assertTrue( Institution::evaluate( $reader_id, [ $inst_a, $inst_b ] ) );
	}

	/**
	 * Test that the institution access rule is registered.
	 */
	public function test_access_rule_registered() {
		$rules = \Newspack\Access_Rules::get_registered_rules();
		$this->assertArrayHasKey( 'institution', $rules );
	}

	/**
	 * Test evaluate_rule integration with the institution rule.
	 */
	public function test_evaluate_rule_integration() {
		$inst_id = Institution::create(
			'Integration Test University',
			'',
			[ 'email_domain' => 'integration.edu' ]
		);
		$this->assertIsInt( $inst_id );
		$this->post_ids[] = $inst_id;
		$reader_id = $this->create_reader( 'reader@integration.edu' );

		delete_transient( Institution::TRANSIENT_KEY );
		$this->assertTrue(
			\Newspack\Access_Rules::evaluate_rule( 'institution', [ $inst_id ], $reader_id )
		);
	}

	/**
	 * Test that evaluate_rule returns false for anonymous users without matching IP.
	 */
	public function test_evaluate_rule_anonymous_no_ip_returns_false() {
		$inst_id = Institution::create(
			'Anon Test',
			'',
			[ 'email_domain' => 'test.edu' ]
		);
		$this->assertIsInt( $inst_id );
		$this->post_ids[] = $inst_id;
		delete_transient( Institution::TRANSIENT_KEY );
		// Anonymous user can't match email domain rules.
		$this->assertFalse(
			\Newspack\Access_Rules::evaluate_rule( 'institution', [ $inst_id ], 0 )
		);
	}

	/**
	 * Test check_ip filter handler.
	 */
	public function test_check_ip_filter() {
		$inst_id = Institution::create(
			'IP Filter Institution',
			'',
			[ 'ip_range' => '192.168.1.0/24' ]
		);
		$this->assertIsInt( $inst_id );
		$this->post_ids[] = $inst_id;
		delete_transient( Institution::TRANSIENT_KEY );

		$_SERVER['REMOTE_ADDR'] = '192.168.1.50'; // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__
		$this->assertSame( $inst_id, Institution::check_ip( false ) );

		$_SERVER['REMOTE_ADDR'] = '10.0.0.1'; // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__
		delete_transient( Institution::TRANSIENT_KEY );
		$this->assertFalse( Institution::check_ip( false ) );

		unset( $_SERVER['REMOTE_ADDR'] ); // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__
	}
}
