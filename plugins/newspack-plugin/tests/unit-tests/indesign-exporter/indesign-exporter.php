<?php
/**
 * Tests the InDesign Exporter functionality.
 *
 * @package Newspack\Tests
 */

use Newspack\Optional_Modules\InDesign_Export\InDesign_Converter;

/**
 * Tests the InDesign Exporter functionality.
 */
class Newspack_Test_InDesign_Exporter extends WP_UnitTestCase {
	/**
	 * Test converting a simple post.
	 */
	public function test_convert_simple_post() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<p>This is a test post.</p>',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<ASCII-WIN>', $content );
		$this->assertStringContainsString( '<pstyle:24head>Test Post', $content );
		$this->assertStringContainsString( '<pstyle:text>This is a test post.', $content );
	}

	/**
	 * Test converting pullquotes.
	 */
	public function test_convert_pullquote() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<blockquote><p>A pullquote content</p><cite>John Doe</cite></blockquote>',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<pstyle:pullquote>A pullquote content', $content );
		$this->assertStringContainsString( '<pstyle:pullquotename>John Doe', $content );
	}

	/**
	 * Test converting blockquotes.
	 */
	public function test_convert_blockquote() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<blockquote class="wp-block-quote">This is a blockquote.</blockquote>',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<pstyle:blockquote>This is a blockquote.', $content );
	}

	/**
	 * Test converting lists.
	 */
	public function test_convert_list() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<ul><li>Item 1.</li><li>Item 2.</li></ul>',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<bnListType:Bullet>Item 1.<bnListType:>', $content );
		$this->assertStringContainsString( '<bnListType:Bullet>Item 2.<bnListType:>', $content );
	}

	/**
	 * Test cleaning HTML markup.
	 */
	public function test_clean_html_markup() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<div><p>This is a test post.</p></div>',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<pstyle:text>This is a test post.', $content );
		$this->assertStringNotContainsString( '<div>', $content );
		$this->assertStringNotContainsString( '<p>', $content );
	}

	/**
	 * Test converting superscript and subscript.
	 */
	public function test_convert_superscript_and_subscript() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<p>This is a test post with <sup>superscript</sup> and <sub>subscript</sub>.</p>',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<pstyle:text>This is a test post with <cPosition:Superscript>superscript<cPosition:> and <cPosition:Subscript>subscript<cPosition:>.', $content );
	}

	/**
	 * Test cleaning img markup.
	 */
	public function test_clean_img_markup() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<figure class="wp-block-image size-large"><img src="http://localhost/image.jpg" alt="" class="wp-image-1234"/><figcaption class="wp-element-caption">My Caption <span class="image-credit"><span class="credit-label-wrapper">Credit:</span> <a href="http://localhost/credit">My Credit</a></span></figcaption></figure>',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringNotContainsString( '<figure', $content );
		$this->assertStringNotContainsString( '<figcaption', $content );
		$this->assertStringNotContainsString( '<img', $content );
	}

	/**
	 * Test image processing.
	 */
	public function test_image_processing() {
		$thumbnail_id = $this->factory->attachment->create();
		wp_update_post(
			[
				'ID'           => $thumbnail_id,
				'post_excerpt' => 'Featured Image Caption',
			]
		);
		update_post_meta( $thumbnail_id, '_media_credit', 'Featured Image Credit' );

		$image_id = $this->factory->attachment->create();
		wp_update_post(
			[
				'ID'           => $image_id,
				'post_excerpt' => 'Image Caption',
			]
		);
		update_post_meta( $image_id, '_media_credit', 'Image Credit' );

		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:image {"id":' . $image_id . '} --><!-- /wp:image -->',
			]
		);
		update_post_meta( $post_id, '_thumbnail_id', $thumbnail_id );

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<pstyle:PhotoCaption>Featured Image Caption', $content );
		$this->assertStringContainsString( '<pstyle:PhotoCredit>Featured Image Credit', $content );
		$this->assertStringContainsString( '<pstyle:PhotoCaption>Image Caption', $content );
		$this->assertStringContainsString( '<pstyle:PhotoCredit>Image Credit', $content );
	}

	/**
	 * Test image with custom caption.
	 */
	public function test_image_with_custom_caption() {
		$image_id = $this->factory->attachment->create();
		wp_update_post(
			[
				'ID'           => $image_id,
				'post_excerpt' => 'Image Caption',
			]
		);

		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:image {"id":' . $image_id . '} --><figure class="wp-block-image"><img src="http://localhost/wp-content/uploads/2025/01/image.jpg" /><figcaption class="wp-element-caption">Custom Caption</figcaption></figure><!-- /wp:image -->',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<pstyle:PhotoCaption>Custom Caption', $content );
	}

	/**
	 * Test converting HTML entities.
	 */
	public function test_convert_html_entities() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<p>This is a test post with &nbsp;, &amp;, &lt;, &gt; and •.</p>',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<pstyle:text>This is a test post with  , &, <, > and <CharStyle:bullet>n<CharStyle:>.', $content );
	}

	/**
	 * Test converting special characters.
	 */
	public function test_convert_special_characters() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<p>àáâãäåæçèéêëìíîïñòóôõöøùúûüýÿĀāĂă…€</p>',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<0x00E0><0x00E1><0x00E2><0x00E3><0x00E4><0x00E5><0x00E6><0x00E7><0x00E8><0x00E9><0x00EA><0x00EB><0x00EC><0x00ED><0x00EE><0x00EF><0x00F1><0x00F2><0x00F3><0x00F4><0x00F5><0x00F6><0x00F8><0x00F9><0x00FA><0x00FB><0x00FC><0x00FD><0x00FF><0x0100><0x0101><0x0102><0x0103><0x2026><0x20AC>', $content );
	}

	/**
	 * Test blocks with custom tags.
	 */
	public function test_convert_blocks_with_custom_tags() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:paragraph {"indesignTag":"customparagraph"} --><p>This is a test post with custom tag.</p><!-- /wp:paragraph -->',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<customparagraph>This is a test post with custom tag.', $content );
	}

	/**
	 * Test headings.
	 */
	public function test_convert_headings() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<h1>Heading 1</h1><h2>Heading 2</h2><h3>Heading 3</h3><h4>Heading 4</h4><h5>Heading 5</h5><h6>Heading 6</h6>',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<pstyle:h1>Heading 1', $content );
		$this->assertStringContainsString( '<pstyle:h2>Heading 2', $content );
		$this->assertStringContainsString( '<pstyle:h3>Heading 3', $content );
		$this->assertStringContainsString( '<pstyle:h4>Heading 4', $content );
		$this->assertStringContainsString( '<pstyle:h5>Heading 5', $content );
		$this->assertStringContainsString( '<pstyle:h6>Heading 6', $content );
	}

	/**
	 * Test horizontal rule.
	 */
	public function test_convert_horizontal_rule() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<hr>',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<pstyle:hr>', $content );
	}

	/**
	 * Test that core/file blocks are excluded from export.
	 *
	 * PDF embeds have no print equivalent and their raw markup (<object> tags,
	 * download links) must not appear in the InDesign output.
	 */
	public function test_file_block_excluded() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:paragraph --><p>Before the file.</p><!-- /wp:paragraph --><!-- wp:file {"id":1,"href":"https://example.com/document.pdf"} --><div class="wp-block-file"><object class="wp-block-file__embed" data="https://example.com/document.pdf" type="application/pdf" style="width:100%;height:600px"></object><a href="https://example.com/document.pdf" class="wp-block-file__button">Download</a></div><!-- /wp:file --><!-- wp:paragraph --><p>After the file.</p><!-- /wp:paragraph -->',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id );

		$this->assertStringContainsString( 'Before the file.', $content );
		$this->assertStringContainsString( 'After the file.', $content );
		$this->assertStringNotContainsString( '<object', $content );
		$this->assertStringNotContainsString( 'document.pdf', $content );
		$this->assertStringNotContainsString( 'Download', $content );
	}

	/**
	 * Test that core/embed blocks are excluded from export.
	 *
	 * Rich media embeds (YouTube, etc.) have no print equivalent and their
	 * raw URLs must not appear in the InDesign output.
	 */
	public function test_embed_block_excluded() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:paragraph --><p>Before the embed.</p><!-- /wp:paragraph --><!-- wp:embed {"url":"https://www.youtube.com/watch?v=abc123","type":"video","providerNameSlug":"youtube"} --><figure class="wp-block-embed is-type-video is-provider-youtube"><div class="wp-block-embed__wrapper">' . "\n" . 'https://www.youtube.com/watch?v=abc123' . "\n" . '</div></figure><!-- /wp:embed --><!-- wp:paragraph --><p>After the embed.</p><!-- /wp:paragraph -->',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id );

		$this->assertStringContainsString( 'Before the embed.', $content );
		$this->assertStringContainsString( 'After the embed.', $content );
		$this->assertStringNotContainsString( 'youtube.com', $content );
		$this->assertStringNotContainsString( 'abc123', $content );
	}

	/**
	 * Test that core/file blocks are excluded from export when nested inside a group block.
	 */
	public function test_file_block_excluded_when_nested_in_group() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:paragraph --><p>Before the group.</p><!-- /wp:paragraph --><!-- wp:group --><div class="wp-block-group"><!-- wp:file {"id":1,"href":"https://example.com/document.pdf"} --><div class="wp-block-file"><object class="wp-block-file__embed" data="https://example.com/document.pdf" type="application/pdf" style="width:100%;height:600px"></object><a href="https://example.com/document.pdf" class="wp-block-file__button">Download</a></div><!-- /wp:file --></div><!-- /wp:group --><!-- wp:paragraph --><p>After the group.</p><!-- /wp:paragraph -->',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id );

		$this->assertStringContainsString( 'Before the group.', $content );
		$this->assertStringContainsString( 'After the group.', $content );
		$this->assertStringNotContainsString( '<object', $content );
		$this->assertStringNotContainsString( 'document.pdf', $content );
		$this->assertStringNotContainsString( 'Download', $content );
	}

	/**
	 * Test that core/embed blocks are excluded from export when nested inside a group block.
	 */
	public function test_embed_block_excluded_when_nested_in_group() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:paragraph --><p>Before the group.</p><!-- /wp:paragraph --><!-- wp:group --><div class="wp-block-group"><!-- wp:embed {"url":"https://www.youtube.com/watch?v=abc123","type":"video","providerNameSlug":"youtube"} --><figure class="wp-block-embed is-type-video is-provider-youtube"><div class="wp-block-embed__wrapper">' . "\n" . 'https://www.youtube.com/watch?v=abc123' . "\n" . '</div></figure><!-- /wp:embed --></div><!-- /wp:group --><!-- wp:paragraph --><p>After the group.</p><!-- /wp:paragraph -->',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id );

		$this->assertStringContainsString( 'Before the group.', $content );
		$this->assertStringContainsString( 'After the group.', $content );
		$this->assertStringNotContainsString( 'youtube.com', $content );
		$this->assertStringNotContainsString( 'abc123', $content );
	}

	/**
	 * Test that core/video blocks are excluded from export.
	 *
	 * Video embeds have no print equivalent and their raw markup must not
	 * appear in the InDesign output.
	 */
	public function test_video_block_excluded() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:paragraph --><p>Before the video.</p><!-- /wp:paragraph --><!-- wp:video {"id":1} --><figure class="wp-block-video"><video controls src="https://example.com/video.mp4"></video></figure><!-- /wp:video --><!-- wp:paragraph --><p>After the video.</p><!-- /wp:paragraph -->',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id );

		$this->assertStringContainsString( 'Before the video.', $content );
		$this->assertStringContainsString( 'After the video.', $content );
		$this->assertStringNotContainsString( 'video.mp4', $content );
		$this->assertStringNotContainsString( '<video', $content );
	}

	/**
	 * Test that core/audio blocks are excluded from export.
	 *
	 * Audio embeds have no print equivalent and their raw markup must not
	 * appear in the InDesign output.
	 */
	public function test_audio_block_excluded() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:paragraph --><p>Before the audio.</p><!-- /wp:paragraph --><!-- wp:audio {"id":1} --><figure class="wp-block-audio"><audio controls src="https://example.com/audio.mp3"></audio></figure><!-- /wp:audio --><!-- wp:paragraph --><p>After the audio.</p><!-- /wp:paragraph -->',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id );

		$this->assertStringContainsString( 'Before the audio.', $content );
		$this->assertStringContainsString( 'After the audio.', $content );
		$this->assertStringNotContainsString( 'audio.mp3', $content );
		$this->assertStringNotContainsString( '<audio', $content );
	}

	/**
	 * Test that core/embed blocks are excluded from export when nested inside a columns block.
	 *
	 * The core/columns block has a different innerContent shape from core/group (it contains
	 * core/column children which in turn contain the embed), exercising the recursive
	 * strip logic through two container levels.
	 */
	public function test_embed_block_excluded_when_nested_in_columns() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:paragraph --><p>Before the columns.</p><!-- /wp:paragraph --><!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:embed {"url":"https://www.youtube.com/watch?v=xyz789","type":"video","providerNameSlug":"youtube"} --><figure class="wp-block-embed is-type-video is-provider-youtube"><div class="wp-block-embed__wrapper">' . "\n" . 'https://www.youtube.com/watch?v=xyz789' . "\n" . '</div></figure><!-- /wp:embed --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Text in second column.</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns --><!-- wp:paragraph --><p>After the columns.</p><!-- /wp:paragraph -->',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id );

		$this->assertStringContainsString( 'Before the columns.', $content );
		$this->assertStringContainsString( 'After the columns.', $content );
		$this->assertStringNotContainsString( 'youtube.com', $content );
		$this->assertStringNotContainsString( 'xyz789', $content );
	}

	/**
	 * Test that two consecutive excluded blocks inside a container are both removed.
	 *
	 * This exercises the $inner_index increment path in strip_excluded_blocks() where
	 * two null placeholders in innerContent map to two consecutive excluded innerBlocks
	 * entries — ensuring the index stays in sync after the first block is skipped.
	 */
	public function test_two_excluded_siblings_in_container() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:paragraph --><p>Before the group.</p><!-- /wp:paragraph --><!-- wp:group --><div class="wp-block-group"><!-- wp:embed {"url":"https://www.youtube.com/watch?v=first","type":"video","providerNameSlug":"youtube"} --><figure class="wp-block-embed is-type-video is-provider-youtube"><div class="wp-block-embed__wrapper">' . "\n" . 'https://www.youtube.com/watch?v=first' . "\n" . '</div></figure><!-- /wp:embed --><!-- wp:embed {"url":"https://www.youtube.com/watch?v=second","type":"video","providerNameSlug":"youtube"} --><figure class="wp-block-embed is-type-video is-provider-youtube"><div class="wp-block-embed__wrapper">' . "\n" . 'https://www.youtube.com/watch?v=second' . "\n" . '</div></figure><!-- /wp:embed --><!-- wp:paragraph --><p>After both embeds.</p><!-- /wp:paragraph --></div><!-- /wp:group --><!-- wp:paragraph --><p>After the group.</p><!-- /wp:paragraph -->',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id );

		$this->assertStringContainsString( 'Before the group.', $content );
		$this->assertStringContainsString( 'After both embeds.', $content );
		$this->assertStringContainsString( 'After the group.', $content );
		$this->assertStringNotContainsString( 'first', $content );
		$this->assertStringNotContainsString( 'second', $content );
	}

	/**
	 * Test that legacy core-embed/* blocks (pre-WP 5.6) are excluded from export.
	 *
	 * WordPress 5.6 unified embed blocks under core/embed. Older content may still
	 * contain core-embed/youtube, core-embed/vimeo, etc. These must also be excluded.
	 */
	public function test_legacy_core_embed_block_excluded() {
		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:paragraph --><p>Before the embed.</p><!-- /wp:paragraph --><!-- wp:core-embed/youtube {"url":"https://www.youtube.com/watch?v=legacy123"} --><figure class="wp-block-embed-youtube"><div class="wp-block-embed__wrapper">' . "\n" . 'https://www.youtube.com/watch?v=legacy123' . "\n" . '</div></figure><!-- /wp:core-embed/youtube --><!-- wp:paragraph --><p>After the embed.</p><!-- /wp:paragraph -->',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id );

		$this->assertStringContainsString( 'Before the embed.', $content );
		$this->assertStringContainsString( 'After the embed.', $content );
		$this->assertStringNotContainsString( 'youtube.com', $content );
		$this->assertStringNotContainsString( 'legacy123', $content );
	}

	/**
	 * Test that a custom block type added via the filter is excluded from export.
	 *
	 * Verifies the `newspack_indesign_export_excluded_blocks` filter is an effective
	 * extension point for publishers with custom rich-media blocks.
	 */
	public function test_custom_block_excluded_via_filter() {
		$callback = function ( $types ) {
			$types[] = 'my-plugin/custom-embed';
			return $types;
		};
		add_filter( 'newspack_indesign_export_excluded_blocks', $callback );

		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:paragraph --><p>Before the custom block.</p><!-- /wp:paragraph --><!-- wp:my-plugin/custom-embed --><div>CUSTOM_EMBED_MARKER</div><!-- /wp:my-plugin/custom-embed --><!-- wp:paragraph --><p>After the custom block.</p><!-- /wp:paragraph -->',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id );

		remove_filter( 'newspack_indesign_export_excluded_blocks', $callback );

		$this->assertStringContainsString( 'Before the custom block.', $content );
		$this->assertStringContainsString( 'After the custom block.', $content );
		$this->assertStringNotContainsString( 'CUSTOM_EMBED_MARKER', $content );
	}

	/**
	 * Test that a misbehaving filter callback does not break the export.
	 *
	 * The filter result is normalized to an array of strings, so a callback
	 * returning null, a string, or any non-array type must not cause a TypeError.
	 */
	public function test_filter_returning_non_array_does_not_break_export() {
		$callback = function () {
			return null;
		};
		add_filter( 'newspack_indesign_export_excluded_blocks', $callback );

		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:paragraph --><p>Content survives a bad filter.</p><!-- /wp:paragraph -->',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id );

		remove_filter( 'newspack_indesign_export_excluded_blocks', $callback );

		$this->assertStringContainsString( 'Content survives a bad filter.', $content );
	}

	/**
	 * Test that legacy core-embed/* blocks follow the core/embed filter state.
	 *
	 * When a publisher removes core/embed from the filter to allow embed content
	 * in exports, legacy core-embed/* blocks should also be allowed for consistency.
	 */
	public function test_legacy_core_embed_follows_core_embed_filter() {
		$callback = function ( $types ) {
			return array_values( array_diff( $types, [ 'core/embed' ] ) );
		};
		add_filter( 'newspack_indesign_export_excluded_blocks', $callback );

		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:paragraph --><p>Before the embed.</p><!-- /wp:paragraph --><!-- wp:core-embed/youtube {"url":"https://www.youtube.com/watch?v=legacy123"} --><figure class="wp-block-embed-youtube"><div class="wp-block-embed__wrapper">' . "\n" . 'https://www.youtube.com/watch?v=legacy123' . "\n" . '</div></figure><!-- /wp:core-embed/youtube --><!-- wp:paragraph --><p>After the embed.</p><!-- /wp:paragraph -->',
			]
		);

		$converter = new InDesign_Converter();
		$content   = $converter->convert_post( $post_id );

		remove_filter( 'newspack_indesign_export_excluded_blocks', $callback );

		$this->assertStringContainsString( 'Before the embed.', $content );
		$this->assertStringContainsString( 'After the embed.', $content );
		$this->assertStringContainsString( 'legacy123', $content );
	}

	/**
	 * Test image caption and credit special characters.
	 */
	public function test_image_caption_and_credit_special_characters() {
		$image_id = $this->factory->attachment->create();
		wp_update_post(
			[
				'ID'           => $image_id,
				'post_excerpt' => 'Image Caption with á é í ó ú ñ ç ð ð &nbsp;, &amp;, &lt;, &gt; and •.',
			]
		);
		update_post_meta( $image_id, '_media_credit', 'Image Credit with á é í ó ú ñ ç ð ð &nbsp;, &amp;, &lt;, &gt; and •.' );

		$post_id = $this->factory->post->create(
			[
				'post_title'   => 'Test Post',
				'post_content' => '<!-- wp:image {"id":' . $image_id . '} --><figure class="wp-block-image"><img src="http://localhost/wp-content/uploads/2025/01/image.jpg" /><figcaption class="wp-element-caption">Image Caption with á é í ó ú ñ ç ð ð &nbsp;, &amp;, &lt;, &gt; and •.</figcaption></figure><!-- /wp:image -->',
			]
		);

		$converter = new InDesign_Converter();
		$content = $converter->convert_post( $post_id );
		$this->assertStringContainsString( '<pstyle:PhotoCaption>Image Caption with <0x00E1> <0x00E9> <0x00ED> <0x00F3> <0x00FA> <0x00F1> <0x00E7> <0x00F0> <0x00F0>  , &, <, > and <CharStyle:bullet>n<CharStyle:>.', $content );
		$this->assertStringContainsString( '<pstyle:PhotoCredit>Image Credit with <0x00E1> <0x00E9> <0x00ED> <0x00F3> <0x00FA> <0x00F1> <0x00E7> <0x00F0> <0x00F0>  , &, <, > and <CharStyle:bullet>n<CharStyle:>.', $content );
	}
}
