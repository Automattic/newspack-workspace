<?php
/**
 * Tests for the unified email config schema and the wizard response builder.
 *
 * Slice 1 (NPPD-945) coverage — schema completeness across Newspack-side
 * providers, per-provider content, response shape, and the
 * apply_config_defaults() merge mechanism. The WC integration test bucket
 * lands with slice 2.
 *
 * @package Newspack\Tests
 */

use Newspack\Emails;
use Newspack\Reader_Activation_Emails;
use Newspack\Reader_Revenue_Emails;
use Newspack\Wizards;
use Newspack\Wizards\Newspack\Emails_Section;

/**
 * Tests the unified Emails config schema and the wizard response builder.
 */
class Newspack_Test_Emails_Section extends WP_UnitTestCase {
	/*
	 * ------------------------------------------------------------------
	 * Bucket A — Schema completeness
	 * ------------------------------------------------------------------
	 * Iterates every config from `newspack_email_configs` after defaults
	 * are applied. Catches provider classes that forget to declare the
	 * new fields, or that declare invalid values.
	 */

	/**
	 * Every config has the four new schema fields after defaults are applied.
	 */
	public function test_email_configs_have_all_required_fields() {
		$configs = Emails::get_email_configs();
		$this->assertNotEmpty( $configs, 'Expected at least one registered email config.' );

		foreach ( $configs as $type => $config ) {
			$this->assertArrayHasKey( 'trigger_description', $config, "Config '$type' is missing trigger_description." );
			$this->assertIsString( $config['trigger_description'], "Config '$type' trigger_description must be a string." );

			$this->assertArrayHasKey( 'recipient', $config, "Config '$type' is missing recipient." );
			$this->assertContains(
				$config['recipient'],
				[ 'reader', 'admin' ],
				"Config '$type' has an invalid recipient value."
			);

			$this->assertArrayHasKey( 'recommended', $config, "Config '$type' is missing recommended." );
			$this->assertIsBool( $config['recommended'], "Config '$type' recommended must be a bool." );

			$this->assertArrayHasKey( 'chip', $config, "Config '$type' is missing chip." );
			$this->assertContains(
				$config['chip'],
				[ 'auth-account', 'reader-revenue' ],
				"Config '$type' has an invalid chip value."
			);
		}
	}

	/*
	 * ------------------------------------------------------------------
	 * Bucket B — Per-provider content
	 * ------------------------------------------------------------------
	 * Asserts each provider class registers entries with the expected
	 * field values. Catches data regressions when providers are touched.
	 */

	/**
	 * Reader-revenue provider: 3 entries chip='reader-revenue', recipient='reader', recommended=true.
	 */
	public function test_reader_revenue_provider_entries() {
		$configs = Emails::get_email_configs();

		$expected = [
			Reader_Revenue_Emails::EMAIL_TYPES['RECEIPT'] => 'Sent after a successful payment.',
			Reader_Revenue_Emails::EMAIL_TYPES['WELCOME'] => 'Sent to new supporters after their first payment.',
			Reader_Revenue_Emails::EMAIL_TYPES['CANCELLATION'] => 'Sent when a reader cancels their subscription.',
		];

		foreach ( $expected as $type => $trigger_description ) {
			$this->assertArrayHasKey( $type, $configs, "Reader-revenue type '$type' not registered." );
			$this->assertSame( 'reader-revenue', $configs[ $type ]['chip'], "Type '$type' should chip to reader-revenue." );
			$this->assertSame( 'reader', $configs[ $type ]['recipient'], "Type '$type' should target reader." );
			$this->assertTrue( $configs[ $type ]['recommended'], "Type '$type' should be recommended." );
			$this->assertSame( $trigger_description, $configs[ $type ]['trigger_description'] );
		}
	}

	/**
	 * Reader-activation provider: chip='auth-account', recipient='reader' for all entries.
	 * Recommended=true for the four core sign-in flows; false for the account-management flows.
	 *
	 * CHANGE_EMAIL and CHANGE_EMAIL_CANCEL are gated on WooCommerce_My_Account::is_email_change_enabled(),
	 * so they're only asserted when registered.
	 */
	public function test_reader_activation_provider_entries() {
		$configs = Emails::get_email_configs();

		$recommended_types     = [
			Reader_Activation_Emails::EMAIL_TYPES['VERIFICATION'],
			Reader_Activation_Emails::EMAIL_TYPES['MAGIC_LINK'],
			Reader_Activation_Emails::EMAIL_TYPES['OTP_AUTH'],
			Reader_Activation_Emails::EMAIL_TYPES['RESET_PASSWORD'],
		];
		$non_recommended_types = [
			Reader_Activation_Emails::EMAIL_TYPES['DELETE_ACCOUNT'],
			Reader_Activation_Emails::EMAIL_TYPES['NON_READER'],
		];
		$conditional_types     = [
			Reader_Activation_Emails::EMAIL_TYPES['CHANGE_EMAIL'],
			Reader_Activation_Emails::EMAIL_TYPES['CHANGE_EMAIL_CANCEL'],
		];

		foreach ( array_merge( $recommended_types, $non_recommended_types ) as $type ) {
			$this->assertArrayHasKey( $type, $configs, "Reader-activation type '$type' not registered." );
			$this->assertSame( 'auth-account', $configs[ $type ]['chip'], "Type '$type' should chip to auth-account." );
			$this->assertSame( 'reader', $configs[ $type ]['recipient'], "Type '$type' should target reader." );
			$this->assertNotEmpty( $configs[ $type ]['trigger_description'], "Type '$type' should have a trigger description." );
		}
		foreach ( $recommended_types as $type ) {
			$this->assertTrue( $configs[ $type ]['recommended'], "Type '$type' should be recommended." );
		}
		foreach ( $non_recommended_types as $type ) {
			$this->assertFalse( $configs[ $type ]['recommended'], "Type '$type' should NOT be recommended." );
		}
		// CHANGE_EMAIL pair only registers when WC My Account email change is enabled.
		foreach ( $conditional_types as $type ) {
			if ( ! isset( $configs[ $type ] ) ) {
				continue;
			}
			$this->assertSame( 'auth-account', $configs[ $type ]['chip'] );
			$this->assertSame( 'reader', $configs[ $type ]['recipient'] );
			$this->assertFalse( $configs[ $type ]['recommended'] );
		}
	}

	/**
	 * Group-subscription-invite provider: chip='reader-revenue' (paid product), not recommended.
	 */
	public function test_group_subscription_invite_provider_entry() {
		$configs = Emails::get_email_configs();
		$type    = 'group-subscription-invite';

		if ( ! isset( $configs[ $type ] ) ) {
			$this->markTestSkipped( 'group-subscription-invite config not registered in this environment.' );
		}
		$this->assertSame( 'reader-revenue', $configs[ $type ]['chip'] );
		$this->assertSame( 'reader', $configs[ $type ]['recipient'] );
		$this->assertFalse( $configs[ $type ]['recommended'] );
		$this->assertSame( 'Sent to invite a reader to join a group subscription.', $configs[ $type ]['trigger_description'] );
	}

	/*
	 * ------------------------------------------------------------------
	 * Bucket C — Response shape of api_get_email_settings()
	 * ------------------------------------------------------------------
	 * Verifies the wizard endpoint response structure after the rewrite:
	 * top-level keys are correct, each row carries the new fields,
	 * registry_slug is the config key string, no view_category leakage,
	 * and category sort grouping holds. (WooCommerce-source surfacing is
	 * covered in emails-section-woocommerce.php; slice 2a no longer
	 * excludes WC rows here.)
	 */

	/**
	 * Response has the expected top-level shape and rows carry the new fields.
	 */
	public function test_api_get_email_settings_response_shape() {
		$result = Emails_Section::api_get_email_settings();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'newspack_emails', $result );
		$this->assertArrayHasKey( 'post_type', $result );
		$this->assertIsArray( $result['newspack_emails'] );

		// Precondition: if Emails::supports_emails() returns false (e.g.
		// Newspack_Newsletters isn't loaded in this test bootstrap),
		// newspack_emails is [] and the foreach below runs zero times —
		// making the per-row assertions vacuous. Skip explicitly rather
		// than letting a regression slip through silently.
		if ( empty( $result['newspack_emails'] ) ) {
			$this->markTestSkipped( 'Emails::supports_emails() is false in this environment; no rows to assert against.' );
		}

		$required_row_fields = [
			'label',
			'trigger_description',
			'recipient',
			'recommended',
			'chip',
			'source',
			'category',
			'status',
		];
		foreach ( $result['newspack_emails'] as $email ) {
			foreach ( $required_row_fields as $field ) {
				$this->assertArrayHasKey( $field, $email, "Row '{$email['label']}' is missing field '$field'." );
			}
		}
	}

	/**
	 * `view_category` is dead — the response builder never emits it.
	 */
	public function test_api_get_email_settings_omits_view_category() {
		$result = Emails_Section::api_get_email_settings();
		foreach ( $result['newspack_emails'] as $email ) {
			$this->assertArrayNotHasKey( 'view_category', $email );
		}
	}

	/**
	 * Within-category tiebreaker is config-registration order, not
	 * alphabetical. Providers register in deliberate order
	 * (Reader_Revenue_Emails: receipt → welcome → cancellation;
	 * Reader_Activation_Emails: verification → magic-link → otp →
	 * reset-password → ...), so registration order = intended display
	 * order. Lock that contract in.
	 */
	public function test_api_get_email_settings_within_category_follows_registration_order() {
		$result = Emails_Section::api_get_email_settings();

		// Reader-revenue group: receipt → welcome → cancellation
		// (per Reader_Revenue_Emails::add_email_configs() order), then
		// group-subscription-invite (also reader-revenue category after
		// the slice-1 fix flipped it from reader-activation).
		$rr_types = array_values(
			array_map(
				fn( $email ) => $email['type'],
				array_filter(
					$result['newspack_emails'],
					fn( $email ) => 'reader-revenue' === ( $email['category'] ?? '' )
				)
			)
		);
		$this->assertSame(
			[
				Reader_Revenue_Emails::EMAIL_TYPES['RECEIPT'],
				Reader_Revenue_Emails::EMAIL_TYPES['WELCOME'],
				Reader_Revenue_Emails::EMAIL_TYPES['CANCELLATION'],
				'group-subscription-invite',
			],
			$rr_types,
			'Reader-revenue rows must follow provider registration order.'
		);

		// Reader-activation group: verification → magic-link → otp →
		// reset-password (the four sign-in flows that are always present,
		// per the order in Reader_Activation_Emails::add_email_configs()).
		$ra_types_all  = array_values(
			array_map(
				fn( $email ) => $email['type'],
				array_filter(
					$result['newspack_emails'],
					fn( $email ) => 'reader-activation' === ( $email['category'] ?? '' )
				)
			)
		);
		$ra_core_types = array_values(
			array_filter(
				$ra_types_all,
				fn( $type ) => in_array(
					$type,
					[
						Reader_Activation_Emails::EMAIL_TYPES['VERIFICATION'],
						Reader_Activation_Emails::EMAIL_TYPES['MAGIC_LINK'],
						Reader_Activation_Emails::EMAIL_TYPES['OTP_AUTH'],
						Reader_Activation_Emails::EMAIL_TYPES['RESET_PASSWORD'],
					],
					true
				)
			)
		);
		$this->assertSame(
			[
				Reader_Activation_Emails::EMAIL_TYPES['VERIFICATION'],
				Reader_Activation_Emails::EMAIL_TYPES['MAGIC_LINK'],
				Reader_Activation_Emails::EMAIL_TYPES['OTP_AUTH'],
				Reader_Activation_Emails::EMAIL_TYPES['RESET_PASSWORD'],
			],
			$ra_core_types,
			'Reader-activation rows must follow Reader_Activation_Emails provider registration order.'
		);
	}

	/**
	 * Sort order: reader-revenue first, then reader-activation, then everything else.
	 */
	public function test_api_get_email_settings_sort_order() {
		$result    = Emails_Section::api_get_email_settings();
		$group_map = [
			'reader-revenue'    => 0,
			'reader-activation' => 1,
		];

		$last_group = -1;
		foreach ( $result['newspack_emails'] as $i => $email ) {
			$group = $group_map[ $email['category'] ?? '' ] ?? 2;
			$this->assertGreaterThanOrEqual(
				$last_group,
				$group,
				"Email at index $i (category '{$email['category']}') is out of sort order."
			);
			$last_group = $group;
		}
	}


	/*
	 * ------------------------------------------------------------------
	 * Bucket D — Default-merge mechanism
	 * ------------------------------------------------------------------
	 * Direct coverage of Emails::apply_config_defaults() — the public
	 * helper introduced in commit 1. Catches changes to the documented
	 * defaults and regressions in the merge logic.
	 */

	/**
	 * Partial config gets the documented defaults filled in.
	 */
	public function test_apply_config_defaults_fills_missing_fields() {
		$partial = [
			'name'     => 'test-email',
			'label'    => 'Test Email',
			'category' => 'reader-activation',
		];
		$merged  = Emails::apply_config_defaults( $partial );

		$this->assertSame( '', $merged['trigger_description'] );
		$this->assertSame( 'reader', $merged['recipient'] );
		// `recommended` defaults to false — third-parties must opt in.
		$this->assertFalse( $merged['recommended'] );
		// `chip` is derived from category in apply_config_defaults().
		$this->assertSame( 'auth-account', $merged['chip'] );

		// Declared fields pass through unchanged.
		$this->assertSame( 'test-email', $merged['name'] );
		$this->assertSame( 'Test Email', $merged['label'] );
		$this->assertSame( 'reader-activation', $merged['category'] );
	}

	/**
	 * Chip is derived from category: reader-revenue → reader-revenue.
	 */
	public function test_apply_config_defaults_derives_reader_revenue_chip_from_category() {
		$partial = [
			'name'     => 'test-rr-email',
			'category' => 'reader-revenue',
		];
		$merged  = Emails::apply_config_defaults( $partial );
		$this->assertSame( 'reader-revenue', $merged['chip'] );
	}

	/**
	 * Explicit chip declaration wins over the category-derived default.
	 */
	public function test_apply_config_defaults_explicit_chip_overrides_derivation() {
		$partial = [
			'name'     => 'test-email',
			'category' => 'reader-revenue',
			'chip'     => 'auth-account',
		];
		$merged  = Emails::apply_config_defaults( $partial );
		$this->assertSame( 'auth-account', $merged['chip'] );
	}

	/**
	 * A config that declares the new fields keeps its values — defaults
	 * do not clobber explicit declarations.
	 */
	public function test_apply_config_defaults_preserves_declared_fields() {
		$full   = [
			'name'                => 'test-email',
			'trigger_description' => 'A specific trigger.',
			'recipient'           => 'admin',
			'recommended'         => false,
			'chip'                => 'reader-revenue',
		];
		$merged = Emails::apply_config_defaults( $full );

		$this->assertSame( 'A specific trigger.', $merged['trigger_description'] );
		$this->assertSame( 'admin', $merged['recipient'] );
		$this->assertFalse( $merged['recommended'] );
		$this->assertSame( 'reader-revenue', $merged['chip'] );
	}

	/**
	 * A third-party provider can register with no new fields at all and
	 * still get a complete config back out of Emails::get_email_configs().
	 */
	public function test_email_configs_filter_partial_provider_gets_defaults() {
		$type     = 'test-partial-third-party-config';
		$callback = function ( $configs ) use ( $type ) {
			$configs[ $type ] = [
				'name'     => $type,
				'category' => 'reader-activation',
				'label'    => 'Third-party',
			];
			return $configs;
		};

		add_filter( 'newspack_email_configs', $callback );
		Emails::reset_email_configs_cache();
		$configs = Emails::get_email_configs();
		remove_filter( 'newspack_email_configs', $callback );
		Emails::reset_email_configs_cache();

		$this->assertArrayHasKey( $type, $configs );
		$this->assertSame( '', $configs[ $type ]['trigger_description'] );
		$this->assertSame( 'reader', $configs[ $type ]['recipient'] );
		$this->assertFalse( $configs[ $type ]['recommended'] );
		$this->assertSame( 'auth-account', $configs[ $type ]['chip'] );
	}

	/**
	 * Defensive: a third-party filter that returns a non-array entry at a
	 * key must NOT fatal get_email_configs() — the bad row is dropped and
	 * the other entries pass through unchanged.
	 */
	public function test_email_configs_filter_skips_non_array_entries() {
		$callback = function ( $configs ) {
			$configs['malformed-string']    = 'not-an-array';
			$configs['malformed-null']      = null;
			$configs['valid-third-party']   = [
				'name'     => 'valid-third-party',
				'category' => 'reader-activation',
				'label'    => 'Valid third-party',
			];
			return $configs;
		};

		add_filter( 'newspack_email_configs', $callback );
		Emails::reset_email_configs_cache();
		$configs = Emails::get_email_configs();
		remove_filter( 'newspack_email_configs', $callback );
		Emails::reset_email_configs_cache();

		// Bad rows silently dropped.
		$this->assertArrayNotHasKey( 'malformed-string', $configs );
		$this->assertArrayNotHasKey( 'malformed-null', $configs );
		// Valid row still surfaces with defaults applied.
		$this->assertArrayHasKey( 'valid-third-party', $configs );
		$this->assertSame( 'reader', $configs['valid-third-party']['recipient'] );
	}

	/*
	 * ------------------------------------------------------------------
	 * Bucket E — Reader Activation gating
	 * ------------------------------------------------------------------
	 * Covers Emails_Section::filter_configs_by_ra_state(), the helper
	 * api_get_email_settings() uses to scope the visible config set when
	 * Reader Activation is disabled. Tests the helper directly because
	 * Reader_Activation::is_enabled() hard-returns true in the test
	 * environment (see class-reader-activation.php:1013), making the
	 * disabled branch unreachable through the public endpoint.
	 */

	/**
	 * When RA is enabled, all configs pass through unchanged.
	 */
	public function test_filter_configs_by_ra_state_passes_through_when_enabled() {
		$configs = [
			'receipt'                        => [ 'name' => 'receipt' ],
			'reader-activation-verification' => [ 'name' => 'reader-activation-verification' ],
			'group-subscription-invite'      => [ 'name' => 'group-subscription-invite' ],
		];
		$filtered = Emails_Section::filter_configs_by_ra_state( true, $configs );
		$this->assertSame( $configs, $filtered );
	}

	/**
	 * When RA is disabled, only configs tagged with `chip: 'reader-revenue'`
	 * survive — i.e. anything in the reader-revenue grouping regardless of
	 * which provider class registered it.
	 */
	public function test_filter_configs_by_ra_state_scopes_to_reader_revenue_when_disabled() {
		$configs = [
			'receipt'                        => [
				'name' => 'receipt',
				'chip' => 'reader-revenue',
			],
			'welcome'                        => [
				'name' => 'welcome',
				'chip' => 'reader-revenue',
			],
			'group-subscription-invite'      => [
				'name' => 'group-subscription-invite',
				'chip' => 'reader-revenue',
			],
			'reader-activation-verification' => [
				'name' => 'verification',
				'chip' => 'auth-account',
			],
			'reader-activation-magic-link'   => [
				'name' => 'magic-link',
				'chip' => 'auth-account',
			],
			// Edge: a config that somehow lost its chip — must be excluded.
			'untagged'                       => [ 'name' => 'untagged' ],
		];
		$filtered = Emails_Section::filter_configs_by_ra_state( false, $configs );

		// Reader-revenue-chipped configs should survive.
		$this->assertArrayHasKey( 'receipt', $filtered );
		$this->assertArrayHasKey( 'welcome', $filtered );
		$this->assertArrayHasKey( 'group-subscription-invite', $filtered );

		// Auth-account-chipped configs should be dropped.
		$this->assertArrayNotHasKey( 'reader-activation-verification', $filtered );
		$this->assertArrayNotHasKey( 'reader-activation-magic-link', $filtered );

		// Untagged configs (no chip) should also be dropped — conservative.
		$this->assertArrayNotHasKey( 'untagged', $filtered );
	}

	/**
	 * Empty configs in, empty configs out — regardless of RA state.
	 */
	public function test_filter_configs_by_ra_state_handles_empty_configs() {
		$this->assertSame( [], Emails_Section::filter_configs_by_ra_state( true, [] ) );
		$this->assertSame( [], Emails_Section::filter_configs_by_ra_state( false, [] ) );
	}

	/*
	 * ------------------------------------------------------------------
	 * Bucket F — Reset endpoint (NPPD-1535)
	 * ------------------------------------------------------------------
	 * Validates `api_reset_email`, the DELETE
	 * /wizard/newspack-settings/emails/{id} handler ported from
	 * Audience_Donations in NPPD-1535. Plus architectural-lock-in
	 * assertions that the new route is registered with the expected
	 * permission_callback AND that the legacy donations-namespace route
	 * is gone.
	 */

	/**
	 * Happy path: a valid email post ID is trashed and the response
	 * carries the refreshed list shape from `Emails::get_emails()`.
	 */
	public function test_reset_email_successful() {
		$post_id = wp_insert_post(
			[
				'post_type'   => Emails::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test email for reset',
				'meta_input'  => [
					Emails::EMAIL_CONFIG_NAME_META => 'receipt',
				],
			]
		);

		$request = new WP_REST_Request( 'DELETE' );
		$request->set_param( 'id', $post_id );

		$response = Emails_Section::api_reset_email( $request );

		$this->assertNotInstanceOf( WP_Error::class, $response, 'Reset on a valid email post should not error.' );
		$this->assertSame( 'trash', get_post_status( $post_id ), 'Email post should be trashed after reset.' );
		$this->assertIsArray( $response->get_data(), 'Response payload should be the refreshed email list array.' );
	}

	/**
	 * Trash-failure branch: when `wp_trash_post()` fails, the handler returns
	 * 400 with the `newspack_reset_email_reset_failed` code and leaves the
	 * post un-trashed. The failure is forced via the `pre_trash_post`
	 * short-circuit filter (returning non-null makes wp_trash_post() return
	 * that value without trashing). Locks the error code introduced when the
	 * endpoint moved off the donations namespace in NPPD-1535.
	 */
	public function test_reset_email_trash_failure() {
		$post_id = wp_insert_post(
			[
				'post_type'   => Emails::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test email for reset failure',
				'meta_input'  => [
					Emails::EMAIL_CONFIG_NAME_META => 'receipt',
				],
			]
		);

		add_filter( 'pre_trash_post', '__return_false' );
		$request = new WP_REST_Request( 'DELETE' );
		$request->set_param( 'id', $post_id );
		$response = Emails_Section::api_reset_email( $request );
		remove_filter( 'pre_trash_post', '__return_false' );

		$this->assertInstanceOf( WP_Error::class, $response, 'A failed trash must return WP_Error.' );
		$this->assertSame( 'newspack_reset_email_reset_failed', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
		$this->assertSame( 'publish', get_post_status( $post_id ), 'Post must remain un-trashed when the trash fails.' );
	}

	/**
	 * Non-existent post ID returns 400 with the invalid_arg error code.
	 *
	 * Uses `wp_insert_post` + `wp_delete_post( … true )` to derive a
	 * guaranteed-missing ID rather than a hardcoded sentinel like
	 * `999999`. A hardcoded sentinel can collide with a real post in
	 * long-running test suites or seeded environments — at which point
	 * the test would fall through to the wrong-post-type branch (same
	 * error code) and silently pass for the wrong reason.
	 */
	public function test_reset_email_invalid_post_id() {
		$missing_id = wp_insert_post(
			[
				'post_type'  => 'post',
				'post_title' => 'Temp post to derive a guaranteed-missing id',
			]
		);
		wp_delete_post( $missing_id, true );

		$request = new WP_REST_Request( 'DELETE' );
		$request->set_param( 'id', $missing_id );

		$response = Emails_Section::api_reset_email( $request );

		$this->assertNull( get_post( $missing_id ), 'Sanity: the derived id must actually be missing.' );
		$this->assertInstanceOf( WP_Error::class, $response, 'A nonexistent post id must return WP_Error.' );
		$this->assertSame( 'newspack_reset_email_invalid_arg', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}

	/**
	 * Passing a non-email post type returns 400 with the invalid_arg error.
	 *
	 * Defense against accidental cross-type deletion — e.g. a request
	 * crafted with a regular post's ID must NOT trash that post.
	 */
	public function test_reset_email_wrong_post_type() {
		$post_id = wp_insert_post(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Regular post — not an email',
			]
		);

		$request = new WP_REST_Request( 'DELETE' );
		$request->set_param( 'id', $post_id );

		$response = Emails_Section::api_reset_email( $request );

		$this->assertInstanceOf( WP_Error::class, $response, 'A non-email post type must return WP_Error.' );
		$this->assertSame( 'newspack_reset_email_invalid_arg', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
		$this->assertSame( 'publish', get_post_status( $post_id ), 'Non-email post must not be trashed by the reset endpoint.' );
	}

	/**
	 * Architectural lock-in (permission_callback registration):
	 *
	 * The DELETE route's `permission_callback` is the only thing
	 * blocking unauthenticated callers from trashing email posts.
	 * Asserting `api_permissions_check()` works in isolation is not
	 * enough — a regression that drops `'permission_callback' =>
	 * [ $this, 'api_permissions_check' ]` from the route registration
	 * would still leave the standalone method working, but WP would
	 * default the route to `__return_true` (with a _doing_it_wrong
	 * notice) and the endpoint would be open. So this test introspects
	 * the actual registered route via `rest_get_server()->get_routes()`
	 * and asserts the route entry carries a callable
	 * `permission_callback` that is NOT `__return_true`.
	 */
	public function test_reset_email_route_has_permission_callback() {
		do_action( 'rest_api_init' );
		$routes    = rest_get_server()->get_routes( NEWSPACK_API_NAMESPACE );
		$new_path  = '/' . NEWSPACK_API_NAMESPACE . '/wizard/newspack-settings/emails/(?P<id>\d+)';
		$endpoints = $routes[ $new_path ] ?? [];

		$delete_endpoint = null;
		foreach ( $endpoints as $endpoint ) {
			$methods = $endpoint['methods'] ?? [];
			if ( ! empty( $methods['DELETE'] ) ) {
				$delete_endpoint = $endpoint;
				break;
			}
		}

		$this->assertNotNull( $delete_endpoint, 'DELETE method on the reset route should be registered.' );
		$this->assertArrayHasKey( 'permission_callback', $delete_endpoint, 'DELETE route must declare a permission_callback.' );
		$this->assertNotSame( '__return_true', $delete_endpoint['permission_callback'], 'permission_callback must not default to __return_true (would leave the endpoint open).' );
		$this->assertIsCallable( $delete_endpoint['permission_callback'], 'permission_callback must be a real callable, not a string placeholder.' );

		// And the callback itself must deny anonymous callers.
		$prev_user = get_current_user_id();
		wp_set_current_user( 0 );
		$result = call_user_func( $delete_endpoint['permission_callback'], new WP_REST_Request( 'DELETE' ) );
		wp_set_current_user( $prev_user );

		$this->assertInstanceOf( WP_Error::class, $result, 'Anonymous user must be denied.' );
		$this->assertSame( 'newspack_rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Architectural lock-in (route migration):
	 *
	 * 1. Positive: `/newspack/v1/wizard/newspack-settings/emails/(?P<id>\d+)`
	 *    IS registered. Without this, a refactor that removes both the
	 *    new and legacy route registrations would silently break the
	 *    feature while leaving the negative-only assertion below
	 *    passing.
	 * 2. Negative: `/newspack/v1/wizard/newspack-audience-donations/emails/(?P<id>\d+)`
	 *    is NOT registered. NPPD-1535 moved the endpoint; this guards
	 *    against a bad merge resurrecting `api_reset_donation_email`.
	 *
	 * Modeled on the route-presence/absence assertion pattern used at
	 * `tests/unit-tests/corrections.php:53-56` (positive) and
	 * `tests/unit-tests/content-gate/class-ip-access-rule.php:97-99`
	 * (route shape introspection).
	 */
	public function test_reset_route_moved_to_emails_namespace() {
		do_action( 'rest_api_init' );
		$routes = rest_get_server()->get_routes( NEWSPACK_API_NAMESPACE );

		$new_path    = '/' . NEWSPACK_API_NAMESPACE . '/wizard/newspack-settings/emails/(?P<id>\d+)';
		$legacy_path = '/' . NEWSPACK_API_NAMESPACE . '/wizard/newspack-audience-donations/emails/(?P<id>\d+)';

		$this->assertArrayHasKey( $new_path, $routes, 'The reset route at REST_BASE/{id} must be registered.' );
		$this->assertArrayNotHasKey(
			$legacy_path,
			$routes,
			'The legacy donations-namespace reset route was re-registered. NPPD-1535 moved it to /wizard/newspack-settings/emails/{id} — if you intentionally need to revert, update the frontend resetEmail() path accordingly.'
		);
	}

	/*
	 * ------------------------------------------------------------------
	 * Bucket F — Settings endpoint (NPPD-1566)
	 * ------------------------------------------------------------------
	 * GET + POST /wizard/newspack-settings/emails/settings carry the
	 * three transactional-email setting values (sender_name,
	 * sender_email_address, contact_email_address) previously surfaced
	 * in the Reader Activation prerequisite card. The endpoint lives
	 * in Emails_Section; writes delegate to Reader_Activation::update_setting()
	 * so the underlying newspack_reader_activation_* wp_options keys
	 * are unchanged.
	 */

	/**
	 * GET returns the three saved values + a `defaults` sub-array with
	 * the derived defaults. Saved values come through as the option
	 * values; defaults stay constant regardless of overrides.
	 */
	public function test_get_settings_returns_three_fields() {
		update_option( 'newspack_reader_activation_sender_name', 'Test Sender' );
		update_option( 'newspack_reader_activation_sender_email_address', 'sender@example.test' );
		update_option( 'newspack_reader_activation_contact_email_address', 'contact@example.test' );

		$response = Emails_Section::api_get_settings();
		$this->assertNotInstanceOf( WP_Error::class, $response );

		$data = $response->get_data();
		$this->assertSame( 'Test Sender', $data['sender_name'] );
		$this->assertSame( 'sender@example.test', $data['sender_email_address'] );
		$this->assertSame( 'contact@example.test', $data['contact_email_address'] );

		// Defaults are derived from bloginfo / domain regardless of
		// whether overrides are saved. Type-check rather than value-check
		// since the bootstrap's site title / admin email vary.
		$this->assertArrayHasKey( 'defaults', $data );
		$this->assertIsString( $data['defaults']['sender_name'] );
		$this->assertIsString( $data['defaults']['sender_email_address'] );
		$this->assertStringStartsWith( 'no-reply@', $data['defaults']['sender_email_address'] );
		$this->assertIsString( $data['defaults']['contact_email_address'] );

		delete_option( 'newspack_reader_activation_sender_name' );
		delete_option( 'newspack_reader_activation_sender_email_address' );
		delete_option( 'newspack_reader_activation_contact_email_address' );
	}

	/**
	 * GET on a fresh install (no overrides saved) returns empty strings
	 * for the three top-level keys and populated derived defaults. This
	 * is the load-bearing case for the launch-safety story — publishers
	 * who've never explicitly saved should see empty fields with
	 * placeholder hints, not auto-derived values that could be locked
	 * in on first save.
	 */
	public function test_get_settings_returns_empty_values_when_no_override_saved() {
		// Belt-and-suspenders: no preceding update_option in this test,
		// but make sure no leftover state from a sibling test bleeds in.
		delete_option( 'newspack_reader_activation_sender_name' );
		delete_option( 'newspack_reader_activation_sender_email_address' );
		delete_option( 'newspack_reader_activation_contact_email_address' );

		$data = Emails_Section::api_get_settings()->get_data();

		$this->assertSame( '', $data['sender_name'] );
		$this->assertSame( '', $data['sender_email_address'] );
		$this->assertSame( '', $data['contact_email_address'] );

		// Defaults still populated even with no overrides.
		$this->assertNotEmpty( $data['defaults']['sender_name'] );
		$this->assertNotEmpty( $data['defaults']['sender_email_address'] );
		$this->assertNotEmpty( $data['defaults']['contact_email_address'] );
	}

	/**
	 * POST with valid non-empty values writes the three options and
	 * returns the refreshed `{values + defaults}` shape via api_get_settings.
	 */
	public function test_post_settings_persists_three_fields() {
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'sender_name', 'My Site' );
		$request->set_param( 'sender_email_address', 'hello@example.test' );
		$request->set_param( 'contact_email_address', 'support@example.test' );

		$response = Emails_Section::api_update_settings( $request );
		$this->assertNotInstanceOf( WP_Error::class, $response );

		// Underlying wp_options keys are unchanged from the legacy surface.
		$this->assertSame( 'My Site', get_option( 'newspack_reader_activation_sender_name' ) );
		$this->assertSame( 'hello@example.test', get_option( 'newspack_reader_activation_sender_email_address' ) );
		$this->assertSame( 'support@example.test', get_option( 'newspack_reader_activation_contact_email_address' ) );

		// Response carries the refreshed value/default pair.
		$data = $response->get_data();
		$this->assertSame( 'My Site', $data['sender_name'] );
		$this->assertSame( 'hello@example.test', $data['sender_email_address'] );
		$this->assertSame( 'support@example.test', $data['contact_email_address'] );
		$this->assertArrayHasKey( 'defaults', $data );

		delete_option( 'newspack_reader_activation_sender_name' );
		delete_option( 'newspack_reader_activation_sender_email_address' );
		delete_option( 'newspack_reader_activation_contact_email_address' );
	}

	/**
	 * Invalid sender_email_address returns 400 + newspack_invalid_sender_email.
	 *
	 * The handler call below mirrors what reaches it through the live
	 * REST route: the args' `sanitize_text_field` callback passes
	 * 'not-an-email' through unchanged (sanitize_text_field strips
	 * tags + trims whitespace, but does not collapse non-email input).
	 * The handler's `is_email()` guard then rejects it with 400. This
	 * test would silently succeed against an unreachable code path if
	 * the args callback ever regressed to `sanitize_email`, which
	 * collapses the input to '' BEFORE the handler sees it — and the
	 * empty-as-revert branch would then delete the publisher's
	 * previously saved override without surfacing a validation error.
	 */
	public function test_post_settings_rejects_invalid_email_in_sender() {
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'sender_name', 'My Site' );
		$request->set_param( 'sender_email_address', 'not-an-email' );
		$request->set_param( 'contact_email_address', 'support@example.test' );

		$response = Emails_Section::api_update_settings( $request );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'newspack_invalid_sender_email', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );

		// No options written on validation failure.
		$this->assertFalse( get_option( 'newspack_reader_activation_sender_name' ) );
	}

	/**
	 * Invalid contact_email_address returns 400 + newspack_invalid_contact_email.
	 */
	public function test_post_settings_rejects_invalid_email_in_contact() {
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'sender_name', 'My Site' );
		$request->set_param( 'sender_email_address', 'sender@example.test' );
		$request->set_param( 'contact_email_address', 'also-not-an-email' );

		$response = Emails_Section::api_update_settings( $request );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'newspack_invalid_contact_email', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}

	/**
	 * Regression guard: the args' `sanitize_callback` for email fields
	 * must NOT be `sanitize_email`. Dispatching a typo email through
	 * the live REST route runs the args sanitize before the handler;
	 * `sanitize_email` collapses non-conforming input to '' so the
	 * handler's `'' === $value` branch would `delete_option()` the
	 * publisher's saved override and return 200 OK — bypassing the
	 * `is_email()` validation entirely. `sanitize_text_field`
	 * preserves the typo so the handler rejects it with a proper 400.
	 *
	 * This test introspects the actually-registered route's args
	 * config rather than running the route end-to-end, because the
	 * test bootstrap doesn't always register Newspack REST routes
	 * deterministically.
	 */
	public function test_post_settings_args_use_text_field_sanitizer_for_emails() {
		// register_rest_route normally complains when called outside
		// `rest_api_init`; we're calling register_rest_routes()
		// directly to introspect what would be registered through the
		// normal lifecycle. Acknowledge the notice rather than fire
		// the whole action chain (which would re-register every
		// Newspack REST route as a side effect).
		$this->setExpectedIncorrectUsage( 'register_rest_route' );

		global $wp_rest_server;
		$prev_server    = $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		( new Emails_Section( [ 'wizard_slug' => 'newspack-settings' ] ) )->register_rest_routes();
		$routes         = $wp_rest_server->get_routes();
		$wp_rest_server = $prev_server;

		$route_key = '/' . NEWSPACK_API_NAMESPACE . '/wizard/newspack-settings/emails/settings';
		$this->assertArrayHasKey( $route_key, $routes );

		// Find the POST endpoint config in the registered routes. WP
		// stores `methods` as an associative array of method => true.
		$post_endpoint = null;
		foreach ( $routes[ $route_key ] as $endpoint ) {
			if ( ! empty( $endpoint['methods']['POST'] ) ) {
				$post_endpoint = $endpoint;
				break;
			}
		}
		$this->assertNotNull( $post_endpoint, 'POST endpoint should be registered.' );

		foreach ( [ 'sender_email_address', 'contact_email_address' ] as $field ) {
			$this->assertNotSame(
				'sanitize_email',
				$post_endpoint['args'][ $field ]['sanitize_callback'] ?? null,
				"sanitize_email on '$field' silently collapses typo input to '' before the handler runs, defeating the is_email() guard. Use sanitize_text_field."
			);
			$this->assertSame(
				'sanitize_text_field',
				$post_endpoint['args'][ $field ]['sanitize_callback'] ?? null,
				"Expected '$field' to use sanitize_text_field as args sanitize callback."
			);
		}
	}

	/**
	 * The `defaults.sender_email_address` returned by api_get_settings()
	 * MUST match the value `Emails::get_from_email()` returns when no
	 * override is saved. If they diverge, the modal's placeholder
	 * shows one default while outbound mail is sent from another —
	 * silent UX gap. Locks the contract: any future change to the
	 * default-derivation logic on either side has to update both,
	 * or this test fails.
	 */
	public function test_get_settings_default_sender_email_matches_send_path() {
		delete_option( 'newspack_reader_activation_sender_email_address' );

		$data = Emails_Section::api_get_settings()->get_data();
		$this->assertSame(
			Emails::get_from_email(),
			$data['defaults']['sender_email_address'],
			'Modal placeholder must match Emails::get_from_email() default-fallback when no override saved.'
		);
	}

	/**
	 * Same alignment contract for sender_name (against
	 * Emails::get_from_name()) and contact_email_address (against
	 * Emails::get_reply_to_email()). Locks both surfaces against
	 * silent divergence.
	 */
	public function test_get_settings_defaults_match_send_path_helpers() {
		delete_option( 'newspack_reader_activation_sender_name' );
		delete_option( 'newspack_reader_activation_contact_email_address' );

		$data = Emails_Section::api_get_settings()->get_data();
		$this->assertSame(
			Emails::get_from_name(),
			$data['defaults']['sender_name'],
			'Modal placeholder must match Emails::get_from_name() default-fallback.'
		);
		$this->assertSame(
			Emails::get_reply_to_email(),
			$data['defaults']['contact_email_address'],
			'Modal placeholder must match Emails::get_reply_to_email() default-fallback.'
		);
	}

	/**
	 * POSTing an empty value for any field deletes the option row,
	 * reverting that field to its derived default. This is the
	 * load-bearing case for letting publishers "unset" a previously
	 * saved override and re-engage the dynamic-default behavior.
	 */
	public function test_post_settings_empty_value_deletes_option() {
		// Pre-condition: a saved override exists.
		update_option( 'newspack_reader_activation_sender_name', 'Old Override' );
		$this->assertSame( 'Old Override', get_option( 'newspack_reader_activation_sender_name' ) );

		// POST with empty sender_name — other fields valid so the
		// handler reaches the write path.
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'sender_name', '' );
		$request->set_param( 'sender_email_address', 'sender@example.test' );
		$request->set_param( 'contact_email_address', 'contact@example.test' );

		$response = Emails_Section::api_update_settings( $request );
		$this->assertNotInstanceOf( WP_Error::class, $response );

		// Option row is gone — get_option returns the default `false`
		// (no row exists), not the previously-saved 'Old Override'.
		$this->assertFalse( get_option( 'newspack_reader_activation_sender_name' ) );

		// Response carries '' for the cleared field and populated
		// values for the others.
		$data = $response->get_data();
		$this->assertSame( '', $data['sender_name'] );
		$this->assertSame( 'sender@example.test', $data['sender_email_address'] );
		$this->assertSame( 'contact@example.test', $data['contact_email_address'] );

		delete_option( 'newspack_reader_activation_sender_email_address' );
		delete_option( 'newspack_reader_activation_contact_email_address' );
	}

	/**
	 * If `Reader_Activation::update_setting()` returns false (the key
	 * was removed from get_settings_config(), e.g. via the
	 * `newspack_reader_activation_settings_config` filter, or
	 * update_option itself failed), the handler must surface a
	 * `newspack_settings_write_failed` WP_Error rather than silently
	 * proceed to return `api_get_settings()` — which would look like a
	 * successful save while the option was never written.
	 */
	public function test_post_settings_returns_error_when_update_setting_fails() {
		$filter = function ( $config ) {
			unset( $config['sender_name'] );
			return $config;
		};
		add_filter( 'newspack_reader_activation_settings_config', $filter );

		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'sender_name', 'My Site' );
		$request->set_param( 'sender_email_address', 'sender@example.test' );
		$request->set_param( 'contact_email_address', 'contact@example.test' );

		$response = Emails_Section::api_update_settings( $request );

		remove_filter( 'newspack_reader_activation_settings_config', $filter );

		// The filter-removal of sender_name causes update_setting() to
		// return false on the first non-empty write; the handler must
		// convert that into a visible 500 rather than the misleading
		// 200 OK + empty re-read.
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'newspack_settings_write_failed', $response->get_error_code() );
		$this->assertSame( 500, $response->get_error_data()['status'] );
	}

	/**
	 * Empty-value revert path fires `newspack_reader_activation_update_setting`
	 * with an empty value so external subscribers (audit logs, ESP
	 * sync) observe the change. Without this, the delete path would
	 * be invisible to hook listeners, producing drift between the
	 * stored state and any external mirror built from the hook.
	 */
	public function test_post_settings_empty_value_fires_update_setting_action() {
		update_option( 'newspack_reader_activation_sender_name', 'Old Override' );

		$captured = [];
		$callback = function ( $key, $value ) use ( &$captured ) {
			$captured[] = [ $key, $value ];
		};
		add_action( 'newspack_reader_activation_update_setting', $callback, 10, 2 );

		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'sender_name', '' );
		$request->set_param( 'sender_email_address', 'sender@example.test' );
		$request->set_param( 'contact_email_address', 'contact@example.test' );

		$response = Emails_Section::api_update_settings( $request );

		remove_action( 'newspack_reader_activation_update_setting', $callback, 10 );

		$this->assertNotInstanceOf( WP_Error::class, $response );

		// Should have fired for sender_name (empty/revert path) and
		// the two non-empty writes (via update_setting internal).
		$sender_name_events = array_filter( $captured, fn( $e ) => 'sender_name' === $e[0] );
		$this->assertCount( 1, $sender_name_events, 'Empty-revert path should fire update_setting action once.' );
		$this->assertSame( '', array_values( $sender_name_events )[0][1] );

		delete_option( 'newspack_reader_activation_sender_email_address' );
		delete_option( 'newspack_reader_activation_contact_email_address' );
	}

	/**
	 * Re-saving the same value must return success, not 500.
	 *
	 * WordPress's `update_option()` returns false in two distinct
	 * cases: genuine write failure AND no-op (new value === current
	 * value). The handler's `! update_setting(...)` check treats
	 * both as failure. Without a pre-check skipping no-ops, hitting
	 * Save twice in a row with the same value 500s on the second
	 * call. Pre-check via `get_option` and `continue` past unchanged
	 * fields.
	 *
	 * Also asserts the action hook does NOT fire on the no-op
	 * re-save — "this changed" semantics. (`update_setting`
	 * unconditionally fires the hook before reaching update_option,
	 * so the only way to keep the no-op silent is to bypass
	 * update_setting entirely when value is unchanged.)
	 */
	public function test_post_settings_idempotent_when_value_unchanged() {
		// First save.
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'sender_name', 'Same Site' );
		$request->set_param( 'sender_email_address', 'same@example.test' );
		$request->set_param( 'contact_email_address', 'same-contact@example.test' );

		$first_response = Emails_Section::api_update_settings( $request );
		$this->assertNotInstanceOf( WP_Error::class, $first_response );

		// Second save with identical values — must NOT 500.
		$captured = [];
		$callback = function ( $key, $value ) use ( &$captured ) {
			$captured[] = [ $key, $value ];
		};
		add_action( 'newspack_reader_activation_update_setting', $callback, 10, 2 );

		$second_response = Emails_Section::api_update_settings( $request );

		remove_action( 'newspack_reader_activation_update_setting', $callback, 10 );

		$this->assertNotInstanceOf(
			WP_Error::class,
			$second_response,
			'Re-saving identical values must not 500. update_option() returns false on no-op, which the handler must distinguish from genuine write failure.'
		);
		$this->assertSame(
			[],
			$captured,
			'newspack_reader_activation_update_setting must NOT fire on no-op re-saves — the hook means "this changed", not "this was submitted".'
		);

		// Response shape is still correct (reads back current state).
		$data = $second_response->get_data();
		$this->assertSame( 'Same Site', $data['sender_name'] );
		$this->assertSame( 'same@example.test', $data['sender_email_address'] );
		$this->assertSame( 'same-contact@example.test', $data['contact_email_address'] );

		delete_option( 'newspack_reader_activation_sender_name' );
		delete_option( 'newspack_reader_activation_sender_email_address' );
		delete_option( 'newspack_reader_activation_contact_email_address' );
	}

	/**
	 * Empty-revert on an already-absent option must be a no-op:
	 * delete_option returns false when the row doesn't exist (same
	 * return as a genuine failure, but semantically different), and
	 * firing the action hook would be a phantom "change" event with
	 * nothing actually changing.
	 */
	public function test_post_settings_empty_value_idempotent_when_already_empty() {
		// Belt-and-suspenders: ensure no row exists.
		delete_option( 'newspack_reader_activation_sender_name' );

		$captured = [];
		$callback = function ( $key, $value ) use ( &$captured ) {
			$captured[] = [ $key, $value ];
		};
		add_action( 'newspack_reader_activation_update_setting', $callback, 10, 2 );

		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'sender_name', '' );
		$request->set_param( 'sender_email_address', 'sender@example.test' );
		$request->set_param( 'contact_email_address', 'contact@example.test' );

		$response = Emails_Section::api_update_settings( $request );

		remove_action( 'newspack_reader_activation_update_setting', $callback, 10 );

		$this->assertNotInstanceOf( WP_Error::class, $response );

		// Action hook should NOT have fired for sender_name — there
		// was no row to delete, no actual change. The two non-empty
		// writes DO fire the hook via update_setting() internally,
		// but the empty-already-absent path must stay silent.
		$sender_name_events = array_filter( $captured, fn( $e ) => 'sender_name' === $e[0] );
		$this->assertSame(
			[],
			array_values( $sender_name_events ),
			'Empty-revert on an already-absent option must not fire the update_setting action hook.'
		);

		// And the option row still doesn't exist (we didn't accidentally create one).
		$this->assertFalse( get_option( 'newspack_reader_activation_sender_name' ) );

		delete_option( 'newspack_reader_activation_sender_email_address' );
		delete_option( 'newspack_reader_activation_contact_email_address' );
	}

	/**
	 * The settings endpoints inherit Wizard_Section's manage_options
	 * default — a subscriber-level user hitting api_permissions_check()
	 * gets WP_Error 403. Both GET and POST share the same permission
	 * callback, so testing the section's permission method once covers
	 * the gating for both methods.
	 */
	public function test_settings_endpoint_permission_check() {
		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$prev_user     = get_current_user_id();
		wp_set_current_user( $subscriber_id );

		$section = new Emails_Section();
		$result  = $section->api_permissions_check();

		$this->assertInstanceOf( WP_Error::class, $result, 'Subscriber should be denied.' );
		$this->assertSame( 'newspack_rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );

		wp_set_current_user( $prev_user );
		wp_delete_user( $subscriber_id );
	}

	/*
	 * ------------------------------------------------------------------
	 * Bucket G — Architectural lock-in (NPPD-1538)
	 * ------------------------------------------------------------------
	 * Pins the post-move invariants: Emails_Section now lives under
	 * Audience but its REST surface stays under newspack-settings.
	 * These tests fail loudly if a future contributor accidentally
	 * unmoves the section, drifts the REST_BASE constant, or adds a
	 * new endpoint that interpolates $wizard_slug instead of REST_BASE.
	 * They also cover the legacy-URL redirect both directions
	 * (with hint → fires, without hint → passes through).
	 */

	/**
	 * Emails_Section's wizard_slug is 'newspack-audience' at runtime.
	 *
	 * The class declares `$wizard_slug` UNINITIALIZED by design — there is
	 * no class-level default. Wizard_Section::__construct assigns it from
	 * the args passed by Wizard::load_wizard_sections, so the value comes
	 * solely from the registration site. The assertion below checks the
	 * actually-registered instance. A regression where Emails_Section is
	 * re-registered under Newspack_Settings would set wizard_slug to
	 * 'newspack-settings' and this test would catch it.
	 */
	public function test_emails_section_wizard_slug_is_audience() {
		$audience_wizard = Wizards::get_wizard( 'audience' );
		$this->assertNotFalse( $audience_wizard, 'Audience wizard must be registered.' );

		$reflection      = new ReflectionClass( $audience_wizard );
		$sections_prop   = $reflection->getProperty( 'sections' );
		$sections_prop->setAccessible( true );
		$sections = $sections_prop->getValue( $audience_wizard );

		$this->assertArrayHasKey( 'emails', $sections, 'Audience wizard must host the emails section.' );
		$emails_section = $sections['emails'];
		$this->assertInstanceOf( Emails_Section::class, $emails_section );

		$wizard_slug_prop = ( new ReflectionClass( $emails_section ) )->getProperty( 'wizard_slug' );
		$wizard_slug_prop->setAccessible( true );
		$this->assertSame(
			'newspack-audience',
			$wizard_slug_prop->getValue( $emails_section ),
			'Emails_Section must register under Audience after NPPD-1538.'
		);
	}

	/**
	 * The reset endpoint is served from Emails_Section under the pinned
	 * emails namespace (ported from NPPD-1535), so the moved Audience UI
	 * no longer depends on the legacy donations route. Locks the handler's
	 * source-boundary contract: a non-email id is rejected with
	 * `newspack_reset_email_invalid_arg` (400). Named distinctly from
	 * 1535's own reset coverage so the two don't collide at merge.
	 */
	public function test_reset_endpoint_ported_rejects_non_email_id() {
		// A regular post is not a Newspack email — reset must refuse it.
		$post_id = wp_insert_post(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Not an email',
			]
		);

		$request = new WP_REST_Request( 'DELETE' );
		$request->set_param( 'id', $post_id );
		$response = Emails_Section::api_reset_email( $request );

		$this->assertInstanceOf( WP_Error::class, $response, 'Resetting a non-email post must return WP_Error.' );
		$this->assertSame( 'newspack_reset_email_invalid_arg', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
		$this->assertNotSame( 'trash', get_post_status( $post_id ), 'The non-email post must NOT be trashed.' );
	}

	/**
	 * Reset is idempotent: resetting an already-trashed Newspack email
	 * returns success (it's recreated from the default on the next read),
	 * not a spurious "reset failed". Guards the double-click case where
	 * wp_trash_post() returns false for a post already in the trash.
	 */
	public function test_reset_endpoint_idempotent_on_already_trashed_email() {
		$post_id = wp_insert_post(
			[
				'post_type'   => Emails::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Receipt',
				'meta_input'  => [ Emails::EMAIL_CONFIG_NAME_META => 'receipt' ],
			]
		);
		wp_trash_post( $post_id );
		$this->assertSame( 'trash', get_post_status( $post_id ), 'Precondition: the email post is trashed.' );

		$request = new WP_REST_Request( 'DELETE' );
		$request->set_param( 'id', $post_id );
		$response = Emails_Section::api_reset_email( $request );

		$this->assertNotInstanceOf( WP_Error::class, $response, 'Resetting an already-trashed email must not error.' );
	}

	/**
	 * REST_BASE is hardcoded to 'wizard/newspack-settings/emails'.
	 *
	 * The class docblock says "Do NOT change" — this test locks the
	 * invariant. If anyone flips REST_BASE to follow the wizard's new
	 * home, every external caller and the frontend's hardcoded API
	 * paths would break.
	 */
	public function test_rest_base_is_pinned_to_newspack_settings() {
		$this->assertSame( 'wizard/newspack-settings/emails', Emails_Section::REST_BASE );
	}

	/**
	 * No Emails_Section REST route interpolates $wizard_slug.
	 *
	 * NPPD-1538 migrated GET /emails and POST /emails/{id}/toggle to use
	 * self::REST_BASE (slice 2a had registered them with $wizard_slug
	 * directly). This test verifies by constructing a fresh
	 * `WP_REST_Server`, calling `register_rest_routes()` on a freshly-
	 * instantiated `Emails_Section` (with a deliberately-mismatched
	 * wizard_slug to surface any remaining `$wizard_slug` interpolation),
	 * and inspecting the resulting route table. Both the listing endpoint
	 * AND the toggle endpoint must register under `wizard/newspack-settings`
	 * regardless of what `$wizard_slug` is set to — that's the invariant.
	 *
	 * Forces `newspack_woocommerce_active` to true so the toggle endpoint
	 * (gated by `is_woocommerce_active()` in `register_rest_routes`) is
	 * exercised — a regression that reintroduced `$wizard_slug` interpolation
	 * on the toggle endpoint would otherwise slip through in WC-inactive
	 * test environments.
	 *
	 * Uses the in-test route-registration pattern established at
	 * `test_post_settings_args_use_text_field_sanitizer_for_emails` —
	 * a fresh `WP_REST_Server` avoids depending on global REST state
	 * and on whether `rest_api_init` fired with the section attached.
	 */
	public function test_no_wizard_slug_in_rest_route_registration() {
		add_filter( 'newspack_woocommerce_active', '__return_true' );

		// register_rest_route normally complains when called outside
		// `rest_api_init`; we're calling register_rest_routes() directly
		// to introspect what would be registered through the normal
		// lifecycle. Acknowledge the notice rather than fire the whole
		// action chain.
		$this->setExpectedIncorrectUsage( 'register_rest_route' );

		global $wp_rest_server;
		$prev_server    = $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		// Deliberately pass a different wizard_slug than the section's
		// natural home. If any route registration still interpolates
		// $this->wizard_slug, it would land under
		// `wizard/regression-canary-slug/...` and the negative
		// assertion below would fire.
		( new Emails_Section( [ 'wizard_slug' => 'regression-canary-slug' ] ) )->register_rest_routes();
		$routes         = $wp_rest_server->get_routes();
		$wp_rest_server = $prev_server;

		remove_filter( 'newspack_woocommerce_active', '__return_true' );

		// Positive: both the listing endpoint AND the toggle endpoint
		// (WC-active branch) must register under REST_BASE.
		$this->assertArrayHasKey(
			'/newspack/v1/wizard/newspack-settings/emails',
			$routes,
			'Pinned listing endpoint must be registered under REST_BASE.'
		);
		$toggle_pattern    = '#^/newspack/v1/wizard/newspack-settings/emails/\(\?P<id>#';
		$matching_toggle   = array_filter(
			array_keys( $routes ),
			fn( $route ) => preg_match( $toggle_pattern, $route )
		);
		$this->assertNotEmpty(
			$matching_toggle,
			'Pinned toggle endpoint must be registered under REST_BASE when WC is active.'
		);

		// Negative: no Emails_Section route may live under the
		// canary slug — would only happen if a registration still
		// interpolates $this->wizard_slug instead of self::REST_BASE.
		$leak_pattern = '#^/newspack/v1/wizard/regression-canary-slug/#';
		$leaks        = array_filter(
			array_keys( $routes ),
			fn( $route ) => preg_match( $leak_pattern, $route )
		);
		$this->assertEmpty(
			$leaks,
			'Emails_Section endpoints must not register under the wizard_slug namespace. Leaked routes: ' . implode( ', ', $leaks )
		);
	}

	/**
	 * Platform-scoped list (NPPD-1538): reader-revenue (commerce) emails
	 * appear only when Newspack is the reader-revenue platform. On RevEngine
	 * (nrh) and "Other", the list returns only auth/account emails — the
	 * commerce emails never fire there (off-site checkout / nothing sent
	 * through Newspack), so they're filtered out. The Emails tab itself stays
	 * available on every platform; only the list is scoped.
	 */
	public function test_email_list_scopes_reader_revenue_to_newspack_platform() {
		$prev_platform = \Newspack\Donations::get_platform_slug();
		try {
			\Newspack\Donations::set_platform_slug( 'wc' );
			$wc_chips = array_column( Emails_Section::api_get_email_settings()['newspack_emails'], 'chip' );
			$this->assertContains( 'reader-revenue', $wc_chips, 'Newspack platform must surface reader-revenue emails.' );

			foreach ( [ 'nrh', 'other' ] as $platform ) {
				\Newspack\Donations::set_platform_slug( $platform );
				$chips = array_column( Emails_Section::api_get_email_settings()['newspack_emails'], 'chip' );
				$this->assertNotContains(
					'reader-revenue',
					$chips,
					sprintf( '"%s" platform must NOT surface reader-revenue emails.', $platform )
				);
			}
		} finally {
			\Newspack\Donations::set_platform_slug( $prev_platform );
		}
	}
}
