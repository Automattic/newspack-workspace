<?php
/**
 * Contextual Prompt render pipeline.
 *
 * Shapes every Contextual Prompt as it renders and owns what leaves it: the
 * analytics hooks the view script reads, the empty-copy suppression, and the
 * strip that hides instances while the feature is off.
 *
 * The pattern's stored CTA is fixed when it is written, so a change of donation
 * platform can leave it disagreeing with the site. Reconciling happens twice —
 * once against the stored pattern (repair, so the editor sees the truth too) and
 * again in memory for whatever the pattern's own markup happens to be at render.
 *
 * Normalization is scoped to blocks arriving from the pattern: a Group a
 * publisher detached and pasted into a post is theirs, not ours.
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contextual Prompt render class.
 */
final class Newspack_Popups_Contextual_Prompt_Render {
	/**
	 * Handle the card's own inline CSS is delivered on.
	 */
	const LAYOUT_STYLE_HANDLE = 'newspack-popups-contextual-prompt-layout';

	/**
	 * Whether the block being rendered came from the pattern.
	 *
	 * @var bool
	 */
	private static $in_instance = false;

	/**
	 * Whether the pattern has already been reconciled this request.
	 *
	 * @var bool
	 */
	private static $repaired = false;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'render_block_data', [ __CLASS__, 'open_instance_window' ], 10, 1 );
		add_filter( 'render_block_data', [ __CLASS__, 'normalize_group' ], 10, 1 );
		add_filter( 'render_block_core/block', [ __CLASS__, 'suppress_empty_instance' ], 9, 2 );
		add_filter( 'render_block_core/group', [ __CLASS__, 'add_analytics_attributes' ], 10, 2 );
		add_filter( 'render_block_core/block', [ __CLASS__, 'close_instance_window' ], 999, 2 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_layout_styles' ] );
		add_filter( 'block_editor_settings_all', [ __CLASS__, 'add_editor_layout_styles' ] );
	}

	/**
	 * What a classic theme leaves the card without. Block themes get both rules
	 * from layout support, so nothing is delivered there.
	 *
	 * Without that support core restores the Group's inner container, in the
	 * editor and at render alike, so the card's children sit a level deeper —
	 * except under a flex layout, which drops the container on both surfaces.
	 * Hence the pair of selectors for each rule.
	 *
	 * The card's blockGap emits no CSS at all without the support, leaving the
	 * gap to the theme on one surface and to the browser on the other; it is
	 * owned here, at the value the card is seeded with, so the editor shows what
	 * a reader gets. The theme's own group spacing is zeroed first: an
	 * unreconciled bottom margin would collapse with it and win.
	 *
	 * A flex item holding a CTA shrinks to its narrowest wrap — one letter per
	 * line — so it is held at its own width, at a specificity anything set on a
	 * single prompt still beats.
	 *
	 * @return string CSS, or an empty string on a block theme.
	 */
	public static function get_layout_css() {
		if ( wp_is_block_theme() ) {
			return '';
		}

		$card   = '.wp-block-group.' . Newspack_Popups_Contextual_Prompt_Pattern::MARKER_CLASS;
		$stacks = [ $card . ' > .wp-block-group__inner-container', $card . '.is-layout-flow', $card . '.is-layout-constrained' ];
		$reset  = [];
		$gap    = [];
		foreach ( $stacks as $stack ) {
			$reset[] = $stack . ' > *';
			$gap[]   = $stack . ' > * + *';
		}

		$flex = ':where(.' . Newspack_Popups_Contextual_Prompt_Pattern::MARKER_CLASS . '.is-layout-flex) > ';

		return implode( ',', $reset ) . '{margin-block-start:0;margin-block-end:0}'
			. implode( ',', $gap ) . '{margin-block-start:var(--wp--preset--spacing--30,1rem)}'
			. $flex . '.wp-block-buttons,' . $flex . '.wpbnbd{flex-shrink:0}';
	}

	/**
	 * Restates the CTA's label colour on the Newspack classic theme, where a link
	 * inside a colour-carrying block inherits that colour above the theme's own
	 * button rule, leaving the card's near-black label on a dark button. What a
	 * publisher sets on the button still wins: core emits `!important` for a
	 * palette colour and inline for a custom one.
	 *
	 * The colour is the theme's pair for the theme's button background, so it is
	 * wrong for a background a publisher chose and unnecessary for an outline
	 * button the theme already colours. Gated on that theme because elsewhere the
	 * variable is undefined, which would drop the declaration and restore the
	 * inheritance.
	 *
	 * @return string CSS, or an empty string off the Newspack classic theme.
	 */
	public static function get_button_color_css() {
		$css = '';

		if ( 'newspack-theme' === get_template() ) {
			$filled = '.' . Newspack_Popups_Contextual_Prompt_Pattern::MARKER_CLASS
				. ' .wp-block-button:not(.is-style-outline) > .wp-block-button__link:not(.is-style-outline):not(.has-background)';
			$css    = $filled . '{color:var(--newspack-theme-color-against-secondary)}';
		}

		/**
		 * Filters the CSS restating the Contextual Prompt CTA's label colour.
		 *
		 * Applied off the theme gate too, so a theme the check misses can opt in.
		 *
		 * @param string $css The CSS, or an empty string.
		 */
		return (string) apply_filters( 'newspack_contextual_prompts_button_color_css', $css );
	}

	/**
	 * Everything the card is delivered. Both hooks read this, so the editor and
	 * the front end cannot drift apart through an edit to one of them.
	 *
	 * @return string
	 */
	private static function get_delivered_css() {
		return self::get_layout_css() . self::get_button_color_css();
	}

	/**
	 * Front end: an inline stylesheet of its own, so it lands wherever the theme
	 * prints its styles and carries no file to version.
	 */
	public static function enqueue_layout_styles() {
		$css = self::get_delivered_css();
		if ( '' === $css ) {
			return;
		}

		wp_register_style( self::LAYOUT_STYLE_HANDLE, false ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Inline styles only, no source file to version.
		wp_enqueue_style( self::LAYOUT_STYLE_HANDLE );
		wp_add_inline_style( self::LAYOUT_STYLE_HANDLE, $css );
	}

	/**
	 * Editor: the same CSS in the canvas, after the entries core added from
	 * theme.json.
	 *
	 * @param array $settings Block editor settings.
	 * @return array
	 */
	public static function add_editor_layout_styles( $settings ) {
		$css = self::get_delivered_css();
		if ( '' === $css ) {
			return $settings;
		}

		if ( ! isset( $settings['styles'] ) || ! is_array( $settings['styles'] ) ) {
			$settings['styles'] = [];
		}
		$settings['styles'][] = [ 'css' => $css ];

		return $settings;
	}

	/**
	 * Whether the block currently rendering came from the pattern.
	 *
	 * @return bool
	 */
	public static function is_in_instance() {
		return self::$in_instance;
	}

	/**
	 * Whether a parsed block references the pattern. The record is read raw: a
	 * render must never seed, and an unseeded site must not match a ref of 0.
	 *
	 * @param array $block Parsed block.
	 * @return bool
	 */
	private static function is_instance( $block ) {
		$ref = (int) ( $block['attrs']['ref'] ?? 0 );

		return (bool) $ref && $ref === (int) get_option( Newspack_Popups_Contextual_Prompt_Pattern::OPTION_PATTERN_ID, 0 );
	}

	/**
	 * Open the window at the pattern instance, so the blocks core renders from
	 * the pattern's markup are recognizable as ours. Reconciling the stored
	 * pattern with the site's platform rides along, once a request: a stale
	 * pattern would otherwise be normalized for every reader without the editor
	 * ever showing what they actually publish.
	 *
	 * The pattern record is read raw — a render must never seed. The repair is
	 * gated on the same pair the strip is, so a feature an admin has switched off
	 * never writes, and on the capability to edit the pattern: a reader's render
	 * gets the in-memory normalization only, never a write.
	 *
	 * @param array $parsed_block The block being rendered.
	 * @return array
	 */
	public static function open_instance_window( $parsed_block ) {
		if ( 'core/block' !== ( $parsed_block['blockName'] ?? '' ) || ! self::is_instance( $parsed_block ) ) {
			return $parsed_block;
		}

		if ( ! self::$repaired && self::is_feature_on() && current_user_can( 'edit_post', (int) $parsed_block['attrs']['ref'] ) ) {
			self::$repaired = true;
			Newspack_Popups_Contextual_Prompt_Pattern::repair();
		}

		self::$in_instance = true;

		return $parsed_block;
	}

	/**
	 * Close the window. Unconditional: a window left open would hand the rest of
	 * the page the pattern's normalization.
	 *
	 * @param string $block_content Rendered block markup.
	 * @param array  $block         The parsed block.
	 * @return string
	 */
	public static function close_instance_window( $block_content, $block = [] ) {
		self::$in_instance = false;

		return $block_content;
	}

	/**
	 * Hide every instance while the feature is not fully on: the rollout flag must
	 * be defined AND the admin opt-in active. Registered unconditionally and
	 * checked at render, because the opt-in is an option an admin can withdraw
	 * without a reload — without this, disabling the feature would leave prompts
	 * (and a live site-wide override) rendering with no UI to stop them.
	 *
	 * A detached card is the publisher's own content, so only instances are hidden.
	 *
	 * @param string $block_content Rendered block markup.
	 * @param array  $block         The parsed block.
	 * @return string
	 */
	public static function maybe_strip_instance( $block_content, $block = [] ) {
		if ( ! self::is_instance( is_array( $block ) ? $block : [] ) ) {
			return $block_content;
		}

		return self::is_feature_on() ? $block_content : '';
	}

	/**
	 * Whether Contextual Prompts are fully on: the rollout flag defined and the
	 * admin opt-in active.
	 *
	 * @return bool
	 */
	private static function is_feature_on() {
		return Newspack_Popups::is_contextual_prompts_enabled() && Newspack_Popups_Settings::is_ai_copy_assistant_enabled();
	}

	/**
	 * Retire the pre-pattern beta markup. Posts saved while Contextual Prompts
	 * were their own block carry a `newspack-popups/contextual-prompt` block no
	 * longer registered, which would render as a card nothing here manages.
	 *
	 * @param string $block_content Rendered block markup.
	 * @param array  $block         The parsed block.
	 * @return string
	 */
	public static function strip_legacy_block( $block_content, $block = [] ) {
		$name = is_array( $block ) ? ( $block['blockName'] ?? '' ) : '';

		return 'newspack-popups/contextual-prompt' === $name ? '' : $block_content;
	}

	/**
	 * An instance displaying no copy — generation failed and the post was
	 * published anyway — renders nothing rather than a CTA-only card. Read off the
	 * rendered markup, so the copy an instance override or the site-wide override
	 * supplies counts as copy.
	 *
	 * @param string $block_content Rendered block markup.
	 * @param array  $block         The parsed block.
	 * @return string
	 */
	public static function suppress_empty_instance( $block_content, $block = [] ) {
		if ( ! self::is_instance( is_array( $block ) ? $block : [] ) ) {
			return $block_content;
		}

		return self::has_copy( $block_content ) ? $block_content : '';
	}

	/**
	 * Whether the card's copy paragraph — the first one it renders — carries
	 * visible text. A paragraph holding only a non-breaking space is what the
	 * editor leaves behind when copy is deleted, and reads as empty.
	 *
	 * @param string $block_content Rendered block markup.
	 * @return bool
	 */
	private static function has_copy( $block_content ) {
		if ( ! preg_match( '#<p\b[^>]*>(.*?)</p>#s', (string) $block_content, $matches ) ) {
			return false;
		}

		$text = html_entity_decode( wp_strip_all_tags( $matches[1] ), ENT_QUOTES, 'UTF-8' );

		return '' !== trim( str_replace( "\xC2\xA0", ' ', $text ) );
	}

	/**
	 * Stamp analytics hooks on the rendered card so the view script can report
	 * seen/clicked events. Done at render (not in saved content) so the values stay
	 * live and no markup carries stale ids.
	 *
	 * @param string $block_content Rendered block markup.
	 * @param array  $block         The parsed block.
	 * @return string
	 */
	public static function add_analytics_attributes( $block_content, $block = [] ) {
		// This filter runs for every Group on the page, so the marker is looked for
		// in the string before anything is parsed.
		if ( false === strpos( (string) $block_content, Newspack_Popups_Contextual_Prompt_Pattern::MARKER_CLASS ) ) {
			return $block_content;
		}

		// Parsed rather than pattern-matched, so a leading comment or text node
		// can't push the attributes off the card. The card is the first tag: a Group
		// that merely contains one carries the marker in its content, and stamping
		// it too would report the same prompt twice.
		$processor = new WP_HTML_Tag_Processor( $block_content );
		if ( ! $processor->next_tag() || ! $processor->has_class( Newspack_Popups_Contextual_Prompt_Pattern::MARKER_CLASS ) ) {
			return $block_content;
		}

		$post_id = (int) get_the_ID();

		// set_attribute() escapes the value, so these are passed unescaped.
		$processor->set_attribute( 'data-newspack-cp-post-id', (string) $post_id );
		$processor->set_attribute( 'data-newspack-cp-cta', self::get_cta_type_from_html( $block_content ) );
		$processor->set_attribute( 'data-newspack-cp-placement', self::get_placement( $post_id ) );

		return $processor->get_updated_html();
	}

	/**
	 * The CTA actually rendered, read off the markup: the configured platform
	 * alone can disagree with it (a button override on a native site, or an
	 * off-site setup with no destination, where the CTA is dropped).
	 *
	 * @param string $html Rendered card markup.
	 * @return string 'donate_block' | 'button' | 'none'.
	 */
	public static function get_cta_type_from_html( $html ) {
		if ( false !== strpos( (string) $html, 'wpbnbd' ) ) {
			return 'donate_block';
		}
		if ( false !== strpos( (string) $html, 'wp-block-button__link' ) ) {
			return 'button';
		}

		return 'none';
	}

	/**
	 * Where the prompt sits in the story, as a coarse bucket: top / mid / end.
	 *
	 * A prompt is body content, so placement is its position among the article's
	 * top-level blocks — measured, not the framing the editor first chose, since
	 * it can be moved after insertion. This is the "which placement converts best"
	 * grant metric.
	 *
	 * The bucket is the first prompt card's and is memoized per post, so a post
	 * carrying several cards reports that one bucket for all of them — the product
	 * model is one prompt per post.
	 *
	 * Memoized for the request: add_analytics_attributes() calls this on every
	 * render and each miss reparses the whole post. Only a resolved post is
	 * memoized, so an id that resolves later in the request is never pinned to the
	 * 'unknown' its earlier lookup produced.
	 *
	 * @param int $post_id The article.
	 * @return string 'top' | 'mid' | 'end' | 'unknown'.
	 */
	public static function get_placement( $post_id ) {
		static $memo = [];

		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return 'unknown';
		}

		if ( ! isset( $memo[ $post->ID ] ) ) {
			$memo[ $post->ID ] = self::bucket_placement( $post );
		}

		return $memo[ $post->ID ];
	}

	/**
	 * Bucket a post's prompt position among its top-level blocks.
	 *
	 * @param WP_Post $post The article.
	 * @return string 'top' | 'mid' | 'end' | 'unknown'.
	 */
	private static function bucket_placement( $post ) {
		$blocks = array_values(
			array_filter(
				parse_blocks( $post->post_content ),
				function ( $block ) {
					return ! empty( $block['blockName'] );
				}
			)
		);
		$total = count( $blocks );
		if ( $total < 1 ) {
			return 'unknown';
		}

		$index = null;
		foreach ( $blocks as $i => $block ) {
			if ( self::is_prompt_card( $block ) ) {
				$index = $i;
				break;
			}
		}
		if ( null === $index ) {
			// Nested inside a group/columns — position can't be bucketed cleanly.
			return 'unknown';
		}

		if ( 1 === $total ) {
			return 'top';
		}

		$ratio = $index / ( $total - 1 );
		if ( $ratio <= 1 / 3 ) {
			return 'top';
		}
		if ( $ratio >= 2 / 3 ) {
			return 'end';
		}
		return 'mid';
	}

	/**
	 * Whether a parsed block is a prompt card: an instance of the pattern, or the
	 * marker Group a publisher detached from it.
	 *
	 * @param array $block Parsed block.
	 * @return bool
	 */
	private static function is_prompt_card( $block ) {
		$name = $block['blockName'] ?? '';

		if ( 'core/block' === $name ) {
			return self::is_instance( $block );
		}

		return 'core/group' === $name
			&& false !== strpos( (string) ( $block['attrs']['className'] ?? '' ), Newspack_Popups_Contextual_Prompt_Pattern::MARKER_CLASS );
	}

	/**
	 * Normalize the prompt card's CTA and apply the site-wide override, for blocks
	 * coming from the pattern only.
	 *
	 * @param array $parsed_block The block being rendered.
	 * @return array
	 */
	public static function normalize_group( $parsed_block ) {
		if (
			! self::$in_instance
			|| 'core/group' !== ( $parsed_block['blockName'] ?? '' )
			|| false === strpos( (string) ( $parsed_block['attrs']['className'] ?? '' ), Newspack_Popups_Contextual_Prompt_Pattern::MARKER_CLASS )
		) {
			return $parsed_block;
		}

		$parsed_block = self::normalize_cta( $parsed_block );

		if ( class_exists( 'Newspack_Popups_Settings' ) && Newspack_Popups_Settings::is_override_active() ) {
			$parsed_block = self::apply_override( $parsed_block );
		}

		return $parsed_block;
	}

	/**
	 * A prompt's CTA type is fixed when it is written, so after a change of
	 * donation platform the stored CTA can disagree with the site. Normalize:
	 * the native platform renders the donate form, off-site renders a button to
	 * the donor landing page — or copy only when none is configured. Matching
	 * CTAs pass through untouched, preserving publisher customization.
	 *
	 * @param array $parsed_block Parsed prompt card.
	 * @return array
	 */
	public static function normalize_cta( $parsed_block ) {
		$cta = self::find_cta( $parsed_block );
		if ( null === $cta ) {
			return $parsed_block;
		}

		if ( Newspack_Popups_Contextual_Prompt_Pattern::use_donate_block() ) {
			if ( 'core/buttons' === $cta['name'] ) {
				// Not recorded: this rebuild is thrown away with the request, and
				// only the stored pattern's stamp belongs in the record.
				$parsed_block['innerBlocks'][ $cta['index'] ] = Newspack_Popups_Contextual_Prompt_Pattern::build_donate_child( false );
			}
			return $parsed_block;
		}

		$needs_destination = 'newspack-blocks/donate' === $cta['name']
			// A plain-button CTA without a destination anywhere — written before a
			// donor landing page was configured — is a dead ask. Buttons carrying
			// any URL pass through untouched.
			|| ! self::buttons_have_destination( $parsed_block['innerBlocks'][ $cta['index'] ] );

		if ( $needs_destination ) {
			if ( '' === Newspack_Popups_Contextual_Prompt_Pattern::get_button_url() ) {
				// No destination to point a button at: render the copy alone
				// rather than a dead button or a form on a disabled platform.
				return self::remove_child( $parsed_block, $cta['index'] );
			}
			$parsed_block['innerBlocks'][ $cta['index'] ] = Newspack_Popups_Contextual_Prompt_Pattern::build_buttons_child();
		}

		return $parsed_block;
	}

	/**
	 * Site-wide override ("fund-drive mode"): swap the copy of every prompt, and
	 * in button mode replace the CTA with the override button. Stored content is
	 * untouched — turning the override off restores each story's own prompt.
	 *
	 * @param array $parsed_block Parsed prompt card, already normalized.
	 * @return array
	 */
	public static function apply_override( $parsed_block ) {
		$body = trim( (string) get_option( 'newspack_contextual_prompts_override_body', '' ) );
		if ( '' !== $body ) {
			$copy_index = self::find_copy( $parsed_block );
			if ( null !== $copy_index ) {
				// An instance's own copy is a pattern override, resolved after this
				// filter: left bound, it would be written back over the site-wide copy.
				unset( $parsed_block['innerBlocks'][ $copy_index ]['attrs']['metadata']['bindings'] );
			}
			$parsed_block = self::replace_copy( $parsed_block, $body );
		}

		if ( 'button' === Newspack_Popups_Settings::get_override_cta() ) {
			$label = trim( (string) get_option( 'newspack_contextual_prompts_override_label', '' ) );
			$child = Newspack_Popups_Contextual_Prompt_Pattern::build_buttons_child(
				(string) get_option( 'newspack_contextual_prompts_override_url', '' ),
				'' !== $label ? $label : null
			);

			$cta = self::find_cta( $parsed_block );
			if ( null !== $cta ) {
				$parsed_block['innerBlocks'][ $cta['index'] ] = $child;
			} else {
				$parsed_block = self::append_child( $parsed_block, $child );
			}
		}

		return $parsed_block;
	}

	/**
	 * Replace the text of the copy paragraph.
	 *
	 * Uses preg_replace_callback, not preg_replace, so a literal $1 / ${1} / \1
	 * in the override copy — "Give $5 today" — is never expanded as a
	 * backreference.
	 *
	 * @param array  $parsed_block Parsed prompt card.
	 * @param string $body         Replacement copy, unescaped.
	 * @return array
	 */
	private static function replace_copy( $parsed_block, $body ) {
		$index = self::find_copy( $parsed_block );
		if ( null === $index ) {
			return $parsed_block;
		}

		$child = $parsed_block['innerBlocks'][ $index ];
		$swap  = function ( $html ) use ( $body ) {
			return preg_replace_callback(
				'#(<p\b[^>]*>).*?(</p>)#s',
				function ( $matches ) use ( $body ) {
					return $matches[1] . esc_html( $body ) . $matches[2];
				},
				(string) $html,
				1
			);
		};

		$child['innerHTML'] = $swap( $child['innerHTML'] );
		foreach ( $child['innerContent'] as $chunk_index => $chunk ) {
			if ( is_string( $chunk ) && '' !== trim( $chunk ) ) {
				$child['innerContent'][ $chunk_index ] = $swap( $chunk );
			}
		}
		$parsed_block['innerBlocks'][ $index ] = $child;

		return $parsed_block;
	}

	/**
	 * Locate the copy child: the first paragraph.
	 *
	 * @param array $parsed_block Parsed prompt card.
	 * @return int|null Child index, or null.
	 */
	private static function find_copy( $parsed_block ) {
		foreach ( $parsed_block['innerBlocks'] ?? [] as $index => $child ) {
			if ( 'core/paragraph' === ( $child['blockName'] ?? '' ) ) {
				return $index;
			}
		}
		return null;
	}

	/**
	 * Locate the CTA child: the donate block or the buttons wrapper.
	 *
	 * @param array $parsed_block Parsed prompt card.
	 * @return array|null [ 'index' => int, 'name' => string ], or null.
	 */
	public static function find_cta( $parsed_block ) {
		foreach ( $parsed_block['innerBlocks'] ?? [] as $index => $child ) {
			if ( in_array( $child['blockName'] ?? '', [ 'newspack-blocks/donate', 'core/buttons' ], true ) ) {
				return [
					'index' => $index,
					'name'  => $child['blockName'],
				];
			}
		}
		return null;
	}

	/**
	 * Append a child, inserting its innerContent placeholder before the block's
	 * closing markup chunk.
	 *
	 * @param array $parsed_block Parsed block.
	 * @param array $child        Parsed child block.
	 * @return array
	 */
	public static function append_child( $parsed_block, $child ) {
		$parsed_block['innerBlocks'][] = $child;

		$last_chunk = count( $parsed_block['innerContent'] ) - 1;
		while ( $last_chunk >= 0 && null === $parsed_block['innerContent'][ $last_chunk ] ) {
			--$last_chunk;
		}
		array_splice( $parsed_block['innerContent'], max( 0, $last_chunk ), 0, [ null ] );

		return $parsed_block;
	}

	/**
	 * Whether a buttons wrapper contains at least one button with a destination,
	 * as a URL attribute or an href in its markup. The editor drops the attribute
	 * from a saved button, leaving the markup as the only record.
	 *
	 * @param array $buttons Parsed core/buttons child.
	 * @return bool
	 */
	private static function buttons_have_destination( $buttons ) {
		foreach ( $buttons['innerBlocks'] ?? [] as $child ) {
			if ( '' !== trim( (string) ( $child['attrs']['url'] ?? '' ) ) ) {
				return true;
			}
			if ( false !== strpos( (string) ( $child['innerHTML'] ?? '' ), 'href=' ) ) {
				return true;
			}
			if ( ! empty( $child['innerBlocks'] ) && self::buttons_have_destination( $child ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Remove the Nth child and its innerContent placeholder.
	 *
	 * The innerContent array interleaves HTML chunks with one null placeholder
	 * per child, in order — the placeholder count must track the child count or
	 * the block renders misaligned.
	 *
	 * @param array $parsed_block Parsed block.
	 * @param int   $index        Child index to remove.
	 * @return array
	 */
	private static function remove_child( $parsed_block, $index ) {
		array_splice( $parsed_block['innerBlocks'], $index, 1 );

		$seen = 0;
		foreach ( $parsed_block['innerContent'] as $chunk_index => $chunk ) {
			if ( null !== $chunk ) {
				continue;
			}
			if ( $seen === $index ) {
				array_splice( $parsed_block['innerContent'], $chunk_index, 1 );
				break;
			}
			++$seen;
		}

		return $parsed_block;
	}
}
