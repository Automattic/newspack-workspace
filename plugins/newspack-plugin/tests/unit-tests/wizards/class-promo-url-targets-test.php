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
}
