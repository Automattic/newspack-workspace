<?php
/**
 * InDesign Converter - Converts WordPress posts to Adobe InDesign Tagged Text format.
 *
 * @package Newspack
 */

namespace Newspack\Optional_Modules\InDesign_Export;

defined( 'ABSPATH' ) || exit;

/**
 * Converts WordPress posts to Adobe InDesign Tagged Text format.
 */
class InDesign_Converter {

	/**
	 * Tagged Text start tag, written as the first line of every export.
	 *
	 * This declares the format of the file itself — not the operating system
	 * running InDesign, which reads this format on both macOS and Windows.
	 * ASCII because get_transformed_text() escapes every code point above 127
	 * to a <0xXXXX> tag, leaving the payload 7-bit; WIN because every line ends
	 * with self::EOL.
	 *
	 * @var string
	 */
	private const START_TAG = '<ASCII-WIN>';

	/**
	 * Line terminator used throughout the exported file.
	 *
	 * Must stay consistent with self::START_TAG: <ASCII-WIN> promises InDesign
	 * that lines end with CRLF, and <ASCII-MAC> would promise a bare CR. When
	 * the header and the terminators disagree, the leftover byte pushes each
	 * <pstyle:...> tag off the start of its paragraph and InDesign renders the
	 * markup as literal text (NPPM-3098). Change one and you must change the
	 * other.
	 *
	 * @var string
	 */
	private const EOL = "\r\n";

	/**
	 * Block types with no print equivalent, excluded from InDesign export by default.
	 * Filterable via the newspack_indesign_export_excluded_blocks filter.
	 *
	 * @var string[]
	 */
	const EXCLUDED_BLOCK_TYPES = [
		'core/file',
		'core/embed',
		'core/video',
		'core/audio',
	];

	/**
	 * Default InDesign styles configuration.
	 *
	 * @var array
	 */
	private static $default_styles = [
		'headline'          => '<pstyle:24head>',
		'initial_paragraph' => '<pstyle:dropcap>',
		'paragraph'         => '<pstyle:text>',
		'horizontal_rule'   => '<pstyle:hr>',
		'subhead'           => '<pstyle:12sub>',
		'byline'            => '<pstyle:byline>By ',
		'pullquote'         => '<pstyle:pullquote>',
		'pullquote_name'    => '<pstyle:pullquotename>',
		'blockquote'        => '<pstyle:blockquote>',
	];

	/**
	 * InDesign styles configuration.
	 *
	 * @var array
	 */
	private $styles;

	/**
	 * Constructor.
	 *
	 * @param array $styles Optional. Custom InDesign styles configuration.
	 */
	public function __construct( $styles = [] ) {
		$this->styles = wp_parse_args( $styles, self::$default_styles );
	}

	/**
	 * Convert a WordPress post to InDesign Tagged Text format.
	 *
	 * @param int|\WP_Post $post    Post ID or WP_Post object.
	 * @param array        $options {
	 *     Optional. Conversion options.
	 *
	 *     @type bool   $include_subtitle Whether to include the post subtitle. Default true.
	 *     @type bool   $include_byline   Whether to include the byline. Default true.
	 *     @type bool   $include_captions Whether to append photo captions. Photo credits are
	 *                                    a separate attribution field and are always exported.
	 *                                    Default true.
	 * }
	 * @return string|false InDesign Tagged Text content, or false on failure.
	 */
	public function convert_post( $post, $options = [] ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}

		$default_options = [
			'include_subtitle' => true,
			'include_byline'   => true,
			'include_captions' => true,
		];
		$options = wp_parse_args( $options, $default_options );

		$content_parts = [];

		$content_parts[] = self::START_TAG;
		$content_parts[] = $this->styles['headline'] . $this->get_transformed_plain_text( $post->post_title );

		if ( $options['include_subtitle'] ) {
			$subtitle = $this->get_post_subtitle( $post );
			if ( $subtitle ) {
				$content_parts[] = $this->styles['subhead'] . $this->get_transformed_plain_text( $subtitle );
			}
		}

		if ( $options['include_byline'] ) {
			$byline = $this->get_byline( $post );
			if ( ! empty( $byline ) ) {
				$content_parts[] = $this->styles['byline'] . $this->get_transformed_plain_text( $byline );
			}
		}

		$content_parts[] = $this->process_post_content( $post->post_content, $options );
		$content_parts[] = $this->process_post_images( $post, $options );

		$content = implode( self::EOL, array_filter( $content_parts ) );

		// Final guarantee that the file matches what self::START_TAG declares.
		// Post content reaches the converter with line endings of every flavor
		// (pasted copy, imported HTML, serialized blocks), and the conversion
		// steps above introduce their own. A single stray CR or LF makes part of
		// the file Mac- or Unix-terminated while the header promises CRLF, and
		// InDesign then places that stretch as literal markup.
		$normalized = preg_replace( '/\r\n|\r|\n/', self::EOL, $content );

		// preg_replace() yields null on a PCRE failure. Returning it would break
		// the documented string|false contract and reach the download headers as
		// an empty body, so fall back to the un-normalized content.
		return null === $normalized ? $content : $normalized;
	}

	/**
	 * Get the post subtitle.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string|null Post subtitle or null if not available.
	 */
	private function get_post_subtitle( $post ) {
		$subtitle = get_post_meta( $post->ID, 'newspack_post_subtitle', true );
		return $subtitle ?? null;
	}

	/**
	 * Get the post authors.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array Array of author objects.
	 */
	private function get_post_authors( $post ) {
		if ( function_exists( 'get_coauthors' ) ) {
			return get_coauthors( $post->ID );
		}

		$author = get_userdata( $post->post_author );
		return $author ? [ $author ] : [];
	}

	/**
	 * Format byline.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string Formatted byline.
	 */
	private function get_byline( $post ) {
		$authors = $this->get_post_authors( $post );

		if ( empty( $authors ) ) {
			return '';
		}

		$author_names = [];
		foreach ( $authors as $author ) {
			$author_names[] = $author->display_name;
		}

		if ( 1 === count( $author_names ) ) {
			return $author_names[0];
		} else {
			$last_author = array_pop( $author_names );
			return implode( ', ', $author_names ) . ' & ' . $last_author;
		}
	}

	/**
	 * Process post content for InDesign export.
	 *
	 * @param string $content Raw post content.
	 * @param array  $options Conversion options.
	 * @return string Processed content.
	 */
	private function process_post_content( $content, $options = [] ) {
		if ( has_blocks( $content ) ) {
			$content = $this->process_blocks( $content );
		}
		$content = $this->process_html_headings( $content );
		$content = $this->process_quotes( $content );
		$content = $this->convert_html_to_indesign( $content );
		$content = preg_replace( '/<!--.*?-->/s', '', $content );
		$content = $this->get_transformed_text( $content );
		$content = $this->clean_whitespace( $content );

		return $content;
	}

	/**
	 * Process blocks in the content.
	 *
	 * @param string $content Post content.
	 *
	 * @return string Content with processed blocks.
	 */
	private function process_blocks( $content ) {
		// Rich media blocks have no print equivalent. Exclude them entirely to
		// prevent raw HTML (e.g. <object> tags, embed URLs) from leaking into
		// the InDesign output. Strip recursively so nested occurrences inside
		// container blocks (core/group, core/columns, etc.) are also removed.
		// Publishers can extend this list via the filter for custom block types.
		// Normalize the filter result to an array of strings in case a callback
		// returns a non-array or mixed-type value.
		$excluded_block_types = (array) apply_filters(
			'newspack_indesign_export_excluded_blocks',
			self::EXCLUDED_BLOCK_TYPES
		);
		$excluded_block_types = array_values( array_filter( $excluded_block_types, 'is_string' ) );

		$blocks  = $this->strip_excluded_blocks( parse_blocks( $content ), $excluded_block_types );
		$content = '';
		foreach ( $blocks as $block ) {
			$tag = $this->get_block_tag( $block );
			if ( ! empty( $tag ) ) {
				// Left untransformed: process_post_content() transforms the whole
				// body once the HTML is gone. Decoding entities here would turn a
				// bracket the author wrote as text into a bare one, which the
				// HTML-to-tag pass below then reads — and removes — as markup.
				$content .= $tag . preg_replace( '/^<[^>]+>(.*)<\/[^>]+>$/s', '$1', trim( $block['innerHTML'] ) );
			} else {
				$content .= serialize_block( $block );
			}
		}
		return $content;
	}

	/**
	 * Recursively remove excluded block types from a block tree.
	 *
	 * Strips both the top-level block and any occurrences nested inside
	 * container blocks (core/group, core/columns, etc.) by filtering
	 * innerBlocks and the corresponding innerContent null placeholders.
	 *
	 * @param array $blocks               Block list to filter.
	 * @param array $excluded_block_types Block type names to remove.
	 *
	 * @return array Filtered block list.
	 */
	private function strip_excluded_blocks( $blocks, $excluded_block_types ) {
		$filtered = [];
		foreach ( $blocks as $block ) {
			if ( $this->is_excluded_block( $block['blockName'], $excluded_block_types ) ) {
				continue;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$new_inner_blocks  = [];
				$new_inner_content = [];
				$inner_index       = 0;
				foreach ( $block['innerContent'] as $chunk ) {
					if ( is_string( $chunk ) ) {
						$new_inner_content[] = $chunk;
					} else {
						if ( ! isset( $block['innerBlocks'][ $inner_index ] ) ) {
							$inner_index++;
							continue;
						}
						$inner_block = $block['innerBlocks'][ $inner_index++ ];
						if ( ! $this->is_excluded_block( $inner_block['blockName'], $excluded_block_types ) ) {
							$new_inner_blocks[]  = $inner_block;
							$new_inner_content[] = null;
						}
					}
				}
				$block['innerBlocks']  = $this->strip_excluded_blocks( $new_inner_blocks, $excluded_block_types );
				$block['innerContent'] = $new_inner_content;
			}
			$filtered[] = $block;
		}
		return $filtered;
	}

	/**
	 * Check whether a block name should be excluded from export.
	 *
	 * Legacy core-embed/* block names (pre-WP 5.6) follow the same exclusion
	 * state as core/embed — if core/embed is in the filtered list, its legacy
	 * variants are excluded too.
	 *
	 * @param string   $block_name           Block type name.
	 * @param string[] $excluded_block_types Filtered list of excluded block types.
	 *
	 * @return bool True if the block should be excluded.
	 */
	private function is_excluded_block( $block_name, $excluded_block_types ) {
		// parse_blocks() returns null blockName for freeform/whitespace chunks.
		if ( ! is_string( $block_name ) || '' === $block_name ) {
			return false;
		}
		if ( in_array( $block_name, $excluded_block_types, true ) ) {
			return true;
		}
		// Legacy core-embed/* variants follow core/embed's exclusion state.
		if (
			in_array( 'core/embed', $excluded_block_types, true )
			&& 0 === strpos( $block_name, 'core-embed/' )
		) {
			return true;
		}
		return false;
	}

	/**
	 * Get the tag for a block.
	 *
	 * @param array $block Block data.
	 *
	 * @return string Block tag.
	 */
	private function get_block_tag( $block ) {
		if ( ! empty( $block['attrs']['indesignTag'] ) ) {
			return sprintf( '<%1$s>', $block['attrs']['indesignTag'] );
		}

		if ( 'core/paragraph' === $block['blockName'] ) {
			return $this->styles['paragraph'];
		}

		if ( 'core/heading' === $block['blockName'] ) {
			return sprintf( '<pstyle:h%d>', $block['attrs']['level'] ?? 2 ); // Default to h2 if level is not set.
		}
		return '';
	}

	/**
	 * Process headings in the content.
	 *
	 * @param string $content Post content.
	 *
	 * @return string Content with processed subheads.
	 */
	private function process_html_headings( $content ) {
		$content = preg_replace_callback(
			'/<h([1-6])[^>]*>(.*?)<\/h[1-6]>/is',
			function ( $matches ) {
				// Heading text is transformed with the rest of the body, for the
				// reason given in process_blocks().
				return sprintf( '<pstyle:h%d>%s', $matches[1], $matches[2] );
			},
			$content
		);

		return $content;
	}

	/**
	 * Process blockquotes and pullquotes.
	 *
	 * @param string $content Post content.
	 *
	 * @return string Content with processed blockquotes and pullquotes.
	 */
	private function process_quotes( $content ) {
		$pattern      = '/<blockquote[^>]*>(.*?)<\/blockquote>/is';
		$cite_pattern = '/<cite[^>]*>(.*?)<\/cite>/is';

		preg_match_all( $pattern, $content, $quote_matches );
		$quotes = $quote_matches[1];

		foreach ( $quotes as $i => $quote ) {
			$tag = $this->styles['pullquote'];
			if ( strpos( $quote_matches[0][ $i ], 'wp-block-quote' ) !== false ) {
				$tag = $this->styles['blockquote'];
			}
			$quote_content = $tag . wp_strip_all_tags( preg_replace( $cite_pattern, '', $quote ) );

			preg_match( $cite_pattern, $quote, $cite_matches );
			if ( ! empty( $cite_matches ) ) {
				$cite = $cite_matches[1];
				if ( ! empty( $cite ) ) {
					$quote_content .= self::EOL . $this->styles['pullquote_name'] . wp_strip_all_tags( $cite );
				}
			}

			$content = preg_replace( $pattern, $quote_content, $content, 1 );
		}
		return $content;
	}

	/**
	 * Convert HTML elements to InDesign tagged text equivalents.
	 *
	 * @param string $content Post content.
	 *
	 * @return string Content with InDesign tags.
	 */
	private function convert_html_to_indesign( $content ) {
		$conversions = [
			// Remove figcaption entirely.
			'/<figcaption[^>]*>.*?<\/figcaption>/' => '',

			// Paragraphs.
			'/<(?!pstyle:)(p[^>]*)>/'              => $this->styles['paragraph'],

			// Lists. TODO: Handle numbered and nested lists.
			'/<li[^>]*>(.*)<\/li>/U'               => '<bnListType:Bullet>$1<bnListType:>',

			// Line breaks.
			'/<br[^>]*>/'                          => '<0x000A>',

			// Horizontal rules.
			'/<hr[^>]*>/'                          => $this->styles['horizontal_rule'],

			// Typography.
			'/<strong[^>]*>/'                      => '<cTypeface:Bold>',
			'/<\/strong>/'                         => '<cTypeface:>',
			'/<em[^>]*>/'                          => '<cTypeface:Italic>',
			'/<\/em>/'                             => '<cTypeface:>',
			'/<(?!img)i[^>]*>/'                    => '<cTypeface:Italic>',
			'/<\/i>/'                              => '<cTypeface:>',
			'/<sup[^>]*>/'                         => '<cPosition:Superscript>',
			'/<\/sup>/'                            => '<cPosition:>',
			'/<sub[^>]*>/'                         => '<cPosition:Subscript>',
			'/<\/sub>/'                            => '<cPosition:>',

			// Remove unsupported tags while preserving content.
			'/<(?:div|ol|ul|a|img|figure)[^>]*>/'  => '',

			// Replace paragraphs and remaining lists end tags with line breaks.
			'/<\/(?:p|ul|ol)[^>]*>/'               => self::EOL,

			// Remove all remaining closing tags.
			'/<\/[^>]*>/'                          => '',
		];

		foreach ( $conversions as $pattern => $replacement ) {
			$content = preg_replace(
				$pattern,
				$replacement,
				$content
			);
		}

		return $content;
	}

	/**
	 * Convert a plain-text field for InDesign.
	 *
	 * Every character in these fields — titles, subtitles, bylines, captions,
	 * credits — is content, so an angle bracket in one is text rather than a tag
	 * delimiter. Encoding those brackets routes them through the same escaping
	 * get_transformed_text() applies to bracket entities in the body.
	 *
	 * Use this instead of get_transformed_text() wherever the whole string is
	 * known to be content. get_transformed_text() also runs over strings that
	 * already carry the converter's own tags, where a bare bracket is markup.
	 *
	 * @param string $text Text to convert.
	 *
	 * @return string Converted text.
	 */
	private function get_transformed_plain_text( $text ) {
		return $this->get_transformed_text( str_replace( [ '<', '>' ], [ '&lt;', '&gt;' ], $text ) );
	}

	/**
	 * Convert text for InDesign, handling special characters and typography.
	 *
	 * Angle brackets already present in $text are left alone: this also runs over
	 * strings carrying the converter's own tags, where they are markup. Brackets
	 * that arrive encoded are content, and come out backslash-escaped.
	 *
	 * @param string $text Text to convert.
	 *
	 * @return string Converted text.
	 */
	private function get_transformed_text( $text ) {
		// Character conversions for InDesign Tagged Text.
		$conversions = [
			// Dashes.
			'--' => '<0x2014>',
			'—'  => '<0x2014>',
			'–'  => '<0x2013>',

			// Quotes.
			'“'  => '"',
			'”'  => '"',
			'‘'  => "'",
			'’'  => "'",

			// Special characters.
			'•'  => '<CharStyle:bullet>n<CharStyle:>',
		];

		$text = str_replace( array_keys( $conversions ), array_values( $conversions ), $text );

		// Escape literal backslashes before the escapes below introduce any, so
		// those aren't escaped a second time. The converter emits no backslash of
		// its own, so every backslash reaching this point is content.
		$text = str_replace( '\\', '\\\\', $text );

		// Angle brackets written as entities are content, not tag delimiters.
		// Shield them from the decode below — which would hand InDesign a bare
		// bracket to read as the start or end of a tag — then resolve them to
		// escaped literals. Covers the named and numeric forms of both brackets.
		$text = preg_replace( '/&(lt|gt|#0*6[02]|#[xX]0*3[cCeE]);/', '&amp;$1;', $text );

		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$text = preg_replace( '/&(?:lt|#0*60|#[xX]0*3[cC]);/', '\\\\<', $text );
		$text = preg_replace( '/&(?:gt|#0*62|#[xX]0*3[eE]);/', '\\\\>', $text );

		// Convert remaining HTML entities.
		$text = str_replace(
			[ '&nbsp;', '&amp;' ],
			[ ' ', '&' ],
			$text
		);

		// Remove non-breaking space UTF-8 character.
		$text = str_replace( "\xC2\xA0", ' ', $text );

		// Convert remaining special characters to hexadecimal unicode code points.
		$char_length = mb_strlen( $text, 'UTF-8' );
		for ( $i = 0; $i < $char_length; $i++ ) {
			$char       = mb_substr( $text, $i, 1, 'UTF-8' );
			$code_point = mb_ord( $char, 'UTF-8' );
			if ( $code_point > 127 ) {
				$text        = str_replace( $char, sprintf( '<0x%04X>', $code_point ), $text );
				$char_length = mb_strlen( $text, 'UTF-8' );
			}
		}

		return $text;
	}

	/**
	 * Clean up whitespace and line breaks.
	 *
	 * @param string $content Content to clean.
	 *
	 * @return string Cleaned content.
	 */
	private function clean_whitespace( $content ) {
		// Collapse runs of blank lines into a single terminator. The alternation
		// consumes CRLF as one unit: matching on \n alone would count the LF of a
		// CRLF pair as a separate break and rewrite it, stranding the CR in front
		// as a bare Mac-style terminator (NPPM-2813).
		$content = preg_replace( '/(?:\r\n|\r|\n){2,}/', self::EOL, $content );
		$content = trim( $content );

		return $content;
	}

	/**
	 * Recursively get all the image blocks.
	 *
	 * @param array $blocks Blocks to process.
	 *
	 * @return array Image blocks.
	 */
	private function get_image_blocks( $blocks ) {
		$block_names  = [ 'core/image', 'jetpack/slideshow', 'jetpack/tiled-gallery' ];
		$image_blocks = [];
		foreach ( $blocks as $block ) {
			if ( in_array( $block['blockName'], $block_names, true ) ) {
				$image_blocks[] = $block;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$image_blocks = array_merge( $image_blocks, $this->get_image_blocks( $block['innerBlocks'] ) );
			}
		}
		return $image_blocks;
	}

	/**
	 * Process post images metadata to generate photo credit and caption tags.
	 *
	 * @param \WP_Post $post    Post object.
	 * @param array    $options Conversion options. Honors 'include_captions' (default
	 *                          true); credits are always emitted regardless.
	 *
	 * @return string Photo credit and caption tags.
	 */
	private function process_post_images( $post, $options = [] ) {
		$include_captions = ! isset( $options['include_captions'] ) || $options['include_captions'];
		$images           = [];
		$inline_captions  = [];

		$featured_image_id = get_post_thumbnail_id( $post->ID );
		if ( $featured_image_id ) {
			if ( ! isset( $images[ $featured_image_id ] ) ) {
				$images[ $featured_image_id ] = true;
			}
		}

		// Avoid processing images from Newspack Network Content Distribution.
		if ( ! get_post_meta( $post->ID, 'newspack_network_post_id', true ) ) {
			$blocks       = parse_blocks( $post->post_content );
			$image_blocks = $this->get_image_blocks( $blocks );

			foreach ( $image_blocks as $block ) {
				$id = $block['attrs']['id'] ?? null;
				if ( ! empty( $id ) && ! isset( $images[ $id ] ) ) {
					$images[ $id ] = true;
				}
				if ( ! empty( $block['attrs']['ids'] ) && is_array( $block['attrs']['ids'] ) ) {
					foreach ( $block['attrs']['ids'] as $id ) {
						if ( ! isset( $images[ $id ] ) ) {
							$images[ $id ] = true;
						}
					}
				}
				// Preg match figcaption content.
				if ( ! empty( $block['innerHTML'] ) ) {
					preg_match( '/<figcaption[^>]*>(.*?)<\/figcaption>/', $block['innerHTML'], $matches );
					if ( ! empty( $matches ) ) {
						$inline_captions[ $id ] = $matches[1];
					}
				}
			}
		}

		if ( empty( array_filter( $images ) ) ) {
			return '';
		}

		$tag_content = '';

		foreach ( $images as $image_id => $insert_tag ) {
			if ( ! $insert_tag ) {
				continue;
			}

			$caption = $include_captions ? ( $inline_captions[ $image_id ] ?? wp_get_attachment_caption( $image_id ) ) : '';
			$credit  = get_post_meta( $image_id, '_media_credit', true ) ?? '';

			if ( ! $caption && ! $credit ) {
				continue;
			}

			$tag_content .= self::EOL;
			if ( $caption ) {
				$tag_content .= '<pstyle:PhotoCaption>' . $this->get_transformed_plain_text( $caption ) . self::EOL;
			}
			if ( $credit ) {
				$tag_content .= '<pstyle:PhotoCredit>' . $this->get_transformed_plain_text( $credit ) . self::EOL;
			}
		}

		// Every image was skipped (no caption/credit to emit, e.g. caption-only
		// images with captions excluded). Return nothing so array_filter() in
		// convert_post() drops this block instead of appending a blank line.
		if ( '' === $tag_content ) {
			return '';
		}

		return self::EOL . $tag_content;
	}

	/**
	 * Update the InDesign styles configuration.
	 *
	 * @param array $styles New styles configuration.
	 */
	public function set_styles( $styles ) {
		$this->styles = wp_parse_args( $styles, self::$default_styles );
	}

	/**
	 * Get the current InDesign styles configuration.
	 *
	 * @return array Current styles configuration.
	 */
	public function get_styles() {
		return $this->styles;
	}

	/**
	 * Get the default InDesign styles configuration.
	 *
	 * @return array Default styles configuration.
	 */
	public static function get_default_styles() {
		return self::$default_styles;
	}
}
