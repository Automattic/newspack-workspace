<?php
/**
 * Tests for the Template_Helper class.
 *
 * @package Newspack\Tests\Unit\Collections
 */

namespace Newspack\Tests\Unit\Collections;

use Newspack\Collections\Post_Type;
use Newspack\Collections\Collection_Meta;
use Newspack\Collections\Collection_Taxonomy;
use Newspack\Collections\Collection_Category_Taxonomy;
use Newspack\Collections\Template_Helper;
use Newspack\Collections\Enqueuer;
use Newspack\Collections\Settings;
use Newspack\Collections\Query_Helper;

/**
 * Tests for the Template_Helper class.
 */
class Test_Template_Helper extends \WP_UnitTestCase {
	use Traits\Trait_Collections_Test;
	use Traits\Trait_Enqueuer_Test;

	/**
	 * Set up the test environment.
	 */
	public function set_up() {
		parent::set_up();

		Post_Type::register_post_type();
		Collection_Meta::register_meta();
		Collection_Taxonomy::register_taxonomy();
		Collection_Category_Taxonomy::register_taxonomy();
	}

	/**
	 * Test template_include returns correct template names.
	 *
	 * @covers \Newspack\Collections\Template_Helper::template_include
	 */
	public function test_template_include() {
		// Test archive template.
		$this->go_to( get_post_type_archive_link( Post_Type::get_post_type() ) );

		$template = Template_Helper::template_include( 'default-template.php' );
		$this->assertStringEndsWith( 'archive-newspack-collection.php', $template, 'Template should match archive template.' );

		// Test single template.
		$collection_id = $this->create_test_collection();
		$this->go_to( get_permalink( $collection_id ) );

		$template = Template_Helper::template_include( 'default-template.php' );
		$this->assertStringEndsWith( 'single-newspack-collection.php', $template, 'Template should match single template.' );
	}

	/**
	 * Test enqueue_assets triggers on collection pages.
	 *
	 * @covers \Newspack\Collections\Template_Helper::enqueue_assets
	 */
	public function test_enqueue_assets() {
		// Test archive page.
		$this->go_to( get_post_type_archive_link( Post_Type::get_post_type() ) );
		Template_Helper::enqueue_assets();
		Enqueuer::maybe_enqueue_frontend_assets();

		$this->assertFalse( wp_script_is( Enqueuer::SCRIPT_NAME_ADMIN, 'registered' ), 'Admin script should not be registered.' );
		$this->assertFalse( wp_style_is( Enqueuer::SCRIPT_NAME_ADMIN, 'registered' ), 'Admin style should not be registered.' );
		$this->assertTrue( wp_script_is( Enqueuer::SCRIPT_NAME_FRONTEND, 'registered' ), 'Frontend script should be registered.' );
		$this->assertTrue( wp_style_is( Enqueuer::SCRIPT_NAME_FRONTEND, 'registered' ), 'Frontend style should be registered.' );

		// Reset state.
		$this->cleanup_enqueued_assets_for_script( Enqueuer::SCRIPT_NAME_FRONTEND );
		$this->reset_enqueuer_data();

		// Test regular post.
		$this->go_to( get_permalink( $this->factory()->post->create() ) );
		Template_Helper::enqueue_assets();
		Enqueuer::maybe_enqueue_frontend_assets();

		$this->assertFalse( wp_script_is( Enqueuer::SCRIPT_NAME_ADMIN, 'registered' ), 'Admin script should not be registered.' );
		$this->assertFalse( wp_style_is( Enqueuer::SCRIPT_NAME_ADMIN, 'registered' ), 'Admin style should not be registered.' );
		$this->assertFalse( wp_script_is( Enqueuer::SCRIPT_NAME_FRONTEND, 'registered' ), 'Frontend script should not be registered.' );
		$this->assertFalse( wp_style_is( Enqueuer::SCRIPT_NAME_FRONTEND, 'registered' ), 'Frontend style should not be registered.' );

		$this->cleanup_enqueued_assets_for_script( Enqueuer::SCRIPT_NAME_FRONTEND );
	}

	/**
	 * Test archive_filters modifies query correctly.
	 *
	 * @covers \Newspack\Collections\Template_Helper::archive_filters
	 */
	public function test_archive_filters() {
		// Create a test category.
		$this->set_current_user_role( 'administrator' );
		$term_data = wp_insert_term( 'Test Category', Collection_Category_Taxonomy::get_taxonomy() );

		// Go to collection archive.
		$this->go_to( get_post_type_archive_link( Post_Type::get_post_type() ) );

		global $wp_query;

		// Test category filtering.
		$_GET[ Settings::CATEGORY_QUERY_PARAM ] = 'test-category';
		Template_Helper::archive_filters( $wp_query );
		$tax_query = $wp_query->get( 'tax_query' );
		$this->assertIsArray( $tax_query, 'Tax query should be an array.' );
		$this->assertEquals( Collection_Category_Taxonomy::get_taxonomy(), $tax_query[0]['taxonomy'], 'Taxonomy should match.' );
		$this->assertEquals( 'test-category', $tax_query[0]['terms'], 'Terms should match.' );

		// Test year filtering.
		$_GET[ Settings::YEAR_QUERY_PARAM ] = '2023';
		Template_Helper::archive_filters( $wp_query );
		$date_query = $wp_query->get( 'date_query' );
		$this->assertIsArray( $date_query, 'Date query should be an array.' );
		$this->assertEquals( 2023, $date_query[0]['year'], 'Year should match.' );

		// Test posts per page setting.
		$posts_per_page = $wp_query->get( 'posts_per_page' );
		$this->assertGreaterThan( 0, $posts_per_page, 'Posts per page should be greater than 0.' );

		// Clean up.
		unset( $_GET[ Settings::CATEGORY_QUERY_PARAM ], $_GET[ Settings::YEAR_QUERY_PARAM ] );
	}

	/**
	 * Test render_image generates correct HTML.
	 *
	 * @covers \Newspack\Collections\Template_Helper::render_image
	 */
	public function test_render_image() {
		$collection_id = $this->create_test_collection();

		// Test without thumbnail (placeholder).
		$html = Template_Helper::render_image( $collection_id );
		$this->assertStringContainsString( 'collection-placeholder', $html, 'Placeholder should be rendered.' );
		$this->assertStringContainsString( get_permalink( $collection_id ), $html, 'Permalink should be rendered.' );

		// Test with permalink disabled.
		$html = Template_Helper::render_image( $collection_id, false );
		$this->assertStringNotContainsString( '<a ', $html, 'Permalink should not be rendered.' );

		// Test with custom permalink.
		$custom_permalink = 'https://example.com';
		$html             = Template_Helper::render_image( $collection_id, $custom_permalink );
		$this->assertStringContainsString( $custom_permalink, $html, 'Custom permalink should be rendered.' );
	}

	/**
	 * Test render_meta_text formats metadata correctly.
	 *
	 * @covers \Newspack\Collections\Template_Helper::render_meta_text
	 */
	public function test_render_meta_text() {
		$collection_id = $this->create_test_collection();

		// Test with no metadata.
		$html = Template_Helper::render_meta_text( $collection_id );
		$this->assertEmpty( $html, 'No metadata should be rendered.' );

		// Test with metadata.
		Collection_Meta::set( $collection_id, 'volume', '1' );
		Collection_Meta::set( $collection_id, 'number', '2' );
		Collection_Meta::set( $collection_id, 'period', 'Spring 2023' );

		$html = Template_Helper::render_meta_text( $collection_id );
		$this->assertStringContainsString( 'Spring 2023', $html, 'Period should be rendered.' );
		$this->assertStringContainsString( 'Vol. 1', $html, 'Volume should be rendered.' );
		$this->assertStringContainsString( 'No. 2', $html, 'Number should be rendered.' );
		$this->assertStringContainsString( '<br>', $html, 'There should be 2 lines by default.' );

		// Test with 1 line.
		$html = Template_Helper::render_meta_text( $collection_id, 1 );
		$this->assertStringNotContainsString( '<br>', $html, 'There should be 1 line.' );
	}

	/**
	 * Test render_cta generates correct button HTML.
	 *
	 * @covers \Newspack\Collections\Template_Helper::render_cta
	 */
	public function test_render_cta() {
		$url   = 'https://example.com';
		$label = 'Subscribe';
		$class = 'cta--subscribe_link';

		$cta = compact( 'url', 'label', 'class' );

		$html = Template_Helper::render_cta( $cta );
		$this->assertStringContainsString( $url, $html, 'URL should be rendered.' );
		$this->assertStringContainsString( $label, $html, 'Label should be rendered.' );
		$this->assertStringContainsString( $class, $html, 'Class should be rendered.' );

		// Test with missing data.
		$empty_cta = [
			'url'   => '',
			'label' => '',
		];
		$html      = Template_Helper::render_cta( $empty_cta );
		$this->assertEmpty( $html, 'Empty CTA should not be rendered.' );
	}

	/**
	 * Test CTA new tab functionality for different URL types.
	 *
	 * @covers \Newspack\Collections\Template_Helper::render_cta
	 * @covers \Newspack\Collections\Template_Helper::should_cta_open_in_new_tab
	 * @covers \Newspack\Collections\Template_Helper::determine_should_cta_open_in_new_tab
	 */
	public function test_cta_new_tab() {
		$collection_id = $this->create_test_collection();
		$attachment_id = $this->factory()->attachment->create();

		// Set up CTAs on the collection.
		$ctas = [
			[
				'label' => 'Download PDF',
				'type'  => 'attachment',
				'id'    => $attachment_id,
			],
			[
				'label' => 'External Link',
				'type'  => 'link',
				'url'   => 'https://external-site.com/page',
			],
			[
				'label' => 'PDF File',
				'type'  => 'link',
				'url'   => home_url( '/uploads/document.pdf' ),
			],
			[
				'label' => 'Internal Page',
				'type'  => 'link',
				'url'   => home_url( '/internal-page' ),
			],
			[
				'label' => 'Relative Page',
				'type'  => 'link',
				'url'   => '/relative-page',
			],
		];

		Collection_Meta::set( $collection_id, 'ctas', $ctas );

		// Get processed CTAs.
		$processed_ctas = Query_Helper::get_ctas( $collection_id );

		$this->assertCount( 5, $processed_ctas, 'All CTAs should be processed.' );

		// Test attachment CTA.
		$attachment_cta = $processed_ctas[0];
		$html           = Template_Helper::render_cta( $attachment_cta );
		$this->assertEquals( 'attachment', $attachment_cta['type'], 'Type should be attachment.' );
		$this->assertStringContainsString( 'target="_blank"', $html, 'Attachment should open in new tab.' );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $html, 'Attachment should have security attributes.' );

		// Test external URL.
		$external_cta = $processed_ctas[1];
		$html         = Template_Helper::render_cta( $external_cta );
		$this->assertEquals( 'link', $external_cta['type'], 'Type should be link.' );
		$this->assertStringContainsString( 'target="_blank"', $html, 'External link should open in new tab.' );

		// Test PDF file.
		$pdf_cta = $processed_ctas[2];
		$html    = Template_Helper::render_cta( $pdf_cta );
		$this->assertStringContainsString( 'target="_blank"', $html, 'PDF should open in new tab.' );

		// Test internal link.
		$internal_cta = $processed_ctas[3];
		$html         = Template_Helper::render_cta( $internal_cta );
		$this->assertStringNotContainsString( 'target="_blank"', $html, 'Internal link should not open in new tab.' );

		// Test relative URL.
		$relative_cta = $processed_ctas[4];
		$html         = Template_Helper::render_cta( $relative_cta );
		$this->assertStringNotContainsString( 'target="_blank"', $html, 'Relative link should not open in new tab.' );
	}

	/**
	 * Test CTA new tab filters work correctly.
	 *
	 * @covers \Newspack\Collections\Template_Helper::should_cta_open_in_new_tab
	 */
	public function test_cta_new_tab_filters() {
		$collection_id = $this->create_test_collection();

		// Test custom file extensions filter.
		add_filter(
			'newspack_collections_new_tab_file_extensions',
			function ( $extensions ) {
				$extensions[] = 'docx';
				return $extensions;
			}
		);

		Collection_Meta::set(
			$collection_id,
			'ctas',
			[
				[
					'label' => 'View Document',
					'type'  => 'link',
					'url'   => home_url( '/path/to/document.docx' ),
				],
			]
		);

		$processed_ctas = Query_Helper::get_ctas( $collection_id );
		$docx_cta       = $processed_ctas[0];
		$html           = Template_Helper::render_cta( $docx_cta );
		$this->assertStringContainsString( 'target="_blank"', $html, 'Custom extension should open in new tab.' );

		// Test internal hosts filter.
		add_filter(
			'newspack_collections_new_tab_internal_hosts',
			function ( $hosts ) {
				$hosts[] = 'partner-site.com';
				return $hosts;
			}
		);

		Collection_Meta::set(
			$collection_id,
			'ctas',
			[
				[
					'label' => 'Partner Site',
					'type'  => 'link',
					'url'   => 'https://partner-site.com/page',
				],
			]
		);

		$processed_ctas = Query_Helper::get_ctas( $collection_id );
		$partner_cta    = $processed_ctas[0];
		$html           = Template_Helper::render_cta( $partner_cta );
		$this->assertStringNotContainsString( 'target="_blank"', $html, 'Site should not open in new tab.' );

		// Test override filter.
		add_filter(
			'newspack_collections_should_cta_open_in_new_tab',
			function ( $result, $cta ) {
				if ( isset( $cta['label'] ) && 'Force New Tab' === $cta['label'] ) {
					return true;
				}
				return $result;
			},
			10,
			2
		);

		Collection_Meta::set(
			$collection_id,
			'ctas',
			[
				[
					'label' => 'Force New Tab',
					'type'  => 'link',
					'url'   => home_url( '/internal-page' ),
				],
			]
		);

		$processed_ctas = Query_Helper::get_ctas( $collection_id );
		$force_cta      = $processed_ctas[0];
		$html           = Template_Helper::render_cta( $force_cta );
		$this->assertStringContainsString( 'target="_blank"', $html, 'Filter override should force new tab.' );
	}

	/**
	 * Test render_articles generates content loop block.
	 *
	 * @covers \Newspack\Collections\Template_Helper::render_articles
	 */
	public function test_render_articles() {
		$post_ids = [
			self::factory()->post->create(),
			self::factory()->post->create(),
		];

		// Register to prevent skipping the rest of the method, given that the block is not registered in the test environment.
		register_block_type(
			'newspack-blocks/homepage-articles',
			[
				'render_callback' => '__return_empty_string',
			]
		);

		$html = Template_Helper::render_articles( $post_ids, 'Test Section' );
		$this->assertIsString( $html, 'Articles HTML should be a string.' );

		// Test global settings override.
		Settings::update_setting( 'articles_block_attrs', [ 'showCategory' => true ] );

		$filter = add_filter(
			'newspack_collections_render_articles_attrs',
			function ( $attrs ) {
				$this->assertTrue( $attrs['showCategory'], 'Global setting should be present.' );
				return $attrs;
			}
		);

		// Test that render_articles doesn't fail with global settings.
		Template_Helper::render_articles( $post_ids );

		remove_filter( 'newspack_collections_render_articles_attrs', $filter );
		unregister_block_type( 'newspack-blocks/homepage-articles' );
	}

	/**
	 * Test render_see_all_link generates correct link.
	 *
	 * @covers \Newspack\Collections\Template_Helper::render_see_all_link
	 */
	public function test_render_see_all_link() {
		$html = Template_Helper::render_see_all_link();

		$this->assertStringContainsString( '<a ', $html, 'See all link should be rendered.' );
		$this->assertStringContainsString( get_post_type_archive_link( Post_Type::get_post_type() ), $html, 'See all link should point to the collection archive.' );
	}

	/**
	 * Test load_template_part handles collections template parts correctly.
	 *
	 * @covers \Newspack\Collections\Template_Helper::load_template_part
	 */
	public function test_load_template_part() {
		// Test collections template part with name parameter.
		ob_start();
		Template_Helper::load_template_part( Template_Helper::TEMPLATE_PARTS_DIR . 'newspack-collection-intro', 'variant', [], [] );
		$this->assertEmpty( ob_get_clean(), 'Template with name should not output without existing file.' );

		// Test collections template part with missing file should not output anything.
		ob_start();
		Template_Helper::load_template_part( Template_Helper::TEMPLATE_PARTS_DIR . 'non-existent', null, [], [] );
		$this->assertEmpty( ob_get_clean(), 'Missing template should not output anything.' );

		// Test non-collections template part.
		ob_start();
		Template_Helper::load_template_part( 'some/other/template', null, [], [] );
		$this->assertEmpty( ob_get_clean(), 'Non-collections template should not be processed.' );
	}

	/**
	 * Test should_show_cover_story_image respects settings and meta.
	 *
	 * @covers \Newspack\Collections\Template_Helper::should_show_cover_story_image
	 */
	public function test_should_show_cover_story_image() {
		$collection_id = $this->create_test_collection();

		// Test default behavior (inherit from global setting).
		$this->assertFalse( Template_Helper::should_show_cover_story_image( $collection_id ) );

		// Test collection-specific override to show.
		Collection_Meta::set( $collection_id, 'cover_story_img_visibility', 'show' );
		$this->assertTrue( Template_Helper::should_show_cover_story_image( $collection_id ) );
	}

	/**
	 * Test update_document_title modifies title for collections archive pages.
	 *
	 * @covers \Newspack\Collections\Template_Helper::update_document_title
	 */
	public function test_update_document_title() {
		$original_title_parts = [
			'title' => 'Original Title',
			'site'  => 'Test Site',
		];

		$custom_label = 'Magazines';

		// Set custom collection label.
		Settings::update_settings(
			[
				'custom_naming_enabled' => true,
				'custom_name'           => $custom_label,
			]
		);

		// Test on collections archive page.
		$this->go_to( get_post_type_archive_link( Post_Type::get_post_type() ) );

		$modified_title_parts = Template_Helper::update_document_title( $original_title_parts );
		$this->assertEquals( $custom_label, $modified_title_parts['title'], 'Title should be updated to custom label on archive page.' );
		$this->assertEquals( $original_title_parts['site'], $modified_title_parts['site'], 'Other title parts should remain unchanged.' );

		// Test on regular page (should not modify title).
		$this->go_to( home_url() );

		$unmodified_title_parts = Template_Helper::update_document_title( $original_title_parts );
		$this->assertEquals( $original_title_parts['title'], $unmodified_title_parts['title'], 'Title should not be modified.' );
	}

	/**
	 * Test normalize_post_list handles various input types correctly.
	 *
	 * @covers \Newspack\Collections\Template_Helper::normalize_post_list
	 */
	public function test_normalize_post_list() {
		$post_id_1 = $this->create_test_collection();
		$post_id_2 = $this->create_test_collection();
		$post_1    = get_post( $post_id_1 );
		$post_2    = get_post( $post_id_2 );

		// Test empty array.
		$result = Template_Helper::normalize_post_list( [] );
		$this->assertEquals( 'ids', $result['type'] );
		$this->assertEmpty( $result['items'] );

		// Test all WP_Post objects.
		$result = Template_Helper::normalize_post_list( [ $post_1, $post_2 ] );
		$this->assertEquals( 'objects', $result['type'] );
		$this->assertSame( [ $post_1, $post_2 ], $result['items'] );

		// Test all IDs.
		$result = Template_Helper::normalize_post_list( [ $post_id_1, $post_id_2 ] );
		$this->assertEquals( 'ids', $result['type'] );
		$this->assertEquals( [ $post_id_1, $post_id_2 ], $result['items'] );

		// Test mixed content (forces ID mode).
		$result = Template_Helper::normalize_post_list( [ $post_1, $post_id_2, (string) $post_id_1 ] );
		$this->assertEquals( 'ids', $result['type'] );
		$this->assertEquals( [ $post_id_1, $post_id_2 ], $result['items'] ); // Deduplicated.

		// Test invalid items (null, non-numeric strings are skipped; 0 is filtered out; -1 becomes 1).
		$result = Template_Helper::normalize_post_list( [ $post_id_1, null, 'invalid', 0, -1 ] );
		$this->assertEquals( 'ids', $result['type'] );
		$this->assertEquals( [ $post_id_1, 1 ], $result['items'] ); // 0 filtered out, -1 becomes 1.
	}

	/**
	 * Test render_collections_intro generates collections block HTML.
	 *
	 * @covers \Newspack\Collections\Template_Helper::render_collections_intro
	 */
	public function test_render_collections_intro() {
		$collection = get_post( $this->create_test_collection() );

		// Test basic rendering.
		$html = Template_Helper::render_collections_intro( $collection );
		$this->assertIsString( $html, 'Intro HTML should be a string.' );

		// Test with invalid collection.
		$html = Template_Helper::render_collections_intro( null );
		$this->assertEmpty( $html, 'Invalid collection should return empty string.' );
	}
}
