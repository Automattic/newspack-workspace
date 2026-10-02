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

		// Handing an unresolvable value to core would not fail -- wp_trim_excerpt()
		// calls get_the_content( '', false, null ), which falls back to $GLOBALS['post']
		// and builds an excerpt from whatever the loop has set up, unsanitized. A filter
		// whose job is withholding content must not answer for a post it was never
		// asked about, so return $text and let the caller have nothing.
		if ( ! $resolved instanceof \WP_Post ) {
			return $text;
		}

		// A post the gate withholds outside its own article page gets its excerpt
		// built from the teaser, not from the body. The staged substitution cannot
		// serve this on its own: it is written when `the_post` fires, and an
		// excerpt is not always built inside a loop — core's Latest Posts block
		// walks get_posts() results and asks for each excerpt by post object.
		// Two surfaces own their own restriction and must not be answered over.
		// REST is Content_Gate::filter_rest_response()'s, which evaluates
		// entitlement per requester and leaves an editor's context=edit payload
		// whole. Feeds are Content_Gate_Advanced_Settings', which honours a
		// publisher setting that includes leaving items unrestricted — withholding
		// here would override that choice with no way to switch it off, and in an
		// excerpt feed it is this filter, not the feed layer, that produces the
		// item. Content_Gate::restrict_post() stands down for both for the same
		// reasons.
		//
		// The REST stand-down covers a read, not a render. The posts controller's
		// shape is a read: it sets each item up with setup_postdata() and serves
		// it, staging nothing. A route running a real loop — newspack-blocks'
		// load-more endpoint is one — renders like a page, and
		// Content_Gate::withhold_post_in_loop(), which restrict_post() hands the
		// loop's posts to, has already staged the teaser. Answer for that post
		// rather than leaving its excerpt to the substitution filter further down
		// the content chain, which a plugin can remove.
		$rest_read = Content_Gate::is_dispatching_rest() && ! Content_Gate::has_staged_restriction( $resolved->ID );
		$teaser    = ( is_feed() || $rest_read )
			? null
			: Content_Gate::get_teaser_outside_article( $resolved );
		if ( null !== $teaser ) {
			// A hand-written excerpt is the author's own words about a post they
			// chose to gate, and stands, exactly as it does below.
			if ( '' !== trim( (string) $resolved->post_excerpt ) ) {
				return wp_trim_excerpt( $resolved->post_excerpt, $resolved );
			}
			$text = self::get_free_excerpt_text( $resolved, $teaser );

			/** This filter is documented in wp-includes/formatting.php */
			$excerpt_length = (int) apply_filters( 'excerpt_length', (int) _x( '55', 'excerpt_length' ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
			/** This filter is documented in wp-includes/formatting.php */
			$excerpt_more = apply_filters( 'excerpt_more', ' [&hellip;]' );
			$excerpt      = wp_trim_words( $text, $excerpt_length, $excerpt_more );

			// The overlay layout ends its teaser with an ellipsis; the excerpt ends
			// with the site's own excerpt_more in its place.
			if ( self::has_overlay_ellipsis( $teaser ) && ! str_ends_with( $excerpt, $excerpt_more ) ) {
				$excerpt .= $excerpt_more;
			}

			/** This filter is documented in wp-includes/formatting.php */
			return apply_filters( 'wp_trim_excerpt', $excerpt, '' );
		}

		// Core returns a non-empty $text untouched; the branches below deliberately
		// do not. Confine that difference to posts that actually use the gate, so on
		// every other post -- including every post on a site with gates switched off,
		// where this filter still replaces core's -- an excerpt supplied by a filter
		// below priority 10 survives exactly as it would without Newspack installed.
		if ( ! Block_Visibility::has_access_control( (string) $resolved->post_content ) ) {
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

	/**
	 * The excerpt text of a withheld post's free part.
	 *
	 * The teaser is rendered HTML, which excerpt_remove_blocks() cannot sort: it
	 * needs block delimiters to drop captions and the like. So core builds the
	 * excerpt from the post itself, and it is cut where the teaser ends. Words are
	 * matched in order against the teaser's; text only the teaser has, a caption,
	 * is passed over, and the first word the teaser lacks is the gated body. Every
	 * word returned is the teaser's own, so a mismatch shortens the excerpt and
	 * never reaches past the free part.
	 *
	 * Runs core's steps rather than wp_trim_excerpt(), whose 'the_content' pass
	 * would let the restriction substitution hand back the staged teaser.
	 *
	 * @param \WP_Post $post   The withheld post.
	 * @param string   $teaser Its teaser, from Content_Gate::get_teaser_outside_article().
	 * @return string Plain text, untrimmed.
	 */
	public static function get_free_excerpt_text( \WP_Post $post, string $teaser ): string {
		if ( '' === trim( $teaser ) ) {
			return '';
		}

		// Cached beside the teaser and for the same reason: this renders the
		// whole body, and a listing pays it once per card. The teaser's hash
		// stands in for the gate layout and settings it was sliced by.
		$cache_key = md5( wp_json_encode( [ 'excerpt', $post->ID, $post->post_modified_gmt, md5( $teaser ) ] ) );
		$cached    = wp_cache_get( $cache_key, Content_Gate::WITHHELD_TEASER_CACHE_GROUP );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		// From the row: in a loop, Content_Gate::withhold_post_in_loop() has already
		// replaced this instance's post_content with the excerpt text.
		$content = Block_Visibility::strip_blocks_hidden_from_public( (string) get_post_field( 'post_content', $post->ID, 'raw' ) );
		$content = strip_shortcodes( $content );
		// Can run inside `the_post` for a withheld post, so a block added to
		// `excerpt_allowed_blocks` must not build an excerpt itself; core's own
		// docs for that filter set the same rule, for the same infinite loop.
		$content = excerpt_remove_blocks( $content );
		$content = excerpt_remove_footnotes( $content );
		// The text filters of the teaser's `newspack_gate_content` pass, so both
		// sides read alike.
		$content = convert_smilies( capital_P_dangit( wptexturize( $content ) ) );

		$free  = self::split_words( excerpt_remove_footnotes( $teaser ) );
		$index = 0;
		$count = count( $free );
		$words = [];
		foreach ( self::split_words( $content ) as $word ) {
			$normalized = self::normalize_word( $word );
			// Punctuation a stripped shortcode leaves behind ("in [year]." becomes
			// "in .") is the post's alone; any other word the teaser lacks is the
			// gated body.
			if ( ! preg_match( '/[\p{L}\p{N}]/u', $normalized ) ) {
				continue;
			}
			while ( $index < $count && self::normalize_word( $free[ $index ] ) !== $normalized ) {
				++$index;
			}
			if ( $index >= $count ) {
				break;
			}
			$words[] = $free[ $index++ ];
		}

		$text = implode( ' ', $words );
		wp_cache_set( $cache_key, $text, Content_Gate::WITHHELD_TEASER_CACHE_GROUP, HOUR_IN_SECONDS );
		return $text;
	}

	/**
	 * Whether a teaser ends with the ellipsis the overlay layout appends.
	 *
	 * @param string $teaser Teaser HTML.
	 * @return bool
	 */
	public static function has_overlay_ellipsis( string $teaser ): bool {
		return str_ends_with( rtrim( wp_strip_all_tags( $teaser ) ), '[&hellip;]' );
	}

	/**
	 * Split rendered HTML into words, as wp_trim_words() does. Block-level closing
	 * tags count as breaks, so a caption is not glued to the paragraph after it.
	 *
	 * @param string $html Rendered HTML.
	 * @return string[]
	 */
	private static function split_words( string $html ): array {
		$html = preg_replace( '#(</(?:p|div|figure|figcaption|li|h[1-6]|blockquote|pre|td|th|summary)>)#i', '$1 ', $html );
		return preg_split( '/[\n\r\t ]+/', wp_strip_all_tags( $html ), -1, PREG_SPLIT_NO_EMPTY );
	}

	/**
	 * A word's comparable form: entities decoded, so `&#8217;` and `’` agree.
	 *
	 * @param string $word Word.
	 * @return string
	 */
	private static function normalize_word( string $word ): string {
		return html_entity_decode( $word, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
Content_Gate_Excerpt::init();
