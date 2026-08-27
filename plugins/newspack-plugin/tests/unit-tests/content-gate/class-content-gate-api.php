<?php
/**
 * Tests for Content_Gate_API access rule sanitization.
 *
 * @package Newspack\Tests\Content_Gate
 */

use Newspack\Access_Rules;
use Newspack\Content_Gate;
use Newspack\Content_Gate_API;
use Newspack\Institution;

/**
 * Test Content_Gate_API::sanitize_access_rule() and the `has_options`
 * registration discriminator it branches on.
 *
 * @group Access_Rules
 */
class Newspack_Test_Content_Gate_API extends WP_UnitTestCase {

	/**
	 * Snapshot of the rule registry, restored after each test so rules
	 * registered here don't leak into other test classes (the registry is a
	 * static property and PHPUnit runs the suite in one process).
	 *
	 * @var array
	 */
	private $registered_rules_snapshot;

	/**
	 * Setup.
	 */
	public function set_up() {
		parent::set_up();
		$this->registered_rules_snapshot = Access_Rules::get_registered_rules();
	}

	/**
	 * Teardown.
	 */
	public function tear_down() {
		$registry_property = new ReflectionProperty( Access_Rules::class, 'rules' );
		$registry_property->setValue( null, $this->registered_rules_snapshot );
		parent::tear_down();
	}

	/**
	 * The `has_options` discriminator derives from what the registration
	 * declared: a callable or populated options source makes the rule
	 * options-backed; an explicitly empty list or no `options` key at all keeps
	 * it free-text (e.g. a promoted ESP field always passes `options`, empty for
	 * plain string fields).
	 */
	public function test_has_options_derives_from_the_declared_options_source() {
		$declared_options_per_rule = [
			'nppd2143_callable_options' => [ 'options' => [ __CLASS__, 'empty_options_source' ] ],
			'nppd2143_static_options'   => [
				'options' => [
					[
						'value' => 1,
						'label' => 'One',
					],
				],
			],
			'nppd2143_empty_options'    => [ 'options' => [] ],
			'nppd2143_no_options'       => [],
			// The one shape the two discriminators disagree on: `Promoted_Fields`
			// registers this for a provider dropdown whose choices have not been
			// pulled yet, and reading `options` instead would call it free text.
			'nppd2143_declared_options' => [
				'options'     => [],
				'has_options' => true,
			],
		];
		foreach ( $declared_options_per_rule as $rule_id => $config ) {
			Access_Rules::register_rule(
				array_merge(
					$config,
					[
						'id'       => $rule_id,
						'callback' => '__return_false',
					]
				)
			);
		}

		$registered = Access_Rules::get_registered_rules();
		$this->assertTrue( $registered['nppd2143_callable_options']['has_options'], 'A callable options source is options-backed even if it resolves empty.' );
		$this->assertTrue( $registered['nppd2143_static_options']['has_options'], 'A populated options list is options-backed.' );
		$this->assertFalse( $registered['nppd2143_empty_options']['has_options'], 'An explicitly empty options list stays free-text.' );
		$this->assertFalse( $registered['nppd2143_no_options']['has_options'], 'No options declaration stays free-text.' );

		// The sanitizer follows the discriminator: array values only for an options-backed rule.
		$rejected = Content_Gate_API::sanitize_access_rule(
			[
				'slug'  => 'nppd2143_callable_options',
				'value' => 'free text',
			]
		);
		$this->assertWPError( $rejected, 'A string value on an options-backed rule must be rejected.' );

		// And free text for a rule whose declared options list is empty.
		$sanitized = Content_Gate_API::sanitize_access_rule(
			[
				'slug'  => 'nppd2143_empty_options',
				'value' => 'free text',
			]
		);
		$this->assertSame( 'free text', $sanitized['value'], 'An empty declared options list keeps the free-text value.' );

		// A rule that declares itself options-backed is one, whatever its list holds today.
		$this->assertTrue( $registered['nppd2143_declared_options']['has_options'], 'A declared has_options survives an empty resolved list.' );
		$this->assertWPError(
			Content_Gate_API::sanitize_access_rule(
				[
					'slug'  => 'nppd2143_declared_options',
					'value' => 'free text',
				]
			),
			'A string value must be rejected on a rule that declares itself options-backed, even with no options resolved.'
		);
	}

	/**
	 * An options source that resolves to no entries, for the callable case above.
	 *
	 * @return array
	 */
	public static function empty_options_source() {
		return [];
	}

	/**
	 * An options-backed rule must reject a non-array value even when its option
	 * list is empty — the empty list must not degrade the rule to free text.
	 * On this bare test site no institutions exist and WooCommerce is absent,
	 * so both options-backed rules resolve to an empty option list.
	 */
	public function test_options_backed_rule_requires_array_value_even_with_no_options() {
		$this->assertEmpty( Institution::get_options(), 'Premise: no institutions exist.' );
		$this->assertEmpty( Access_Rules::get_access_rules()['subscription']['options'], 'Premise: no subscription products exist.' );

		foreach ( [ 'institution', 'subscription' ] as $options_backed_slug ) {
			$sanitized = Content_Gate_API::sanitize_access_rule(
				[
					'slug'  => $options_backed_slug,
					'value' => 'Springfield University',
				]
			);
			$this->assertWPError( $sanitized, "A string value for the '{$options_backed_slug}' rule must be rejected." );
			$this->assertSame( 'invalid_access_rule_value', $sanitized->get_error_code() );
		}
	}

	/**
	 * An options-backed rule accepts an array value regardless of whether the
	 * option list currently resolves any entries.
	 */
	public function test_options_backed_rule_accepts_array_value() {
		$sanitized = Content_Gate_API::sanitize_access_rule(
			[
				'slug'  => 'institution',
				'value' => [ '12', 34 ],
			]
		);
		$this->assertSame(
			[
				'slug'  => 'institution',
				'value' => [ 12, 34 ],
			],
			$sanitized
		);
	}

	/**
	 * A rule that declares no options is genuinely free-text and keeps its
	 * string value.
	 */
	public function test_free_text_rule_keeps_string_value() {
		$sanitized = Content_Gate_API::sanitize_access_rule(
			[
				'slug'  => 'email_domain',
				'value' => 'example.com,another.com',
			]
		);
		$this->assertSame(
			[
				'slug'  => 'email_domain',
				'value' => 'example.com,another.com',
			],
			$sanitized
		);
	}

	/**
	 * The mirror shape check: a free-text rule must reject a populated
	 * non-scalar value — `sanitize_text_field()` would collapse an array to '',
	 * which free-text rules read as "no constraint".
	 */
	public function test_free_text_rule_rejects_non_scalar_value() {
		$sanitized = Content_Gate_API::sanitize_access_rule(
			[
				'slug'  => 'email_domain',
				'value' => [ 'example.com' ],
			]
		);
		$this->assertWPError( $sanitized );
		$this->assertSame( 'invalid_access_rule_value', $sanitized->get_error_code() );
	}

	/**
	 * An invalid value fails the whole save — sanitize_gate() propagates the
	 * error instead of silently dropping the rule. The group's other rule is
	 * valid, and saving it on its own would drop a condition from an AND group
	 * and loosen the gate.
	 */
	public function test_invalid_access_rule_value_fails_the_gate_save() {
		$sanitized_gate = Content_Gate_API::sanitize_gate(
			[
				'title'         => 'Legacy gate',
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => 'email_domain',
								'value' => 'example.com',
							],
							[
								'slug'  => 'institution',
								'value' => 'Springfield University',
							],
						],
					],
				],
			]
		);
		$this->assertWPError( $sanitized_gate );
		$this->assertSame( 'invalid_access_rule_value', $sanitized_gate->get_error_code() );
	}

	/**
	 * An options-backed value whose elements all sanitize away must not save as an
	 * empty list — that reads as "no constraint" and grants access. Element values
	 * that are merely falsy (`0`, `'0'`) are legitimate option values and survive.
	 */
	public function test_options_backed_values_that_sanitize_away_are_rejected() {
		$values_that_sanitize_away = [
			'an empty string' => [ '' ],
			'a nested array'  => [ [ 'Springfield University' ] ],
			'boolean false'   => [ false ],
		];
		foreach ( $values_that_sanitize_away as $description => $value ) {
			$sanitized = Content_Gate_API::sanitize_access_rule(
				[
					'slug'  => 'institution',
					'value' => $value,
				]
			);
			$this->assertWPError( $sanitized, "A populated value made only of {$description} must be rejected." );
			$this->assertSame( 'invalid_access_rule_value', $sanitized->get_error_code() );
		}

		$sanitized = Content_Gate_API::sanitize_access_rule(
			[
				'slug'  => 'institution',
				'value' => [ '0', 0 ],
			]
		);
		$this->assertSame( [ 0, 0 ], $sanitized['value'], 'A zero option value is a value, not an absent one.' );
	}

	/**
	 * `has_options` is the discriminator both pickers branch on, so it has to
	 * survive the trip to the client — where the PHP callables must not.
	 */
	public function test_has_options_reaches_the_client_payload() {
		$client_rules = Access_Rules::get_access_rules_for_client();

		foreach ( $client_rules as $slug => $rule ) {
			$this->assertArrayHasKey( 'has_options', $rule, "The '{$slug}' rule must tell the client whether it is options-backed." );
			$this->assertArrayNotHasKey( 'callback', $rule, "The '{$slug}' rule must not ship its PHP callback to the client." );
		}
		$this->assertTrue( $client_rules['institution']['has_options'] );
		$this->assertFalse( $client_rules['email_domain']['has_options'] );
	}

	/**
	 * Dropping unknown rules is safe until nothing is left: an empty rule set
	 * grants access to everyone, so that save fails instead.
	 */
	public function test_gate_save_fails_when_dropping_unknown_rules_leaves_nothing() {
		$sanitized_gate = Content_Gate_API::sanitize_gate(
			[
				'custom_access' => [
					'access_rules' => [
						[
							[
								'slug'  => 'rule_from_deactivated_integration',
								'value' => [ 42 ],
							],
						],
					],
				],
			]
		);
		$this->assertWPError( $sanitized_gate );
		$this->assertSame( 'invalid_access_rules', $sanitized_gate->get_error_code() );
	}

	/**
	 * A rule with an unknown slug (e.g. from a since-deactivated integration) is
	 * still dropped rather than failing the save.
	 */
	public function test_unknown_rule_slug_is_dropped_not_fatal() {
		$sanitized_gate = Content_Gate_API::sanitize_gate(
			[
				'custom_access' => [
					'access_rules' => [
						[
							[
								'slug'  => 'rule_from_deactivated_integration',
								'value' => 'anything',
							],
						],
						[
							[
								'slug'  => 'email_domain',
								'value' => 'example.com',
							],
						],
					],
				],
			]
		);
		$this->assertSame(
			[
				[
					[
						'slug'  => 'email_domain',
						'value' => 'example.com',
					],
				],
			],
			$sanitized_gate['custom_access']['access_rules']
		);
	}

	/**
	 * Create a published gate holding an institution rule whose stored value is a
	 * legacy free-text string, as a site that saved one before the value shape was
	 * enforced still holds today.
	 *
	 * @return int The gate ID.
	 */
	private function create_gate_with_invalid_stored_rule() {
		$gate_id = Content_Gate::create_gate( [ 'title' => 'Legacy gate' ] );
		Content_Gate::update_custom_access_settings(
			$gate_id,
			[
				'active'       => true,
				'access_rules' => [
					[
						[
							'slug'  => 'institution',
							'value' => 'Springfield University',
						],
					],
				],
			]
		);
		return $gate_id;
	}

	/**
	 * The request WP hands to the `sanitize_callback`, carrying the gate ID from
	 * the route.
	 *
	 * @param int      $gate_id      The gate ID in the route.
	 * @param int|null $body_gate_id Optional. A contradicting gate ID in the request body.
	 *
	 * @return WP_REST_Request
	 */
	private function gate_update_request( $gate_id, $body_gate_id = null ) {
		$request = new WP_REST_Request( 'POST', '/newspack/v1/wizard/audience-content-gates/' . $gate_id );
		// Where WP itself puts the route's captures, and the only source the body
		// cannot shadow.
		$request->set_url_params( [ 'id' => $gate_id ] );
		if ( null !== $body_gate_id ) {
			$request->set_body_params( [ 'id' => $body_gate_id ] );
		}
		return $request;
	}

	/**
	 * Create a gate holding an institution rule with nothing selected: a
	 * well-formed value that `Institution::evaluate()` reads as "no constraint",
	 * so the rule grants access to every reader.
	 *
	 * @param bool  $active    Whether custom access is switched on.
	 * @param array $selection The institution IDs the rule names.
	 *
	 * @return int The gate ID.
	 */
	private function create_gate_with_stored_institution_rule( $active = true, $selection = [] ) {
		$gate_id = Content_Gate::create_gate( [ 'title' => 'Half-configured gate' ] );
		Content_Gate::update_custom_access_settings(
			$gate_id,
			[
				'active'       => $active,
				'access_rules' => [
					[
						[
							'slug'  => 'institution',
							'value' => $selection,
						],
					],
				],
			]
		);
		return $gate_id;
	}

	/**
	 * A gate whose stored rules are already invalid has to stay switchable. The
	 * gates list turns a gate off by POSTing back the whole gate it just read, so
	 * rejecting the value the operator never touched would lock the gate on —
	 * exactly when they are trying to switch off one that walls off paying
	 * readers. Turning it back on is the opposite case: that save puts a gate that
	 * denies every reader live, so it has to fix the value first.
	 */
	public function test_an_invalid_stored_value_can_be_saved_back_only_by_a_save_that_turns_the_gate_off() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$gate_id     = $this->create_gate_with_invalid_stored_rule();
		$stored_gate = Content_Gate::get_gate( $gate_id );
		$request     = $this->gate_update_request( $gate_id );

		$disabled = Content_Gate_API::sanitize_gate( array_merge( $stored_gate, [ 'status' => 'draft' ] ), $request );
		$this->assertNotWPError( $disabled, 'Saving back an untouched invalid value must not block the operator.' );
		$this->assertSame( 'draft', $disabled['status'] );
		$this->assertArrayNotHasKey( 'access_rules', $disabled['custom_access'], 'The stored rules are left as they are rather than rewritten.' );

		$this->assertWPError(
			Content_Gate_API::sanitize_gate( array_merge( $stored_gate, [ 'status' => 'publish' ] ), $request ),
			'The same payload that publishes the gate must not go through: it would put a gate denying every reader live.'
		);

		$changed_gate = array_merge( $stored_gate, [ 'status' => 'draft' ] );
		$changed_gate['custom_access']['access_rules'][0][0]['value'] = 'Another University';
		$this->assertWPError(
			Content_Gate_API::sanitize_gate( $changed_gate, $request ),
			'Changing the value is still a new invalid value, and still fails the save.'
		);
	}

	/**
	 * Two reads that must not be driven by the request body: the stored rules are
	 * the ones the gate in the route holds, and the comparison is only made for a
	 * caller who could save the gate anyway — sanitization runs ahead of the
	 * route's `permission_callback`, so nothing else has checked yet.
	 */
	public function test_stored_rules_are_read_from_the_route_gate_and_only_for_a_caller_who_could_save_it() {
		$admin_id    = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$gate_id     = $this->create_gate_with_invalid_stored_rule();
		$other_gate  = Content_Gate::create_gate( [ 'title' => 'Unrelated gate' ] );
		$stored_gate = Content_Gate::get_gate( $gate_id );
		$disabling   = array_merge( $stored_gate, [ 'status' => 'draft' ] );

		wp_set_current_user( $admin_id );
		$this->assertWPError(
			Content_Gate_API::sanitize_gate( $disabling, $this->gate_update_request( $other_gate ) ),
			'A body naming one gate must be compared against the gate the route names, which stores different rules.'
		);
		$this->assertNotWPError(
			Content_Gate_API::sanitize_gate( $disabling, $this->gate_update_request( $gate_id, $other_gate ) ),
			'`get_param()` reads the body ahead of the URL, so a top-level `id` there must not decide which gate is read.'
		);

		wp_set_current_user( 0 );
		$this->assertWPError(
			Content_Gate_API::sanitize_gate( $disabling, $this->gate_update_request( $gate_id ) ),
			'A caller who cannot edit the gate must not learn from the response code what it stores.'
		);
	}

	/**
	 * The other route to a gate that grants everyone: enable an options-backed
	 * rule on a site with nothing to select, touch nothing, save. The rule seeds
	 * with its `[]` default, which is a well-formed "no constraint" value, and
	 * `Institution::evaluate()` returns true for it. An active gate must not save
	 * in that state.
	 */
	public function test_active_custom_access_rejects_an_options_backed_rule_with_nothing_selected() {
		$sanitized_gate = Content_Gate_API::sanitize_gate(
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => 'institution',
								'value' => [],
							],
						],
					],
				],
			]
		);
		$this->assertWPError( $sanitized_gate );
		$this->assertSame( 'empty_access_rule_value', $sanitized_gate->get_error_code() );
	}

	/**
	 * The same rule set is fine while custom access is off — that is a gate being
	 * configured, not one letting everyone through.
	 */
	public function test_inactive_custom_access_accepts_a_rule_with_nothing_selected() {
		$sanitized_gate = Content_Gate_API::sanitize_gate(
			[
				'custom_access' => [
					'active'       => false,
					'access_rules' => [
						[
							[
								'slug'  => 'institution',
								'value' => [],
							],
						],
					],
				],
			]
		);
		$this->assertNotWPError( $sanitized_gate );
		$this->assertSame( [], $sanitized_gate['custom_access']['access_rules'][0][0]['value'] );
	}

	/**
	 * The escape hatch: a gate already stored in that state can be switched off.
	 * Turning custom access off is the operator's way out, so it must not be the
	 * one save the guard refuses.
	 */
	public function test_a_gate_storing_an_unselected_rule_can_have_custom_access_switched_off() {
		$gate_id                                = $this->create_gate_with_stored_institution_rule();
		$stored_gate                            = Content_Gate::get_gate( $gate_id );
		$stored_gate['custom_access']['active'] = false;

		$switched_off = Content_Gate_API::sanitize_gate( $stored_gate );
		$this->assertNotWPError( $switched_off );
		$this->assertFalse( $switched_off['custom_access']['active'] );
	}

	/**
	 * `[]` is a configuration for some options-backed rules, not the absence of
	 * one. `subscription` with no product named requires *any* active
	 * subscription, which still keeps non-subscribers out. Only a rule that
	 * declares `empty_grants_access` opens the gate when left empty, so only
	 * that rule may refuse the save.
	 */
	public function test_active_custom_access_accepts_an_empty_value_for_a_rule_that_still_constrains() {
		$sanitized_gate = Content_Gate_API::sanitize_gate(
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => 'subscription',
								'value' => [],
							],
						],
					],
				],
			]
		);
		$this->assertNotWPError( $sanitized_gate );
		$this->assertSame( [], $sanitized_gate['custom_access']['access_rules'][0][0]['value'] );
	}

	/**
	 * An active gate carrying no access rules at all imposes no condition, so every
	 * reader passes it — the same state as one rule left empty, reached by sending
	 * the whole set as `[]` rather than by emptying one rule. The per-rule loop
	 * cannot see it, having nothing to iterate.
	 */
	public function test_active_custom_access_refuses_an_empty_rule_set() {
		$refused = Content_Gate_API::sanitize_gate(
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [],
				],
			]
		);

		$this->assertWPError( $refused, 'An active gate with no access rules must not save.' );
		$this->assertSame( 'empty_access_rules', $refused->get_error_code() );
	}

	/**
	 * The refusal above is about the gate being live, not about the rule set being
	 * empty: a gate switched off is allowed to hold no rules, which is how one is
	 * cleared before Paid access is turned back on.
	 */
	public function test_inactive_custom_access_accepts_an_empty_rule_set() {
		$sanitized_gate = Content_Gate_API::sanitize_gate(
			[
				'custom_access' => [
					'active'       => false,
					'access_rules' => [],
				],
			]
		);

		$this->assertNotWPError( $sanitized_gate );
		$this->assertSame( [], $sanitized_gate['custom_access']['access_rules'] );
	}

	/**
	 * The other read in the same guard: whether the *stored* gate is active. A
	 * body that omits `active` isn't changing it, so the stored value decides —
	 * but reading it for a caller who can't edit the gate would let an
	 * unauthenticated POST tell an active gate with nothing selected from any
	 * other gate, by the response code alone.
	 */
	public function test_the_stored_active_flag_is_read_only_for_a_caller_who_could_save_the_gate() {
		$gate_id = $this->create_gate_with_stored_institution_rule();
		// No `active` key, so the guard has to fall back to the stored one.
		$body = [
			'custom_access' => [
				'access_rules' => [
					[
						[
							'slug'  => 'institution',
							'value' => [],
						],
					],
				],
			],
		];

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertWPError(
			Content_Gate_API::sanitize_gate( $body, $this->gate_update_request( $gate_id ) ),
			'An editor saving an active gate that grants everyone still has to fix it.'
		);

		wp_set_current_user( 0 );
		$this->assertNotWPError(
			Content_Gate_API::sanitize_gate( $body, $this->gate_update_request( $gate_id ) ),
			'A caller who cannot edit the gate must not learn its stored state from the response code; the request fails on permissions instead.'
		);

		// An Editor holds `edit_post` on a published gate — the routes require
		// `manage_options`, and these reads have to require what the routes do.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertNotWPError(
			Content_Gate_API::sanitize_gate( $body, $this->gate_update_request( $gate_id ) ),
			'A caller the route would refuse must not drive a read of the gate either.'
		);
	}

	/**
	 * A partial save carries only the settings it changes, and they are merged
	 * over the stored ones — so a save that switches the gate on without
	 * resending its rules puts the stored rules live. Judging only what the
	 * payload names would leave the granting-everyone state one ordinary save
	 * away.
	 */
	public function test_a_save_that_activates_the_gate_is_judged_against_the_rules_it_leaves_out() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$gate_id = $this->create_gate_with_stored_institution_rule( false );

		$refused = Content_Gate_API::sanitize_gate(
			[
				'status'        => 'publish',
				'custom_access' => [ 'active' => true ],
			],
			$this->gate_update_request( $gate_id )
		);
		$this->assertWPError( $refused );
		$this->assertSame( 'empty_access_rule_value', $refused->get_error_code() );

		$this->assertNotWPError(
			Content_Gate_API::sanitize_gate(
				[
					'status'        => 'draft',
					'custom_access' => [ 'active' => true ],
				],
				$this->gate_update_request( $gate_id )
			),
			'A save that leaves the rules unenforced puts nothing live, and the operator may be on their way out of the state.'
		);
	}

	/**
	 * The same for a save that names no custom access section at all: publishing
	 * the gate is enough to put the rules it stores live.
	 */
	public function test_publishing_a_gate_is_judged_against_the_rules_it_stores() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$gate_id = $this->create_gate_with_stored_institution_rule();
		wp_update_post(
			[
				'ID'          => $gate_id,
				'post_status' => 'draft',
			]
		);

		$refused = Content_Gate_API::sanitize_gate( [ 'status' => 'publish' ], $this->gate_update_request( $gate_id ) );
		$this->assertWPError( $refused );
		$this->assertSame( 'empty_access_rule_value', $refused->get_error_code() );
	}

	/**
	 * A save that omits `status` isn't changing it, so the stored status is what
	 * decides whether the rules end up enforced — and only a save that leaves
	 * them unenforced may carry an already-invalid stored value back untouched.
	 */
	public function test_the_stored_status_decides_whether_a_save_leaves_the_rules_unenforced() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$gate_id = $this->create_gate_with_invalid_stored_rule();
		$body    = [ 'custom_access' => Content_Gate::get_gate( $gate_id )['custom_access'] ];

		$this->assertWPError(
			Content_Gate_API::sanitize_gate( $body, $this->gate_update_request( $gate_id ) ),
			'The gate is published, so this save keeps its invalid rules enforced.'
		);

		wp_update_post(
			[
				'ID'          => $gate_id,
				'post_status' => 'draft',
			]
		);
		$this->assertNotWPError(
			Content_Gate_API::sanitize_gate( $body, $this->gate_update_request( $gate_id ) ),
			'The same save against a gate that is already out of service enforces nothing.'
		);
	}

	/**
	 * `empty_grants_access` is a property of the rule's callback, not of its
	 * value's shape. A free-text rule left blank grants every reader just as an
	 * options-backed one with nothing selected does.
	 */
	public function test_an_active_gate_may_not_leave_a_free_text_rule_that_grants_everyone_blank() {
		$refused = Content_Gate_API::sanitize_gate(
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => 'email_domain',
								'value' => '',
							],
						],
					],
				],
			]
		);
		$this->assertWPError( $refused );
		$this->assertSame( 'empty_access_rule_value', $refused->get_error_code() );
	}

	/**
	 * Emptying the rule and taking the gate out of service in one save is still
	 * refused — the value would grant everyone the moment the gate is live again
	 * — but the refusal must not answer with "turn the rule off", which is what
	 * that save was doing.
	 */
	public function test_the_refusal_is_worded_for_a_save_that_was_taking_the_gate_out_of_service() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$institution_id = self::factory()->post->create( [ 'post_type' => Institution::POST_TYPE ] );
		$gate_id        = $this->create_gate_with_stored_institution_rule( true, [ $institution_id ] );

		$refused = Content_Gate_API::sanitize_gate(
			[
				'status'        => 'draft',
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => 'institution',
								'value' => [],
							],
						],
					],
				],
			],
			$this->gate_update_request( $gate_id )
		);
		$this->assertWPError( $refused );
		$this->assertStringContainsString( 'active again', $refused->get_error_message() );
	}
}
