<?php
/**
 * Newspack Popups Data API
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Popup Data API
 *
 * This class provides data about the prompts to be used by the Newspack Data Events API and the Google Analytics tracking.
 */
final class Newspack_Popups_Data_Api {

	/**
	 * The rendered popups data.
	 *
	 * @var array
	 */
	protected static $popups = [];

	/**
	 * Memoized site conversion URLs for block-less CTA classification.
	 *
	 * See get_site_conversion_urls(). A class property (rather than a
	 * function-local static) so it can be reset between tests.
	 *
	 * @var array|null
	 */
	private static $conversion_urls_cache = null;

	/**
	 * Registers the hooks.
	 */
	public static function init() {
		\add_action( 'newspack_campaigns_after_campaign_render', [ __CLASS__, 'get_rendered_popups' ] );
		\add_action( 'wp_footer', [ __CLASS__, 'print_popups_data' ], 999 );
		add_filter( 'newspack_blocks_modal_checkout_cart_item_data', [ __CLASS__, 'checkout_cart_item_data' ], 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'checkout_create_order_line_item' ], 10, 4 );
		add_filter( 'newspack_auth_form_metadata', [ __CLASS__, 'register_reader_metadata' ] );
		add_filter( 'newspack_register_reader_form_metadata', [ __CLASS__, 'register_reader_metadata' ] );
		add_filter( 'newspack_newsletters_subscription_form_metadata', [ __CLASS__, 'register_reader_metadata' ] );
	}

	/**
	 * Get a description of a prompt's frequency settings, for analytics purposes.
	 *
	 * @param array $popup The popup object for the prompt.
	 *
	 * @return string Frequency summary.
	 */
	public static function get_frequency_summary( $popup ) {
		if ( 'custom' !== $popup['options']['frequency'] ) {
			return $popup['options']['frequency'];
		}

		$custom_settings = [];

		if ( 0 < $popup['options']['frequency_between'] ) {
			// Translators: %d is the number of pageviews in between prompt displays, if greater than 0 (every pageview).
			$custom_settings[] = sprintf( __( 'every %d pageviews', 'newspack-popups' ), $popup['options']['frequency_between'] + 1 );
		}
		if ( 0 < $popup['options']['frequency_start'] ) {
			// Translators: %d is the pageview when the prompt starts to be displayed, if greater than 0 (first pageview).
			$custom_settings[] = sprintf( __( 'starting on pageview %d', 'newspack-popups' ), $popup['options']['frequency_start'] + 1 );
		}
		if ( 0 < $popup['options']['frequency_max'] ) {
			// Translators: %d is the max number number of displays for the prompt, if greater than 0 (no max).
			$custom_settings[] = sprintf( __( 'max %d times', 'newspack-popups' ), $popup['options']['frequency_max'] );

			// Translators: %s is the time period for when the prompt can be displayed again after the max number of displays.
			$custom_settings[] = sprintf( __( 'resetting every %s', 'newspack-popups' ), $popup['options']['frequency_reset'] );
		}

		return implode( ',', $custom_settings );
	}

	/**
	 * Return block data matching the given block name in the array of blocks, looking recursively in innerBlocks if necessary.
	 *
	 * @param string $block_name The block name to search for.
	 * @param array  $blocks The array of blocks to search in.
	 *
	 * @return array|false Array of block data if found, false otherwise.
	 */
	public static function get_block_data( $block_name, $blocks ) {
		$found_blocks = [];
		foreach ( $blocks as $block ) {
			if ( $block_name === $block['blockName'] ) {
				$found_blocks[] = $block;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$found_blocks = array_merge( $found_blocks, self::get_block_data( $block_name, $block['innerBlocks'] ) );
			}
		}
		return $found_blocks;
	}

	/**
	 * Extract the relevant data from a popup.
	 *
	 * This method is used by the Newspack Data Events API.
	 *
	 * @param int|array $popup The popup ID or object.
	 * @return array
	 */
	public static function get_popup_metadata( $popup ) {
		if ( is_numeric( $popup ) ) {
			$popup = Newspack_Popups_Model::retrieve_popup_by_id( $popup );
		}
		$data = [];
		if ( ! $popup ) {
			return $data;
		}

		$data['newspack_popup_id'] = $popup['id'];
		$data['prompt_title']      = $popup['title'];

		if ( isset( $popup['options'] ) ) {
			$data['prompt_frequency'] = self::get_frequency_summary( $popup );
			$data['prompt_placement'] = $popup['options']['placement'] ?? '';
		}

		$watched_blocks = [
			'registration'             => 'newspack/reader-registration',
			'donation'                 => 'newspack-blocks/donate',
			'newsletters_subscription' => 'newspack-newsletters/subscribe',
			// NPPD-1755: subscription/paywall capability. Emits `prompt_has_checkout`
			// on the seen event so Insights can build a checkout-capable impressions
			// denominator (the prompts analog of the gate `checkout_impressions`),
			// matched to subscription orders carrying the popup id. Also gives a
			// checkout-only prompt the `action_type='checkout'` intent (was 'undefined').
			'checkout'                 => 'newspack-blocks/checkout-button',
		];

		$data['prompt_blocks']    = [];
		$data['interaction_data'] = [];

		foreach ( $watched_blocks as $key => $block_name ) {
			if ( \has_block( $block_name, $popup['content'] ) ) {
				$data['prompt_blocks'][] = $key;

				// Get the suggested donation values for the donation block.
				if ( 'donation' === $key ) {
					$prompt_blocks = \parse_blocks( $popup['content'] );
					$donate_blocks = self::get_block_data( $block_name, $prompt_blocks );

					if ( ! empty( $donate_blocks ) && method_exists( '\Newspack\Donations', 'get_donation_settings' ) ) {
						$donate_block     = reset( $donate_blocks );
						$is_manual        = $donate_block['attrs']['manual'] ?? false;
						$is_layout_tiers  = isset( $donate_block['attrs']['layoutOption'] ) && 'tiers' === $donate_block['attrs']['layoutOption'];
						$default_settings = \Newspack\Donations::get_donation_settings();
						if ( ! $default_settings || is_wp_error( $default_settings ) ) {
							$default_settings = [];
						}
						$donation_settings = $is_manual ? \wp_parse_args( $donate_block['attrs'], $default_settings ) : $default_settings;
						$is_tiered         = $donation_settings['tiered'] ?? false;
						$suggested_amounts = $donation_settings['amounts'] ?? [];
						$disabled_tiers    = $donation_settings['disabledFrequencies'] ?? [];
						$suggested_summary = [];

						// The tiers block layout doesn't allow for one-time donations.
						if ( $is_layout_tiers ) {
							// So we can differentiate between standard and tiers layouts.
							$suggested_summary['l'] = __( 'tiers', 'newspack-popup' );
							$disabled_tiers['once'] = true;
						} else {
							$suggested_summary['l'] = __( 'default', 'newspack-popup' );
						}

						foreach ( $suggested_amounts as $frequency => $amounts ) {
							if ( empty( $disabled_tiers[ $frequency ] ) ) {
								if ( $is_layout_tiers ) {
									// The tiers block layout doesn't allow for "other" inputs.
									array_pop( $amounts );
								} elseif ( ! $is_tiered ) {
									// If standard layout + untiered, only show the suggested amount for "other".
									$amounts = [ end( $amounts ) ];
								}
								$suggested_summary[ substr( $frequency, 0, 1 ) ] = $amounts;
							}
						}

						if ( ! empty( $suggested_summary ) ) {
							$data['interaction_data']['donation_suggested_values'] = \wp_json_encode( $suggested_summary );
						}
					}
				}
			}
		}

		// NPPD-1837: block-less CTA classification (display-only).
		// Runs only when no recognized conversion block was found, i.e. the exact
		// population that would otherwise collapse to action_type='undefined'. The
		// inferred intent lives on its own params and is NEVER written to a
		// prompt_has_* flag or action_type, so it can never enter a conversion
		// denominator (block-less prompts have no Newspack-tracked conversion).
		if ( empty( $data['prompt_blocks'] ) ) {
			$inferred = self::classify_blockless_cta( $popup['content'] );
			if ( $inferred ) {
				$data['inferred_cta_intent'] = $inferred['intent']; // One of donation, newsletter, subscription, event, sponsor, editorial.
				$data['inferred_cta_source'] = $inferred['source']; // One of site_config, processor, pattern.
			}
		}

		return $data;
	}

	/**
	 * Classify a block-less prompt by its button link target(s).
	 *
	 * Only conversion-legible or clearly non-conversion targets return a value;
	 * anything ambiguous returns null and the prompt stays 'undefined' (precision
	 * over recall — a false conversion label is worse than an honest blank).
	 *
	 * @param string $content The prompt post_content.
	 * @return array|null ['intent' => string, 'source' => string] or null to abstain.
	 */
	public static function classify_blockless_cta( $content ) {
		$hrefs = self::extract_button_hrefs( $content );
		if ( empty( $hrefs ) ) {
			return null;
		}

		$config = self::get_site_conversion_urls();

		// Classify every button; a prompt can hold more than one.
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
		// (don't guess). Precedence within conversion: donation, newsletter, subscription.
		$precedence     = [
			'donation'     => 1,
			'newsletter'   => 2,
			'subscription' => 3,
		];
		$conversion     = [];
		$non_conversion = [];
		foreach ( $hits as $hit ) {
			if ( isset( $precedence[ $hit['intent'] ] ) ) {
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
			usort(
				$conversion,
				function ( $a, $b ) use ( $precedence ) {
					return $precedence[ $a['intent'] ] <=> $precedence[ $b['intent'] ];
				}
			);
			return $conversion[0];
		}

		// Only non-conversion signals: report the first (sponsor/editorial/event) so
		// the dashboard can show a non-conversion label instead of a bare "undefined".
		return $non_conversion[0];
	}

	/**
	 * Pull hrefs from core/button blocks in the prompt content.
	 *
	 * Scope is intentionally limited to button blocks (not every <a> in body copy)
	 * to keep precision high. Reuses the recursive get_block_data() helper, so
	 * buttons nested inside core/buttons wrappers are covered.
	 *
	 * @param string $content The prompt post_content.
	 * @return string[] Lowercased hrefs.
	 */
	public static function extract_button_hrefs( $content ) {
		$blocks  = \parse_blocks( $content );
		$buttons = self::get_block_data( 'core/button', $blocks );
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
	 * Classify a single href. Precedence:
	 *   1) site-configured conversion URLs (highest confidence; catches the
	 *      unguessable cases like a bespoke on-site donation page),
	 *   2) known third-party processor domains,
	 *   3) path / substring patterns,
	 *   4) non-conversion tells (event, sponsor, editorial),
	 *   else null (abstain).
	 *
	 * @param string $href   Lowercased href.
	 * @param array  $config Output of get_site_conversion_urls().
	 * @return array|null ['intent' => string, 'source' => string] or null.
	 */
	public static function classify_href( $href, $config ) {
		// 1) Site config (substring match against this site's own configured URLs).
		foreach ( [ 'donation', 'newsletter', 'subscription' ] as $intent ) {
			foreach ( $config[ $intent ] as $configured ) {
				if ( $configured && str_contains( $href, $configured ) ) {
					return [
						'intent' => $intent,
						'source' => 'site_config',
					];
				}
			}
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
	 * newspack_donation_page_id option, NOT \Newspack\Donations::get_donation_page_info(),
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
			$page_id = \get_option( \Newspack\Donations::DONATION_PAGE_ID_OPTION, 0 );
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

	/**
	 * Store the rendered popups data.
	 *
	 * @param array $popup The popup array representation.
	 * @return void
	 */
	public static function get_rendered_popups( $popup ) {
		$data = self::get_popup_metadata( $popup );
		if ( ! empty( $data['newspack_popup_id'] ) ) {
			self::$popups[ $data['newspack_popup_id'] ] = $data;
		}
		if ( ! empty( $data['prompt_title'] ) ) {
			self::$popups[ $data['prompt_title'] ] = $data;
		}
	}

	/**
	 * Sanitizes the popup params to be sent as params for GA events
	 *
	 * All params in GA events must be strings, so we need to make the array flat and convert all values to strings.
	 *
	 * This method is also used by the Newspack Data Events API.
	 *
	 * @param array $popup_params The popup params as they are returned by Newspack_Popups_Data_Api::get_popup_metadata and by the prompt_interaction data.
	 * @return array
	 */
	public static function prepare_popup_params_for_ga( $popup_params ) {
		// Invalid input.
		if ( ! is_array( $popup_params ) || ! isset( $popup_params['newspack_popup_id'] ) ) {
			return [];
		}

		$sanitized = $popup_params;

		unset( $sanitized['interaction_data'] );
		$sanitized = array_merge( $sanitized, $popup_params['interaction_data'] );

		unset( $sanitized['prompt_blocks'] );
		foreach ( $popup_params['prompt_blocks'] as $block ) {
			$sanitized[ 'prompt_has_' . $block ] = 1;
		}

		// @TODO: How to handle prompts with more than one block?
		// Note: block-less prompts stay 'undefined' here but may carry a display-only
		// inferred_cta_intent (NPPD-1837), which passes through as a top-level param.
		$action_type = 'undefined';
		if ( 1 === count( $popup_params['prompt_blocks'] ) ) {
			$action_type = $popup_params['prompt_blocks'][0];
		}
		$sanitized['action_type'] = $action_type;

		return $sanitized;
	}

	/**
	 * Output the rendered popups data as a JS variable.
	 *
	 * @return void
	 */
	public static function print_popups_data() {
		if ( empty( self::$popups ) ) {
			return;
		}
		$popups = array_map( [ __CLASS__, 'prepare_popup_params_for_ga' ], self::$popups );
		?>
		<script>
			var newspackPopupsData = <?php echo \wp_json_encode( $popups ); ?>;
		</script>
		<?php
	}

	/**
	 * Add content gate metadata to the cart item.
	 *
	 * @param array $cart_item_data The cart item data.
	 *
	 * @return array
	 */
	public static function checkout_cart_item_data( $cart_item_data ) {
		$popup_id = filter_input( INPUT_GET, 'newspack_popup_id', FILTER_SANITIZE_NUMBER_INT );
		if ( ! empty( $popup_id ) ) {
			$cart_item_data['newspack_popup_id'] = $popup_id;
		}
		$prompt_title = filter_input( INPUT_GET, 'prompt_title', FILTER_SANITIZE_SPECIAL_CHARS );
		if ( ! empty( $prompt_title ) ) {
			$cart_item_data['prompt_title'] = $prompt_title;
		}
		return $cart_item_data;
	}

	/**
	 * Add content gate metadata from the cart item to the order.
	 *
	 * @param \WC_Order_Item_Product $item The cart item.
	 * @param string                 $cart_item_key The cart item key.
	 * @param array                  $values The cart item values.
	 * @param \WC_Order              $order The order.
	 * @return void
	 */
	public static function checkout_create_order_line_item( $item, $cart_item_key, $values, $order ) {
		if ( ! empty( $values['newspack_popup_id'] ) ) {
			$order->add_meta_data( '_newspack_popup_id', $values['newspack_popup_id'] );
		}
		if ( ! empty( $values['prompt_title'] ) ) {
			$order->add_meta_data( '_prompt_title', $values['prompt_title'] );
		}
	}

	/**
	 * Add content gate metadata on reader registration.
	 *
	 * @param array $metadata The metadata.
	 *
	 * @return array
	 */
	public static function register_reader_metadata( $metadata ) {
		$popup_id = filter_input( INPUT_POST, 'newspack_popup_id', FILTER_SANITIZE_NUMBER_INT );
		if ( ! empty( $popup_id ) && isset( $metadata['registration_method'] ) ) {
			$metadata['newspack_popup_id'] = $popup_id;
		}
		return $metadata;
	}
}

Newspack_Popups_Data_Api::init();
