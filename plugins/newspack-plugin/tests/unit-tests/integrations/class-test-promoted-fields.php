<?php
/**
 * Tests for Promoted_Fields.
 *
 * @package Newspack\Tests\Unit\Integrations
 */

namespace Newspack\Tests\Unit\Integrations;

use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Integrations\Incoming_Field;
use Newspack\Reader_Activation\Promoted_Fields;
use Sample_Integration;

/**
 * Tests for the Promoted_Fields class.
 *
 * @group promoted_fields
 */
class Test_Promoted_Fields extends \WP_UnitTestCase {

	/**
	 * Integration instance.
	 *
	 * @var Sample_Integration
	 */
	private $integration;

	/**
	 * Set up test environment.
	 */
	public function set_up() {
		parent::set_up();
		$this->reset_integrations();
		Promoted_Fields::reset_cache();

		$this->integration = new Sample_Integration( 'promoted-test', 'Test ESP' );
		Integrations::register( $this->integration );
		Integrations::enable( 'promoted-test' );
	}

	/**
	 * Tear down test environment.
	 */
	public function tear_down() {
		Promoted_Fields::reset_cache();
		$this->reset_integrations();
		Integrations::register_integrations();
		delete_option( 'newspack_integration_incoming_fields_promoted-test' );
		delete_option( Integrations::OPTION_NAME );
		parent::tear_down();
	}

	/**
	 * Reset integrations registry via reflection.
	 */
	private function reset_integrations() {
		$reflection = new \ReflectionClass( Integrations::class );
		$property   = $reflection->getProperty( 'integrations' );
		$property->setAccessible( true );
		$property->setValue( null, [] );
	}

	/**
	 * Test that get_promoted_fields returns empty when no fields are configured.
	 */
	public function test_returns_empty_when_no_incoming_fields() {
		$fields = Promoted_Fields::get_promoted_fields();
		$this->assertIsArray( $fields );
		$this->assertEmpty( $fields );
	}

	/**
	 * Test that incoming fields without config are not promoted.
	 */
	public function test_incoming_fields_without_config_not_promoted() {
		$this->integration->update_enabled_incoming_fields( [ 'some_field' ] );
		Promoted_Fields::reset_cache();

		$fields = Promoted_Fields::get_promoted_fields();
		$this->assertEmpty( $fields );
	}

	/**
	 * Test that incoming fields with config are promoted.
	 */
	public function test_incoming_fields_with_config_promoted() {
		$integration = new class( 'config-test', 'Config Test' ) extends Sample_Integration {
			/**
			 * Configure incoming field with promotion config.
			 *
			 * @param \Newspack\Reader_Activation\Integrations\Incoming_Field $field The field.
			 * @return \Newspack\Reader_Activation\Integrations\Incoming_Field
			 */
			protected function configure_incoming_field( $field ) {
				if ( 'organization' === $field->get_key() ) {
					$field->set_name( 'Organization' )
						->set_is_access_rule( true )
						->set_is_segment_criteria( true );
				}
				return $field;
			}
		};

		$this->reset_integrations();
		Integrations::register( $integration );
		Integrations::enable( 'config-test' );
		$integration->update_enabled_incoming_fields( [ 'organization' ] );
		Promoted_Fields::reset_cache();

		$fields = Promoted_Fields::get_promoted_fields();
		$this->assertArrayHasKey( 'config-test__organization', $fields );
		$field = $fields['config-test__organization']['field'];
		$this->assertTrue( $field->is_access_rule() );
		$this->assertTrue( $field->is_segment_criteria() );
	}

	/**
	 * Test that promoted field names are prefixed with integration name.
	 */
	public function test_field_name_prefixed_with_integration_name() {
		// Use a subclass that enriches the field via build_incoming_field.
		$integration = new class( 'prefix-test', 'ActiveCampaign' ) extends Sample_Integration {
			/**
			 * Configure incoming field with promotion config.
			 *
			 * @param \Newspack\Reader_Activation\Integrations\Incoming_Field $field The field.
			 * @return \Newspack\Reader_Activation\Integrations\Incoming_Field
			 */
			protected function configure_incoming_field( $field ) {
				if ( 'org' === $field->get_key() ) {
					$field->set_name( 'Organization' )
						->set_is_access_rule( true )
						->set_is_segment_criteria( true );
				}
				return $field;
			}
		};

		$this->reset_integrations();
		Integrations::register( $integration );
		Integrations::enable( 'prefix-test' );
		$integration->update_enabled_incoming_fields( [ 'org' ] );
		Promoted_Fields::reset_cache();

		$fields = Promoted_Fields::get_promoted_fields();
		$this->assertArrayHasKey( 'prefix-test__org', $fields );
		$this->assertSame( 'Organization', $fields['prefix-test__org']['field']->get_name() );
		$this->assertSame( 'ActiveCampaign', $fields['prefix-test__org']['integration']->get_name() );
	}

	/**
	 * Test that default values are applied for missing config keys.
	 */
	public function test_defaults_applied() {
		$integration = new class( 'defaults-test', 'TestInt' ) extends Sample_Integration {
			/**
			 * Configure incoming field with minimal promotion config.
			 *
			 * @param \Newspack\Reader_Activation\Integrations\Incoming_Field $field The field.
			 * @return \Newspack\Reader_Activation\Integrations\Incoming_Field
			 */
			protected function configure_incoming_field( $field ) {
				if ( 'role' === $field->get_key() ) {
					$field->set_is_segment_criteria( true );
				}
				return $field;
			}
		};

		$this->reset_integrations();
		Integrations::register( $integration );
		Integrations::enable( 'defaults-test' );
		$integration->update_enabled_incoming_fields( [ 'role' ] );
		Promoted_Fields::reset_cache();

		$fields = Promoted_Fields::get_promoted_fields();
		$this->assertArrayHasKey( 'defaults-test__role', $fields );
		$field = $fields['defaults-test__role']['field'];
		$this->assertSame( 'default', $field->get_matching_function() );
		$this->assertSame( 'role', $field->get_key() );
		// Name defaults to field key.
		$this->assertSame( 'role', $field->get_name() );
	}

	/**
	 * Test that evaluate_field works for default matching.
	 */
	public function test_evaluate_default_matching() {
		$user_id = $this->factory->user->create();

		if ( class_exists( '\Newspack\Reader_Data' ) ) {
			\Newspack\Reader_Data::update_item( $user_id, 'org', wp_json_encode( 'Newspack' ) );
		}

		$method = new \ReflectionMethod( Promoted_Fields::class, 'evaluate_field' );
		$method->setAccessible( true );

		$field = new Incoming_Field( 'org' );

		$this->assertTrue( $method->invoke( null, $field, $user_id, 'Newspack' ) );
		$this->assertFalse( $method->invoke( null, $field, $user_id, 'Other' ) );
	}

	/**
	 * Test that evaluate_field handles boolean value_type.
	 */
	public function test_evaluate_boolean_matching() {
		$user_id = $this->factory->user->create();

		$method = new \ReflectionMethod( Promoted_Fields::class, 'evaluate_field' );
		$method->setAccessible( true );

		$field = ( new Incoming_Field( 'is_vip' ) )->set_value_type( 'boolean' );

		// No data stored — falsy.
		$this->assertTrue( $method->invoke( null, $field, $user_id, 'no' ) );
		$this->assertFalse( $method->invoke( null, $field, $user_id, 'yes' ) );

		// Store truthy value.
		if ( class_exists( '\Newspack\Reader_Data' ) ) {
			\Newspack\Reader_Data::update_item( $user_id, 'is_vip', wp_json_encode( true ) );
		}

		$this->assertTrue( $method->invoke( null, $field, $user_id, 'yes' ) );
		$this->assertFalse( $method->invoke( null, $field, $user_id, 'no' ) );

		// Access rule style — no specific args, just check truthiness.
		$this->assertTrue( $method->invoke( null, $field, $user_id, null ) );

		// Access rule style — boolean true value, as used by content-gate rules.
		$this->assertTrue( $method->invoke( null, $field, $user_id, true ) );
	}

	/**
	 * Range matching casts both the stored value and the min/max bounds to float, so a
	 * decimal amount (e.g. a Mailchimp `number` merge field) matches correctly. Absent
	 * bounds default to 0..PHP_INT_MAX and a non-numeric value coerces to 0.0, so a
	 * blank-bounds range rule grants access broadly — pinned here so that fail-open
	 * default stays intentional.
	 */
	public function test_evaluate_range_matching() {
		$user_id = $this->factory->user->create();
		if ( ! class_exists( '\Newspack\Reader_Data' ) ) {
			$this->markTestSkipped( 'Reader_Data not available.' );
		}
		\Newspack\Reader_Data::update_item( $user_id, 'amount', wp_json_encode( 49.99 ) );

		$method = new \ReflectionMethod( Promoted_Fields::class, 'evaluate_field' );
		$method->setAccessible( true );

		$field = ( new Incoming_Field( 'amount' ) )
			->set_value_type( 'number' )
			->set_matching_function( 'range' );

		// Decimal value within decimal bounds, and boundary-inclusive.
		$this->assertTrue(
			$method->invoke(
				null,
				$field,
				$user_id,
				[
					'min' => 10.5,
					'max' => 99.99,
				]
			)
		);
		$this->assertTrue(
			$method->invoke(
				null,
				$field,
				$user_id,
				[
					'min' => 49.99,
					'max' => 49.99,
				]
			)
		);
		// Outside the bounds on each side.
		$this->assertFalse( $method->invoke( null, $field, $user_id, [ 'min' => 50 ] ) );
		$this->assertFalse( $method->invoke( null, $field, $user_id, [ 'max' => 10 ] ) );

		// Absent bounds default to 0..PHP_INT_MAX: any non-negative amount matches.
		$this->assertTrue( $method->invoke( null, $field, $user_id, [] ) );

		// A non-numeric stored value coerces to 0.0: blank bounds still match (fail-open),
		// but a positive min excludes the coerced-to-zero value.
		\Newspack\Reader_Data::update_item( $user_id, 'amount', wp_json_encode( 'not-a-number' ) );
		$this->assertTrue( $method->invoke( null, $field, $user_id, [] ) );
		$this->assertFalse( $method->invoke( null, $field, $user_id, [ 'min' => 1 ] ) );
	}

	/**
	 * Test list__in matching with a plain scalar string (non-JSON).
	 */
	public function test_evaluate_list_in_plain_string() {
		$user_id = $this->factory->user->create();

		if ( class_exists( '\Newspack\Reader_Data' ) ) {
			\Newspack\Reader_Data::update_item( $user_id, 'institution', wp_json_encode( 'University of Testing' ) );
		}

		$method = new \ReflectionMethod( Promoted_Fields::class, 'evaluate_field' );
		$method->setAccessible( true );

		$field = ( new Incoming_Field( 'institution' ) )->set_matching_function( 'list__in' );

		// Plain string should match when included in args.
		$this->assertTrue( $method->invoke( null, $field, $user_id, [ 'University of Testing' ] ) );
		$this->assertFalse( $method->invoke( null, $field, $user_id, [ 'Other University' ] ) );

		// list__not_in should be the inverse.
		$field->set_matching_function( 'list__not_in' );
		$this->assertFalse( $method->invoke( null, $field, $user_id, [ 'University of Testing' ] ) );
		$this->assertTrue( $method->invoke( null, $field, $user_id, [ 'Other University' ] ) );
	}

	/**
	 * Test that access_rule_callback takes precedence over matching_function.
	 */
	public function test_access_rule_callback_takes_precedence() {
		$user_id = $this->factory->user->create();

		$method = new \ReflectionMethod( Promoted_Fields::class, 'evaluate_field' );
		$method->setAccessible( true );

		// Field with default matching that would return false.
		$field = ( new Incoming_Field( 'custom' ) )
			->set_access_rule_callback(
				function ( $uid, $args ) {
					// Always grant access regardless of stored data.
					return true;
				}
			);

		$this->assertTrue( $method->invoke( null, $field, $user_id, 'nonexistent_value' ) );

		// Callback that denies access.
		$field->set_access_rule_callback(
			function ( $uid, $args ) {
				return false;
			}
		);

		$this->assertFalse( $method->invoke( null, $field, $user_id, 'anything' ) );
	}

	/**
	 * Test that access_rule_callback receives correct arguments.
	 */
	public function test_access_rule_callback_receives_arguments() {
		$user_id = $this->factory->user->create();

		$method = new \ReflectionMethod( Promoted_Fields::class, 'evaluate_field' );
		$method->setAccessible( true );

		$captured = [];
		$field    = ( new Incoming_Field( 'test_field' ) )
			->set_access_rule_callback(
				function ( $uid, $args ) use ( &$captured ) {
					$captured = [
						'user_id' => $uid,
						'args'    => $args,
					];
					return true;
				}
			);

		$method->invoke( null, $field, $user_id, 'test_value' );

		$this->assertSame( $user_id, $captured['user_id'] );
		$this->assertSame( 'test_value', $captured['args'] );
	}

	/**
	 * AC stores `checkbox` and `multiselect` field values as `||val1||val2||`. The list
	 * matcher must recognize that delimiter so an audience rule on one option matches
	 * a contact who has multiple selections.
	 */
	public function test_list_in_recognizes_active_campaign_pipe_delimited_values() {
		$user_id = $this->factory->user->create();
		if ( ! class_exists( '\Newspack\Reader_Data' ) ) {
			$this->markTestSkipped( 'Reader_Data not available.' );
		}
		\Newspack\Reader_Data::update_item( $user_id, 'interests', wp_json_encode( '||Politics||Sports||' ) );

		$method = new \ReflectionMethod( Promoted_Fields::class, 'evaluate_field' );
		$method->setAccessible( true );

		$field = ( new Incoming_Field( 'interests' ) )->set_matching_function( 'list__in' );
		$this->assertTrue( $method->invoke( null, $field, $user_id, [ 'Politics' ] ), 'matches first selection' );
		$this->assertTrue( $method->invoke( null, $field, $user_id, [ 'Sports' ] ), 'matches second selection' );
		$this->assertFalse( $method->invoke( null, $field, $user_id, [ 'Cooking' ] ), 'no match for unselected option' );

		$field->set_matching_function( 'list__not_in' );
		$this->assertFalse( $method->invoke( null, $field, $user_id, [ 'Politics' ] ) );
		$this->assertTrue( $method->invoke( null, $field, $user_id, [ 'Cooking' ] ) );
	}

	/**
	 * Single-selection AC fields come back wrapped (`||Politics||`) too. The list matcher
	 * must also handle that case correctly.
	 */
	public function test_list_in_handles_single_selection_pipe_wrapped_value() {
		$user_id = $this->factory->user->create();
		if ( ! class_exists( '\Newspack\Reader_Data' ) ) {
			$this->markTestSkipped( 'Reader_Data not available.' );
		}
		\Newspack\Reader_Data::update_item( $user_id, 'interests', wp_json_encode( '||Politics||' ) );

		$method = new \ReflectionMethod( Promoted_Fields::class, 'evaluate_field' );
		$method->setAccessible( true );

		$field = ( new Incoming_Field( 'interests' ) )->set_matching_function( 'list__in' );
		$this->assertTrue( $method->invoke( null, $field, $user_id, [ 'Politics' ] ) );
		$this->assertFalse( $method->invoke( null, $field, $user_id, [ 'Sports' ] ) );
	}

	/**
	 * Access rules pass a scalar argument and have no date-range UI, so a field
	 * typed as date_range still matches one date exactly. It compares calendar
	 * dates rather than raw strings: choosing the operator makes the pull rewrite
	 * stored values to ISO, and a rule written in the provider's own format would
	 * otherwise silently stop matching a live gate.
	 */
	public function test_date_range_field_still_exact_matches_as_access_rule() {
		$user_id = $this->factory->user->create();
		if ( ! class_exists( '\Newspack\Reader_Data' ) ) {
			$this->markTestSkipped( 'Reader_Data not available.' );
		}
		\Newspack\Reader_Data::update_item( $user_id, 'LAST_GIFT_DATE', wp_json_encode( '2026-01-15' ) );

		$method = new \ReflectionMethod( Promoted_Fields::class, 'evaluate_field' );
		$method->setAccessible( true );

		$field = ( new Incoming_Field( 'LAST_GIFT_DATE' ) )
			->set_value_type( 'date' )
			->set_matching_function( 'date_range' );

		$this->assertTrue( $method->invoke( null, $field, $user_id, '2026-01-15' ) );
		$this->assertFalse( $method->invoke( null, $field, $user_id, '2026-01-16' ) );

		// A rule the publisher wrote in the provider's format keeps matching the
		// value the pull rewrote to ISO.
		$mailchimp_field = ( new Incoming_Field( 'LAST_GIFT_DATE' ) )
			->set_value_type( 'date' )
			->set_matching_function( 'date_range' )
			->set_date_format( 'm/d/Y' );
		$this->assertTrue( $method->invoke( null, $mailchimp_field, $user_id, '01/15/2026' ) );
		$this->assertFalse( $method->invoke( null, $mailchimp_field, $user_id, '01/16/2026' ) );

		// A datetime value is compared on its date part, as written — the ATOM form
		// the pull stores can never be typed into a gate rule by hand.
		\Newspack\Reader_Data::update_item( $user_id, 'LAST_GIFT_DATE', wp_json_encode( '2026-01-15T23:30:00-06:00' ) );
		$datetime_field = ( new Incoming_Field( 'LAST_GIFT_DATE' ) )
			->set_value_type( 'datetime' )
			->set_matching_function( 'date_range' );
		$this->assertTrue( $method->invoke( null, $datetime_field, $user_id, '2026-01-15' ) );
		$this->assertFalse( $method->invoke( null, $datetime_field, $user_id, '2026-01-16' ) );

		// A reader with no value never matches, even against an unusable rule.
		\Newspack\Reader_Data::update_item( $user_id, 'LAST_GIFT_DATE', wp_json_encode( '' ) );
		$this->assertFalse( $method->invoke( null, $field, $user_id, '' ) );
		$this->assertFalse( $method->invoke( null, $field, $user_id, 'not a date' ) );

		// A day that doesn't exist in its month is what the normalizer stores
		// untouched precisely because it isn't a date; it must not become one here.
		\Newspack\Reader_Data::update_item( $user_id, 'LAST_GIFT_DATE', wp_json_encode( '2026-02-30' ) );
		$this->assertFalse( $method->invoke( null, $field, $user_id, '2026-02-30' ) );

		// A leap day is a real date and still matches.
		\Newspack\Reader_Data::update_item( $user_id, 'LAST_GIFT_DATE', wp_json_encode( '2024-02-29' ) );
		$this->assertTrue( $method->invoke( null, $field, $user_id, '2024-02-29' ) );
	}

	/**
	 * The version-skew guard is what stops an old newspack-popups from being
	 * handed a matching function it can't resolve — a TypeError that escapes
	 * segment matching and takes prompt display down sitewide. newspack-popups
	 * is not loaded in this suite, which is exactly the probe-less environment
	 * an old build presents (the probe method can't exist), so this pins the
	 * degradation path; the probe-present path is covered from the popups side
	 * by its own CriteriaTest, since CI runs each plugin's suite in isolation.
	 */
	public function test_supported_matching_function_degrades_without_the_popups_probe() {
		if ( class_exists( '\Newspack_Popups_Criteria' ) ) {
			$this->markTestSkipped( 'newspack-popups is loaded; this test covers the probe-less environment.' );
		}

		$method = new \ReflectionMethod( Promoted_Fields::class, 'supported_matching_function' );
		$method->setAccessible( true );

		// Baseline operators predate the capability probe: every newspack-popups
		// build old enough to lack the probe still resolves all of them, so they
		// must pass through untouched — degrading them would silently change
		// matching semantics on sites that are not skewed at all.
		foreach ( [ 'default', 'range', 'list__in', 'list__not_in' ] as $matching_function ) {
			$this->assertSame( $matching_function, $method->invoke( null, $matching_function ), $matching_function );
		}

		// Anything past the baseline cannot be confirmed without the probe, so it
		// degrades to exact matching — a criterion that matches too narrowly,
		// rather than one that breaks the page.
		$this->assertSame( 'default', $method->invoke( null, 'date_range' ) );
		$this->assertSame( 'default', $method->invoke( null, 'a_future_operator' ) );
	}

	/**
	 * The baseline list is a hand-maintained copy of the operator set that
	 * predates the popups capability probe. It must never grow: an operator
	 * added to it skips the probe entirely and would be emitted to builds that
	 * cannot resolve it — the exact crash the probe exists to prevent.
	 */
	public function test_baseline_matching_functions_is_frozen() {
		$constant = new \ReflectionClassConstant( Promoted_Fields::class, 'BASELINE_MATCHING_FUNCTIONS' );
		$this->assertSame(
			[ 'default', 'range', 'list__in', 'list__not_in' ],
			$constant->getValue(),
			'BASELINE_MATCHING_FUNCTIONS is the pre-probe operator set; new operators must rely on the probe, not the baseline.'
		);
	}
}
