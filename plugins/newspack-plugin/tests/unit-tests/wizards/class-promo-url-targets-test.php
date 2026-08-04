<?php
/**
 * Tests for the promotional-URL target scanner.
 *
 * @package Newspack\Tests
 */

use Newspack\Promo_Url_Targets;

/**
 * Promo URL targets scanner test case.
 */
class Promo_Url_Targets_Test extends WP_UnitTestCase {
	const BUTTON = 'newspack-blocks/checkout-button';

	/**
	 * Test extracting blocks at top-level and nested within groups.
	 */
	public function test_extract_blocks_finds_top_level_and_nested() {
		$content = '<!-- wp:newspack-blocks/checkout-button {"product":"11"} /-->'
			. '<!-- wp:group --><div><!-- wp:newspack-blocks/checkout-button {"product":"22","variation":"33"} /--></div><!-- /wp:group -->';
		$attrs   = Promo_Url_Targets::extract_blocks( $content, self::BUTTON );
		$this->assertCount( 2, $attrs );
		$this->assertSame( '11', $attrs[0]['product'] );
		$this->assertSame( '33', $attrs[1]['variation'] );
	}

	/**
	 * Test resolving blocks within synced pattern references.
	 */
	public function test_extract_blocks_resolves_synced_pattern_refs() {
		$pattern_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:newspack-blocks/checkout-button {"product":"44"} /-->',
			]
		);
		$content    = '<!-- wp:block {"ref":' . $pattern_id . '} /-->';
		$attrs      = Promo_Url_Targets::extract_blocks( $content, self::BUTTON );
		$this->assertCount( 1, $attrs );
		$this->assertSame( '44', $attrs[0]['product'] );
	}

	/**
	 * Test ignoring other blocks and missing pattern references.
	 */
	public function test_extract_blocks_ignores_other_blocks_and_missing_refs() {
		$content = '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --><!-- wp:block {"ref":999999} /-->';
		$this->assertSame( [], Promo_Url_Targets::extract_blocks( $content, self::BUTTON ) );
	}

	/**
	 * Test finding candidate posts with direct content and pattern references.
	 */
	public function test_find_candidate_post_ids_matches_direct_content_and_pattern_refs() {
		$direct_id  = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:newspack-blocks/checkout-button {"product":"11"} /-->',
			]
		);
		$pattern_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:newspack-blocks/checkout-button {"product":"44"} /-->',
			]
		);
		$ref_id     = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:block {"ref":' . $pattern_id . '} /-->',
			]
		);
		$draft_id   = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_content' => '<!-- wp:newspack-blocks/checkout-button {"product":"11"} /-->',
			]
		);
		list( $ids, $truncated ) = Promo_Url_Targets::find_candidate_post_ids( self::BUTTON );
		$this->assertContains( $direct_id, $ids );
		$this->assertContains( $ref_id, $ids );
		$this->assertNotContains( $draft_id, $ids );
		$this->assertNotContains( $pattern_id, $ids );
		$this->assertFalse( $truncated );
	}

	/**
	 * Test cache version bumping only for relevant post types.
	 */
	public function test_bump_cache_version_only_for_relevant_post_types() {
		delete_option( Promo_Url_Targets::CACHE_VERSION_OPTION );
		$attachment = self::factory()->post->create( [ 'post_type' => 'attachment' ] );
		Promo_Url_Targets::bump_cache_version( $attachment );
		$this->assertFalse( get_option( Promo_Url_Targets::CACHE_VERSION_OPTION ) );
		$page = self::factory()->post->create( [ 'post_type' => 'page' ] );
		Promo_Url_Targets::bump_cache_version( $page );
		$this->assertNotFalse( get_option( Promo_Url_Targets::CACHE_VERSION_OPTION ) );
	}

	/**
	 * Test that ref matching is anchored to the exact pattern id, not merely
	 * a numeric prefix of it (e.g. pattern id 5 must not match a page that
	 * references pattern id 52).
	 */
	public function test_ref_matching_is_anchored_to_exact_pattern_id() {
		$pattern_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:newspack-blocks/checkout-button {"product":"11"} /-->',
			]
		);
		$decoy_id   = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:block {"ref":' . $pattern_id . '2} /-->',
			]
		);
		$ref_id     = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:block {"ref":' . $pattern_id . '} /-->',
			]
		);
		list( $ids, $truncated ) = Promo_Url_Targets::find_candidate_post_ids( self::BUTTON );
		$this->assertNotContains( $decoy_id, $ids );
		$this->assertContains( $ref_id, $ids );
		$this->assertFalse( $truncated );
	}

	/**
	 * Test that the returned candidate ids are capped at SCAN_LIMIT and the
	 * truncated flag is set when there are more matches than that.
	 */
	public function test_truncated_flag_when_scan_limit_exceeded() {
		for ( $i = 0; $i < Promo_Url_Targets::SCAN_LIMIT + 1; $i++ ) {
			self::factory()->post->create(
				[
					'post_type'    => 'post',
					'post_status'  => 'publish',
					'post_content' => '<!-- wp:newspack-blocks/checkout-button {"product":"1"} /-->',
				]
			);
		}
		list( $ids, $truncated ) = Promo_Url_Targets::find_candidate_post_ids( self::BUTTON );
		$this->assertCount( Promo_Url_Targets::SCAN_LIMIT, $ids );
		$this->assertTrue( $truncated );
	}

	/**
	 * Test that a circular chain of synced-pattern refs terminates via the
	 * depth cap instead of recursing indefinitely.
	 */
	public function test_extract_blocks_survives_circular_pattern_refs() {
		$post_a = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:newspack-blocks/checkout-button {"product":"11"} /-->',
			]
		);
		$post_b = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:block {"ref":' . $post_a . '} /-->',
			]
		);
		// Close the cycle now that both posts exist.
		wp_update_post(
			[
				'ID'           => $post_a,
				'post_content' => '<!-- wp:newspack-blocks/checkout-button {"product":"11"} /--><!-- wp:block {"ref":' . $post_b . '} /-->',
			]
		);
		$attrs = Promo_Url_Targets::extract_blocks( '<!-- wp:block {"ref":' . $post_a . '} /-->', self::BUTTON );
		$this->assertIsArray( $attrs );
		$this->assertNotEmpty( $attrs );
		$this->assertSame( '11', $attrs[0]['product'] );
	}

	/**
	 * Helper to build a test product family.
	 */
	private function family() {
		return [
			'parent'     => 100,
			'variations' => [ 101, 102 ],
			'members'    => [],
		];
	}

	/**
	 * Test deriving config for a parent product button with variation picker.
	 */
	public function test_derive_parent_button_with_picker() {
		$config = Promo_Url_Targets::derive_checkout_button_config(
			[
				'product'     => '100',
				'is_variable' => true,
			],
			$this->family()
		);
		$this->assertSame( 100, $config['product_id'] );
		$this->assertNull( $config['variation_id'] );
		$this->assertTrue( $config['has_variation_picker'] );
	}

	/**
	 * Test deriving config for a variation-locked button.
	 */
	public function test_derive_variation_locked_button() {
		$config = Promo_Url_Targets::derive_checkout_button_config(
			[
				'product'     => '100',
				'variation'   => '102',
				'is_variable' => true,
			],
			$this->family()
		);
		$this->assertSame( 100, $config['product_id'] );
		$this->assertSame( 102, $config['variation_id'] );
		$this->assertFalse( $config['has_variation_picker'] );
	}

	/**
	 * Test deriving config for a button pointing directly at a variation.
	 */
	public function test_derive_button_pointing_directly_at_variation() {
		$config = Promo_Url_Targets::derive_checkout_button_config( [ 'product' => '101' ], $this->family() );
		$this->assertSame( 100, $config['product_id'] );
		$this->assertSame( 101, $config['variation_id'] );
	}

	/**
	 * Test deriving config for a grouped product member button.
	 */
	public function test_derive_grouped_member_button_uses_member_id() {
		$family = [
			'parent'     => 200,
			'variations' => [],
			'members'    => [ 201, 202 ],
		];
		$config = Promo_Url_Targets::derive_checkout_button_config( [ 'product' => '201' ], $family );
		$this->assertSame( 201, $config['product_id'] );
		$this->assertNull( $config['variation_id'] );
		$this->assertFalse( $config['has_variation_picker'] );
	}

	/**
	 * Test deriving config for a grouped parent button with picker.
	 */
	public function test_derive_grouped_parent_button_has_picker() {
		$family = [
			'parent'     => 200,
			'variations' => [],
			'members'    => [ 201, 202 ],
		];
		$config = Promo_Url_Targets::derive_checkout_button_config( [ 'product' => '200' ], $family );
		$this->assertSame( 200, $config['product_id'] );
		$this->assertTrue( $config['has_variation_picker'] );
	}

	/**
	 * Test that derive_checkout_button_config returns null for unrelated products.
	 */
	public function test_derive_returns_null_for_unrelated_product() {
		$this->assertNull( Promo_Url_Targets::derive_checkout_button_config( [ 'product' => '999' ], $this->family() ) );
		$this->assertNull( Promo_Url_Targets::derive_checkout_button_config( [], $this->family() ) );
	}

	/**
	 * Test that derive_checkout_button_config carries coupon and after_success.
	 */
	public function test_derive_carries_coupon_and_after_success() {
		$config = Promo_Url_Targets::derive_checkout_button_config(
			[
				'product'                 => '100',
				'coupon'                  => 'SPRING20',
				'afterSuccessBehavior'    => 'custom',
				'afterSuccessURL'         => 'https://example.com/thanks',
				'afterSuccessButtonLabel' => 'Continue',
			],
			$this->family()
		);
		$this->assertSame( 'SPRING20', $config['coupon'] );
		$this->assertSame( 'custom', $config['after_success']['behavior'] );
		$this->assertSame( 'https://example.com/thanks', $config['after_success']['url'] );
		// Default button label alone must not fabricate an after_success config.
		$plain = Promo_Url_Targets::derive_checkout_button_config(
			[
				'product'                 => '100',
				'afterSuccessButtonLabel' => 'Continue',
			],
			$this->family()
		);
		$this->assertNull( $plain['after_success'] );
		$this->assertNull( $plain['coupon'] );
	}

	/**
	 * Helper to build a test donate configuration.
	 *
	 * @param array $overrides Optional overrides to apply.
	 * @return array Donate configuration.
	 */
	private function donate_configuration( $overrides = [] ) {
		return array_merge(
			[
				'is_tier_based_layout' => false,
				'tiered'               => true,
				'frequencies'          => [
					'month' => 'Monthly',
					'year'  => 'Annually',
				],
				'amounts'              => [
					'once'  => [ 9, 20, 90, 20 ],
					'month' => [ 7, 15, 30, 15 ],
					'year'  => [ 84, 180, 360, 180 ],
				],
				'defaultFrequency'     => 'month',
				'minimumDonation'      => 5,
			],
			$overrides
		);
	}

	/**
	 * Test mapping tiers-based layout.
	 */
	public function test_map_tiers_based_layout() {
		$config = Promo_Url_Targets::map_donate_configuration(
			$this->donate_configuration( [ 'is_tier_based_layout' => true ] ),
			true
		);
		$this->assertSame( 'tiered', $config['layout_param'] );
		$this->assertTrue( $config['frequencies']['month']['enabled'] );
		$this->assertFalse( $config['frequencies']['once']['enabled'] );
		$this->assertSame( [ 7.0, 15.0, 30.0 ], $config['frequencies']['month']['amounts'] );
		$this->assertFalse( $config['frequencies']['month']['supports_custom'] );
	}

	/**
	 * Test mapping frequency-based tiered layout.
	 */
	public function test_map_frequency_based_tiered_layout() {
		$config = Promo_Url_Targets::map_donate_configuration( $this->donate_configuration(), true );
		$this->assertSame( 'frequency', $config['layout_param'] );
		$this->assertTrue( $config['frequencies']['month']['supports_custom'] );
		$config_no_nyp = Promo_Url_Targets::map_donate_configuration( $this->donate_configuration(), false );
		$this->assertFalse( $config_no_nyp['frequencies']['month']['supports_custom'] );
	}

	/**
	 * Test mapping untiered layout with and without NYP.
	 */
	public function test_map_untiered_layout_with_and_without_nyp() {
		$config = Promo_Url_Targets::map_donate_configuration(
			$this->donate_configuration( [ 'tiered' => false ] ),
			true
		);
		$this->assertSame( 'untiered', $config['layout_param'] );
		$this->assertTrue( $config['frequencies']['month']['supports_custom'] );
		$this->assertSame( 15.0, $config['frequencies']['month']['suggested'] );
		$this->assertSame( [], $config['frequencies']['month']['amounts'] );

		$config_no_nyp = Promo_Url_Targets::map_donate_configuration(
			$this->donate_configuration( [ 'tiered' => false ] ),
			false
		);
		$this->assertSame( 'frequency', $config_no_nyp['layout_param'] );
		$this->assertSame( [ 15.0 ], $config_no_nyp['frequencies']['month']['amounts'] );
		$this->assertFalse( $config_no_nyp['frequencies']['month']['supports_custom'] );
	}

	/**
	 * Test that default frequency and minimum are carried through.
	 */
	public function test_map_carries_default_frequency_and_minimum() {
		$config = Promo_Url_Targets::map_donate_configuration( $this->donate_configuration(), true );
		$this->assertSame( 'month', $config['default_frequency'] );
		$this->assertSame( 5.0, $config['minimum'] );
	}

	/**
	 * Test that get_donate_target_config returns null when newspack-blocks is not loaded.
	 */
	public function test_get_donate_target_config_without_newspack_blocks_returns_null() {
		// newspack-blocks is not loaded in this test suite, so the guarded seam
		// must bail out cleanly rather than fataling.
		$this->assertNull( Promo_Url_Targets::get_donate_target_config( [ 'useModalCheckout' => true ] ) );
	}

	/**
	 * Test that get_donate_target_config rejects an explicit modal-checkout
	 * opt-out before the newspack-blocks class guards, since an explicit
	 * false never needs schema defaults to be resolved.
	 */
	public function test_get_donate_target_config_rejects_explicit_modal_optout() {
		$this->assertNull( Promo_Url_Targets::get_donate_target_config( [ 'useModalCheckout' => false ] ) );
	}

	/**
	 * Test that evaluate_donate_configuration gates on WP_Error and on a
	 * non-WooCommerce platform before mapping, and maps through otherwise.
	 */
	public function test_evaluate_donate_configuration_gates() {
		$this->assertNull( Promo_Url_Targets::evaluate_donate_configuration( new WP_Error( 'error', 'Error' ), true ) );
		$this->assertNull(
			Promo_Url_Targets::evaluate_donate_configuration(
				$this->donate_configuration( [ 'platform' => 'stripe' ] ),
				true
			)
		);
		$config = Promo_Url_Targets::evaluate_donate_configuration(
			$this->donate_configuration( [ 'platform' => 'wc' ] ),
			true
		);
		$this->assertArrayHasKey( 'layout_param', $config );
	}

	/**
	 * Test that get_targets scans and returns matching pages with derived
	 * block configs, and reports truncation.
	 */
	public function test_get_targets_returns_matching_pages_with_configs() {
		delete_option( Promo_Url_Targets::CACHE_VERSION_OPTION );
		$page_id = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Support us',
				'post_content' => '<!-- wp:newspack-blocks/checkout-button {"product":"300"} /-->',
			]
		);
		self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:newspack-blocks/checkout-button {"product":"999"} /-->',
			]
		);
		$result = Promo_Url_Targets::get_targets( 'checkout_button', 300 );
		$this->assertCount( 1, $result['targets'] );
		$this->assertSame( $page_id, $result['targets'][0]['id'] );
		$this->assertSame( 'Support us', $result['targets'][0]['title'] );
		$this->assertSame( 300, $result['targets'][0]['blocks'][0]['product_id'] );
		$this->assertFalse( $result['truncated'] );
	}

	/**
	 * Test that get_targets caches its result until the cache version bumps.
	 */
	public function test_get_targets_is_cached_until_version_bump() {
		delete_option( Promo_Url_Targets::CACHE_VERSION_OPTION );
		$result_before = Promo_Url_Targets::get_targets( 'checkout_button', 301 );
		$this->assertCount( 0, $result_before['targets'] );
		$page_id = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:newspack-blocks/checkout-button {"product":"301"} /-->',
			]
		);
		// Same version: cached empty result.
		$this->assertCount( 0, Promo_Url_Targets::get_targets( 'checkout_button', 301 )['targets'] );
		Promo_Url_Targets::bump_cache_version( $page_id );
		$this->assertCount( 1, Promo_Url_Targets::get_targets( 'checkout_button', 301 )['targets'] );
	}

	/**
	 * Test the promo-targets REST endpoint's permission check and type
	 * validation, and the shape of a successful response.
	 *
	 * The wizard's own endpoint registration is also hooked explicitly before
	 * dispatch, so this doesn't depend on how/when the constructor's hook
	 * fires relative to `do_action( 'rest_api_init' )`.
	 */
	public function test_promo_targets_endpoint_permissions_and_validation() {
		$wizard = new Newspack\Audience_Subscription_Products();
		add_action( 'rest_api_init', [ $wizard, 'register_api_endpoints' ] );
		do_action( 'rest_api_init' );
		$route = '/newspack/v1/wizard/newspack-audience-subscription-products/promo-targets';

		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_param( 'type', 'donate' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 403, $response->get_status() );

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		get_user_by( 'id', $admin )->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'GET', $route );
		$request->set_param( 'type', 'checkout_button' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );

		$request = new WP_REST_Request( 'GET', $route );
		$request->set_param( 'type', 'donate' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'targets', $data );
		$this->assertArrayHasKey( 'truncated', $data );
		// Donate targets carry no product context.
		$this->assertArrayNotHasKey( 'eligible_children', $data );

		$request = new WP_REST_Request( 'GET', $route );
		$request->set_param( 'type', 'checkout_button' );
		$request->set_param( 'product_id', 12345 );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'targets', $data );
		$this->assertArrayHasKey( 'truncated', $data );
		$this->assertArrayHasKey( 'eligible_children', $data );
	}

	/**
	 * Test that a grouped plan whose children the tiers picker cannot serve
	 * reports no picker, so the UI never offers a "reader chooses" option that
	 * would resolve to no radio at runtime.
	 */
	public function test_derive_grouped_parent_without_picker_members_has_no_picker() {
		$family = [
			'parent'         => 200,
			'variations'     => [],
			'members'        => [ 201, 202 ],
			'picker_members' => [],
		];
		$config = Promo_Url_Targets::derive_checkout_button_config( [ 'product' => '200' ], $family );
		$this->assertSame( 200, $config['product_id'] );
		$this->assertFalse( $config['has_variation_picker'] );

		$family['picker_members'] = [ 201 ];
		$with_picker              = Promo_Url_Targets::derive_checkout_button_config( [ 'product' => '200' ], $family );
		$this->assertTrue( $with_picker['has_variation_picker'] );
	}

	/**
	 * Test that eligible children span variations for a variable plan and only
	 * picker-servable members for a grouped one.
	 */
	public function test_get_eligible_children() {
		$this->assertSame(
			[ 101, 102 ],
			Promo_Url_Targets::get_eligible_children(
				[
					'parent'         => 100,
					'variations'     => [ 101, 102 ],
					'members'        => [],
					'picker_members' => [],
				]
			)
		);
		$this->assertSame(
			[ 201 ],
			Promo_Url_Targets::get_eligible_children(
				[
					'parent'         => 200,
					'variations'     => [],
					'members'        => [ 201, 202 ],
					'picker_members' => [ 201 ],
				]
			)
		);
	}

	/**
	 * Test that the content scan is cached per block type, so every plan row
	 * reuses it instead of repeating the LIKE queries. Asserted by removing the
	 * matching page behind save_post's back: a fresh scan would miss it.
	 */
	public function test_scanned_blocks_are_cached_per_block_type() {
		global $wpdb;
		delete_option( Promo_Url_Targets::CACHE_VERSION_OPTION );
		$page_id = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:newspack-blocks/checkout-button {"product":"401"} /-->',
			]
		);
		$first = Promo_Url_Targets::get_scanned_blocks( self::BUTTON );
		$this->assertCount( 1, $first['posts'] );
		$this->assertSame( '401', $first['posts'][0]['attrs'][0]['product'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->posts, [ 'ID' => $page_id ] );
		clean_post_cache( $page_id );
		$this->assertCount( 1, Promo_Url_Targets::get_scanned_blocks( self::BUTTON )['posts'] );
	}

	/**
	 * Test that hitting the synced-pattern cap signals truncation, even when the
	 * resulting page set stays well under SCAN_LIMIT — there may be further
	 * patterns, and pages referencing them, that were never looked at.
	 */
	public function test_truncated_flag_when_pattern_scan_limit_reached() {
		delete_option( Promo_Url_Targets::CACHE_VERSION_OPTION );
		for ( $i = 0; $i < Promo_Url_Targets::PATTERN_SCAN_LIMIT; $i++ ) {
			self::factory()->post->create(
				[
					'post_type'    => 'wp_block',
					'post_status'  => 'publish',
					'post_content' => '<!-- wp:newspack-blocks/checkout-button {"product":"1"} /-->',
				]
			);
		}
		list( $ids, $truncated ) = Promo_Url_Targets::find_candidate_post_ids( self::BUTTON );
		$this->assertTrue( $truncated );
		$this->assertLessThanOrEqual( Promo_Url_Targets::SCAN_LIMIT, count( $ids ) );
	}

	/**
	 * Test that a pattern referenced by more pages than the scan can return also
	 * signals truncation.
	 */
	public function test_truncated_flag_when_pattern_referencing_pages_exceed_limit() {
		delete_option( Promo_Url_Targets::CACHE_VERSION_OPTION );
		$pattern_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:newspack-blocks/checkout-button {"product":"1"} /-->',
			]
		);
		for ( $i = 0; $i < Promo_Url_Targets::SCAN_LIMIT + 1; $i++ ) {
			self::factory()->post->create(
				[
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_content' => '<!-- wp:block {"ref":' . $pattern_id . '} /-->',
				]
			);
		}
		list( $ids, $truncated ) = Promo_Url_Targets::find_candidate_post_ids( self::BUTTON );
		$this->assertTrue( $truncated );
		$this->assertCount( Promo_Url_Targets::SCAN_LIMIT, $ids );
	}

	/**
	 * Test that candidates come back newest-modified first, which is what the
	 * UI's "only the most recently updated pages were checked" warning promises.
	 */
	public function test_find_candidate_post_ids_orders_by_post_modified_desc() {
		global $wpdb;
		delete_option( Promo_Url_Targets::CACHE_VERSION_OPTION );
		$content = '<!-- wp:newspack-blocks/checkout-button {"product":"1"} /-->';
		$older   = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $content,
			]
		);
		$newer   = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $content,
			]
		);
		// Set post_modified explicitly: wp_insert_post derives it from the
		// insert time, so two posts created in the same second would tie.
		$modified_dates = [
			$older => '2020-01-01 00:00:00',
			$newer => '2030-01-01 00:00:00',
		];
		foreach ( $modified_dates as $id => $modified ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->posts,
				[
					'post_modified'     => $modified,
					'post_modified_gmt' => $modified,
				],
				[ 'ID' => $id ]
			);
			clean_post_cache( $id );
		}
		list( $ids, $truncated ) = Promo_Url_Targets::find_candidate_post_ids( self::BUTTON );
		$this->assertFalse( $truncated );
		$this->assertSame( [ $newer, $older ], array_slice( $ids, 0, 2 ) );
	}
}
