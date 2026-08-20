<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing
/**
 * Tests that a full sync (get_contact_with_metadata() with $fields = null)
 * computes only the metadata classes a push-enabled integration could
 * actually use, per the merged name-based catalog (schema coexistence).
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation\Integration;
use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Sync\Metadata;

require_once __DIR__ . '/../integrations/class-sample-integration.php';
require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';

/**
 * Compute-scoping for a full sync, derived from every push-enabled active
 * integration's enabled outgoing fields (Metadata::get_push_enabled_fields_union()).
 *
 * Two canaries stand in for "did this class run": `account` (Legacy_Basic,
 * always set by WooCommerce::get_contact_from_customer()) and `User_Role`
 * (Identity, always set for any WP_User). Neither raw key is emitted by any
 * other class, so their presence/absence in the raw-keyed compute output
 * pins exactly which class ran without needing WooCommerce order/subscription
 * fixtures for the classes that are expected to be skipped.
 *
 * @group Sync_Metadata_Classes
 */
class Test_Sync_Metadata_Classes extends WP_UnitTestCase {

	/**
	 * Integration under test.
	 *
	 * @var Sample_Integration
	 */
	private $integration;

	/**
	 * A plain reader with no WooCommerce orders or subscriptions, so the
	 * v1/v2 classes that do run resolve to their empty-state output rather
	 * than erroring for lack of fixtures.
	 *
	 * @var int
	 */
	private $user_id;

	public function set_up() {
		parent::set_up();
		$this->reset_integrations();
		Sample_Integration::reset();
		$this->integration = new Sample_Integration( 'scope-test', 'Scope Test' );
		Integrations::register( $this->integration );
		Integrations::enable( 'scope-test' );

		$this->user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
	}

	public function tear_down() {
		Integrations::disable( 'scope-test' );
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'scope-test' );
		Sample_Integration::reset();
		$this->reset_integrations();
		Integrations::register_integrations();
		parent::tear_down();
	}

	/**
	 * Reset the integrations registry via reflection, mirroring the sibling
	 * suites so tests are order-independent.
	 */
	private function reset_integrations() {
		$reflection = new \ReflectionClass( Integrations::class );
		$property   = $reflection->getProperty( 'integrations' );
		$property->setAccessible( true );
		$property->setValue( null, [] );
	}

	/**
	 * A v1-only selection ('Newsletter Selection' belongs only to
	 * Legacy_Basic) must run the legacy class and skip every v2 class —
	 * including the WooCommerce-heavy ones (Subscription, Donation) this
	 * scoping exists to avoid querying on a legacy site's sync.
	 */
	public function test_v1_only_selection_computes_no_v2_class_metadata() {
		$this->integration->update_enabled_outgoing_fields( [ 'Newsletter Selection' ] );

		$contact = Metadata::get_contact_with_metadata( $this->user_id );

		$this->assertArrayHasKey( 'account', $contact['metadata'], 'Legacy_Basic must run for a v1-only selection.' );
		$this->assertArrayNotHasKey( 'User_Role', $contact['metadata'], 'Identity must not run.' );
		$this->assertArrayNotHasKey( 'Registration_Strategy', $contact['metadata'], 'Registration must not run.' );
		$this->assertArrayNotHasKey( 'First_Visit_Date', $contact['metadata'], 'Engagement must not run.' );
		$this->assertArrayNotHasKey( 'Subscriber_Status', $contact['metadata'], 'Subscription must not run.' );
		$this->assertArrayNotHasKey( 'Donor_Status', $contact['metadata'], 'Donation must not run.' );
	}

	/**
	 * Symmetrically, a v2-only selection ('User Role' belongs only to
	 * Identity) must run that class and skip the legacy one.
	 */
	public function test_v2_only_selection_computes_no_v1_class_metadata() {
		$this->integration->update_enabled_outgoing_fields( [ 'User Role' ] );

		$contact = Metadata::get_contact_with_metadata( $this->user_id );

		$this->assertArrayHasKey( 'User_Role', $contact['metadata'], 'Identity must run for a v2-only selection.' );
		$this->assertArrayNotHasKey( 'account', $contact['metadata'], 'Legacy_Basic must not run.' );
	}

	/**
	 * 'Account' is a value-equivalent pair name declared by both Legacy_Basic
	 * (v1) and Identity (v2). Selecting it alone must run both owning
	 * classes in full — class-level scoping, not per-field — while still
	 * leaving every other v2 class (which doesn't declare 'Account') skipped.
	 */
	public function test_pair_name_alone_computes_both_eras_owning_classes() {
		$this->integration->update_enabled_outgoing_fields( [ 'Account' ] );

		$contact = Metadata::get_contact_with_metadata( $this->user_id );

		$this->assertArrayHasKey( 'account', $contact['metadata'], 'Legacy_Basic (the v1 owner of Account) must run.' );
		$this->assertArrayHasKey( 'Account', $contact['metadata'], 'Identity (the v2 owner of Account) must run.' );
		$this->assertArrayHasKey( 'first_name', $contact['metadata'], 'Identity computes its whole field set, not just the shared name.' );
		$this->assertArrayNotHasKey( 'Registration_Strategy', $contact['metadata'], "Registration doesn't own Account and must stay skipped." );
	}

	/**
	 * An explicitly stored empty selection is not "no evidence" — it is
	 * evidence that nothing should sync. Unlike the no-registry case below,
	 * this must compute nothing at all.
	 */
	public function test_all_selections_empty_computes_nothing() {
		$this->integration->update_enabled_outgoing_fields( [] );

		$contact = Metadata::get_contact_with_metadata( $this->user_id );

		$this->assertSame( [], $contact['metadata'], 'An all-empty union must compute no metadata.' );
	}

	/**
	 * With no integrations registered at all (the pre-init state), the union
	 * is null rather than empty, and null must fall through to "compute
	 * everything" — the pre-existing, unscoped behavior.
	 */
	public function test_empty_integrations_registry_computes_everything() {
		$this->reset_integrations();

		$contact = Metadata::get_contact_with_metadata( $this->user_id );

		$this->assertArrayHasKey( 'account', $contact['metadata'], 'Legacy_Basic must run.' );
		$this->assertArrayHasKey( 'User_Role', $contact['metadata'], 'Identity must run.' );
		$this->assertArrayHasKey( 'Registration_Strategy', $contact['metadata'], 'Registration must run even though nothing named it.' );
	}

	/**
	 * The derivation only fires when the caller passes $fields = null. An
	 * explicit array — however narrow — must keep governing scoping on its
	 * own, even when the derived union (had it been consulted) would have
	 * been null and widened to "everything".
	 */
	public function test_explicit_fields_array_ignores_the_derived_union() {
		$this->reset_integrations();

		$contact = Metadata::get_contact_with_metadata( $this->user_id, [ 'Newsletter Selection' ] );

		$this->assertArrayHasKey( 'account', $contact['metadata'], 'Legacy_Basic must run per the explicit array.' );
		$this->assertArrayNotHasKey( 'User_Role', $contact['metadata'], 'Identity must stay skipped: the explicit array names no v2 field.' );
	}
}
