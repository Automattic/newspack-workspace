<?php
/**
 * Keep non-public block content out of auto-generated excerpts.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Content_Gate_Excerpt class.
 */
class Content_Gate_Excerpt {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Core registers wp_trim_excerpt() here at priority 10. Replace it with a
		// wrapper that hands core the same work over sanitized content, rather than
		// reimplementing the trimming: two copies of that logic already exist in this
		// monorepo and both have drifted from core.
		$removed = remove_filter( 'get_the_excerpt', 'wp_trim_excerpt', 10 );
		if ( ! $removed ) {
			// Not a gate bypass: core's callback would then run first at this same
			// priority, and the auto branch below ignores whatever $text it produced,
			// rebuilding from the sanitized clone either way. The cost is a duplicate
			// trim, so this is a performance signal rather than a correctness one.
			Logger::log( 'Could not remove core wp_trim_excerpt filter; excerpts are trimmed twice.', 'CONTENT-GATE-EXCERPT' );
		}
		add_filter( 'get_the_excerpt', [ __CLASS__, 'filter_get_the_excerpt' ], 10, 2 );
		add_filter( 'get_the_excerpt', [ __CLASS__, 'enforce_withheld_excerpt' ], PHP_INT_MAX, 2 );
	}

	/**
	 * Last word on a withheld post's excerpt.
	 *
	 * The filter above answers at priority 10, and anything above that can discard
	 * the answer and rebuild from `post_content` — which is how the paid body
	 * reached listing cards in the first place. Two plugins in this repository did
	 * exactly that and had to be taught the teaser one at a time; a third-party or
	 * custom-code filter cannot be taught at all.
	 *
	 * So the excerpt is checked rather than trusted. The teaser is the most a
	 * withheld post may show, so an excerpt no longer than the teaser cannot say
	 * more than the article page does — every rebuild starts at the body's opening,
	 * which is the teaser's opening. One that runs longer reached past the teaser
	 * and is replaced with the priority-10 answer.
	 *
	 * A consumer's own trimming therefore survives, whatever length and whatever
	 * "read more" string it appends; its access to the body does not. What this
	 * does not catch is an excerpt built from the middle of a body and kept under
	 * the teaser's length, which nothing in this repository does — the priority-10
	 * filter and the consumers themselves remain the mechanism, and this is the
	 * net beneath them.
	 *
	 * @param string   $text The excerpt the chain has produced.
	 * @param \WP_Post $post The post.
	 * @return string
	 */
	public static function enforce_withheld_excerpt( $text, $post = null ) {
		$resolved = $post instanceof \WP_Post ? $post : get_post( $post );
		if ( ! $resolved instanceof \WP_Post ) {
			return $text;
		}
		if ( ! Content_Gate::should_withhold_content( $resolved->ID ) ) {
			return $text;
		}

		// A hand-written excerpt is the author's own words, and the priority-10
		// filter passes it through. It is not drawn from the teaser, so it would
		// fail the containment test below.
		if ( '' !== trim( (string) $resolved->post_excerpt ) ) {
			return $text;
		}

		$produced = self::comparable_text( $text );
		if ( '' === $produced ) {
			return $text;
		}
		$teaser = self::comparable_text( Content_Gate::get_withheld_teaser( $resolved->ID ) );
		if ( false !== strpos( $teaser, $produced ) ) {
			return $text;
		}
		if ( self::word_count( $produced ) <= self::word_count( $teaser ) ) {
			return $text;
		}

		return self::filter_get_the_excerpt( '', $resolved );
	}

	/**
	 * Count the words in already-normalized text.
	 *
	 * Not str_word_count(), which is byte-oriented and undercounts every language
	 * this runs in other than English.
	 *
	 * @param string $text Normalized text.
	 * @return int
	 */
	private static function word_count( $text ) {
		return '' === $text ? 0 : count( preg_split( '/\s+/u', $text ) );
	}

	/**
	 * Reduce an excerpt to the form the length and containment tests compare.
	 *
	 * Markup, entities and whitespace all vary with how a consumer assembled the
	 * excerpt without telling us anything about where the words came from. The
	 * trailing "read more" string goes too: core appends it to a trimmed excerpt,
	 * and it is by definition not part of the teaser.
	 *
	 * @param string $text Excerpt text.
	 * @return string
	 */
	private static function comparable_text( $text ) {
		/** This filter is documented in wp-includes/formatting.php */
		$excerpt_more = apply_filters( 'excerpt_more', ' [&hellip;]' );
		$text         = str_replace( wp_strip_all_tags( $excerpt_more ), '', wp_strip_all_tags( (string) $text ) );
		$text         = html_entity_decode( $text, ENT_QUOTES, get_bloginfo( 'charset' ) );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Build the excerpt from content with non-public blocks removed.
	 *
	 * @param string   $text The post excerpt, empty when auto-generating.
	 * @param \WP_Post $post The post.
	 * @return string
	 */
	public static function filter_get_the_excerpt( $text, $post = null ) {
		$resolved = $post instanceof \WP_Post ? $post : get_post( $post );

		// Every branch below ends in wp_trim_excerpt(), which applies 'the_content' —
		// where the gate answers for whichever post the loop has set up, not for the
		// post this filter was asked about. On a gated article, an excerpt requested
		// for any other post would come back as the article's teaser.
		//
		// So point the loop at this post for the duration. Suspending the gate instead
		// would fix the same symptom by un-gating everything: a listing block inside
		// this body runs 'the_content' over its own posts, and those would come back
		// whole.
		$previous_post   = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = $resolved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		try {
			return self::build_excerpt( $text, $post, $resolved );
		} finally {
			if ( null === $previous_post ) {
				unset( $GLOBALS['post'] );
			} else {
				$GLOBALS['post'] = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}
		}
	}

	/**
	 * Build the excerpt, with the gate's content substitution already suspended.
	 *
	 * @param string            $text     The post excerpt, empty when auto-generating.
	 * @param \WP_Post|int|null $post     The value this filter was passed.
	 * @param \WP_Post|null     $resolved The post $post resolves to, or null.
	 * @return string
	 */
	private static function build_excerpt( $text, $post, $resolved ) {

		// Handing an unresolvable value to core would not fail -- wp_trim_excerpt()
		// calls get_the_content( '', false, null ), which falls back to $GLOBALS['post']
		// and builds an excerpt from whatever the loop has set up, unsanitized. A filter
		// whose job is withholding content must not answer for a post it was never
		// asked about, so return $text and let the caller have nothing.
		if ( ! $resolved instanceof \WP_Post ) {
			return $text;
		}

		// A post restricted wholesale by a gate rule carries no markup saying so, so
		// block-level visibility alone cannot see it and core rebuilds the excerpt
		// from the paid body. On an article with a short lede, the first 55 words
		// reach copy the reader has not paid for.
		$is_withheld = Content_Gate::should_withhold_content( $resolved->ID );

		// Core returns a non-empty $text untouched; the branches below deliberately
		// do not. Confine that difference to posts that actually use the gate, so on
		// every other post -- including every post on a site with gates switched off,
		// where this filter still replaces core's -- an excerpt supplied by a filter
		// below priority 10 survives exactly as it would without Newspack installed.
		if ( ! $is_withheld && ! Block_Visibility::has_access_control( (string) $resolved->post_content ) ) {
			return wp_trim_excerpt( $text, $post );
		}

		// Core is only ever handed the sanitized clone, in both branches below. Any
		// path that lets core rebuild from the post reaches post_content, so handing
		// it the real post anywhere would put gated blocks back in the excerpt.
		$sanitized               = clone $resolved;
		$sanitized->post_content = Block_Visibility::strip_blocks_hidden_from_public( $resolved->post_content );

		// wp_trim_excerpt() opens with get_post(), which ends in WP_Post::filter( 'raw' ).
		// That returns $this only when the property already reads 'raw'; on any other
		// value it re-reads the row by ID and the clone -- with its sanitized content --
		// is silently discarded. A display-form post reaching this filter is enough.
		$sanitized->filter = 'raw';

		// A manually written excerpt is the author's own words; core returns it
		// untouched and so do we. Pass the post's own excerpt rather than $text: by
		// the time this runs $text is whatever the filter chain holds, and core
		// returns a non-empty value verbatim, so an upstream filter deriving $text
		// from post_content would put gated blocks straight into the teaser. The auto
		// branch below refuses to trust $text for the same reason; this keeps the two
		// consistent. The cost is that on a gated post a filter's substitution loses
		// to the author's excerpt, which is the conservative way to lose.
		if ( '' !== trim( (string) $resolved->post_excerpt ) ) {
			return wp_trim_excerpt( $resolved->post_excerpt, $sanitized );
		}

		// A withheld post hands core the gate teaser instead of its body: the same
		// free opening the article page shows a non-member, so the excerpt can never
		// say more than the article does. Built below the manual-excerpt branch
		// because building it runs the whole body through the block pipeline, and an
		// author's own excerpt would discard the result.
		if ( $is_withheld ) {
			$sanitized->post_content = Content_Gate::get_withheld_teaser( $resolved->ID );
		}

		// Pass an empty $text so core rebuilds from the sanitized post.
		// wp_trim_excerpt() returns $text unchanged when it is non-empty, so
		// forwarding a value another filter already produced would skip the
		// sanitized content entirely.
		//
		// Gated blocks never contribute to a teaser. A post whose readable content is
		// entirely gated gets a blank excerpt, matching what its article page already
		// shows a non-member.
		return wp_trim_excerpt( '', $sanitized );
	}
}
Content_Gate_Excerpt::init();
