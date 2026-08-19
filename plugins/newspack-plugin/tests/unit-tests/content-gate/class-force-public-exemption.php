<?php
/**
 * Tests for the WooCommerce Memberships force-public exemption fallback.
 *
 * A post Memberships forced public stays readable after a site migrates to
 * Access Control. Both halves of that contract are covered here: an absent
 * exemption defers to Memberships, and a recorded one wins even when falsy.
 *
 * @package Newspack\Tests
 */

use Newspack\Content_Gate;
use Newspack\Content_Restriction_Control;
use Newspack\Tests\Content_Gate\Traits\Trait_Restriction_Cache_Test;

/**
 * Tests that Memberships' force-public flag stands in for a missing exemption.
 *
 * @group content-gate
 */
class Force_Public_Exemption_Test extends WP_UnitTestCase {

	use Trait_Restriction_Cache_Test;

	/**
	 * Unregister the exemption meta between cases.
	 */
	public function tear_down() {
		foreach ( array_column( (array) Content_Restriction_Control::get_available_post_types(), 'value' ) as $subtype ) {
			unregister_meta_key( 'post', Content_Restriction_Control::IS_EXEMPT_META_KEY, $subtype );
		}
		parent::tear_down();
	}

	/**
	 * Enable the feature and register the exemption meta for a case.
	 *
	 * Per-case so the disabled-feature case below can run without it.
	 */
	private function enable_gates_and_register() {
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
		Content_Restriction_Control::register_meta();
		$this->reset_restriction_cache();
		Content_Gate::flush_gates_cache();
	}

	/**
	 * Create a published gate that puts every post behind registration.
	 */
	private function create_registration_gate() {
		$gate_id = Content_Gate::create_gate(
			[
				'title'         => 'Force public test gate',
				'status'        => 'publish',
				'content_rules' => [
					[
						'slug'  => 'post_types',
						'value' => [ 'post' ],
					],
				],
				'registration'  => [ 'active' => true ],
			]
		);
		$this->assertNotWPError( $gate_id, 'Gate fixture could not be created.' );
		$this->reset_restriction_cache();
		Content_Gate::flush_gates_cache();
	}

	/**
	 * The control: the gate fixture restricts, so the cases below that assert
	 * otherwise are testing the fallback and not an inert gate.
	 */
	public function test_the_gate_fixture_restricts_an_unexempted_post() {
		$this->enable_gates_and_register();
		$this->create_registration_gate();
		$post_id = self::factory()->post->create();

		$this->assertTrue(
			Content_Restriction_Control::is_post_restricted( false, $post_id, 0 ),
			'Expected the registration gate to restrict an anonymous reader.'
		);
	}

	/**
	 * A post carrying only the Memberships flag reads as exempt and stays readable
	 * once the migrated gates start enforcing.
	 */
	public function test_force_public_stands_in_for_a_missing_exemption() {
		$this->enable_gates_and_register();
		$this->create_registration_gate();
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Content_Restriction_Control::WC_FORCE_PUBLIC_META_KEY, 'yes' );

		$this->assertTrue(
			(bool) get_post_meta( $post_id, Content_Restriction_Control::IS_EXEMPT_META_KEY, true ),
			'A force-public post with no exemption of its own should read as exempt.'
		);
		$this->assertFalse(
			Content_Restriction_Control::is_post_restricted( false, $post_id, 0 ),
			'A force-public post should not be restricted for an anonymous reader.'
		);
	}

	/**
	 * A post a publisher explicitly marked as not public must not become exempt.
	 */
	public function test_force_public_no_is_not_treated_as_truthy() {
		$this->enable_gates_and_register();
		$this->create_registration_gate();
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Content_Restriction_Control::WC_FORCE_PUBLIC_META_KEY, 'no' );

		$this->assertFalse(
			(bool) get_post_meta( $post_id, Content_Restriction_Control::IS_EXEMPT_META_KEY, true ),
			'A post explicitly marked not-public must not read as exempt.'
		);
		$this->assertTrue(
			Content_Restriction_Control::is_post_restricted( false, $post_id, 0 ),
			'A post explicitly marked not-public must still be restricted.'
		);
	}

	/**
	 * A recorded falsy exemption is a decision rather than an absence, so it wins
	 * over the Memberships flag.
	 */
	public function test_a_stored_falsy_exemption_ignores_force_public() {
		$this->enable_gates_and_register();
		$this->create_registration_gate();
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Content_Restriction_Control::WC_FORCE_PUBLIC_META_KEY, 'yes' );
		update_post_meta( $post_id, Content_Restriction_Control::IS_EXEMPT_META_KEY, false );

		$this->assertTrue(
			metadata_exists( 'post', $post_id, Content_Restriction_Control::IS_EXEMPT_META_KEY ),
			'Guard: a falsy exemption must be stored as a row for this case to mean anything.'
		);
		$this->assertFalse(
			(bool) get_post_meta( $post_id, Content_Restriction_Control::IS_EXEMPT_META_KEY, true ),
			'A stored falsy exemption must not be overridden by the Memberships flag.'
		);
		$this->assertTrue(
			Content_Restriction_Control::is_post_restricted( false, $post_id, 0 ),
			'A post whose exemption was turned off must still be restricted.'
		);
	}

	/**
	 * The editor sidebar toggle and the gate must agree about a post's exemption.
	 */
	public function test_rest_reflects_the_fallback_for_the_editor_toggle() {
		$this->enable_gates_and_register();
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, Content_Restriction_Control::WC_FORCE_PUBLIC_META_KEY, 'yes' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		rest_get_server();

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'context', 'edit' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue(
			$data['meta'][ Content_Restriction_Control::IS_EXEMPT_META_KEY ],
			'The editor toggle reads this value; it must reflect the fallback.'
		);
	}

	/**
	 * An exemption turned off in the editor stays off.
	 */
	public function test_toggling_the_exemption_off_persists_and_latches() {
		$this->enable_gates_and_register();
		$this->create_registration_gate();
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Content_Restriction_Control::WC_FORCE_PUBLIC_META_KEY, 'yes' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		rest_get_server();

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_body_params(
			[ 'meta' => [ Content_Restriction_Control::IS_EXEMPT_META_KEY => false ] ]
		);
		$this->assertSame( 200, rest_do_request( $request )->get_status() );

		$this->assertTrue(
			metadata_exists( 'post', $post_id, Content_Restriction_Control::IS_EXEMPT_META_KEY ),
			'Turning the toggle off must write a row rather than leaving the meta absent.'
		);
		$this->reset_restriction_cache();
		Content_Gate::flush_gates_cache();
		$this->assertTrue(
			Content_Restriction_Control::is_post_restricted( false, $post_id, 0 ),
			'Once turned off, the exemption must stay off despite the Memberships flag.'
		);
	}

	/**
	 * The escape hatch for sites that finished migrating and would rather stale
	 * Memberships meta stopped widening access.
	 */
	public function test_the_fallback_can_be_filtered_off() {
		$this->enable_gates_and_register();
		$this->create_registration_gate();
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Content_Restriction_Control::WC_FORCE_PUBLIC_META_KEY, 'yes' );
		add_filter( 'newspack_content_gate_respect_memberships_force_public', '__return_false' );

		$this->assertFalse(
			(bool) get_post_meta( $post_id, Content_Restriction_Control::IS_EXEMPT_META_KEY, true ),
			'The filter must suppress the fallback.'
		);
		$this->assertTrue(
			Content_Restriction_Control::is_post_restricted( false, $post_id, 0 ),
			'A suppressed fallback must leave the gate enforcing.'
		);
	}

	/**
	 * The fallback must stay a fallback. The editor loads every meta value it reads
	 * from REST and posts the whole set back whenever any one of them is edited, so
	 * a synthesised exemption would otherwise be written to the database as a real
	 * row -- silently decoupling the post from Memberships and from the opt-out
	 * filter, on nothing more than someone opening the post and saving it.
	 */
	public function test_an_editor_save_does_not_persist_a_synthesised_exemption() {
		$this->enable_gates_and_register();
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, Content_Restriction_Control::WC_FORCE_PUBLIC_META_KEY, 'yes' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		rest_get_server();

		$read = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$read->set_param( 'context', 'edit' );
		$meta = rest_do_request( $read )->get_data()['meta'];
		$this->assertTrue( $meta[ Content_Restriction_Control::IS_EXEMPT_META_KEY ], 'Guard: the editor must be reading the synthesised value.' );

		// The editor returns the whole set because some other value in it was edited.
		register_post_meta(
			'post',
			'newspack_probe_meta',
			[
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
			] 
		);
		$meta['newspack_probe_meta'] = 'edited';

		$save = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$save->set_body_params( [ 'meta' => $meta ] );
		$saved = rest_do_request( $save );
		$this->assertSame( 200, $saved->get_status() );
		$this->assertTrue(
			$saved->get_data()['meta'][ Content_Restriction_Control::IS_EXEMPT_META_KEY ],
			'The post is still exempt, so the response must still say so.'
		);

		$this->assertFalse(
			metadata_exists( 'post', $post_id, Content_Restriction_Control::IS_EXEMPT_META_KEY ),
			'A synthesised exemption must not be written to the database by an ordinary save.'
		);

		delete_post_meta( $post_id, Content_Restriction_Control::WC_FORCE_PUBLIC_META_KEY );
		$this->assertFalse(
			(bool) get_post_meta( $post_id, Content_Restriction_Control::IS_EXEMPT_META_KEY, true ),
			'Once Memberships no longer forces the post public, the exemption must go with it.'
		);
	}

	/**
	 * The fallback covers the post types the exemption itself is registered for, and
	 * no others. Memberships writes its flag to attachments too, and a gate can reach
	 * one through a specific-posts rule -- but there is no toggle there to see or undo
	 * an exemption, and neither save guard is registered for it.
	 */
	public function test_the_fallback_is_scoped_to_supported_post_types() {
		$this->enable_gates_and_register();
		$attachment_id = self::factory()->post->create(
			[
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			]
		);
		update_post_meta( $attachment_id, Content_Restriction_Control::WC_FORCE_PUBLIC_META_KEY, 'yes' );

		$this->assertNotContains(
			'attachment',
			array_column( (array) Content_Restriction_Control::get_available_post_types(), 'value' ),
			'Guard: this case only means something while attachments are out of scope.'
		);
		$this->assertFalse(
			(bool) get_post_meta( $attachment_id, Content_Restriction_Control::IS_EXEMPT_META_KEY, true ),
			'A post type the exemption is not registered for must not pick one up from the fallback.'
		);
	}

	/**
	 * Only keyed reads get the fallback. Whole-object meta reads must not carry it,
	 * which is what stops an inferred exemption travelling to other posts and other
	 * sites -- newspack-network distributes post meta with exactly such a read.
	 */
	public function test_a_whole_object_meta_read_does_not_carry_the_fallback() {
		$this->enable_gates_and_register();
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Content_Restriction_Control::WC_FORCE_PUBLIC_META_KEY, 'yes' );

		$this->assertTrue(
			(bool) get_post_meta( $post_id, Content_Restriction_Control::IS_EXEMPT_META_KEY, true ),
			'Guard: the keyed read must be answering with the fallback.'
		);
		$this->assertArrayNotHasKey(
			Content_Restriction_Control::IS_EXEMPT_META_KEY,
			get_post_meta( $post_id ),
			'A whole-object read must not carry an exemption that was never recorded.'
		);
		$this->assertArrayNotHasKey(
			Content_Restriction_Control::IS_EXEMPT_META_KEY,
			get_post_custom( $post_id ),
			'get_post_custom() must not carry it either.'
		);
	}

	/**
	 * A site that never turned Access Control on gets no exemption at all.
	 *
	 * Separate process because the feature flag is a constant.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_fallback_is_inert_when_the_feature_is_disabled() {
		// NEWSPACK_CONTENT_GATES is undefined in the default test env.
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Content_Restriction_Control::WC_FORCE_PUBLIC_META_KEY, 'yes' );

		$this->assertEmpty(
			get_post_meta( $post_id, Content_Restriction_Control::IS_EXEMPT_META_KEY, true ),
			'With the feature off, the Memberships flag must not produce an exemption.'
		);
	}
}
