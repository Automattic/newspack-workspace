<?php
/**
 * Tests for Content_Gate_API access rule sanitization.
 *
 * @package Newspack\Tests\Content_Gate
 */

use Newspack\Access_Rules;
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
	 * error instead of silently dropping the rule, which would loosen the AND
	 * group (or empty the rule set entirely, granting access).
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
}
