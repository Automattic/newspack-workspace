<?php
/**
 * Class Test_Duplicate_Popup
 *
 * @package Newspack_Popups
 */

/**
 * Duplicating a prompt.
 */
class Test_Duplicate_Popup extends WP_UnitTestCase {
	/**
	 * Create a prompt.
	 *
	 * @param string $title   Title.
	 * @param string $content Content, unslashed.
	 * @return int Prompt ID.
	 */
	private function create_prompt( $title, $content = '' ) {
		return self::factory()->post->create(
			[
				'post_type'    => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_title'   => $title,
				'post_content' => wp_slash( $content ),
			]
		);
	}

	/**
	 * The copy keeps the original's backslashes, in content and in options,
	 * including the escapes block attributes use.
	 */
	public function test_copy_keeps_backslashes() {
		$content = '<!-- wp:button {"text":"\u0022Subscribe\u0022"} --><p>Back\slash</p><!-- /wp:button -->';
		$id      = $this->create_prompt( 'Spring drive', $content );
		update_post_meta( $id, 'additional_classes', wp_slash( 'promo\banner' ) );
		$this->assertSame( $content, get_post( $id )->post_content );

		$copy = Newspack_Popups::duplicate_popup( $id, 'Spring drive copy' );

		$this->assertSame( $content, get_post( $copy )->post_content );
		$this->assertSame( 'promo\banner', get_post_meta( $copy, 'additional_classes', true ) );
	}
}
