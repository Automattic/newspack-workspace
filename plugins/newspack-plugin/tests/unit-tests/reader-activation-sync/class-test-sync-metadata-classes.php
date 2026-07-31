<?php
/**
 * Tests that contact building computes only the schema versions in play.
 *
 * @package Newspack\Tests
 */

namespace Newspack\Tests\Unit\Reader_Activation_Sync;

use Newspack\Reader_Activation\Integration;
use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Sync\Contact_Metadata;
use Newspack\Reader_Activation\Sync\Field_Registry;
use Newspack\Reader_Activation\Sync\Metadata;
use Sample_Integration;

/**
 * Sync metadata class scoping tests.
 *
 * @group sync-metadata-classes
 */
class Test_Sync_Metadata_Classes extends \WP_UnitTestCase {

	/**
	 * Integration under test.
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
		$this->integration = new Sample_Integration( 'scope-test', 'Scope Test' );
		Integrations::register( $this->integration );
		\delete_option( Field_Registry::SCHEMA_ORIGIN_OPTION );
		Field_Registry::reset();
	}

	/**
	 * Tear down test environment.
	 */
	public function tear_down() {
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'scope-test' );
		\delete_option( Field_Registry::SCHEMA_ORIGIN_OPTION );
		Field_Registry::reset();
		$this->reset_integrations();
		Integrations::register_integrations();
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
	 * Call the protected class-scoping helper.
	 *
	 * @return string[]
	 */
	private function get_sync_classes() {
		$method = new \ReflectionMethod( Metadata::class, 'get_sync_metadata_classes' );
		$method->setAccessible( true );
		return $method->invoke( null );
	}

	/**
	 * With only v1 fields enabled, the v2 classes — whose metadata would be
	 * discarded by prepare_contact() — are not computed. Their queries
	 * (wcs_get_users_subscriptions, wc_get_orders) are the expensive half of
	 * a sync, and syncs run inside registration, login and checkout requests.
	 */
	public function test_v1_only_selection_skips_v2_classes() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
		$this->integration->update_enabled_outgoing_fields( [ 'v1:account' ] );

		$classes = $this->get_sync_classes();

		$this->assertContains( Contact_Metadata\Legacy_Basic::class, $classes );
		$this->assertNotContains( Contact_Metadata\Subscription::class, $classes );
		$this->assertNotContains( Contact_Metadata\Donation::class, $classes );
		$this->assertNotContains( Contact_Metadata\Engagement::class, $classes );
	}

	/**
	 * Symmetrically, a v2-only selection skips the legacy classes.
	 */
	public function test_v2_only_selection_skips_v1_classes() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v2' );
		$this->integration->update_enabled_outgoing_fields( [ 'v2:Registration_Date' ] );

		$classes = $this->get_sync_classes();

		$this->assertContains( Contact_Metadata\Registration::class, $classes );
		$this->assertNotContains( Contact_Metadata\Legacy_Basic::class, $classes );
		$this->assertNotContains( Contact_Metadata\Legacy_Payment::class, $classes );
	}

	/**
	 * The coexistence case: with both versions enabled across integrations,
	 * both halves are computed — the capability the registry exists for.
	 */
	public function test_mixed_selection_computes_both_versions() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
		$this->integration->update_enabled_outgoing_fields( [ 'v1:account', 'v2:Registration_Date' ] );

		$classes = $this->get_sync_classes();

		$this->assertContains( Contact_Metadata\Legacy_Basic::class, $classes );
		$this->assertContains( Contact_Metadata\Registration::class, $classes );
	}

	/**
	 * Version-neutral classes participate regardless of the versions in play,
	 * since their fields belong to no schema version.
	 */
	public function test_neutral_classes_always_participate() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
		$this->integration->update_enabled_outgoing_fields( [ 'v1:account' ] );

		$this->assertContains( Contact_Metadata\Content_Gate::class, $this->get_sync_classes() );
	}

	/**
	 * With no stored selection anywhere, scoping falls back to the origin
	 * version's classes rather than computing everything.
	 */
	public function test_no_stored_selection_falls_back_to_origin_classes() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'scope-test' );

		$classes = $this->get_sync_classes();

		$this->assertContains( Contact_Metadata\Legacy_Basic::class, $classes );
		$this->assertNotContains( Contact_Metadata\Subscription::class, $classes );
	}
}
