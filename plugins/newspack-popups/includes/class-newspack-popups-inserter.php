<?php
/**
 * Newspack Popups Inserter
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Popups Inserter class.
 */
final class Newspack_Popups_Inserter {
	/**
	 * Handle for admin UI scripts.
	 */
	const ADMIN_SCRIPT_HANDLE = 'newspack-popups-admin-bar';

	/**
	 * The popup objects to display.
	 *
	 * @var array
	 */
	protected static $popups = [];

	/**
	 * Segments for displayed popups.
	 *
	 * @var array
	 */
	protected static $segments = [];

	/**
	 * Whether we've already inserted prompts into the content.
	 * If we've already inserted popups into the content, don't try to do it again.
	 *
	 * @var boolean
	 */
	public static $the_content_has_rendered = false;

	/**
	 * Whether we're exporting to Apple News.
	 *
	 * @var boolean
	 */
	private static $is_apple_news_exporting = false;

	/**
	 * Whether above-header prompts have already been rendered.
	 *
	 * @var boolean
	 */
	private static $header_template_part_has_rendered = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'the_content', [ $this, 'insert_popups_in_content' ], 1 );
		add_shortcode( 'newspack-popup', [ $this, 'popup_shortcode' ] );
		add_action( 'after_header', [ $this, 'insert_popups_after_header' ] ); // This is a Newspack theme hook. When used with other themes, popups won't be inserted on archive pages.
		add_action( 'wp_body_open', [ $this, 'insert_before_header' ] );
		add_filter( 'render_block_core/template-part', [ $this, 'insert_before_header_in_template_part' ], 10, 2 );
		add_action( 'after_archive_post', [ $this, 'insert_inline_prompt_in_archive_pages' ] );
		add_filter( 'render_block', [ $this, 'insert_inline_prompt_in_block_theme_archives' ], 10, 3 );
		add_action( 'wp_before_admin_bar_render', [ $this, 'add_preview_toggle' ] );

		// Always enqueue scripts, since this plugin's scripts are handling pageview sending via GTAG.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'apple_news_do_fetch_exporter', [ __CLASS__, 'apple_news_do_fetch_exporter' ] );
		add_filter( 'newspack_popups_assess_has_disabled_popups', [ __CLASS__, 'disable_prompts' ] );

		// These hooks are fired before and after rendering posts in the Homepage Posts block.
		// By removing the the_content filter before rendering, we avoid incorrectly injecting popup content into excerpts in the block.
		add_action(
			'newspack_blocks_homepage_posts_before_render',
			function() {
				remove_filter( 'the_content', [ $this, 'insert_popups_in_content' ], 1 );
			}
		);

		add_action(
			'newspack_blocks_homepage_posts_after_render',
			function() {
				add_filter( 'the_content', [ $this, 'insert_popups_in_content' ], 1 );
			}
		);
	}

	/**
	 * Disable prompts for specific conditions.
	 *
	 * @param bool $disabled Whether prompts are disabled.
	 * @return bool Whether prompts are disabled.
	 */
	public static function disable_prompts( $disabled ) {
		// If the post has been set to disable prompts.
		if ( get_post_meta( get_the_ID(), 'newspack_popups_has_disabled_popups', true ) ) {
			return true;
		}

		// The suppress filter used to be named 'newspack_newsletters_assess_has_disabled_popups'.
		// Maintain that filter for backwards compatibility.
		if ( apply_filters( 'newspack_newsletters_assess_has_disabled_popups', false ) ) {
			return true;
		}

		// If exporting to Apple News.
		if ( self::$is_apple_news_exporting ) {
			return true;
		}
		return $disabled;
	}

	/**
	 * Retrieve the appropriate popups for the current post.
	 *
	 * @return array Popup objects.
	 */
	public static function popups_for_post() {
		if ( ! empty( self::$popups ) ) {
			return self::$popups;
		}

		// Get the previewed popup and return early.
		if ( Newspack_Popups::previewed_popup_id() ) {
			$preview_popup = Newspack_Popups_Model::retrieve_preview_popup( Newspack_Popups::previewed_popup_id() );
			return $preview_popup ? [ $preview_popup ] : [];
		}
		if ( Newspack_Popups::preset_popup_id() ) {
			$preset_popup = Newspack_Popups_Presets::retrieve_preset_popup( Newspack_Popups::preset_popup_id() );
			return [ $preset_popup ];
		}

		// Popups disabled for this page.
		if ( self::assess_has_disabled_popups() ) {
			return [];
		}

		$view_as_spec        = Newspack_Popups_View_As::parse_view_as();
		$campaign_id         = isset( $view_as_spec['campaign'] ) ? $view_as_spec['campaign'] : false;
		$include_unpublished = isset( $view_as_spec['show_unpublished'] ) && 'true' === $view_as_spec['show_unpublished'] ? true : false;

		// Retrieve all prompts eligible for display.
		$popups_to_maybe_display = Newspack_Popups_Model::retrieve_eligible_popups( $include_unpublished, $campaign_id );
		$popups_to_display       = array_filter(
			$popups_to_maybe_display,
			function( $popup ) {
				return self::should_display( $popup, true );
			}
		);

		// Cache results so we don't have to query again.
		if ( ! defined( 'IS_TEST_ENV' ) || ! IS_TEST_ENV ) {
			self::$popups = $popups_to_display;
		}

		return $popups_to_display;
	}

	/**
	 * Some blocks should never have a prompt right after them. For example, a prompt right after a subheading
	 * (header block) would not look good.
	 *
	 * @param object $block A block.
	 */
	private static function can_block_be_followed_by_prompt( $block ) {
		if (
			in_array(
				$block['blockName'],
				[
					// A prompt may not appear right after a heading block.
					'core/heading',
				]
			) ) {
			return false;
		}
		if (
			// A prompt may not appear after a floated image block, because it
			// will mess up the layout then.
			'core/image' === $block['blockName']
			&& isset( $block['attrs']['align'] )
			&& in_array( $block['attrs']['align'], [ 'left', 'right' ] )
		) {
			return false;
		}
		return true;
	}

	/**
	 * Convert blocks containing classic (legacy) content into regular blocks.
	 *
	 * @param array $blocks Array of blocks, some of which might be classic content.
	 */
	private static function convert_classic_blocks( $blocks ) {
		return array_reduce(
			$blocks,
			function( $blocks, $block ) {
				$is_classic_block = null === $block['blockName'] || 'core/freeform' === $block['blockName']; // Classic content results in a block without a block name.
				$is_empty         = empty( trim( $block['innerHTML'] ) );
				if ( $is_classic_block && ! $is_empty ) {
					$classic_content = force_balance_tags( wpautop( $block['innerHTML'] ) ); // Ensure we have paragraph tags and valid HTML.
					$dom             = new DomDocument();
					libxml_use_internal_errors( true );
					$dom->loadHTML( '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />' . htmlspecialchars_decode( htmlentities( mb_convert_encoding( $classic_content, 'UTF-8', get_bloginfo( 'charset' ) ) ) ) );
					$dom_body = $dom->getElementsByTagName( 'body' );
					if ( 0 < $dom_body->length ) {
						$dom_body_elements = $dom_body->item( 0 )->childNodes;
						foreach ( $dom_body_elements as $index => $entry ) {
							$block_html = $dom->saveHtml( $entry );
							$block_name = 'core/html';
							if ( 1 === preg_match( '/^<h\d>.*<\/h\d>$/', $block_html ) ) {
								$block_name = 'core/heading';
							}
							$blocks[] = [
								'blockName'    => $block_name,
								'attrs'        => [],
								'innerBlocks'  => [],
								'innerHTML'    => $block_html,
								'innerContent' => [
									$block_html,
								],
							];
						}
					}
				} else {
					$blocks[] = $block;
				}
				return $blocks;
			},
			[]
		);
	}

	/**
	 * Get content from a given block's inner blocks, and recursively from those blocks' inner blocks.
	 *
	 * @param object $block A block.
	 *
	 * @return string The block's inner content.
	 */
	public static function get_inner_block_content( $block ) {
		$inner_block_content = '';

		if ( 0 < count( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $inner_block ) {
				$inner_block_content .= $inner_block['innerHTML'];

				// Recursively get content from nested inner blocks.
				if ( 0 < count( $inner_block['innerBlocks'] ) ) {
					$inner_block_content .= self::get_inner_block_content( $inner_block );
				}
			}
		}

		return $inner_block_content;
	}

	/**
	 * Get content from given block, including content from the block's inner blocks, if any.
	 *
	 * @param object $block A block.
	 *
	 * @return string The block's content.
	 */
	public static function get_block_content( $block ) {
		$is_classic_block = null === $block['blockName'] || 'core/freeform' === $block['blockName']; // Classic block doesn't have a block name.
		$block_content    = $is_classic_block ? force_balance_tags( wpautop( $block['innerHTML'] ) ) : $block['innerHTML'];
		$block_content   .= self::get_inner_block_content( $block );

		return $block_content;
	}

	/**
	 * Sort overlay prompts so that segment-assigned ones appear first, ordered by
	 * ascending segment count (i.e., decreasing specificity). Overlays with no
	 * segments assigned appear last.
	 *
	 * Ensures segment-specific overlays claim the single visible overlay slot before
	 * unsegmented "show to everyone" overlays, since only one overlay can be displayed
	 * at a time and the first eligible one in the DOM wins.
	 *
	 * @param array $overlays Array of overlay popup objects.
	 * @return array Sorted array, segment-assigned overlays first by ascending segment count.
	 */
	private static function sort_overlays_by_specificity( $overlays ) {
		usort(
			$overlays,
			function( $a, $b ) {
				$a_count = isset( $a['segments'] ) && is_array( $a['segments'] ) ? count( $a['segments'] ) : 0;
				$b_count = isset( $b['segments'] ) && is_array( $b['segments'] ) ? count( $b['segments'] ) : 0;
				// Map zero-segment overlays to PHP_INT_MAX so they sort last.
				$a_key = 0 === $a_count ? PHP_INT_MAX : $a_count;
				$b_key = 0 === $b_count ? PHP_INT_MAX : $b_count;
				return $a_key - $b_key;
			}
		);
		return $overlays;
	}

	/**
	 * Insert popups in a post content.
	 *
	 * @param string $content The post content.
	 * @param array  $popups Array of popup objects.
	 */
	public static function insert_popups_in_post_content( $content, $popups ) {
		// For certain types of blocks, their innerHTML is not a good representation of the length of their content.
		// For example, slideshows may have an arbitrary amount of slide content, but only show one slide at a time.
		// For these blocks, let's ignore their length for purposes of inserting prompts.
		$length_ignored_blocks = [ 'jetpack/slideshow', 'newspack-blocks/carousel', 'newspack-popups/single-prompt' ];

		$parsed_blocks = self::convert_classic_blocks( parse_blocks( $content ) );

		// List of blocks that require innerHTML to render content.
		$blocks_to_skip_empty = [
			'core/paragraph',
			'core/heading',
			'core/list',
			'core/quote',
			'core/html',
			'core/freeform',
		];
		$parsed_blocks        = array_values( // array_values will reindex the array.
			// Filter out empty blocks.
			array_filter(
				$parsed_blocks,
				function( $block ) use ( $blocks_to_skip_empty ) {
					$null_block_name     = null === $block['blockName'];
					$is_skip_empty_block = in_array( $block['blockName'], $blocks_to_skip_empty, true );
					$is_empty            = empty( trim( $block['innerHTML'] ) );
					return ! ( $is_empty && ( $null_block_name || $is_skip_empty_block ) );
				}
			)
		);

		$block_index            = 0;
		$grouped_blocks_indexes = [];
		$max_index              = count( $parsed_blocks );

		$parsed_blocks_groups = array_reduce(
			$parsed_blocks,
			function ( $block_groups, $block ) use ( &$block_index, $parsed_blocks, $max_index, &$grouped_blocks_indexes ) {
				$next_index = $block_index;

				// If we've already included this block in a previous group, bail early to avoid content duplication.
				if ( in_array( $next_index, $grouped_blocks_indexes, true ) ) {
					$block_index++;
					return $block_groups;
				}

				// Create a group of blocks that can be followed by a prompt.
				$next_block     = $block;
				$group_blocks   = [];
				$index_in_group = 0;

				// Insert any following blocks, which can't be followed by a prompt.
				while ( $next_index < $max_index && ! self::can_block_be_followed_by_prompt( $next_block ) ) {
					$next_block               = $parsed_blocks[ $next_index ];
					$group_blocks[]           = $next_block;
					$grouped_blocks_indexes[] = $next_index;
					$next_index++;
					$index_in_group++;
				}
				// Always insert the initial block in the group (if the index in group was not incremented, this is the initial block).
				if ( 0 === $index_in_group ) {
					$group_blocks[]           = $next_block;
					$grouped_blocks_indexes[] = $next_index;
				}

				$block_groups[] = $group_blocks;

				$block_index++;
				return $block_groups;
			},
			[]
		);
		$total_length         = 0;

		// Compute the total length of the content.
		foreach ( $parsed_blocks as $block ) {
			if ( in_array( $block['blockName'], $length_ignored_blocks ) ) {
				// Give length-ignored blocks a length of 1 so that prompts at 0% can still be inserted before them.
				$total_length++;
			} else {
				$block_content = self::get_block_content( $block );
				$total_length += strlen( wp_strip_all_tags( $block_content ) );
			}
		}

		// 1. Separate prompts into inline and overlay.
		$inline_popups  = [];
		$overlay_popups = [];
		foreach ( $popups as $popup ) {
			if ( Newspack_Popups_Model::is_inline( $popup ) ) {
				$percentage                = intval( $popup['options']['trigger_scroll_progress'] ) / 100;
				$blocks_before_prompt      = intval( $popup['options']['trigger_blocks_count'] );
				$popup['precise_position'] = 'blocks_count' === $popup['options']['trigger_type'] ? $blocks_before_prompt : $total_length * $percentage;
				$popup['is_inserted']      = false;
				$inline_popups[]           = $popup;
			} elseif ( Newspack_Popups_Model::is_overlay( $popup ) ) {
				$overlay_popups[] = $popup;
			}
		}

		// Return early if there are no popups to insert. This can happen if e.g. the only popup is an above header one.
		if ( empty( $inline_popups ) && empty( $overlay_popups ) ) {
			return $content;
		}

		// 2. Iterate over all blocks and insert inline prompts.
		$pos    = 0;
		$output = '';

		foreach ( $parsed_blocks_groups as $block_index => $block_group ) {
			// Compute the length of the blocks in the group.
			foreach ( $block_group as $block ) {
				if ( in_array( $block['blockName'], $length_ignored_blocks ) ) {
					// Give length-ignored blocks a length of 1 so that prompts at 0% can still be inserted before them.
					$pos++;
				} else {
					$pos += strlen( wp_strip_all_tags( $block['innerHTML'] ) );
				}
			}

			// Inject prompts before the group.
			foreach ( $inline_popups as &$inline_popup ) {
				if ( $inline_popup['is_inserted'] ) {
					// Skip if already inserted.
					continue;
				}

				$position          = $inline_popup['precise_position'];
				$trigger_type      = $inline_popup['options']['trigger_type'];
				$insert_at_zero    = 0 === $position; // If the position is 0, the prompt should always appear first.
				$insert_for_scroll = 'blocks_count' !== $trigger_type && $pos > $position;
				$insert_for_blocks = 'blocks_count' === $trigger_type && $block_index >= $position;

				if ( $insert_at_zero || $insert_for_scroll || $insert_for_blocks ) {
					$output                     .= '<!-- wp:shortcode -->[newspack-popup id="' . $inline_popup['id'] . '"]<!-- /wp:shortcode -->';
					$inline_popup['is_inserted'] = true;
				}
			}

			// Render blocks from the block group.
			foreach ( $block_group as $block ) {
				$output .= serialize_block( $block );
			}
		}

		// 3. Insert any remaining inline prompts at the end.
		foreach ( $inline_popups as &$inline_popup ) {
			if ( ! $inline_popup['is_inserted'] ) {
				$output                     .= '<!-- wp:shortcode -->[newspack-popup id="' . $inline_popup['id'] . '"]<!-- /wp:shortcode -->';
				$inline_popup['is_inserted'] = true;
			}
		}

		// 4. Insert overlay prompts at the top of content.
		// To leave the existing behavior (prepending each overlay) in place,
		// we reverse our sorted overlays to ensure the most specific appear
		// first in the DOM, and get priority for the single available slot.
		$overlay_popups = array_reverse( self::sort_overlays_by_specificity( $overlay_popups ) );
		foreach ( $overlay_popups as $overlay_popup ) {
			$output = '<!-- wp:html -->' . Newspack_Popups_Model::generate_popup( $overlay_popup ) . '<!-- /wp:html -->' . $output;
		}
		return $output;
	}

	/**
	 * Whether the given post is being restricted by Woo Memberships.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return bool
	 */
	private static function is_memberships_restricted( $post_id = null ) {
		if ( ! function_exists( 'wc_memberships_is_post_content_restricted' ) ) {
			return false;
		}
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}
		if ( ! \wc_memberships_is_post_content_restricted( $post_id ) ) {
			return false;
		}
		$is_restricted = ! is_user_logged_in() || ! current_user_can( 'wc_memberships_view_restricted_post_content', $post_id ); // phpcs:ignore WordPress.WP.Capabilities.Unknown
		// Detect Content Gate Metering.
		if ( $is_restricted && method_exists( 'Newspack\Metering', 'is_metering' ) ) {
			$is_restricted = ! \Newspack\Metering::is_metering();
		}
		return $is_restricted;
	}

	/**
	 * Process popups and insert into post and page content if needed.
	 *
	 * @param string $content The content of the post.
	 */
	public static function insert_popups_in_content( $content = '' ) {
		$post = get_post();

		if ( ! $post ) {
			return $content;
		}

		$filtered_content = explode( "\n", self::get_validation_content( $content ) );
		$post_content     = explode( "\n", ltrim( $post->post_content ) );
		if (
			// If prompts are disabled for this post.
			self::assess_has_disabled_popups()
			// Avoid duplicate execution.
			|| true === self::$the_content_has_rendered
			// Not Frontend.
			|| is_admin()
			// Content is empty.
			|| empty( trim( $content ) )
			// No popup insertion in archive pages - there's another method for that.
			|| ! is_singular()
			// If not in the loop, ignore.
			|| ! in_the_loop()
			// Don't inject inline popups on paywalled posts.
			// It doesn't make sense with a paywall message and also causes an infinite loop.
			|| self::is_memberships_restricted()
			// At filter priority 1, $content should be the same as the unfiltered post_content. This guards against inserting in other content such as featured image captions/descriptions.
			|| ( ! empty( $filtered_content ) && ! empty( $post_content ) && $filtered_content[0] !== $post_content[0] )
		) {
			return $content;
		}

		// If any popups are inserted using a shortcode, skip them - no need to duplicate.
		$shortcoded_popups_ids = self::get_shortcoded_popups_ids( get_the_content() );
		$popups                = array_filter(
			self::popups_for_post(),
			function ( $popup ) use ( $shortcoded_popups_ids ) {
				return ! in_array( $popup['id'], $shortcoded_popups_ids ) && Newspack_Popups_Model::should_be_inserted_in_page_content( $popup );
			}
		);

		if ( empty( $popups ) ) {
			return $content;
		}

		$content_with_popups = self::insert_popups_in_post_content(
			$content,
			$popups
		);

		self::$the_content_has_rendered = true;
		return $content_with_popups;
	}

	/**
	 * Insert overlay prompts into archive pages if needed. Applies to Newspack Theme only.
	 */
	public static function insert_popups_after_header() {
		/* Posts and pages are covered by the_content hook */
		if ( is_singular() ) {
			return;
		}
		$popups = array_filter(
			self::popups_for_post(),
			function ( $popup ) {
				return Newspack_Popups_Model::should_be_inserted_in_page_content( $popup ) && Newspack_Popups_Model::is_overlay( $popup );
			}
		);
		$popups = self::sort_overlays_by_specificity( array_values( $popups ) );
		foreach ( $popups as $popup ) {
			echo Newspack_Popups_Model::generate_popup( $popup ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Get the combined markup for all above-header prompts: overlays (sorted by specificity)
	 * first, then inline prompts in their original order.
	 *
	 * @return string HTML markup, or empty string if there are no above-header prompts.
	 */
	private static function get_before_header_markup() {
		$before_header_popups = array_filter( self::popups_for_post(), [ 'Newspack_Popups_Model', 'should_be_inserted_above_page_header' ] );
		if ( empty( $before_header_popups ) ) {
			return '';
		}

		// Sort only the overlay subset by specificity — above-header inline prompts are
		// not subject to the single visible overlay slot constraint and are left in their
		// original order.
		$overlay_popups = self::sort_overlays_by_specificity(
			array_values( array_filter( $before_header_popups, [ 'Newspack_Popups_Model', 'is_overlay' ] ) )
		);
		$inline_popups  = array_values(
			array_filter(
				$before_header_popups,
				function( $popup ) {
					return ! Newspack_Popups_Model::is_overlay( $popup );
				}
			)
		);

		$markup = '';
		foreach ( $overlay_popups as $popup ) {
			$markup .= Newspack_Popups_Model::generate_popup( $popup );
		}
		foreach ( $inline_popups as $popup ) {
			$markup .= Newspack_Popups_Model::generate_popup( $popup );
		}
		return $markup;
	}

	/**
	 * Insert popups markup before header (classic themes via wp_body_open).
	 */
	public static function insert_before_header() {
		// In block themes, prompts are inserted via the header template-part render filter.
		if ( Newspack_Popups_Model::is_block_theme() ) {
			return;
		}

		$markup = self::get_before_header_markup();
		if ( empty( $markup ) ) {
			return;
		}

		echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Insert popups markup before the header template part in block themes.
	 *
	 * @param string $block_content The rendered block content.
	 * @param array  $block         The full block.
	 * @return string Rendered content with campaign markup prepended when applicable.
	 */
	public static function insert_before_header_in_template_part( $block_content, $block ) {
		if ( ! Newspack_Popups_Model::is_block_theme() || is_admin() || self::$header_template_part_has_rendered ) {
			return $block_content;
		}

		if ( ! self::is_header_template_part_block( $block ) ) {
			return $block_content;
		}

		// Set the guard before generating markup to prevent it running again before finishing.
		self::$header_template_part_has_rendered = true;

		$markup = self::get_before_header_markup();
		if ( empty( $markup ) ) {
			return $block_content;
		}

		return $markup . $block_content;
	}

	/**
	 * Whether a template-part block appears to be a header template part.
	 *
	 * Some themes use custom header slugs (for example "header-post"), and in some
	 * contexts area metadata is missing. Use progressively looser checks.
	 *
	 * @param array $block Parsed block data.
	 * @return boolean True if this block is likely a header template part.
	 */
	private static function is_header_template_part_block( $block ) {
		if ( empty( $block['blockName'] ) || 'core/template-part' !== $block['blockName'] ) {
			return false;
		}

		$attrs = isset( $block['attrs'] ) ? $block['attrs'] : [];

		// Most reliable signal when present.
		if ( isset( $attrs['area'] ) && 'header' === $attrs['area'] ) {
			return true;
		}

		// Many themes use non-exact slugs such as "header-post" or "site-header".
		if ( isset( $attrs['slug'] ) && preg_match( '/(^|[-_])header([-_]|$)/', $attrs['slug'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Insert popup after posts in archive pages. Called in the custom hook after_archive_post
	 * The hook is add inside the posts loop in the archive template.
	 *
	 * @param integer $post_count order of the post in the posts loop.
	 * @return void
	 */
	public static function insert_inline_prompt_in_archive_pages( $post_count ) {
		echo self::get_inline_prompt_html_for_archive_pages( $post_count, 'article', null, 'class="entry"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Get the HTML for an inline prompt on archive pages.
	 *
	 * @param integer $post_count      Order of the post in the posts loop.
	 * @param string  $tag             Wrapper element tag name. Defaults to 'article'.
	 * @param array   $archives_popups Pre-filtered list of archive popups. If null, popups_for_post() is filtered internally.
	 * @param string  $attrs           Optional HTML attributes string for the wrapper element (e.g. 'class="entry"').
	 * @return string HTML output, or empty string.
	 */
	public static function get_inline_prompt_html_for_archive_pages( $post_count, $tag = 'article', $archives_popups = null, $attrs = '' ) {
		global $wp_query;

		if ( null === $archives_popups ) {
			$archives_popups = array_filter( self::popups_for_post(), [ 'Newspack_Popups_Model', 'should_be_inserted_in_archive_pages' ] );
		}
		$output = '';
		foreach ( $archives_popups as $popup ) {
			$page_types = $popup['options']['archive_page_types'];
			if ( ( is_home() && ! in_array( 'home', $page_types ) )
				|| ( is_category() && ! in_array( 'category', $page_types ) )
				|| ( is_tag() && ! in_array( 'tag', $page_types ) )
				|| ( is_author() && ! in_array( 'author', $page_types ) )
				|| ( is_date() && ! in_array( 'date', $page_types ) )
				|| ( is_post_type_archive() && ! in_array( 'post-type', $page_types ) )
				|| ( is_tax() && ! in_array( 'taxonomy', $page_types ) )
			) {
				continue;
			}

			$archive_insertion_posts_count = intval( $popup['options']['archive_insertion_posts_count'] );
			// insert after archive_insertion_posts_count articles
			// or every archive_insertion_posts_count posts if prompt set to repeated
			// or at the end if the total posts count is less than the trigger count.
			if ( $post_count === $archive_insertion_posts_count
				|| ( $popup['options']['archive_insertion_is_repeating'] && 0 === $post_count % $archive_insertion_posts_count )
				|| ( $archive_insertion_posts_count >= $wp_query->post_count && $post_count === $wp_query->post_count )
			) {
					$open_tag = ! empty( $attrs ) ? $tag . ' ' . $attrs : $tag;
				$output  .= '<' . $open_tag . '>' . Newspack_Popups_Model::generate_popup( $popup ) . '</' . $tag . '>';
			}
		}
		return $output;
	}

	/**
	 * Insert inline prompts into a rendered core/post-template block on archive/home pages.
	 *
	 * Uses the render_block filter so this works with block themes. Only targets
	 * the primary Query Loop (the one that inherits the global/main query) so
	 * that secondary loops like "featured posts" or "you may also like" don't
	 * receive prompt injection.
	 *
	 * @param string   $block_content Rendered block HTML.
	 * @param array    $block         Block data array, including 'blockName'.
	 * @param WP_Block $instance      The block instance (available since WP 5.9).
	 * @return string Filtered block HTML.
	 */
	public static function insert_inline_prompt_in_block_theme_archives( $block_content, $block, $instance = null ) {
		if ( 'core/post-template' !== $block['blockName'] ) {
			return $block_content;
		}

		if ( ! is_archive() && ! is_home() ) {
			return $block_content;
		}

		// Only inject into the Query Loop that inherits the main/global query.
		// Secondary loops (custom queryId, inherit=false) should not get prompts.
		if ( $instance instanceof WP_Block ) {
			$query_context = $instance->context['query'] ?? [];
			$inherits_main = ! empty( $query_context['inherit'] );
			if ( ! $inherits_main ) {
				return $block_content;
			}
		}

		$archives_popups = array_filter( self::popups_for_post(), [ 'Newspack_Popups_Model', 'should_be_inserted_in_archive_pages' ] );

		// Split on each post item boundary. core/post-template wraps each post in
		// <li class="wp-block-post ...">. Use [\s"'] to avoid false matches on child element
		// classes like wp-block-post-title, wp-block-post-excerpt, etc.
		$parts = preg_split( '/(?=<li[^>]*\bwp-block-post[\s"\'])/', $block_content );

		if ( ! $parts || count( $parts ) < 2 ) {
			return $block_content;
		}

		// $parts[0] is the opening <ul>; $parts[1..n] are the post items.
		$post_count = count( $parts ) - 1;

		// The closing </ul> (and any content that follows it) lives in the last part because
		// preg_split's lookahead puts everything after the final post item there. Strip it off
		// so that any injected prompt <li> elements are inserted before </ul>, not after it.
		$trailing = '';
		$close_ul = strripos( $parts[ $post_count ], '</ul' );
		if ( false !== $close_ul ) {
			$trailing            = substr( $parts[ $post_count ], $close_ul );
			$parts[ $post_count ] = substr( $parts[ $post_count ], 0, $close_ul );
		}

		$output = $parts[0];

		for ( $i = 1; $i <= $post_count; $i++ ) {
			$output .= $parts[ $i ];
			$output .= self::get_inline_prompt_html_for_archive_pages( $i, 'li', $archives_popups );
		}

		$output .= $trailing;

		return $output;
	}

	/**
	 * Is the current page an AMP page?
	 *
	 * @return boolean True if AMP, otherwise false.
	 */
	public static function is_amp() {
		return function_exists( 'is_amp_endpoint' ) && is_amp_endpoint();
	}

	/**
	 * Is AMP Plus enabled on this site?
	 *
	 * @return boolean True if AMP, otherwise false.
	 */
	public static function is_amp_plus() {
		return class_exists( '\Newspack\AMP_Enhancements' ) && \Newspack\AMP_Enhancements::is_amp_plus_configured();
	}

	/**
	 * Should UI for admin users be shown?
	 *
	 * @return boolean
	 */
	public static function should_show_admin_ui() {
		$is_amp      = self::is_amp();
		$is_amp_plus = $is_amp && self::is_amp_plus();

		return Newspack_Popups_Segmentation::is_admin_user() && ( ! $is_amp || $is_amp_plus ) && ! is_admin();
	}

	/**
	 * Filter specific blocks to be ignored when validating content.
	 *
	 * @param string $content The content.
	 *
	 * @return string Filtered content.
	 */
	private static function get_validation_content( $content ) {
		$blocks   = parse_blocks( $content );
		$filtered = array_filter(
			$blocks,
			function ( $block ) {
				$excluded_blocks = [
					// Corrections are always added to the start or end of the content, so we can ignore them.
					'newspack/correction-box',
					'newspack/correction-item',
				];
				return ! in_array( $block['blockName'], $excluded_blocks, true );
			}
		);

		$filtered_html = '';
		foreach ( $filtered as $block ) {
			$filtered_html .= serialize_block( $block );
		}
		return trim( $filtered_html );
	}

	/**
	 * If true, debugging info will be logged to the newspack_popups_debug JS object.
	 *
	 * @return boolean
	 */
	private static function should_log_debug_info() {
		/**
		 * Enables debug logging for Newspack Popups (Campaigns).
		 * When enabled, debugging info is logged to the newspack_popups_debug
		 * JavaScript object, helpful for troubleshooting popup display issues.
		 *
		 * @constant NEWSPACK_POPUPS_DEBUG
		 * @type     bool
		 * @default  Debug disabled
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_POPUPS_DEBUG', true );
		 */
		return ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ( defined( 'NEWSPACK_LOG_LEVEL' ) && 1 < NEWSPACK_LOG_LEVEL ) || ( defined( 'NEWSPACK_POPUPS_DEBUG' ) && NEWSPACK_POPUPS_DEBUG );
	}

	/**
	 * Enqueue the assets needed to display the popups.
	 */
	public static function enqueue_scripts() {
		if ( defined( 'IS_TEST_ENV' ) && IS_TEST_ENV ) {
			return;
		}

		if ( self::should_show_admin_ui() ) {
			$admin_script_handle = self::ADMIN_SCRIPT_HANDLE;
			\wp_register_script(
				$admin_script_handle,
				plugins_url( '../dist/admin.js', __FILE__ ),
				[],
				filemtime( dirname( NEWSPACK_POPUPS_PLUGIN_FILE ) . '/dist/admin.js' ),
				true
			);
			\wp_register_style(
				$admin_script_handle,
				plugins_url( '../dist/admin.css', __FILE__ ),
				null,
				filemtime( dirname( NEWSPACK_POPUPS_PLUGIN_FILE ) . '/dist/admin.css' )
			);
			\wp_localize_script(
				$admin_script_handle,
				'newspack_popups_admin',
				[
					'label_visible' => __( 'Prompts Visible', 'newspack-popups' ),
					'label_hidden'  => __( 'Prompts Hidden', 'newspack-popups' ),
				]
			);
			\wp_script_add_data( $admin_script_handle, 'amp-plus', true );
			\wp_script_add_data( $admin_script_handle, 'async', true );
			\wp_enqueue_script( $admin_script_handle );
			\wp_style_add_data( $admin_script_handle, 'rtl', 'replace' );
			\wp_enqueue_style( $admin_script_handle );
		}

		// Note: always enqueue scripts even when prompts are disabled, to allow page view logging.
		$script_handle = 'newspack-popups-view';

		if ( ! self::is_amp() ) {
			\wp_register_script(
				$script_handle,
				plugins_url( '../dist/view.js', __FILE__ ),
				[
					'wp-url',
					Newspack_Popups_Criteria::SCRIPT_HANDLE,
				],
				filemtime( dirname( NEWSPACK_POPUPS_PLUGIN_FILE ) . '/dist/view.js' ),
				true
			);

			$script_data = [
				'debug'                => self::should_log_debug_info(),
				'has_disabled_prompts' => is_singular() && ! empty( get_post_meta( get_the_ID(), 'newspack_popups_has_disabled_popups', true ) ) && ! Newspack_Popups::is_preview_request(),
			];

			if ( Newspack_Popups::$segmentation_enabled ) {
				$segments = Newspack_Popups_Segmentation::get_segments( false );

				// Gather segments for all prompts to be displayed.
				foreach ( $segments as $segment ) {
					if ( ! empty( $segment ) && ! empty( $segment['criteria'] ) && ! isset( self::$segments[ $segment['id'] ] ) ) {
						self::$segments[ $segment['id'] ] = [
							'name'     => $segment['name'],
							'criteria' => $segment['criteria'],
							'priority' => $segment['priority'],
						];
					}
				}

				$script_data['segments'] = (object) self::$segments;
			}

			$donor_landing_page = Newspack_Popups_Settings::donor_landing_page();
			if ( ! empty( $donor_landing_page ) ) {
				$script_data['donor_landing_page'] = $donor_landing_page;
			}

			\wp_localize_script( $script_handle, 'newspack_popups_view', $script_data );
			\wp_enqueue_script( $script_handle );
		}

		\wp_register_style(
			$script_handle,
			plugins_url( '../dist/view.css', __FILE__ ),
			null,
			filemtime( dirname( NEWSPACK_POPUPS_PLUGIN_FILE ) . '/dist/view.css' )
		);
		\wp_style_add_data( $script_handle, 'rtl', 'replace' );
		\wp_enqueue_style( $script_handle );

		// Enqueue Jetpack contact form styles if any active popups contain contact forms.
		self::maybe_enqueue_contact_form_styles();
	}

	/**
	 * Enqueue Jetpack contact form styles if any active popups contain contact forms.
	 */
	public static function maybe_enqueue_contact_form_styles() {
		// Only proceed if Jetpack contact forms are available.
		if ( ! class_exists( '\Automattic\Jetpack\Extensions\Contact_Form\Contact_Form_Block' ) ) {
			return;
		}

		// Check if any active popups contain Jetpack contact forms.
		$active_popups = Newspack_Popups_Model::retrieve_active_popups();
		foreach ( $active_popups as $popup ) {
			if ( false !== strpos( $popup['content'], 'wp:jetpack/contact-form' ) ) {
				// Enqueue the grunion.css stylesheet.
				\wp_enqueue_style( 'grunion.css' );
				break;
			}
		}
	}

	/**
	 * The popup shortcode function.
	 * Primarily, the shortcode is inserted by the plugin, but it may also be inserted manually to
	 * display a specific popup anywhere on the site.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return HTML
	 */
	public static function popup_shortcode( $atts = array() ) {
		if ( self::assess_has_disabled_popups() ) {
			return;
		}

		$default_atts = [
			'id'    => 0,
			'class' => '',
		];
		$atts = \shortcode_atts( $default_atts, $atts, 'newspack-popup' );

		$found_popup = false;
		if ( Newspack_Popups::preset_popup_id() ) {
			$found_popup = Newspack_Popups_Presets::retrieve_preset_popup( Newspack_Popups::preset_popup_id() );
		} elseif ( ! empty( $atts['id'] ) && is_numeric( $atts['id'] ) ) {
			$include_unpublished = Newspack_Popups::is_preview_request();
			$found_popup         = Newspack_Popups_Model::retrieve_popup_by_id( (int) $atts['id'], $include_unpublished );
		}
		if ( ! $found_popup ) {
			return;
		}

		if (
			// Bail if it should not be displayed.
			! self::should_display( $found_popup ) ||
			// Only inline or manual-only popups can be inserted via the shortcode.
			( ! Newspack_Popups_Model::is_inline( $found_popup ) && ! Newspack_Popups_Model::is_manual_only( $found_popup ) )
		) {
			return;
		}

		$class_names = '';
		if ( ! empty( $atts['class'] ) ) {
			$class_names = sprintf( ' class="%s"', \esc_attr( $atts['class'] ) );
		}

		// Wrapping the inline popup in an aside element prevents the markup from being mangled
		// if the shortcode is the first block.
		return '<aside' . $class_names . '>' . Newspack_Popups_Model::generate_popup( $found_popup ) . '</aside>';
	}

	/**
	 * Disable popups on posts and pages which have newspack_popups_has_disabled_popups.
	 *
	 * @return bool True if popups should be disabled for current page.
	 */
	public static function assess_has_disabled_popups() {
		return apply_filters( 'newspack_popups_assess_has_disabled_popups', false );
	}

	/**
	 * Look for popup shortcodes in a string and return their IDs.
	 *
	 * @param string $string String to assess.
	 * @return array Found shortcoded popups IDs.
	 */
	public static function get_shortcoded_popups_ids( $string ) {
		$parsed_blocks = parse_blocks( $string );

		$single_prompt_blocks    = array_filter(
			$parsed_blocks,
			function( $block ) {
				return 'newspack-popups/single-prompt' === $block['blockName'];
			}
		);
		$single_prompt_block_ids = array_reduce(
			$single_prompt_blocks,
			function( $acc, $popup ) {
				if ( ! empty( $popup['attrs']['promptId'] ) ) {
					$acc[] = intval( $popup['attrs']['promptId'] );
				}
				return $acc;
			},
			[]
		);

		preg_match_all( '/\[newspack-popup .*\]/', $string, $popup_shortcodes_in_content );

		if ( empty( $popup_shortcodes_in_content ) ) {
			return [];
		} else {
			return array_unique(
				array_merge(
					$single_prompt_block_ids,
					array_map(
						function ( $item ) {
							preg_match( '/id=["|\'](\d*)/', $item, $matches );
							if ( empty( $matches ) ) {
								return null;
							} else {
								return $matches[1];
							}
						},
						$popup_shortcodes_in_content[0]
					)
				)
			);
		}
	}

	/**
	 * If a prompt is assigned the given taxonomy, it should only be shown on posts/pages with at least one matching term.
	 * If the prompt has no terms, it should be shown regardless of the post's terms.
	 *
	 * @param object $popup The prompt to assess.
	 * @param string $taxonomy The type of taxonomy to match.
	 *
	 * @return bool Whether the prompt should be shown based on matching terms.
	 */
	public static function assess_taxonomy_filter( $popup, $taxonomy = 'category' ) {
		// For single popup preview, ensure the prompt appears in the first post loaded in the preview window.
		// But for view_as preview, we should still apply category filtering.
		if ( Newspack_Popups::is_preview_request() && ! Newspack_Popups_View_As::viewing_as_spec() ) {
			return true;
		}

		$post_terms     = get_the_terms( get_the_ID(), $taxonomy );
		$post_terms_ids = $post_terms ? array_column( $post_terms, 'term_id' ) : [];

		// Check if a post term is excluded on the popup options.
		if ( 'category' === $taxonomy ) {
			if ( 0 < count( array_intersect( $popup['options']['excluded_categories'], $post_terms_ids ) ) ) {
				return false;
			}
		}

		if ( 'post_tag' === $taxonomy ) {
			if ( 0 < count( array_intersect( $popup['options']['excluded_tags'], $post_terms_ids ) ) ) {
				return false;
			}
		}

		$popup_terms = get_the_terms( $popup['id'], $taxonomy );
		if ( false === $popup_terms ) {
			return true; // No terms on the popup, no need to compare.
		}
		return array_intersect(
			array_column( $post_terms ? $post_terms : [], 'term_id' ),
			array_column( $popup_terms, 'term_id' )
		);
	}

	/**
	 * Should Popup be rendered, based on universal conditions.
	 *
	 * @param object $popup The popup to assess.
	 * @param bool   $check_if_is_post Should the post type of post be taken into account.
	 * @return bool Should popup be shown.
	 */
	public static function should_display( $popup, $check_if_is_post = false ) {
		$post_type = get_post_type();

		// Prompts should be hidden on account related pages (e.g. password reset page).
		if ( Newspack_Popups::is_account_related_post( get_post() ) ) {
			return false;
		}

		// Context in which the popup appears.
		// 1. the taxonomy of the post.
		$is_taxonomy_matching = self::assess_taxonomy_filter( $popup, 'category' ) && self::assess_taxonomy_filter( $popup, 'post_tag' );
		// 2. the type of the post supported by this popup, if different than the global setting.
		$popup_post_types = $popup['options']['post_types'];

		$default_post_types = Newspack_Popups_Model::get_default_popup_post_types();

		sort( $popup_post_types );
		sort( $default_post_types );
		if ( $popup_post_types === $default_post_types ) {
			// Popup's post types are the same as default - global post types should be used.
			$supported_post_types = Newspack_Popups_Model::get_globally_supported_post_types();
		} else {
			// Popup's post types are *set* - different than defaults. These should override the global post types.
			$supported_post_types = $popup_post_types;
		}
		$is_post_context_matching = $is_taxonomy_matching && in_array( $post_type, $supported_post_types );

		/**
		 * Filters the result of the should_display check for each prompt.
		 *
		 * If $check_result is false, it means it failed the previous checks. Changing this to true will make the prompt appear.
		 * Use it with caution as this might result in unexpected behavior.
		 *
		 * @param bool   $check_result Whether the popup should be displayed.
		 * @param object $popup The popup to assess.
		 * @param bool   $check_if_is_post Should the post type of post be taken into account.
		 */
		return apply_filters( 'newspack_popups_should_display_prompt', $is_post_context_matching, $popup, $check_if_is_post );
	}

	/**
	 * Add an admin bar button for logged-in admins and editors to toggle Campaigns visibility.
	 */
	public static function add_preview_toggle() {
		if ( ! self::should_show_admin_ui() ) {
			return;
		}

		global $wp_admin_bar;
		$wp_admin_bar->add_menu(
			[
				'parent' => false,
				'id'     => 'campaigns_preview_toggle',
				'title'  => __( 'Prompts Visible', 'newspack-popups' ),
				'href'   => '#',
				'meta'   => [
					'class' => 'newspack-campaigns-preview-toggle',
				],
			]
		);
	}

	/**
	 * Mark this request as an Apple News exporter request.
	 */
	public static function apple_news_do_fetch_exporter() {
		self::$is_apple_news_exporting = true;
	}
}
$newspack_popups_inserter = new Newspack_Popups_Inserter();
