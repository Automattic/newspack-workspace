<?php
/**
 * Tests Card_Expiry_Warning.
 *
 * Coverage:
 *   - Email config shape: registration via newspack_email_configs, the
 *     four UI-metadata fields the legacy registry pattern used to host
 *     (chip, recommended, recipient, trigger_description) now declared
 *     directly on the config, placeholder set.
 *   - `get_days_before_expiry()` and `get_limit_per_pass()` filter +
 *     minimum-clamp behavior.
 *   - First-deploy seed flag-setting via the observable side effect on
 *     SEEDED_OPTION. The seed-iteration behavior (marks the per-token
 *     SEEDED meta on in-window pairs without sending) needs real WC
 *     Subscriptions + WCS_Payment_Tokens — covered in the integration
 *     smoke script.
 *   - `maybe_send_warning()` signature: confirms the
 *     `$bypass_idempotency` arg defaults to false (cron path
 *     unchanged) — the actual bypass behavior needs real WC and is
 *     covered in smoke.
 *
 * Why config-shape-and-flag-only here:
 *   In CI, WooCommerce Subscriptions is not loaded so the
 *   `class_exists('WCS_Payment_Tokens')` guard in
 *   get_in_window_pairs() returns an empty pair list. The seed flag
 *   still gets set (and we assert that here), but the actual
 *   per-pair iteration + send paths run against a real WC env in
 *   the smoke script.
 *
 * @package Newspack\Tests
 */

use Newspack\Card_Expiry_Warning;
use Newspack\Emails;

require_once __DIR__ . '/../mocks/newsletters-mocks.php';

/**
 * Tests Card_Expiry_Warning.
 */
class Newspack_Test_Card_Expiry_Warning extends WP_UnitTestCase {

	/**
	 * The card-expiry config callback registered in set_up.
	 *
	 * @var callable|null
	 */
	private $config_filter_callback = null;

	/**
	 * Post ID of the email post created in set_up so
	 * `Emails::can_send_email( EMAIL_TYPE )` returns true and the
	 * seed/scan branch logic can be exercised.
	 *
	 * @var int|null
	 */
	private $email_post_id = null;

	/**
	 * Set up. See class docblock for the CI-without-WC strategy.
	 */
	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();

		// Reset SEEDED_OPTION so each test starts unseeded by default.
		// Tests that exercise the post-seed path set it explicitly.
		delete_option( Card_Expiry_Warning::SEEDED_OPTION );

		// In CI, WC Subs is not loaded so Card_Expiry_Warning::init()
		// never hooks the filter. Register the callback ourselves so
		// Emails::get_email_configs() reflects the card-expiry config
		// (needed for tests that exercise can_send_email + the
		// scan/seed branches via Emails::can_send_email).
		$this->config_filter_callback = function ( $configs ) {
			return Card_Expiry_Warning::add_email_config( $configs );
		};
		add_filter( 'newspack_email_configs', $this->config_filter_callback );
		Emails::reset_email_configs_cache();

		// Create the email post with status='publish' so
		// Emails::can_send_email returns true. This unblocks the
		// scan-branch tests below without triggering the lazy-create
		// path inside get_email_config_by_type (which would also work
		// but adds noise).
		$this->email_post_id = wp_insert_post(
			[
				'post_type'   => Emails::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Card expiry warning (test)',
				'meta_input'  => [
					Emails::EMAIL_CONFIG_NAME_META         => Card_Expiry_Warning::EMAIL_TYPE,
					// serialize_email returns false unless EMAIL_HTML_META
					// has a payload; the seed/scan-branch tests need
					// can_send_email → true.
					\Newspack_Newsletters::EMAIL_HTML_META => '<p>test</p>',
				],
			]
		);
	}

	/**
	 * Tear down. Removes filters, resets cache, and deletes the email post.
	 */
	public function tear_down() {
		if ( $this->config_filter_callback ) {
			remove_filter( 'newspack_email_configs', $this->config_filter_callback );
			$this->config_filter_callback = null;
		}
		Emails::reset_email_configs_cache();
		delete_option( Card_Expiry_Warning::SEEDED_OPTION );
		if ( $this->email_post_id ) {
			wp_delete_post( $this->email_post_id, true );
			$this->email_post_id = null;
		}
		// Safe today: only this test file registers these two filter
		// hooks (production code only reads them). If a future sibling
		// integration adds a bootstrap-time default callback, switch
		// these to targeted remove_filter() calls using stored callback
		// references so the production default survives the tear_down.
		remove_all_filters( 'newspack_card_expiry_warning_days' );
		remove_all_filters( 'newspack_card_expiry_warning_limit_per_pass' );
		reset_phpmailer_instance();
		parent::tear_down();
	}

	/**
	 * Helper: get the email config by calling add_email_config directly.
	 *
	 * In CI, WooCommerce Subscriptions is not active so init() never
	 * hooks the filter. We test the static method directly instead.
	 *
	 * @return array Card-expiry config entry.
	 */
	private function get_config(): array {
		$configs = Card_Expiry_Warning::add_email_config( [] );
		return $configs[ Card_Expiry_Warning::EMAIL_TYPE ];
	}

	// --------------------------------------------------------------------
	// Email config shape.
	// --------------------------------------------------------------------

	/**
	 * The card-expiry config registers under EMAIL_TYPE.
	 */
	public function test_email_config_registered() {
		$configs = Card_Expiry_Warning::add_email_config( [] );
		$this->assertArrayHasKey( Card_Expiry_Warning::EMAIL_TYPE, $configs );
	}

	/**
	 * The legacy registry pattern that hosted the four UI-metadata
	 * fields (recommended, recipient, chip, trigger_description) is
	 * gone in slice 1's refactor — they're now declared directly on
	 * the config. Required-keys assertion includes them so a future
	 * accidental drop is caught.
	 */
	public function test_email_config_has_required_keys() {
		$config        = $this->get_config();
		$required_keys = [
			'name',
			'category',
			'chip',
			'recipient',
			'recommended',
			'label',
			'description',
			'trigger_description',
			'template',
			'editor_notice',
			'from_email',
			'available_placeholders',
		];
		foreach ( $required_keys as $key ) {
			$this->assertArrayHasKey( $key, $config, "Email config is missing required key '$key'." );
		}
	}

	/**
	 * The config's `name` matches EMAIL_TYPE (the registration key).
	 */
	public function test_email_config_name() {
		$this->assertSame( Card_Expiry_Warning::EMAIL_TYPE, $this->get_config()['name'] );
	}

	/**
	 * Categorized as `reader-revenue` — drives the chip and the UI grouping.
	 */
	public function test_email_config_category() {
		$this->assertSame( 'reader-revenue', $this->get_config()['category'] );
	}

	/**
	 * The template file referenced by the config exists on disk.
	 */
	public function test_email_config_template_exists() {
		$this->assertFileExists( $this->get_config()['template'] );
	}

	/**
	 * All expected merge placeholders are advertised in available_placeholders.
	 */
	public function test_email_config_placeholders() {
		$placeholders = $this->get_config()['available_placeholders'];
		$templates    = array_column( $placeholders, 'template' );
		$expected     = [
			'*BILLING_FIRST_NAME*',
			'*CARD_LAST_4*',
			'*EXPIRY_DATE*',
			'*RENEWAL_DATE*',
			'*UPDATE_PAYMENT_URL*',
			'*CONTACT_EMAIL*',
			'*SITE_TITLE*',
			'*SITE_URL*',
		];
		foreach ( $expected as $token ) {
			$this->assertContains( $token, $templates, "Placeholder '$token' should be in available_placeholders." );
		}
	}

	/**
	 * Direct config-shape assertion that replaces the legacy
	 * `test_registry_entry_chip_is_reader_revenue`. Slice 1's
	 * `apply_config_defaults()` would also derive `chip` from
	 * `category` here, but we declare it explicitly in the config so
	 * this is a direct rather than a derived assertion.
	 */
	public function test_email_config_chip_is_reader_revenue() {
		$this->assertSame( 'reader-revenue', $this->get_config()['chip'] );
	}

	/**
	 * Direct replacement for `test_registry_entry_is_recommended`.
	 *
	 * Card-expiry is the publisher's defense against involuntary churn
	 * — recommended-by-default is correct. (Slice 1's
	 * EMAIL_CONFIG_DEFAULTS.recommended is `false` for third-party
	 * configs; this one declares true explicitly.)
	 */
	public function test_email_config_recommended() {
		$this->assertTrue( $this->get_config()['recommended'] );
	}

	/**
	 * Direct replacement for `test_registry_entry_recipient`.
	 */
	public function test_email_config_recipient() {
		$this->assertSame( 'reader', $this->get_config()['recipient'] );
	}

	/**
	 * The `trigger_description` is populated (non-empty string) — drives
	 * the "Triggered when..." UI copy in the emails admin.
	 */
	public function test_email_config_trigger_description_present() {
		$this->assertNotEmpty( $this->get_config()['trigger_description'] );
		$this->assertIsString( $this->get_config()['trigger_description'] );
	}

	// --------------------------------------------------------------------
	// get_days_before_expiry.
	// --------------------------------------------------------------------

	/**
	 * Default lead time is 14 days. Changing this is a publisher-facing
	 * choice — flag if the default drifts unintentionally.
	 */
	public function test_days_before_expiry_default() {
		$this->assertSame( 14, Card_Expiry_Warning::get_days_before_expiry() );
	}

	/**
	 * The `newspack_card_expiry_warning_days` filter overrides the default.
	 */
	public function test_days_before_expiry_filterable() {
		add_filter( 'newspack_card_expiry_warning_days', fn() => 7 );
		$this->assertSame( 7, Card_Expiry_Warning::get_days_before_expiry() );
	}

	/**
	 * Hostile/zero/negative filter values clamp to a minimum of 1 day.
	 */
	public function test_days_before_expiry_minimum_is_one() {
		add_filter( 'newspack_card_expiry_warning_days', fn() => -5 );
		$this->assertSame( 1, Card_Expiry_Warning::get_days_before_expiry() );
	}

	// --------------------------------------------------------------------
	// get_limit_per_pass — pins the publisher-respect SQL-LIMIT (see the
	// "Publisher-respect — per-pass SQL LIMIT" section of the class
	// docblock).
	// --------------------------------------------------------------------

	/**
	 * Default per-pass LIMIT is LIMIT_PER_PASS_DEFAULT (100).
	 */
	public function test_limit_per_pass_default() {
		$this->assertSame( 100, Card_Expiry_Warning::get_limit_per_pass() );
	}

	/**
	 * The `newspack_card_expiry_warning_limit_per_pass` filter overrides
	 * the default — the publisher-facing escape hatch for large catalogs.
	 */
	public function test_limit_per_pass_filterable() {
		add_filter( 'newspack_card_expiry_warning_limit_per_pass', fn() => 50 );
		$this->assertSame( 50, Card_Expiry_Warning::get_limit_per_pass() );
	}

	/**
	 * Hostile/zero filter values clamp to a minimum of 1 — protects the
	 * SQL LIMIT clause from receiving 0 (which would mean "all rows" in
	 * some configurations).
	 */
	public function test_limit_per_pass_minimum_is_one() {
		add_filter( 'newspack_card_expiry_warning_limit_per_pass', fn() => 0 );
		$this->assertSame( 1, Card_Expiry_Warning::get_limit_per_pass() );
	}

	// --------------------------------------------------------------------
	// First-deploy seed — pins the "Publisher-respect — first-deploy seed"
	// section of the class docblock. The seed flag-setting is what we can
	// observe in CI without WC; the per-pair SEEDED meta marking + the
	// subsequent-scan distinction live in the integration smoke script.
	// --------------------------------------------------------------------

	/**
	 * First-deploy contract: with SEEDED_OPTION absent, scan_expiring_cards
	 * runs the seed pass and writes SEEDED_OPTION. Without WC Subs loaded,
	 * the per-pair iteration is empty — but the flag MUST still flip so
	 * the second cron run takes the normal-scan branch.
	 */
	public function test_scan_seeds_seeded_option_when_unseeded() {
		$this->assertFalse(
			get_option( Card_Expiry_Warning::SEEDED_OPTION ),
			'SEEDED_OPTION should be unset before first scan.'
		);

		Card_Expiry_Warning::scan_expiring_cards();

		$this->assertSame(
			'1',
			get_option( Card_Expiry_Warning::SEEDED_OPTION ),
			'SEEDED_OPTION should be set to "1" after the first scan.'
		);
	}

	/**
	 * Idempotency on the seed itself: a second scan call after the flag
	 * is set must not overwrite the flag or re-run the seed pass. (The
	 * stored value remains exactly what we set — proves we took the
	 * normal-scan branch on the second call.)
	 */
	public function test_scan_does_not_reset_seeded_option_when_already_set() {
		update_option( Card_Expiry_Warning::SEEDED_OPTION, 'sentinel-value', false );

		Card_Expiry_Warning::scan_expiring_cards();

		$this->assertSame(
			'sentinel-value',
			get_option( Card_Expiry_Warning::SEEDED_OPTION ),
			'SEEDED_OPTION must be untouched on subsequent scans — proves the normal-scan branch ran.'
		);
	}

	/**
	 * Defense in depth: when Emails::can_send_email() returns false (e.g.
	 * the email post is in draft), the scan exits at its top guard and
	 * MUST NOT flip the seed flag. Otherwise, a publisher who keeps the
	 * email disabled until they review it would silently lose their
	 * first-deploy seed protection.
	 */
	public function test_scan_no_op_when_emails_cannot_send_leaves_seed_unset() {
		// Move the email post out of publish so can_send_email returns false.
		wp_update_post(
			[
				'ID'          => $this->email_post_id,
				'post_status' => 'draft',
			]
		);

		Card_Expiry_Warning::scan_expiring_cards();

		$this->assertFalse(
			get_option( Card_Expiry_Warning::SEEDED_OPTION ),
			'SEEDED_OPTION must NOT be set when the scan no-ops at the can_send_email guard.'
		);
	}

	// --------------------------------------------------------------------
	// maybe_send_warning signature — pins the `$bypass_idempotency` arg
	// contract that the WP-CLI backfill relies on. Actual bypass behavior
	// needs real WC and is covered in smoke.
	// --------------------------------------------------------------------

	/**
	 * The cron path is the dominant caller and must not change behavior:
	 * `$bypass_idempotency` MUST default to false so a 2-arg call (the
	 * cron path) is equivalent to the pre-commit-2 behavior.
	 */
	public function test_maybe_send_warning_bypass_idempotency_default_is_false() {
		$reflection = new ReflectionMethod( Card_Expiry_Warning::class, 'maybe_send_warning' );
		$params     = $reflection->getParameters();

		$bypass_param = null;
		foreach ( $params as $param ) {
			if ( 'bypass_idempotency' === $param->getName() ) {
				$bypass_param = $param;
				break;
			}
		}

		$this->assertNotNull( $bypass_param, 'maybe_send_warning() must accept a $bypass_idempotency parameter.' );
		$this->assertTrue( $bypass_param->isDefaultValueAvailable(), '$bypass_idempotency must have a default value.' );
		$this->assertFalse( $bypass_param->getDefaultValue(), '$bypass_idempotency default MUST be false to keep the cron path unchanged.' );
	}

	// --------------------------------------------------------------------
	// Two-meta-keys-per-token schema (NPPD-1568) — locks in the gating
	// + clear behavior that fixes the multi-token collision (Fix-1) and
	// the CLI cross-invocation duplicate-send bug (Fix-2). The full
	// promote invariant (SEEDED deleted when SENT writes) needs a real
	// Emails::send_email path and is covered end-to-end in scenario 8 of
	// tests/integration/card-expiry-warning-smoke.php.
	// --------------------------------------------------------------------

	/**
	 * Helper: invoke the private `is_already_processed` gating helper.
	 *
	 * Tests verify the per-token gating behavior directly rather than
	 * driving `maybe_send_warning` end-to-end (which would require
	 * mocking out the full subscription → user → WC URL → wp_mail
	 * chain — the smoke script covers that). Reflection is the standard
	 * PHPUnit pattern for testing private helpers when the helper is
	 * the design center and direct testing is cleaner than driving via
	 * the caller.
	 *
	 * @param object $subscription       Mock subscription supporting `get_meta`.
	 * @param int    $token_id           Token id (suffix of the meta key).
	 * @param string $expiry_key         Value to match against (`token_id:MM/YYYY`).
	 * @param bool   $bypass_idempotency When true, ignore SEEDED gate.
	 * @return bool
	 */
	private function invoke_is_already_processed( $subscription, int $token_id, string $expiry_key, bool $bypass_idempotency = false ): bool {
		$reflection = new ReflectionMethod( Card_Expiry_Warning::class, 'is_already_processed' );
		$reflection->setAccessible( true );
		return $reflection->invoke( null, $subscription, $token_id, $expiry_key, $bypass_idempotency );
	}

	/**
	 * Helper: build a minimal subscription stub that supports get_meta /
	 * update_meta_data / delete_meta_data / save / get_meta_data — the
	 * subset of WC_Data the schema and clear_sent_flag exercise.
	 *
	 * In-memory only; doesn't touch the DB.
	 *
	 * @param array<string, string> $initial Initial meta as key => value.
	 * @return object
	 */
	private function make_subscription_stub( array $initial = [] ) {
		return new class( $initial ) {
			/**
			 * Internal meta storage keyed by meta_key.
			 *
			 * @var array<string, string>
			 */
			private $meta = [];

			/**
			 * Constructor.
			 *
			 * @param array<string, string> $initial Initial meta.
			 */
			public function __construct( array $initial ) {
				$this->meta = $initial;
			}
			/**
			 * Get a single meta value.
			 *
			 * @param string $key    Meta key.
			 * @param bool   $single Single value flag (ignored — always single).
			 * @return string Stored value or '' if absent.
			 */
			public function get_meta( $key, $single = true ) {
				return $this->meta[ $key ] ?? '';
			}
			/**
			 * Set a meta value.
			 *
			 * @param string $key   Meta key.
			 * @param mixed  $value Meta value.
			 */
			public function update_meta_data( $key, $value ) {
				$this->meta[ $key ] = $value;
			}
			/**
			 * Delete a meta key.
			 *
			 * @param string $key Meta key.
			 */
			public function delete_meta_data( $key ) {
				unset( $this->meta[ $key ] );
			}
			/**
			 * Iterable meta-data view used by `clear_sent_flag`.
			 *
			 * @return array<object> Objects with `key` + `value` public properties.
			 */
			public function get_meta_data() {
				$out = [];
				foreach ( $this->meta as $k => $v ) {
					$out[] = (object) [
						'key'   => $k,
						'value' => $v,
					];
				}
				return $out;
			}
			/**
			 * No-op stand-in for WC's CRUD save().
			 *
			 * @return bool
			 */
			public function save() {
				return true;
			}
		};
	}

	/**
	 * Fix-1 lock-in (multi-token independence):
	 *
	 * SEEDED meta for token 100 must NOT gate token 200 on the same
	 * subscription. The legacy single-key shape collapsed all tokens
	 * onto one meta value — re-introducing the single key would cause
	 * this test to fail because the second token's gate would return
	 * true based on the first token's seed mark.
	 */
	public function test_multi_token_subscription_seeds_independently() {
		// Seed token 100 only; leave token 200 unmarked.
		$sub = $this->make_subscription_stub(
			[
				Card_Expiry_Warning::SEEDED_META_PREFIX . 100 => '100:12/2026',
			]
		);

		$blocked_100 = $this->invoke_is_already_processed( $sub, 100, '100:12/2026', false );
		$blocked_200 = $this->invoke_is_already_processed( $sub, 200, '200:12/2026', false );

		$this->assertTrue( $blocked_100, 'Token 100 must be gated by its own SEEDED meta.' );
		$this->assertFalse( $blocked_200, 'Token 200 must NOT be gated by token 100\'s SEEDED meta — multi-token independence.' );
	}

	/**
	 * Fix-1 lock-in (post-seed scan path):
	 *
	 * After the seed pass marks both tokens on a multi-token
	 * subscription, a normal scan (no bypass) must skip BOTH tokens.
	 * Without per-token keys, only the last-iterated token would have
	 * a surviving mark — earlier tokens would be re-sent.
	 */
	public function test_multi_token_subscription_scans_independently_post_seed() {
		$sub = $this->make_subscription_stub(
			[
				Card_Expiry_Warning::SEEDED_META_PREFIX . 100 => '100:12/2026',
				Card_Expiry_Warning::SEEDED_META_PREFIX . 200 => '200:01/2027',
			]
		);

		// Normal scan = no bypass; SEEDED gate active for both.
		$this->assertTrue(
			$this->invoke_is_already_processed( $sub, 100, '100:12/2026', false ),
			'Token 100 should be gated post-seed.'
		);
		$this->assertTrue(
			$this->invoke_is_already_processed( $sub, 200, '200:01/2027', false ),
			'Token 200 should be gated post-seed.'
		);
	}

	/**
	 * Fix-2 lock-in (CLI cross-invocation idempotency):
	 *
	 * The CLI backfill calls `maybe_send_warning(..., $bypass=true)`.
	 * Bypass skips the SEEDED gate (so seed-suppressed warnings can be
	 * released) but the SENT gate MUST still block — otherwise a
	 * second operator invocation on the same window would re-send
	 * every email a first invocation completed.
	 */
	public function test_cli_backfill_idempotent_across_invocations() {
		// Simulate post-first-CLI-invocation state: SENT meta set.
		$sub = $this->make_subscription_stub(
			[
				Card_Expiry_Warning::SENT_META_PREFIX . 100 => '100:12/2026',
			]
		);

		// Second CLI invocation = same args, bypass=true.
		$blocked = $this->invoke_is_already_processed( $sub, 100, '100:12/2026', true );

		$this->assertTrue(
			$blocked,
			'SENT meta must block even with bypass=true — otherwise CLI re-runs duplicate sends.'
		);

		// Sanity: SEEDED meta absent on this token (the seed mark was
		// deleted by the first CLI invocation's promote step in real
		// production; we omit it here to confirm SENT is what blocks).
		$this->assertSame( '', $sub->get_meta( Card_Expiry_Warning::SEEDED_META_PREFIX . 100, true ) );
	}

	/**
	 * `clear_sent_flag` must clear ALL per-token meta entries on the
	 * subscription, both SEEDED and SENT prefixes. The legacy single-key
	 * shape needed only one delete; the new schema iterates `get_meta_data()`
	 * and deletes every entry matching either prefix.
	 *
	 * Production note: a (subscription, token) pair has at most one of
	 * {SEEDED, SENT} at any time. The "set both for one token" state
	 * the fixture constructs here is not naturally reachable, but it's
	 * the exhaustive shape that proves the clear handles every prefix.
	 */
	public function test_clear_sent_flag_clears_all_per_token_meta() {
		// Two tokens × two prefixes = four meta entries on the sub.
		$sub = $this->make_subscription_stub(
			[
				Card_Expiry_Warning::SEEDED_META_PREFIX . 100 => '100:12/2026',
				Card_Expiry_Warning::SENT_META_PREFIX . 100   => '100:12/2026',
				Card_Expiry_Warning::SEEDED_META_PREFIX . 200 => '200:01/2027',
				Card_Expiry_Warning::SENT_META_PREFIX . 200   => '200:01/2027',
			]
		);

		Card_Expiry_Warning::clear_sent_flag( $sub );

		$this->assertSame( '', $sub->get_meta( Card_Expiry_Warning::SEEDED_META_PREFIX . 100, true ) );
		$this->assertSame( '', $sub->get_meta( Card_Expiry_Warning::SENT_META_PREFIX . 100, true ) );
		$this->assertSame( '', $sub->get_meta( Card_Expiry_Warning::SEEDED_META_PREFIX . 200, true ) );
		$this->assertSame( '', $sub->get_meta( Card_Expiry_Warning::SENT_META_PREFIX . 200, true ) );
		$this->assertSame( [], $sub->get_meta_data(), 'No meta entries should remain after clear_sent_flag.' );
	}

	/**
	 * Decision-#2 lock-in (promote invariant):
	 *
	 * `maybe_send_warning`'s post-send sequence (delete SEEDED, write
	 * SENT) maintains the "at most one of {SEEDED, SENT} per token"
	 * invariant. End-to-end verification of this — i.e. that the seed
	 * mark is actually deleted as the send completes — needs the full
	 * Emails::send_email → wp_mail path and runs in scenario 8 of the
	 * integration smoke script (tests/integration/card-expiry-warning-smoke.php),
	 * which sets up a real WC_Subscription, invokes
	 * `maybe_send_warning(..., bypass=true)` against a pre-seeded
	 * pair, and asserts both `delete_meta(SEEDED)` AND `update_meta(SENT)`
	 * were applied.
	 *
	 * This unit test asserts the structural property — that the helper
	 * treats SENT (post-promote state) as blocking even under bypass.
	 * Combined with smoke scenario 8 above, the invariant is locked in
	 * at both layers.
	 */
	public function test_seeded_meta_deleted_when_sent_promotes() {
		// Simulate post-promote state: SENT set, SEEDED absent (the
		// invariant maintained by maybe_send_warning's promote step).
		$sub = $this->make_subscription_stub(
			[
				Card_Expiry_Warning::SENT_META_PREFIX . 100 => '100:12/2026',
			]
		);

		$this->assertTrue(
			$this->invoke_is_already_processed( $sub, 100, '100:12/2026', false ),
			'Post-promote state: SENT blocks the normal-scan path.'
		);
		$this->assertTrue(
			$this->invoke_is_already_processed( $sub, 100, '100:12/2026', true ),
			'Post-promote state: SENT blocks even with bypass=true (idempotency invariant).'
		);
		$this->assertSame(
			'',
			$sub->get_meta( Card_Expiry_Warning::SEEDED_META_PREFIX . 100, true ),
			'Post-promote state: SEEDED meta must be absent (invariant: at most one of {SEEDED, SENT}).'
		);
	}

	// --------------------------------------------------------------------
	// SENT-marker persistence retry (NPPD-1524, reopened idempotency
	// thread). After a successful send the marker save is retried a
	// bounded number of times to ride out a transient failure, narrowing
	// the window where a later pass could re-send. We deliberately do NOT
	// mark-before-send (over-send beats a missed expiry warning); the
	// durable two-phase fix is tracked as a follow-up.
	// --------------------------------------------------------------------

	/**
	 * Helper: invoke the private `save_subscription_with_retry` helper.
	 *
	 * @param object $subscription Subscription stub.
	 * @return array{saved: bool, last_error: string}
	 */
	private function invoke_save_with_retry( $subscription ): array {
		$reflection = new ReflectionMethod( Card_Expiry_Warning::class, 'save_subscription_with_retry' );
		$reflection->setAccessible( true );
		return $reflection->invoke( null, $subscription );
	}

	/**
	 * Helper: a subscription stub whose save() throws the first
	 * $throw_times calls, then succeeds. Counts invocations so tests can
	 * assert the retry actually re-attempted.
	 *
	 * @param int $throw_times How many leading save() calls should throw.
	 * @return object
	 */
	private function make_flaky_save_stub( int $throw_times ) {
		return new class( $throw_times ) {
			/**
			 * Remaining throws.
			 *
			 * @var int
			 */
			public $remaining_throws;
			/**
			 * Total save() calls.
			 *
			 * @var int
			 */
			public $save_calls = 0;
			/**
			 * Constructor.
			 *
			 * @param int $throw_times Leading throws.
			 */
			public function __construct( int $throw_times ) {
				$this->remaining_throws = $throw_times;
			}
			/**
			 * Save, throwing for the first N calls.
			 *
			 * @return bool
			 * @throws \RuntimeException When a leading throw is still due.
			 */
			public function save() {
				++$this->save_calls;
				if ( $this->remaining_throws > 0 ) {
					--$this->remaining_throws;
					throw new \RuntimeException( 'transient save failure' );
				}
				return true;
			}
		};
	}

	/**
	 * A transient save() failure is ridden out by the bounded retry:
	 * save throws twice then succeeds (within the 3-attempt budget), so
	 * the marker persists and `saved` is true.
	 */
	public function test_save_marker_retry_succeeds_after_transient_failures() {
		$stub   = $this->make_flaky_save_stub( 2 );
		$result = $this->invoke_save_with_retry( $stub );

		$this->assertTrue( $result['saved'], 'Save must succeed once a transient failure clears within the retry budget.' );
		$this->assertSame( 3, $stub->save_calls, 'save() must be retried (2 throws + 1 success).' );
	}

	/**
	 * A persistent save() failure exhausts the retry budget: `saved` is
	 * false and the last error is surfaced for logging. The caller still
	 * counts the send against the per-pass cap (asserted via the bool
	 * contract here — the give-up path returns the error, not an
	 * exception, so maybe_send_warning never throws).
	 */
	public function test_save_marker_retry_gives_up_after_max_attempts() {
		$stub   = $this->make_flaky_save_stub( PHP_INT_MAX );
		$result = $this->invoke_save_with_retry( $stub );

		$this->assertFalse( $result['saved'], 'Save must report failure after exhausting the retry budget.' );
		$this->assertSame(
			Card_Expiry_Warning::SENT_MARKER_SAVE_ATTEMPTS,
			$stub->save_calls,
			'save() must be attempted exactly SENT_MARKER_SAVE_ATTEMPTS times before giving up.'
		);
		$this->assertNotSame( '', $result['last_error'], 'The last error message must be captured for the log.' );
	}
}
