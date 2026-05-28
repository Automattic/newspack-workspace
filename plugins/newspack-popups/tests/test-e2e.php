<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class E2E Test
 *
 * @package Newspack_Popups
 */

/**
 * E2E test case.
 * (as e2e as we can get without spinning up a headless browser).
 */
class E2ETest extends WP_UnitTestCase_PageWithPopups {
	/**
	 * Test duplication feature.
	 * Duplicated prompts should have the same content, taxonomy terms, and prompt options as the original.
	 * Duplicated prompt title should have "copy" appended to the original prompt's title.
	 */
	public function test_e2e_duplicate_prompt() {
		$original_popup_id = self::createPopup(
			'Hello world',
			[
				'placement'           => 'center',
				'selected_segment_id' => self::$segments[0]['id'],
			]
		);

		// Set up some taxonomy terms to apply to the prompt.
		$category_id = self::factory()->term->create(
			[
				'name'     => 'Featured',
				'taxonomy' => 'category',
				'slug'     => 'featured',
			]
		);
		$tag_id      = self::factory()->term->create(
			[
				'name'     => 'Best of',
				'taxonomy' => 'post_tag',
				'slug'     => 'best-of',
			]
		);
		$campaign_id = self::factory()->term->create(
			[
				'name'     => 'Everyday',
				'taxonomy' => Newspack_Popups::NEWSPACK_POPUPS_TAXONOMY,
				'slug'     => 'everyday',
			]
		);
		$segment_id  = self::factory()->term->create(
			[
				'name'     => 'Loyal Readers',
				'taxonomy' => Newspack_Segments_Model::TAX_SLUG,
				'slug'     => 'loyal-readers',
			]
		);
		Newspack_Popups_Model::set_popup_terms( $original_popup_id, [ $category_id ], 'category' );
		Newspack_Popups_Model::set_popup_terms( $original_popup_id, [ $tag_id ], 'post_tag' );
		Newspack_Popups_Model::set_popup_terms( $original_popup_id, [ $campaign_id ], Newspack_Popups::NEWSPACK_POPUPS_TAXONOMY );
		Newspack_Popups_Model::set_popup_terms( $original_popup_id, [ $segment_id ], Newspack_Segments_Model::TAX_SLUG );

		$duplicate_popup_id          = Newspack_Popups::duplicate_popup( $original_popup_id );
		$second_duplicate_id         = Newspack_Popups::duplicate_popup( $duplicate_popup_id );
		$duplicate_with_new_title_id = Newspack_Popups::duplicate_popup( $original_popup_id, 'Second popup' );
		$subsequent_duplicate_id     = Newspack_Popups::duplicate_popup( $duplicate_with_new_title_id );

		$original_popup   = get_post( $original_popup_id );
		$duplicate_popup  = get_post( $duplicate_popup_id );
		$second_duplicate = get_post( $second_duplicate_id );

		self::assertEquals(
			$duplicate_popup->post_title,
			'Popup title copy',
			'Duplicated prompt appends "copy" to original post title.'
		);

		self::assertEquals(
			$second_duplicate->post_title,
			'Popup title copy 2',
			'Subsequent duplicates are iterated based on the original’s title.'
		);

		self::assertEquals(
			get_the_title( $subsequent_duplicate_id ),
			'Second popup copy',
			'Subsequent duplicates are iterated based on their parent’s title.'
		);

		self::assertEquals(
			$duplicate_popup->post_content,
			$original_popup->post_content,
			'Duplicated prompt has same content as original prompt.'
		);

		self::assertEquals(
			wp_get_post_terms( $original_popup_id, [ 'category', 'post_tag', Newspack_Popups::NEWSPACK_POPUPS_TAXONOMY ], [ 'fields' => 'ids' ] ),
			wp_get_post_terms( $duplicate_popup_id, [ 'category', 'post_tag', Newspack_Popups::NEWSPACK_POPUPS_TAXONOMY ], [ 'fields' => 'ids' ] ),
			'Duplicated prompt has the same categories, tags, and campaign terms as original prompt.'
		);

		self::assertEquals(
			Newspack_Popups_Model::get_popup_options( $original_popup_id ),
			Newspack_Popups_Model::get_popup_options( $duplicate_popup_id ),
			'Duplicated prompt has the same prompt options as the original prompt.'
		);

		self::assertEquals(
			Newspack_Popups_Model::get_popup_segments( $original_popup_id ),
			Newspack_Popups_Model::get_popup_segments( $duplicate_popup_id ),
			'Duplicate prompt has the same segments as the original prompt.'
		);
	}
}
