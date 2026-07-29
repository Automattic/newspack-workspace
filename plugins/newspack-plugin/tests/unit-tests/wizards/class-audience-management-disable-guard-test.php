<?php
/**
 * Tests for refusing to disable Audience Management while content gates exist.
 *
 * NPPD-1846 — content gating depends on Audience Management, and gates carry no
 * Reader Activation dependency of their own. Switching Audience Management off
 * with gates in place would leave those gates restricting content while readers
 * lose every surface (registration, sign-in, account emails) they need to
 * satisfy them — and the gate screens, which replace themselves with a
 * prerequisite state, could no longer be used to lift the restrictions.
 *
 * Refusing that transition is what makes "Audience Management off" and "no gates
 * exist" the same state, which the gate screens' wholesale replacement relies on.
 *
 * @package Newspack\Tests
 */

use Newspack\Audience_Wizard;
use Newspack\Content_Gate;

/**
 * Tests for the Audience Management disable guard.
 */
class Audience_Management_Disable_Guard_Test extends WP_UnitTestCase {

	/**
	 * Content gates are flag-gated; the CPT must be registered for gate posts to exist.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	/**
	 * Build a gate post directly, bypassing Content_Gate::create_gate() so the test
	 * asserts against gate *existence* rather than the creation path's side effects.
	 *
	 * @param string $post_status Post status for the gate.
	 * @param bool   $is_newsletter Whether this is a premium newsletter gate.
	 *
	 * @return int The gate post ID.
	 */
	private function create_gate_post( $post_status = 'publish', $is_newsletter = false ) {
		$gate_id = $this->factory->post->create(
			[
				'post_type'   => Content_Gate::GATE_CPT,
				'post_status' => $post_status,
				'post_title'  => 'Test gate',
			]
		);
		if ( $is_newsletter ) {
			update_post_meta( $gate_id, 'is_newsletter', true );
		}
		return $gate_id;
	}

	/**
	 * Send an Audience Management settings update.
	 *
	 * @param array $params Request params.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function update_audience_management( array $params ) {
		$request = new WP_REST_Request();
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return ( new Audience_Wizard() )->api_update_reader_activation_settings( $request );
	}

	/**
	 * With no gates, disabling is a normal settings write.
	 */
	public function test_disabling_permitted_with_no_gates() {
		$this->assertFalse( Content_Gate::has_any_gates() );

		$response = $this->update_audience_management( [ 'enabled' => false ] );
		$this->assertNotWPError( $response );
	}

	/**
	 * The guard counts drafts and premium newsletter gates as well as published
	 * Access Control gates. A draft gate matters because it is publishable, and a
	 * newsletter gate because it restricts list signup by the same mechanism — so
	 * neither may be left behind on a site whose readers cannot register.
	 *
	 * @dataProvider gate_provider
	 *
	 * @param string $post_status   Status of the gate that should block disabling.
	 * @param bool   $is_newsletter Whether the gate is a premium newsletter gate.
	 */
	public function test_disabling_refused_while_a_gate_exists( $post_status, $is_newsletter ) {
		$this->create_gate_post( $post_status, $is_newsletter );
		$this->assertTrue( Content_Gate::has_any_gates() );

		$response = $this->update_audience_management( [ 'enabled' => false ] );
		$this->assertWPError( $response );
		$this->assertSame( 'newspack_audience_management_required_by_gates', $response->get_error_code() );
		$this->assertSame( 409, $response->get_error_data()['status'] );
	}

	/**
	 * Every kind of gate that must block the transition.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function gate_provider() {
		return [
			'published gate'          => [ 'publish', false ],
			'draft gate'              => [ 'draft', false ],
			'premium newsletter gate' => [ 'publish', true ],
		];
	}

	/**
	 * A trashed gate restricts nothing and is not recoverable through the gate
	 * screens, so it must not hold the toggle hostage.
	 */
	public function test_trashed_gate_does_not_block_disabling() {
		$gate_id = $this->create_gate_post( 'publish' );
		wp_trash_post( $gate_id );

		$this->assertFalse( Content_Gate::has_any_gates() );
		$this->assertNotWPError( $this->update_audience_management( [ 'enabled' => false ] ) );
	}

	/**
	 * The guard is specific to the disable transition: enabling, and unrelated
	 * settings writes, must still work on a site that has gates.
	 */
	public function test_only_the_disable_transition_is_refused() {
		$this->create_gate_post( 'publish' );

		$this->assertNotWPError(
			$this->update_audience_management( [ 'enabled' => true ] ),
			'Enabling Audience Management must never be blocked by gates.'
		);
		$this->assertNotWPError(
			$this->update_audience_management( [ 'sync_esp' => true ] ),
			'An unrelated setting write must not be caught by the disable guard.'
		);
	}

	/**
	 * The lock is reported to the admin screen so the toggle can explain itself,
	 * rather than letting the publisher confirm a destructive dialog and only then
	 * meet the REST refusal.
	 */
	public function test_settings_response_reports_the_lock() {
		$wizard = new Audience_Wizard();

		$unlocked = $wizard->api_get_reader_activation_settings()->get_data();
		$this->assertFalse( $unlocked['disabling_blocked_by_gates'] );

		$this->create_gate_post( 'publish' );

		$locked = $wizard->api_get_reader_activation_settings()->get_data();
		$this->assertTrue( $locked['disabling_blocked_by_gates'] );
	}
}
