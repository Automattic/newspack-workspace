<?php
/**
 * Newspack CTA intent classifier.
 *
 * Classifies a call-to-action link target (an href) into a conversion intent —
 * donation, newsletter, subscription — or into a clearly non-conversion tell
 * (event, sponsor, editorial), or abstains.
 *
 * Originally written for NPPD-1837 as a display-only labeller for block-less
 * button prompts, living in newspack-popups. Moved here (NPPD-1887) because
 * content gates need the same classification and must not depend on Campaigns
 * being active. Once the prompts side ships (#571), `Newspack_Popups_Data_Api`
 * delegates to this class and degrades to "no inferred intent" when newspack-plugin
 * is absent — the same silent-degradation contract segmentation already uses for
 * `\Newspack\Reader_Data`. On its own (this branch) the class is self-contained and
 * used only by the content gate.
 *
 * Two distinct consumers, two very different risk profiles:
 *
 *   1. Prompts (NPPD-1837/1840) use the verdict as a DISPLAY LABEL only. It never
 *      touches a `prompt_has_*` flag, `action_type`, or a conversion denominator.
 *   2. Gates (NPPD-1887) use it to decide whether a CTA anchor gets stamped with
 *      `data-newspack-cta`, which DOES lead to attribution: clicking such an anchor
 *      persists the gate id, which is later written to `_gate_post_id` order meta.
 *
 * Because of (2), precision matters more than recall. A false "subscription"
 * verdict on an editorial link would credit a gate for a conversion it had
 * nothing to do with. Every ambiguous case abstains.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Classifies CTA link targets into conversion intents.
 */
final class CTA_Intent_Classifier {

	/**
	 * Intents that represent a reader converting.
	 *
	 * @var string[]
	 */
	const CONVERSION_INTENTS = [ 'donation', 'newsletter', 'subscription' ];

	/**
	 * Conversion intents that end in a WooCommerce order.
	 *
	 * These are the only intents whose CTA anchors are stamped for attribution
	 * (NPPD-1887 v1 scope). Registration and newsletter signups don't produce an
	 * order, and the regwall's Direct rate is already session-scoped and correct,
	 * so persisting attribution for them would add surface area without closing a
	 * measured gap.
	 *
	 * @var string[]
	 */
	const PAID_INTENTS = [ 'donation', 'subscription' ];

	/**
	 * Memoized output of get_site_conversion_urls().
	 *
	 * Per-request. A class property rather than a static local so tests can reset it
	 * (see reset_cache()) after mutating options or the permalink structure.
	 *
	 * @var array|null
	 */
	private static $conversion_urls_cache = null;

	/**
	 * Reset the memoized conversion-URL cache.
	 *
	 * Only useful in tests, and after changing options/permalinks mid-request.
	 *
	 * @return void
	 */
	public static function reset_cache() {
		self::$conversion_urls_cache = null;
	}

	/**
	 * Classify a chunk of block content by its button link target(s).
	 *
	 * Only conversion-legible or clearly non-conversion targets return a value;
	 * anything ambiguous returns null (precision over recall — a false conversion
	 * label is worse than an honest blank).
	 *
	 * @param string $content Block markup (e.g. a prompt's or gate's post_content).
	 * @return array|null ['intent' => string, 'source' => string] or null to abstain.
	 */
	public static function classify_content( $content ) {
		$hrefs = self::extract_button_hrefs( $content );
		if ( empty( $hrefs ) ) {
			return null;
		}

		$config = self::get_site_conversion_urls();

		// Classify every button; a prompt or gate can hold more than one.
		$hits = [];
		foreach ( $hrefs as $href ) {
			$hit = self::classify_href( $href, $config );
			if ( $hit ) {
				$hits[] = $hit;
			}
		}
		if ( empty( $hits ) ) {
			return null;
		}

		// Resolution when buttons disagree: a conversion intent wins over a
		// non-conversion one; if two DIFFERENT conversion intents appear, abstain
		// (don't guess). There is no precedence ORDER among the conversion intents —
		// they are only partitioned from the non-conversion ones here, and a tie between
		// two of them is unresolvable by definition. Ties within a SINGLE intent are
		// broken below, on source confidence.
		$conversion     = [];
		$non_conversion = [];
		foreach ( $hits as $hit ) {
			if ( in_array( $hit['intent'], self::CONVERSION_INTENTS, true ) ) {
				$conversion[] = $hit;
			} else {
				$non_conversion[] = $hit;
			}
		}

		if ( ! empty( $conversion ) ) {
			$distinct = array_unique( array_column( $conversion, 'intent' ) );
			if ( 1 < count( $distinct ) ) {
				return null; // Conflicting conversion signals -> abstain.
			}
			// All remaining hits share one intent, so break ties on source confidence
			// (site_config > processor > pattern) for a deterministic, best-available source.
			$source_rank = [
				'site_config' => 1,
				'processor'   => 2,
				'pattern'     => 3,
			];
			usort(
				$conversion,
				function ( $a, $b ) use ( $source_rank ) {
					return ( $source_rank[ $a['source'] ] ?? PHP_INT_MAX ) <=> ( $source_rank[ $b['source'] ] ?? PHP_INT_MAX );
				}
			);
			return $conversion[0];
		}

		// Only non-conversion signals: report the first (sponsor/editorial/event) so
		// the dashboard can show a non-conversion label instead of a bare "undefined".
		return $non_conversion[0];
	}

	/**
	 * Stamp `data-newspack-cta="<intent>"` on rendered button anchors whose href
	 * classifies into one of the allowed intents.
	 *
	 * Operates on RENDERED html (post-`do_blocks`), and scopes itself to anchors
	 * carrying the `wp-block-button__link` class — the rendered counterpart of the
	 * `core/button`-only scope that extract_button_hrefs() applies to block markup.
	 * Body-copy links are deliberately untouched: a reader clicking "read more" in a
	 * gate must never attribute a later subscription to that gate.
	 *
	 * Per-anchor, not per-container. classify_content() has to collapse a multi-button
	 * surface to a single verdict (and abstains when two conversion intents disagree);
	 * here each anchor carries its own, which is both more precise and lets a gate mix
	 * a "Subscribe" button with a "Read our latest" button without losing either.
	 *
	 * @param string   $html            Rendered HTML.
	 * @param string[] $allowed_intents Intents worth stamping. Defaults to PAID_INTENTS.
	 * @return string The HTML, with attributes added. Unchanged if nothing classified.
	 */
	public static function annotate_button_anchors( $html, $allowed_intents = null ) {
		if ( empty( $html ) || ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}
		$allowed_intents = null === $allowed_intents ? self::PAID_INTENTS : $allowed_intents;
		if ( empty( $allowed_intents ) ) {
			return $html;
		}

		$config    = self::get_site_conversion_urls();
		$processor = new \WP_HTML_Tag_Processor( $html );
		$touched   = false;

		while ( $processor->next_tag( [ 'tag_name' => 'A' ] ) ) {
			if ( ! $processor->has_class( 'wp-block-button__link' ) ) {
				continue;
			}
			$href = $processor->get_attribute( 'href' );
			if ( ! is_string( $href ) || '' === $href ) {
				continue;
			}
			$hit = self::classify_href( strtolower( $href ), $config );
			if ( ! $hit || ! in_array( $hit['intent'], $allowed_intents, true ) ) {
				continue;
			}
			$processor->set_attribute( 'data-newspack-cta', $hit['intent'] );
			$processor->set_attribute( 'data-newspack-cta-source', $hit['source'] );
			$touched = true;
		}

		return $touched ? $processor->get_updated_html() : $html;
	}

	/**
	 * Pull hrefs from core/button blocks in block markup.
	 *
	 * Scope is intentionally limited to button blocks (not every <a> in body copy)
	 * to keep precision high. Recurses so buttons nested inside core/buttons wrappers
	 * are covered.
	 *
	 * @param string $content Block markup.
	 * @return string[] Lowercased hrefs.
	 */
	public static function extract_button_hrefs( $content ) {
		$blocks  = \parse_blocks( $content );
		$buttons = self::find_blocks( 'core/button', $blocks );
		$hrefs   = [];
		foreach ( $buttons as $button ) {
			$url = $button['attrs']['url'] ?? '';
			if ( empty( $url ) && ! empty( $button['innerHTML'] ) ) {
				// Older button blocks store the href only in the saved markup.
				if ( preg_match( '/href="([^"]+)"/i', $button['innerHTML'], $matches ) ) {
					$url = $matches[1];
				}
			}
			if ( ! empty( $url ) ) {
				$hrefs[] = strtolower( $url );
			}
		}
		return $hrefs;
	}

	/**
	 * Recursively collect blocks of a given name.
	 *
	 * @param string $block_name Block name to find.
	 * @param array  $blocks     Parsed blocks.
	 * @return array
	 */
	private static function find_blocks( $block_name, $blocks ) {
		$found = [];
		foreach ( $blocks as $block ) {
			if ( $block_name === $block['blockName'] ) {
				$found[] = $block;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$found = array_merge( $found, self::find_blocks( $block_name, $block['innerBlocks'] ) );
			}
		}
		return $found;
	}

	/**
	 * Classify a single href. Precedence:
	 *   1) site-configured conversion URLs (highest confidence; catches the
	 *      unguessable cases like a bespoke on-site donation page),
	 *   2) known third-party processor domains,
	 *   3) path / substring patterns,
	 *   4) non-conversion tells (event, sponsor, editorial),
	 *   else null (abstain).
	 *
	 * @param string     $href   Lowercased href.
	 * @param array|null $config Output of get_site_conversion_urls(). Resolved if null.
	 * @return array|null ['intent' => string, 'source' => string] or null.
	 */
	public static function classify_href( $href, $config = null ) {
		$config = null === $config ? self::get_site_conversion_urls() : $config;

		// 1) Site config (substring match against this site's own configured URLs).
		foreach ( self::CONVERSION_INTENTS as $intent ) {
			foreach ( $config[ $intent ] as $configured ) {
				if ( $configured && str_contains( $href, $configured ) ) {
					return [
						'intent' => $intent,
						'source' => 'site_config',
					];
				}
			}
		}

		// 1.5) Strong editorial short-circuit (dkoo review, #575). A dated article path on
		// this site's own host — /YYYY/MM/ — is unambiguously editorial and can never be a
		// conversion page, yet its slug may contain a conversion token: e.g.
		// /2026/06/12/school-board-member-profile/ matches the donation pattern on "member"
		// (the hyphen before it is a valid boundary for the lookbehind). Left to fall through
		// it would be stamped as a paid CTA, and a click on it would credit a gate for a
		// conversion the reader never made. So we abstain to editorial BEFORE the conversion
		// patterns. Deliberately narrow: only the dated prefix, so real conversion pages like
		// /become-a-member/ (no date) still reach the donation pattern below. Non-dated
		// editorial slugs that contain a token (e.g. /digital-divide/) remain a known residual
		// — rarer, and bounded by the module's precision-first, under-attribution contract.
		if ( ! self::is_external_host( $href ) && preg_match( '#/[0-9]{4}/[0-9]{2}/#', $href ) ) {
			return [
				'intent' => 'editorial',
				'source' => 'pattern',
			];
		}

		// 2) Processor domains (empirically ranked in NPPD-1836; fundjournalism dominant).
		$donation_processors = [ 'fundjournalism', 'donorbox', 'actblue', 'fundraiseup', 'classy.org', 'givebutter' ];
		foreach ( $donation_processors as $needle ) {
			if ( str_contains( $href, $needle ) ) {
				return [
					'intent' => 'donation',
					'source' => 'processor',
				];
			}
		}
		// giving.<institution>.edu (e.g. giving.umich.edu) — institutional donation.
		if ( preg_match( '#://giving\.[^/]+\.edu#', $href ) ) {
			return [
				'intent' => 'donation',
				'source' => 'processor',
			];
		}

		// 3) Path / substring patterns. Substring (not slash-anchored) on purpose, so
		// membership fragments and newslettersignup slugs are caught (NPPD-1836).
		// Order matters: donation, then newsletter, then subscription.
		// (?<![a-z]) applies only to the keyword branch so mid-word matches (e.g. "member"
		// inside "remember") don't false-positive; the href is already lowercased. Digits,
		// "-", "/", "#" and start-of-string are all valid boundaries, so "/donate",
		// "-membership" and "#...-membership" still match. /give and /support stay
		// slash-anchored exactly as before.
		if ( preg_match( '/(?<![a-z])(?:donate|donation|contribute|donor|member|membership)|\/give\b|\/support\b/', $href ) ) {
			return [
				'intent' => 'donation',
				'source' => 'pattern',
			];
		}
		if ( str_contains( $href, 'newsletter' ) ) {
			return [
				'intent' => 'newsletter',
				'source' => 'pattern',
			];
		}
		if ( preg_match( '#://(account|app|subscribe|checkout|my-?account)\.#', $href )
			|| preg_match( '/subscribe|subscription|\/checkout|my-?account|\/offer|\/join\b|\/digital|\/plans|\/pricing/', $href ) ) {
			return [
				'intent' => 'subscription',
				'source' => 'pattern',
			];
		}

		// 4) Non-conversion tells.
		// Sponsor/ad: outbound link tagged utm_medium=referral.
		// TODO(confirm): compare utm_source to the site slug/home host rather than
		// matching the medium literally.
		if ( str_contains( $href, 'utm_medium=referral' ) && self::is_external_host( $href ) ) {
			return [
				'intent' => 'sponsor',
				'source' => 'pattern',
			];
		}
		// Event / ticketing.
		if ( preg_match( '#/events?(/|$)|eventbrite|tribfest|/fest\b#', $href ) ) {
			return [
				'intent' => 'event',
				'source' => 'pattern',
			];
		}
		// Editorial / navigation on this site's own domain (article slugs, author pages).
		if ( ! self::is_external_host( $href )
			&& preg_match( '#/[0-9]{4}/[0-9]{2}/|/author/|/[a-z0-9]+(?:-[a-z0-9]+){2,}(/[a-z]+)?/?($|\?)#', $href ) ) {
			return [
				'intent' => 'editorial',
				'source' => 'pattern',
			];
		}

		// Abstain: query-param forms (?form=), bare homepages, unrecognized externals.
		return null;
	}

	/**
	 * This site's configured conversion URLs, normalized to lowercase host+path
	 * fragments suitable for str_contains() matching. Memoized per request.
	 *
	 * Side-effect-free by design: the donation page URL is read from the
	 * newspack_donation_page_id option, NOT Donations::get_donation_page_info(),
	 * which creates the page via wp_insert_post when none exists — unsafe on this
	 * per-render path.
	 *
	 * Coverage ceiling: covers WooCommerce publishers whose button links to their own
	 * donation page, checkout, or my-account. External processor URLs (fundjournalism
	 * etc.) and bespoke campaign landing pages are not stored by Newspack — those are
	 * handled by the processor dictionary and patterns in classify_href(), or not at all.
	 *
	 * @return array{donation:string[],newsletter:string[],subscription:string[]}
	 */
	public static function get_site_conversion_urls() {
		if ( null !== self::$conversion_urls_cache ) {
			return self::$conversion_urls_cache;
		}

		$urls = [
			'donation'     => [],
			'newsletter'   => [],
			'subscription' => [],
		];

		// Donation: on-site donation page, read from the option (no side effects).
		if ( class_exists( '\Newspack\Donations' ) ) {
			$page_id = \get_option( Donations::DONATION_PAGE_ID_OPTION, 0 );
			if ( $page_id && 'page' === \get_post_type( $page_id ) ) {
				$urls['donation'][] = self::normalize_url( \get_permalink( $page_id ) );
			}
		}

		// Subscription / checkout.
		if ( function_exists( 'wc_get_checkout_url' ) ) {
			$urls['subscription'][] = self::normalize_url( wc_get_checkout_url() );
		}
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$urls['subscription'][] = self::normalize_url( wc_get_page_permalink( 'myaccount' ) );
		}

		// Newsletter: nothing to wire — Newspack has no configured newsletter page
		// (signup is the inline subscribe block). Newsletter recovery is pattern-only.

		// Drop empties and any URL that normalized to a bare host with no meaningful
		// path (e.g. a non-pretty permalink collapsing to "host/"), which would
		// otherwise substring-match every same-host link.
		foreach ( $urls as $key => $list ) {
			$urls[ $key ] = array_values(
				array_filter(
					$list,
					static function ( $normalized ) {
						return '' !== $normalized && 1 === preg_match( '#/[^/]#', $normalized );
					}
				)
			);
		}

		self::$conversion_urls_cache = $urls;
		return self::$conversion_urls_cache;
	}

	/**
	 * Normalize a full URL to a lowercased host+path fragment for matching.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function normalize_url( $url ) {
		if ( empty( $url ) ) {
			return '';
		}
		$parts = \wp_parse_url( strtolower( $url ) );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$host = preg_replace( '/^www\./', '', $parts['host'] );
		return $host . ( $parts['path'] ?? '' );
	}

	/**
	 * Is this href pointing off the current site's host?
	 *
	 * Single-host detection: compares against `home_url()`'s host only (minus `www.`).
	 * On multisite / multibrand installs served on more than one hostname, a genuine
	 * on-site link on a secondary brand host reads as external, so the editorial-abstain
	 * branch won't fire for it and the link abstains (returns null). The failure mode is
	 * *under*-attribution, which is safe under this module's precision-first contract.
	 * (dkoo review, #575.)
	 *
	 * @param string $href Lowercased href.
	 * @return bool
	 */
	private static function is_external_host( $href ) {
		$href_host = \wp_parse_url( $href, PHP_URL_HOST );
		$home_host = \wp_parse_url( strtolower( \home_url() ), PHP_URL_HOST );
		if ( empty( $href_host ) || empty( $home_host ) ) {
			return false;
		}
		$href_host = preg_replace( '/^www\./', '', $href_host );
		$home_host = preg_replace( '/^www\./', '', $home_host );
		return $href_host !== $home_host;
	}
}
