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
 * A Group a publisher detached from the pattern is still a prompt: the fund
 * drive and the control test swap its copy like any other card, so what the
 * settings preview promises is what renders. A fund drive in button mode
 * replaces its CTA too — during a drive every card carries the drive's ask and
 * the drive's button. What stays scoped to the pattern is the CTA rebuild that
 * reconciles a stored CTA with the site's donation platform, and the strip that
 * hides instances while the feature is off.
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
	 * Condition a card rendered under, reported on every interaction so the
	 * story-aware vs. generic comparison can be read. Absent while the control
	 * override is off.
	 */
	const CONDITION_STORY_AWARE     = 'story_aware';
	const CONDITION_GENERIC_CONTROL = 'generic_control';
	const CONDITION_OVERRIDE        = 'override';
	const CONDITIONS                = [ self::CONDITION_STORY_AWARE, self::CONDITION_GENERIC_CONTROL, self::CONDITION_OVERRIDE ];

	/**
	 * The source triple a donation carries back to the prompt that drove it.
	 * Same names as form fields, cart item keys, checkout payload keys and GA4
	 * params; order meta prefixes them with `_newspack_`.
	 */
	const SOURCE_KEYS = [ 'contextual_prompt_post_id', 'contextual_prompt_placement', 'contextual_prompt_condition' ];

	/**
	 * Placement values get_placement() can return.
	 */
	const PLACEMENTS = [ 'top', 'mid', 'end', 'unknown' ];

	/**
	 * How long the candidate id list is cached for, per pattern id.
	 */
	const CANDIDATES_CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * How many of the newest candidate stories the preview scan reads. A ceiling
	 * on a double-wildcard LIKE over post_content, at the cost of a count that
	 * stops short on a large archive — which the preview reports rather than hides.
	 */
	const CANDIDATES_SCAN_LIMIT = 500;

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
	 * Whether a prompt card is mid-render: set when its Group is normalized and
	 * cleared once the Group's rendered markup comes back, so everything the card
	 * renders in between — the donate form among it — knows it is inside a card.
	 *
	 * @var bool
	 */
	private static $card_open = false;

	/**
	 * The condition the card mid-render actually rendered under, recorded where
	 * the swap happens rather than recomputed afterwards. Null outside a card.
	 *
	 * @var string|null
	 */
	private static $rendered_condition = null;

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
		add_action( 'newspack_blocks_donate_before_form_fields', [ __CLASS__, 'print_form_hidden_fields' ] );
		add_action( 'save_post', [ __CLASS__, 'invalidate_control_candidates_cache' ], 10, 2 );
		add_action( 'deleted_post', [ __CLASS__, 'invalidate_control_candidates_cache' ], 10, 2 );
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
		// Close the attribution window normalize_group() opened, keyed on the parsed
		// block — its className, the same signal the window opened on — not on the
		// markup. The content gate's block-visibility filter blanks a hidden block to
		// '' on `render_block`, which runs before this `render_block_core/group`
		// filter, so a card hidden by a reader-visibility rule arrives here with no
		// marker in the string. Closing on the markup instead would leave the window
		// open, and the next donate form or same-site button on the page would
		// attribute itself to the hidden story. Matches close_instance_window(),
		// which also closes on the parsed block. A plain wrapper Group carries the
		// marker in its content but not its own className, so is_prompt_card() is
		// false for it and its inner card's own Group still closes the window.
		$condition = null;
		if ( self::is_prompt_card( is_array( $block ) ? $block : [] ) ) {
			$condition                = self::$rendered_condition;
			self::$rendered_condition = null;
			self::$card_open          = false;
		}

		// The opt-in is the feature: with it withdrawn a detached card is still the
		// publisher's own content and renders, but the feature reports nothing — so
		// it is not stamped and the view script emits no interaction for it. The
		// window above is still closed either way, so a hidden card can't leak.
		if ( ! self::is_feature_on() ) {
			return $block_content;
		}

		// This filter runs for every Group on the page, so the marker is looked for
		// in the string before anything is parsed. The tag-processor checks below
		// gate *stamping* only: a blanked or marker-less render is not stamped, but
		// its window has already been closed above.
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

		// Reported from what normalize_group() recorded, not recomputed: the label
		// has to name the copy the reader was actually shown.
		if ( is_string( $condition ) && '' !== $condition ) {
			$processor->set_attribute( 'data-newspack-cp-condition', $condition );
		}

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

		if ( 'core/group' !== $name ) {
			return false;
		}

		// A whole class, not a prefix: `newspack-contextual-prompt-custom` is the
		// publisher's own class, and the stamp downstream matches the marker the
		// same way (WP_HTML_Tag_Processor::has_class()), so a looser test here
		// would open a card nothing ever closes.
		$classes = preg_split( '#\s+#', trim( (string) ( $block['attrs']['className'] ?? '' ) ) );

		return in_array( Newspack_Popups_Contextual_Prompt_Pattern::MARKER_CLASS, (array) $classes, true );
	}

	/**
	 * Normalize the prompt card's CTA and apply whichever copy override is in
	 * force. Acts on any prompt card: one arriving from the pattern (the instance
	 * window is open) and one a publisher detached from it alike. A detached card
	 * is still a prompt, so the fund drive and the control test treat it as one —
	 * otherwise the settings preview would list a story whose card never swapped.
	 * What stays scoped to instances is the CTA rebuild below, which would
	 * otherwise throw away the label and destination a publisher chose for their
	 * own card. A fund drive in button mode still replaces that CTA: the drive is
	 * a deliberate, temporary, site-wide swap, and it ends when the drive does.
	 *
	 * The marker class is the whole test: an instance's own Group carries it too,
	 * so the window need not be consulted here.
	 *
	 * Records what actually happened — the condition rendered and that a card is
	 * open — for the two hooks that run before this card's markup comes back:
	 * add_analytics_attributes() stamps the condition, print_form_hidden_fields()
	 * reads the window.
	 *
	 * @param array $parsed_block The block being rendered.
	 * @return array
	 */
	public static function normalize_group( $parsed_block ) {
		if ( 'core/group' !== ( $parsed_block['blockName'] ?? '' ) || ! self::is_prompt_card( $parsed_block ) ) {
			return $parsed_block;
		}

		// The feature switch reaches every card. A detached card survives the strip
		// (its copy is the publisher's), but the fund drive and the control test
		// are the feature: with the opt-in withdrawn they stop swapping copy and
		// stop reporting, so no card is left carrying a live override an admin has
		// no UI to turn off.
		if ( ! self::is_feature_on() ) {
			return $parsed_block;
		}

		if ( self::$in_instance ) {
			// The CTA is normalized for cards the pattern supplied only: a detached
			// card's CTA is content the publisher owns, and rebuilding it would
			// throw away the label and destination they chose.
			$parsed_block = self::normalize_cta( $parsed_block );
		}

		// Seed the condition from the story's assignment, the one function the front
		// end, the editor notice and the settings preview all read, so the swap below
		// and the stamp can't disagree. get_condition() already resolves the
		// override-vs-control-vs-post-id precedence and returns '' when the control is
		// off, when a fund drive is on but the control isn't, or when there is no story
		// to assign (get_the_ID() === 0 off the loop) — so an off-loop render is
		// stamped nothing rather than a bare story_aware next to post-id="0".
		$post_id   = (int) get_the_ID();
		$condition = self::get_condition( $post_id );

		$settings_ready = class_exists( 'Newspack_Popups_Settings' );
		if ( $settings_ready && Newspack_Popups_Settings::is_override_active() ) {
			// A fund drive replaces every card, and the control test is paused for its
			// duration. `override` marks that paused test — get_condition() reports it
			// whenever the control test and the fund drive are both on, whether or not
			// this card actually swapped (a form-mode card with a deleted paragraph
			// swaps nothing, but the drive is still on). The override runs regardless
			// of the control test; the condition it reports does not.
			$parsed_block = self::apply_override( $parsed_block );
		} elseif ( self::CONDITION_GENERIC_CONTROL === $condition ) {
			// Selected for the control copy: swap it in, but report story_aware if the
			// card had nothing to replace (a detached card whose copy paragraph was
			// deleted), so the stamp names the copy the reader was actually shown. For
			// a card nested in another block the swap reaches the render only because
			// WP_Block::render() calls refresh_parsed_block_dependents() when
			// render_block_data changed the parsed block — current-core behavior.
			$before       = self::get_copy_html( $parsed_block );
			$parsed_block = self::apply_control( $parsed_block );
			if ( self::get_copy_html( $parsed_block ) === $before ) {
				$condition = self::CONDITION_STORY_AWARE;
			}
		}

		self::$rendered_condition = $condition;
		self::$card_open          = true;

		return self::tag_button_destination( $parsed_block );
	}

	/**
	 * The markup of the card's copy child, for telling a swap that landed from
	 * one that found nothing to replace.
	 *
	 * @param array $parsed_block Parsed prompt card.
	 * @return string|null The copy child's innerHTML, or null when it has none.
	 */
	private static function get_copy_html( $parsed_block ) {
		$index = self::find_copy( $parsed_block );

		return null === $index ? null : (string) ( $parsed_block['innerBlocks'][ $index ]['innerHTML'] ?? '' );
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
	 * Which condition a story renders under. Derived from the post id alone: a
	 * counter would hand different readers different conditions for the same
	 * story behind a full-page cache, and a story that flips condition mid-test
	 * lands its events on both sides of the comparison.
	 *
	 * @param int $post_id The story.
	 * @return string One of CONDITIONS, or '' while the control override is off.
	 */
	public static function get_condition( $post_id ) {
		if ( ! class_exists( 'Newspack_Popups_Settings' ) || ! Newspack_Popups_Settings::is_control_active() ) {
			return '';
		}
		if ( Newspack_Popups_Settings::is_override_active() ) {
			return self::CONDITION_OVERRIDE;
		}
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			// No story to assign, so nothing to report — the same silence the
			// feature-off case returns.
			return '';
		}
		return self::is_control_story( $post_id, Newspack_Popups_Settings::get_control_interval() )
			? self::CONDITION_GENERIC_CONTROL
			: self::CONDITION_STORY_AWARE;
	}

	/**
	 * Whether a story is selected for the control copy at a given interval.
	 * The one selection rule, shared by the front-end render and the settings
	 * preview so what the preview lists is what readers get.
	 *
	 * @param int $post_id  The story.
	 * @param int $interval Every Nth story.
	 * @return bool
	 */
	public static function is_control_story( $post_id, $interval ) {
		$post_id  = (int) $post_id;
		$interval = max( 1, (int) $interval );
		return $post_id > 0 && 0 === $post_id % $interval;
	}

	/**
	 * Published stories that carry a Contextual Prompt and will show the control
	 * copy at the interval, newest first. The instance markup is a `core/block`
	 * ref to the pattern; a detached card carries the marker class instead, so
	 * both needles are searched.
	 *
	 * @param int $interval Every Nth story.
	 * @param int $limit    Maximum rows to return, starting at $offset.
	 * @param int $offset   How many selected candidates to skip, for "Load more" paging.
	 * @return array{total: int, capped: bool, posts: array[]} `total` counts the selected
	 *                                            stories among the newest
	 *                                            CANDIDATES_SCAN_LIMIT candidates, before
	 *                                            $offset/$limit are applied; `capped` says
	 *                                            the scan hit that limit, so the count and
	 *                                            the list both stop short of the archive;
	 *                                            `posts` is the requested page, each: id,
	 *                                            title, edit_link, permalink.
	 */
	public static function get_control_preview( $interval, $limit = 10, $offset = 0 ) {
		$pattern_id = (int) get_option( Newspack_Popups_Contextual_Prompt_Pattern::OPTION_PATTERN_ID, 0 );
		if ( ! $pattern_id ) {
			return [
				'total'  => 0,
				'capped' => false,
				'posts'  => [],
			];
		}
		$candidates = self::get_control_candidate_ids( $pattern_id );
		$selected   = [];
		foreach ( $candidates['ids'] as $id ) {
			$id = (int) $id;
			if ( self::is_control_story( $id, $interval ) ) {
				$selected[] = $id;
			}
		}
		$page = array_slice( $selected, $offset, $limit );
		$rows = array_map(
			function ( $id ) {
				return [
					'id'        => $id,
					'title'     => get_the_title( $id ),
					'edit_link' => (string) get_edit_post_link( $id, 'raw' ),
					'permalink' => (string) get_permalink( $id ),
				];
			},
			$page
		);
		return [
			'total'  => count( $selected ),
			'capped' => $candidates['capped'],
			'posts'  => $rows,
		];
	}

	/**
	 * The raw candidate id list `get_control_preview()` filters by interval,
	 * cached in a transient keyed on the pattern id: the underlying query is a
	 * double-wildcard `LIKE` scan of `wp_posts.post_content`, and re-running it
	 * on every settings-form keystroke would put a full table scan on the
	 * debounce timer. `save_post`/`deleted_post` clear the transient for
	 * supported post types, so a newly published or removed story shows up
	 * without waiting for the TTL.
	 *
	 * The needle for the `core/block` ref is anchored on both sides of the id
	 * (`"ref":<id>,` or `"ref":<id>}`) so a pattern id of 12 does not
	 * prefix-match an unrelated ref of 123 or 125 serialized elsewhere in the
	 * same post.
	 *
	 * @param int $pattern_id The pattern id.
	 * @return array{ids: int[], capped: bool} The candidate ids (newest first,
	 *                                          narrowed to real prompt cards) and
	 *                                          whether the raw scan hit its ceiling.
	 */
	private static function get_control_candidate_ids( $pattern_id ) {
		$transient_key = self::candidates_transient_key( $pattern_id );
		$cached        = get_transient( $transient_key );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$types = Newspack_Popups_Model::get_default_popup_post_types();
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// The placeholder count sniff miscounts the dynamically-built $in list,
		// and the result is cached in a transient above rather than scoped by
		// interval/limit, which is what the caching sniff cannot see through.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in) AND ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s ) ORDER BY post_date DESC LIMIT %d",
				array_merge(
					$types,
					[
						'%' . $wpdb->esc_like( '"ref":' . $pattern_id . ',' ) . '%',
						'%' . $wpdb->esc_like( '"ref":' . $pattern_id . '}' ) . '%',
						'%' . $wpdb->esc_like( Newspack_Popups_Contextual_Prompt_Pattern::MARKER_CLASS ) . '%',
						self::CANDIDATES_SCAN_LIMIT,
					]
				)
			)
		);
		// phpcs:enable

		// Whether the scan hit its ceiling is a property of the raw result, read
		// before the parse filter narrows it: that is what the preview reports as
		// `capped`, and raising the constant must not strand a filtered count there.
		$capped = count( $rows ) >= self::CANDIDATES_SCAN_LIMIT;

		// The LIKE marker needle is a coarse net: it matches the class as a
		// substring, so a Group whose class merely starts with it
		// (`newspack-contextual-prompt-custom`), or a post that names the class in a
		// code block, is caught too. is_prompt_card() on the parsed content is the
		// predicate the render actually swaps on — and restore_marker_class() can
		// append the marker anywhere in the class list, so a whole-token SQL needle
		// would miss real cards — so the list is narrowed by it here, once, and the
		// cache holds the narrowed result rather than re-parsing on every keystroke.
		$ids = [];
		foreach ( $rows as $row ) {
			if ( self::blocks_have_prompt_card( parse_blocks( (string) $row->post_content ) ) ) {
				$ids[] = (int) $row->ID;
			}
		}

		$candidates = [
			'ids'    => $ids,
			'capped' => $capped,
		];
		set_transient( $transient_key, $candidates, self::CANDIDATES_CACHE_TTL );
		return $candidates;
	}

	/**
	 * Whether any block in a parsed tree is a prompt card — an instance ref or a
	 * marker Group — at any depth. The predicate the render swaps and stamps on,
	 * so the settings preview lists exactly the stories a control will reach.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return bool
	 */
	private static function blocks_have_prompt_card( $blocks ) {
		foreach ( $blocks as $block ) {
			if ( self::is_prompt_card( $block ) ) {
				return true;
			}
			if ( ! empty( $block['innerBlocks'] ) && self::blocks_have_prompt_card( $block['innerBlocks'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Clear the cached control-preview candidate list when a supported post
	 * type is saved or deleted, so a story that just gained or lost its
	 * Contextual Prompt is reflected in the settings preview immediately
	 * instead of waiting for the cache to expire.
	 *
	 * @param int          $post_id The post being saved or deleted.
	 * @param WP_Post|null $post    The post object, when the hook provides one.
	 */
	public static function invalidate_control_candidates_cache( $post_id, $post = null ) {
		$post_type = $post instanceof WP_Post ? $post->post_type : get_post_type( $post_id );
		if ( ! $post_type || ! in_array( $post_type, Newspack_Popups_Model::get_default_popup_post_types(), true ) ) {
			return;
		}
		$pattern_id = (int) get_option( Newspack_Popups_Contextual_Prompt_Pattern::OPTION_PATTERN_ID, 0 );
		if ( $pattern_id ) {
			delete_transient( self::candidates_transient_key( $pattern_id ) );
		}
	}

	/**
	 * Where the candidate id list for a pattern is cached. One spelling, so the
	 * reader and the invalidator cannot drift apart.
	 *
	 * @param int $pattern_id The pattern id.
	 * @return string
	 */
	private static function candidates_transient_key( $pattern_id ) {
		return 'newspack_cp_control_candidates_' . (int) $pattern_id;
	}

	/**
	 * Swap the story's copy for the control copy. Copy only: the CTA must be
	 * identical in both conditions or the test measures two things at once.
	 *
	 * @param array $parsed_block Parsed prompt card, already normalized.
	 * @return array
	 */
	public static function apply_control( $parsed_block ) {
		$body = trim( (string) get_option( Newspack_Popups_Settings::CONTROL_BODY_OPTION, '' ) );
		if ( '' === $body ) {
			return $parsed_block;
		}
		$copy_index = self::find_copy( $parsed_block );
		if ( null !== $copy_index ) {
			// Same reason as apply_override(): the instance's own copy is a pattern
			// override resolved after this filter and would overwrite the swap.
			unset( $parsed_block['innerBlocks'][ $copy_index ]['attrs']['metadata']['bindings'] );
		}
		return self::replace_copy( $parsed_block, $body );
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

	/**
	 * The source triple for a story.
	 *
	 * @param int $post_id The story.
	 * @return array Keyed by SOURCE_KEYS.
	 */
	public static function get_source( $post_id ) {
		$post_id = (int) $post_id;
		return [
			'contextual_prompt_post_id'   => $post_id,
			'contextual_prompt_placement' => self::get_placement( $post_id ),
			'contextual_prompt_condition' => self::get_condition( $post_id ),
		];
	}

	/**
	 * The source triple for the card being rendered: this story's id and
	 * placement, and the condition normalize_group() recorded rather than the one
	 * get_condition() derives. The two disagree when the card had nothing to swap
	 * — a detached card with no copy paragraph on a selected story renders
	 * story-aware copy — and the impression, the form and the button destination
	 * all have to name the copy the reader actually saw.
	 *
	 * @param int $post_id The story.
	 * @return array Keyed by SOURCE_KEYS.
	 */
	private static function get_rendered_source( $post_id ) {
		$source = self::get_source( $post_id );

		$source['contextual_prompt_condition'] = is_string( self::$rendered_condition ) ? self::$rendered_condition : '';

		return $source;
	}

	/**
	 * Validate a source triple from an untrusted carrier (URL, form, cart).
	 * Insights groups by these values, so a junk id invents a story rather than
	 * failing to attribute (the same reason gate ids are validated, NPPD-1887).
	 *
	 * @param array $raw Candidate values keyed by SOURCE_KEYS.
	 * @return array Valid subset; empty unless the post id is a published post.
	 */
	public static function validate_source( $raw ) {
		$post_id = absint( $raw['contextual_prompt_post_id'] ?? 0 );
		if (
			! $post_id
			|| 'publish' !== get_post_status( $post_id )
			|| ! in_array( get_post_type( $post_id ), Newspack_Popups_Model::get_default_popup_post_types(), true )
		) {
			return [];
		}
		$source    = [ 'contextual_prompt_post_id' => $post_id ];
		$placement = (string) ( $raw['contextual_prompt_placement'] ?? '' );
		if ( in_array( $placement, self::PLACEMENTS, true ) ) {
			$source['contextual_prompt_placement'] = $placement;
		}
		$condition = (string) ( $raw['contextual_prompt_condition'] ?? '' );
		if ( in_array( $condition, self::CONDITIONS, true ) ) {
			$source['contextual_prompt_condition'] = $condition;
		}
		return $source;
	}

	/**
	 * Print the source triple as hidden inputs in a donate form. Inside a card —
	 * an instance or a detached one — the values are this story's. Outside one, a
	 * request carrying the triple (a plain-button card sent the reader to the
	 * landing page) is forwarded so both CTA modes attribute the same way. Any
	 * other donate form gets nothing.
	 */
	public static function print_form_hidden_fields() {
		if ( self::$card_open ) {
			$source = self::get_rendered_source( (int) get_the_ID() );
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only attribution, validated below.
			$source = self::validate_source( array_map( 'sanitize_text_field', wp_unslash( array_intersect_key( $_GET, array_flip( self::SOURCE_KEYS ) ) ) ) );
		}
		if ( empty( $source['contextual_prompt_post_id'] ) ) {
			return;
		}
		foreach ( $source as $name => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $name ), esc_attr( $value ) );
		}
	}

	/**
	 * Append the source triple to the plain button's destination, at render.
	 * The stored pattern never carries it: the values are live.
	 *
	 * @param array $parsed_block Parsed prompt card.
	 * @return array
	 */
	private static function tag_button_destination( $parsed_block ) {
		$cta = self::find_cta( $parsed_block );
		if ( null === $cta || 'core/buttons' !== $cta['name'] ) {
			return $parsed_block;
		}
		$post_id = (int) get_the_ID();
		// The sole caller (normalize_group()) sets $card_open two lines before it
		// hands off here, so the window is always open at this point: the condition
		// the card actually rendered under is what the destination has to name.
		$source  = self::get_rendered_source( $post_id );
		$source  = array_filter( $source, fn( $v ) => '' !== (string) $v );
		if ( empty( $source['contextual_prompt_post_id'] ) ) {
			return $parsed_block;
		}
		$buttons = $parsed_block['innerBlocks'][ $cta['index'] ];
		foreach ( $buttons['innerBlocks'] as $i => $button ) {
			if ( 'core/button' !== ( $button['blockName'] ?? '' ) ) {
				continue;
			}
			$tag = function ( $html ) use ( $source ) {
				$processor = new WP_HTML_Tag_Processor( (string) $html );
				while ( $processor->next_tag( 'a' ) ) {
					$href = $processor->get_attribute( 'href' );
					if ( self::is_taggable_destination( $href ) ) {
						$processor->set_attribute( 'href', add_query_arg( $source, $href ) );
					}
				}
				return $processor->get_updated_html();
			};
			$button['innerHTML'] = $tag( $button['innerHTML'] );
			foreach ( $button['innerContent'] as $k => $chunk ) {
				if ( is_string( $chunk ) ) {
					$button['innerContent'][ $k ] = $tag( $chunk );
				}
			}
			if ( ! empty( $button['attrs']['url'] ) && self::is_taggable_destination( $button['attrs']['url'] ) ) {
				$button['attrs']['url'] = add_query_arg( $source, $button['attrs']['url'] );
			}
			$buttons['innerBlocks'][ $i ] = $button;
		}
		$parsed_block['innerBlocks'][ $cta['index'] ] = $buttons;
		return $parsed_block;
	}

	/**
	 * Whether a destination is one of ours to tag. The attribution args are read
	 * back on this site, so they are noise on another publisher's processor and
	 * damage to a `mailto:` or `tel:` address. A relative href is this site.
	 *
	 * @param string|null $href The destination.
	 * @return bool
	 */
	private static function is_taggable_destination( $href ) {
		$href = trim( (string) $href );
		if ( '' === $href ) {
			return false;
		}
		$scheme = wp_parse_url( $href, PHP_URL_SCHEME );
		if ( $scheme && ! in_array( strtolower( $scheme ), [ 'http', 'https' ], true ) ) {
			return false;
		}
		$host = wp_parse_url( $href, PHP_URL_HOST );
		if ( ! $host ) {
			return true;
		}
		// Compare with a leading `www.` stripped from both sides, and accept either
		// home_url() or site_url() — they differ on a WordPress-in-a-subdirectory
		// install. A button a publisher typed as www.example.org on a www-less home
		// URL is still this site, and its attribution args are read back here.
		$host = self::normalize_host( $host );
		$own  = array_filter(
			[
				self::normalize_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
				self::normalize_host( (string) wp_parse_url( site_url(), PHP_URL_HOST ) ),
			]
		);
		return in_array( $host, $own, true );
	}

	/**
	 * A host lowered and stripped of a leading `www.`, so the site's own address
	 * matches whether or not either side spells the `www.`.
	 *
	 * @param string $host The host.
	 * @return string
	 */
	private static function normalize_host( $host ) {
		return preg_replace( '/^www\./', '', strtolower( trim( (string) $host ) ) );
	}
}
