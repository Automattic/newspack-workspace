<?php
/**
 * Class TestDynamicGallery
 *
 * @package Newspack_Network
 */

namespace Test\Content_Distribution;

use Newspack_Network\Content_Distribution\Blocks;

/**
 * Test flattening of WordPress 7.1 dynamic galleries for distribution.
 */
class TestDynamicGallery extends \WP_UnitTestCase {

	/**
	 * The post the gallery images are attached to.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * The attached image IDs.
	 *
	 * @var int[]
	 */
	private $attachment_ids = [];

	/**
	 * Set up a post with three attached images.
	 */
	public function set_up() {
		parent::set_up();

		$this->post_id        = self::factory()->post->create();
		$this->attachment_ids = [];

		for ( $i = 0; $i < 3; $i++ ) {
			$this->attachment_ids[] = self::factory()->attachment->create_object(
				[
					'file'           => "dg-{$i}.png",
					'post_parent'    => $this->post_id,
					'post_mime_type' => 'image/png',
					'post_excerpt'   => "Caption {$i}",
				]
			);
		}
	}

	/**
	 * Skip when the WordPress under test predates dynamic galleries.
	 *
	 * The behaviour under test only exists from WordPress 7.1. Skipping loudly is
	 * better than asserting nothing, so the gap is visible in the CI output.
	 */
	private function requires_dynamic_galleries() {
		if ( ! function_exists( 'block_core_gallery_resolve_dynamic_source' ) ) {
			$this->markTestSkipped( 'Dynamic galleries require WordPress 7.1 or later; this suite runs ' . get_bloginfo( 'version' ) . '.' );
		}
	}

	/**
	 * Build a dynamic gallery block, in the shape the editor saves it.
	 *
	 * @param array  $attrs      Extra gallery attributes.
	 * @param string $inner_html Saved inner HTML, i.e. the gallery caption.
	 *
	 * @return array The parsed block.
	 */
	private function dynamic_gallery( $attrs = [], $inner_html = '' ) {
		$attrs  = array_merge( [ 'dynamicContent' => [ 'source' => 'core/attached-media' ] ], $attrs );
		$markup = '<!-- wp:gallery ' . serialize_block_attributes( $attrs ) . ' -->' . $inner_html . '<!-- /wp:gallery -->';
		$blocks = parse_blocks( $markup );
		return $blocks[0];
	}

	/**
	 * Flatten a block and return it serialized.
	 *
	 * @param array $block The block.
	 *
	 * @return string The serialized block.
	 */
	private function flatten( $block ) {
		return serialize_blocks( [ Blocks::process_outgoing_block( $block, $this->post_id ) ] );
	}

	/**
	 * A dynamic gallery is replaced by the images attached to the post.
	 */
	public function test_resolves_attached_images() {
		$this->requires_dynamic_galleries();

		$output = $this->flatten( $this->dynamic_gallery() );

		$this->assertStringNotContainsString( 'dynamicContent', $output, 'The dynamic instruction must not be distributed.' );
		$this->assertSame( 3, substr_count( $output, '<!-- wp:image ' ), 'Every attached image should become an image block.' );

		foreach ( $this->attachment_ids as $attachment_id ) {
			$this->assertStringContainsString( 'wp-image-' . $attachment_id, $output );
		}
	}

	/**
	 * The flattened gallery no longer depends on the rendering post's attachments,
	 * which is the whole point: a node has only the sideloaded featured image.
	 */
	public function test_renders_on_a_post_without_attachments() {
		$this->requires_dynamic_galleries();

		$output = $this->flatten( $this->dynamic_gallery() );
		$node   = self::factory()->post->create( [ 'post_content' => $output ] );

		$rendered = '';
		foreach ( parse_blocks( get_post( $node )->post_content ) as $block ) {
			$rendered .= render_block( $block );
		}

		$this->assertSame( 3, substr_count( $rendered, '<img ' ), 'All images should render where the post has none attached.' );
	}

	/**
	 * Gallery settings that live on the wrapper survive.
	 */
	public function test_preserves_wrapper_settings() {
		$this->requires_dynamic_galleries();

		$output = $this->flatten(
			$this->dynamic_gallery(
				[
					'columns'   => 3,
					'imageCrop' => false,
				]
			)
		);

		$this->assertStringContainsString( 'columns-3', $output, 'The column count must survive.' );
		$this->assertStringNotContainsString( 'is-cropped', $output, 'imageCrop false must not be cropped on the node.' );
	}

	/**
	 * The gallery caption survives. It is a sourced attribute, so it lives only in
	 * the block's inner HTML.
	 */
	public function test_preserves_gallery_caption() {
		$this->requires_dynamic_galleries();

		$caption = '<figcaption class="blocks-gallery-caption wp-element-caption">Scenes from the parade</figcaption>';
		$output  = $this->flatten( $this->dynamic_gallery( [], $caption ) );

		$this->assertStringContainsString( 'Scenes from the parade', $output );
	}

	/**
	 * Linked galleries keep their links, and the lightbox keeps working.
	 */
	public function test_preserves_links_and_lightbox() {
		$this->requires_dynamic_galleries();

		$first = $this->attachment_ids[0];

		$media = $this->flatten( $this->dynamic_gallery( [ 'linkTo' => 'media' ] ) );
		$this->assertSame( 3, substr_count( $media, '<a href=' ), 'Linked galleries must keep their anchors.' );
		$this->assertStringContainsString( 'href="' . esc_url( wp_get_attachment_url( $first ) ) . '"', $media, 'media must link to the file.' );

		$attachment = $this->flatten( $this->dynamic_gallery( [ 'linkTo' => 'attachment' ] ) );
		$this->assertStringContainsString( 'href="' . esc_url( get_attachment_link( $first ) ) . '"', $attachment, 'attachment must link to the attachment page.' );

		$blank = $this->flatten(
			$this->dynamic_gallery(
				[
					'linkTo'     => 'media',
					'linkTarget' => '_blank',
				] 
			) 
		);
		$this->assertStringContainsString( 'target="_blank"', $blank );
		$this->assertStringContainsString( 'rel="noopener"', $blank );

		$lightbox = $this->flatten( $this->dynamic_gallery( [ 'linkTo' => 'lightbox' ] ) );
		$this->assertStringNotContainsString( '"linkDestination":"lightbox"', $lightbox, 'lightbox is not a linkDestination value.' );
		$this->assertStringContainsString( '"lightbox":{"enabled":true}', $lightbox, 'The lightbox must be switched on, not merely mentioned.' );
	}

	/**
	 * Attribute values cannot break out of the block comment delimiter.
	 */
	public function test_attribute_values_cannot_inject_markup() {
		$this->requires_dynamic_galleries();

		$payload = 'x"} --><img src=x onerror=alert(1)>';
		$output  = $this->flatten( $this->dynamic_gallery( [ 'className' => $payload ] ) );

		$this->assertStringNotContainsString( '<img src=x onerror', $output, 'Attribute values must not become markup.' );

		$reparsed = parse_blocks( $output );
		$this->assertSame( $payload, $reparsed[0]['attrs']['className'], 'The attribute must survive intact.' );
		$this->assertCount( 3, $reparsed[0]['innerBlocks'], 'The block must not be corrupted.' );
	}

	/**
	 * When nothing resolves, the dynamic instruction must not be distributed. A node
	 * would resolve it against its own attachments, which is the bug being fixed.
	 */
	public function test_fails_closed_when_nothing_resolves() {
		$this->requires_dynamic_galleries();

		$empty  = self::factory()->post->create();
		$output = serialize_blocks( [ Blocks::process_outgoing_block( $this->dynamic_gallery(), $empty ) ] );

		$this->assertStringNotContainsString( 'dynamicContent', $output );
		$this->assertStringNotContainsString( '<!-- wp:image ', $output );
	}

	/**
	 * A gallery nested inside another block is processed too.
	 */
	public function test_flattens_a_nested_gallery() {
		$this->requires_dynamic_galleries();

		$markup = '<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:gallery ' . serialize_block_attributes( [ 'dynamicContent' => [ 'source' => 'core/attached-media' ] ] ) . ' /-->'
			. '</div><!-- /wp:group -->';

		$post_id = $this->post_id;
		$output  = serialize_blocks(
			array_map(
				function ( $block ) use ( $post_id ) {
					return Blocks::process_outgoing_block( $block, $post_id );
				},
				parse_blocks( $markup )
			)
		);

		$this->assertStringNotContainsString( 'dynamicContent', $output );
		$this->assertSame( 3, substr_count( $output, '<!-- wp:image ' ) );
	}

	/**
	 * An ordinary gallery is left exactly as it is.
	 */
	public function test_leaves_an_ordinary_gallery_alone() {
		$markup = '<!-- wp:gallery {"linkTo":"none"} --><figure class="wp-block-gallery has-nested-images">'
			. '<!-- wp:image {"id":1} --><figure class="wp-block-image"><img src="http://example.org/a.png" class="wp-image-1"/></figure><!-- /wp:image -->'
			. '</figure><!-- /wp:gallery -->';

		$blocks = parse_blocks( $markup );
		$this->assertSame( serialize_blocks( $blocks ), $this->flatten( $blocks[0] ) );
	}

	/**
	 * Processing an already flattened gallery changes nothing further.
	 */
	public function test_is_idempotent() {
		$this->requires_dynamic_galleries();

		$once  = Blocks::process_outgoing_block( $this->dynamic_gallery(), $this->post_id );
		$twice = Blocks::process_outgoing_block( $once, $this->post_id );

		$this->assertSame( serialize_blocks( [ $once ] ), serialize_blocks( [ $twice ] ) );
	}

	/**
	 * The post ID reaches a registered outgoing callback.
	 */
	public function test_post_id_reaches_the_callback() {
		$seen = null;
		Blocks::register_block_processor(
			'core/separator',
			function ( $block, $post_id ) use ( &$seen ) {
				$seen = $post_id;
				return $block;
			}
		);

		Blocks::process_outgoing_block( parse_blocks( '<!-- wp:separator /-->' )[0], 4242 );
		Blocks::reset_block_processors( 'core/separator' );

		$this->assertSame( 4242, $seen );
	}

	/**
	 * A cropped gallery keeps its ratio on the node.
	 *
	 * The image block's save() derives the inline style from the aspectRatio and
	 * scale attributes, so the two have to travel together or the block fails
	 * validation. Assert both, so they cannot come apart again.
	 */
	public function test_preserves_aspect_ratio() {
		$this->requires_dynamic_galleries();

		$output = $this->flatten( $this->dynamic_gallery( [ 'aspectRatio' => '16/9' ] ) );

		$this->assertStringContainsString( '"aspectRatio":"16/9"', $output, 'The ratio must reach the image block attributes.' );
		$this->assertStringContainsString( '"scale":"cover"', $output, 'scale travels with aspectRatio.' );
		$this->assertSame( 3, substr_count( $output, 'style="aspect-ratio:16/9;object-fit:cover"' ), 'Every image needs the persisted style.' );
	}

	/**
	 * An "auto" ratio adds nothing, which is what core saves for an uncropped gallery.
	 */
	public function test_auto_aspect_ratio_adds_nothing() {
		$this->requires_dynamic_galleries();

		$output = $this->flatten( $this->dynamic_gallery( [ 'aspectRatio' => 'auto' ] ) );

		$this->assertStringNotContainsString( 'aspect-ratio:', $output );
		$this->assertStringNotContainsString( '"scale"', $output );
	}

	/**
	 * The recursion reaches a nested Jetpack gallery.
	 *
	 * This is the part of the change that reaches sites before they update to 7.1,
	 * so unlike the gallery tests it has to run on every WordPress version.
	 */
	public function test_recursion_reaches_a_nested_jetpack_gallery() {
		$markup = '<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:jetpack/tiled-gallery {"ids":[11,22,33]} --><div class="wp-block-jetpack-tiled-gallery"></div><!-- /wp:jetpack/tiled-gallery -->'
			. '</div><!-- /wp:group -->';

		$blocks = parse_blocks( $markup );
		$output = serialize_blocks( [ Blocks::process_outgoing_block( $blocks[0], $this->post_id ) ] );

		$this->assertStringNotContainsString( '"ids"', $output, 'Origin attachment IDs are meaningless on a node.' );
		$this->assertStringContainsString( 'wp:jetpack/tiled-gallery', $output, 'The block itself must survive.' );
	}

	/**
	 * Recursion must return one block per block, or the parent's innerContent
	 * placeholders stop lining up with its inner blocks.
	 */
	public function test_recursion_preserves_block_structure() {
		$markup = '<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:paragraph --><p>One</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>Two</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->';

		$blocks = parse_blocks( $markup );
		$this->assertSame( $markup, serialize_blocks( [ Blocks::process_outgoing_block( $blocks[0], $this->post_id ) ] ) );
	}
}
