<?php
/**
 * Promotional URL targets: scan content for modal-checkout-compatible blocks
 * and derive the effective config a promotional URL must match (NPPD-1707).
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Finds pages/posts containing Checkout Button or Donate blocks compatible
 * with a given plan, and derives per-block effective config so the generator
 * UI can only emit URLs that will actually trigger the modal checkout.
 */
final class Promo_Url_Targets {

	const CACHE_TTL            = 15 * MINUTE_IN_SECONDS;
	const CACHE_VERSION_OPTION = 'newspack_promo_targets_cache_version';
	const SCAN_LIMIT           = 100;
	const PATTERN_SCAN_LIMIT   = 20;
	const MAX_BLOCK_DEPTH      = 3;

	/**
	 * Register hooks. Idempotent (guarded by has_action).
	 */
	public static function init() {
		if ( ! has_action( 'save_post', [ __CLASS__, 'bump_cache_version' ] ) ) {
			add_action( 'save_post', [ __CLASS__, 'bump_cache_version' ] );
		}
	}

	/**
	 * Invalidate cached scans when content that can carry the blocks changes.
	 * Note: save_post also fires on trash, which is desirable here.
	 *
	 * @param int $post_id Saved post ID.
	 */
	public static function bump_cache_version( $post_id ) {
		if ( ! in_array( get_post_type( $post_id ), [ 'page', 'post', 'wp_block' ], true ) ) {
			return;
		}
		update_option( self::CACHE_VERSION_OPTION, (string) time(), false );
	}

	/**
	 * Find published pages/posts whose content contains the given block —
	 * directly, or via a published synced pattern (wp_block ref).
	 *
	 * @param string $block_name Block name, e.g. `newspack-blocks/checkout-button`.
	 * @return array [ int[] $ids, bool $truncated ]
	 */
	public static function find_candidate_post_ids( $block_name ) {
		global $wpdb;
		$like  = '%' . $wpdb->esc_like( '<!-- wp:' . $block_name ) . '%';
		$limit = self::SCAN_LIMIT + 1;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post') AND post_content LIKE %s ORDER BY post_modified DESC LIMIT %d",
				$like,
				$limit
			)
		);
		// Synced patterns containing the block, newest-modified first and
		// capped; if the cap is hit there may be more patterns (and more
		// referencing pages) we never see, so that must be signaled too.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pattern_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'wp_block' AND post_content LIKE %s ORDER BY post_modified DESC LIMIT %d",
				$like,
				self::PATTERN_SCAN_LIMIT
			)
		);
		$truncated = count( $pattern_ids ) === self::PATTERN_SCAN_LIMIT;
		foreach ( $pattern_ids as $pattern_id ) {
			// Anchor the ref match to the full id: block serialization is
			// `{"ref":123}` or `{"ref":123,...}`, so an unanchored match on
			// `"ref":5` would also false-positive on `"ref":52`.
			$ref_like_comma = '%' . $wpdb->esc_like( '"ref":' . (int) $pattern_id . ',' ) . '%';
			$ref_like_brace = '%' . $wpdb->esc_like( '"ref":' . (int) $pattern_id . '}' ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$referencing = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post') AND ( post_content LIKE %s OR post_content LIKE %s ) ORDER BY post_modified DESC LIMIT %d",
					$ref_like_comma,
					$ref_like_brace,
					$limit
				)
			);
			// A full page here means more referencing pages may exist unseen.
			if ( count( $referencing ) >= $limit ) {
				$truncated = true;
			}
			$ids = array_merge( $ids, $referencing );
		}
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		// Direct-content and ref-based ids are each newest-first within their
		// own query, but the merged union isn't; re-sort it in one query.
		if ( count( $ids ) > 1 ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$sql          = "SELECT ID FROM {$wpdb->posts} WHERE ID IN ({$placeholders}) ORDER BY post_modified DESC";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $ids ) ) );
		}

		$truncated = $truncated || count( $ids ) > self::SCAN_LIMIT;
		return [ array_slice( $ids, 0, self::SCAN_LIMIT ), $truncated ];
	}

	/**
	 * Extract attribute arrays for every occurrence of a block in serialized
	 * content, recursing into inner blocks and published synced-pattern refs.
	 *
	 * @param string $content    Serialized post content.
	 * @param string $block_name Block name to match.
	 * @param int    $depth      Recursion depth (pattern refs), capped.
	 * @return array List of attribute arrays.
	 */
	public static function extract_blocks( $content, $block_name, $depth = 0 ) {
		if ( $depth > self::MAX_BLOCK_DEPTH ) {
			return [];
		}
		$found  = [];
		$walker = function ( $blocks ) use ( &$walker, &$found, $block_name, $depth ) {
			foreach ( $blocks as $block ) {
				if ( $block_name === $block['blockName'] ) {
					$found[] = is_array( $block['attrs'] ) ? $block['attrs'] : [];
				}
				if ( 'core/block' === $block['blockName'] && ! empty( $block['attrs']['ref'] ) ) {
					$ref = get_post( (int) $block['attrs']['ref'] );
					if ( $ref && 'wp_block' === $ref->post_type && 'publish' === $ref->post_status ) {
						$found = array_merge( $found, self::extract_blocks( $ref->post_content, $block_name, $depth + 1 ) );
					}
				}
				if ( ! empty( $block['innerBlocks'] ) ) {
					$walker( $block['innerBlocks'] );
				}
			}
		};
		$walker( parse_blocks( $content ) );
		return $found;
	}

	/**
	 * Build the product "family" a plan row spans: the row product itself plus
	 * its variation children (variable) or bundled members (grouped).
	 *
	 * @param int $product_id Plan row product ID.
	 * @return array{parent:int,variations:int[],members:int[]}
	 */
	public static function get_product_family( $product_id ) {
		$family = [
			'parent'     => (int) $product_id,
			'variations' => [],
			'members'    => [],
		];
		if ( ! function_exists( 'wc_get_product' ) ) {
			return $family;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return $family;
		}
		$children = array_map( 'intval', $product->get_children() );
		if ( $product->is_type( 'grouped' ) ) {
			$family['members'] = $children;
		} else {
			$family['variations'] = $children;
		}
		return $family;
	}

	/**
	 * Derive the effective config of a Checkout Button block relative to a plan
	 * family. Returns the ids the block's data-checkout JSON will emit at render
	 * time — the JS URL trigger matches against those, not the raw attributes
	 * (variable-locked buttons collapse product onto the variation in view.php).
	 *
	 * @param array $attrs  Block attributes from parse_blocks().
	 * @param array $family See get_product_family().
	 * @return array|null Effective config, or null when the block doesn't match.
	 */
	public static function derive_checkout_button_config( $attrs, $family ) {
		$block_product   = isset( $attrs['product'] ) ? (int) $attrs['product'] : 0;
		$block_variation = isset( $attrs['variation'] ) ? (int) $attrs['variation'] : 0;
		if ( ! $block_product ) {
			return null;
		}
		$parent     = (int) $family['parent'];
		$variations = array_map( 'intval', $family['variations'] );
		$members    = array_map( 'intval', $family['members'] );

		$config = null;
		if ( $block_product === $parent ) {
			$locked = in_array( $block_variation, array_merge( $variations, $members ), true ) ? $block_variation : 0;
			$config = [
				'product_id'           => $parent,
				'variation_id'         => $locked ? $locked : null,
				'has_variation_picker' => ! $locked && ( ! empty( $attrs['is_variable'] ) || ! empty( $members ) ),
			];
		} elseif ( in_array( $block_product, $variations, true ) ) {
			$config = [
				'product_id'           => $parent,
				'variation_id'         => $block_product,
				'has_variation_picker' => false,
			];
		} elseif ( in_array( $block_product, $members, true ) ) {
			$config = [
				'product_id'           => $block_product,
				'variation_id'         => null,
				'has_variation_picker' => false,
			];
		}
		if ( ! $config ) {
			return null;
		}
		$behavior                = isset( $attrs['afterSuccessBehavior'] ) ? (string) $attrs['afterSuccessBehavior'] : '';
		$config['coupon']        = isset( $attrs['coupon'] ) && '' !== (string) $attrs['coupon'] ? (string) $attrs['coupon'] : null;
		$config['after_success'] = '' !== $behavior ? [
			'behavior'     => $behavior,
			'url'          => isset( $attrs['afterSuccessURL'] ) ? (string) $attrs['afterSuccessURL'] : '',
			'button_label' => isset( $attrs['afterSuccessButtonLabel'] ) ? (string) $attrs['afterSuccessButtonLabel'] : '',
		] : null;
		return $config;
	}

	/**
	 * Map a resolved Donate renderer configuration to the promo-URL contract.
	 *
	 * Encodes the layout-param semantics of triggerDonationForm() in
	 * newspack-blocks (modal.js): `tiered` targets the tiers-based block,
	 * `untiered` the NYP input, and any other value the frequency-based tier
	 * radios. Getting this wrong is a silent failure (NPPM-2815), so it lives
	 * here — server-side, tested — and nowhere else.
	 *
	 * @param array $configuration Output of Newspack_Blocks_Donate_Renderer_Base::get_configuration().
	 * @param bool  $can_use_nyp   Whether Name-Your-Price is available.
	 * @return array Promo donate config (layout_param, frequencies, default_frequency, minimum).
	 */
	public static function map_donate_configuration( $configuration, $can_use_nyp ) {
		$is_tiers_based = ! empty( $configuration['is_tier_based_layout'] );
		$tiered         = ! empty( $configuration['tiered'] );
		if ( $is_tiers_based ) {
			$layout_param = 'tiered';
		} elseif ( $tiered ) {
			$layout_param = 'frequency';
		} else {
			$layout_param = $can_use_nyp ? 'untiered' : 'frequency';
		}
		$frequencies = [];
		foreach ( [ 'once', 'month', 'year' ] as $slug ) {
			$amounts   = isset( $configuration['amounts'][ $slug ] ) ? array_map( 'floatval', (array) $configuration['amounts'][ $slug ] ) : [];
			$suggested = isset( $amounts[3] ) ? $amounts[3] : null;
			if ( 'untiered' === $layout_param ) {
				$preset_amounts  = [];
				$supports_custom = true;
			} elseif ( 'tiered' === $layout_param ) {
				$preset_amounts  = array_slice( $amounts, 0, 3 );
				$supports_custom = false;
			} elseif ( $tiered ) {
				$preset_amounts  = array_slice( $amounts, 0, 3 );
				$supports_custom = $can_use_nyp;
			} else {
				$preset_amounts  = null === $suggested ? [] : [ $suggested ];
				$supports_custom = false;
			}
			$frequencies[ $slug ] = [
				'enabled'         => isset( $configuration['frequencies'][ $slug ] ),
				'amounts'         => $preset_amounts,
				'supports_custom' => $supports_custom,
				'suggested'       => $suggested,
			];
		}
		return [
			'layout_param'      => $layout_param,
			'frequencies'       => $frequencies,
			'default_frequency' => isset( $configuration['defaultFrequency'] ) ? (string) $configuration['defaultFrequency'] : 'month',
			'minimum'           => isset( $configuration['minimumDonation'] ) ? (float) $configuration['minimumDonation'] : 5.0,
		];
	}

	/**
	 * Gate + map a resolved Donate configuration. Split from
	 * get_donate_target_config() so the rejection logic is unit-testable
	 * without newspack-blocks loaded.
	 *
	 * @param array|\WP_Error $configuration Renderer configuration or error.
	 * @param bool            $can_use_nyp   Whether Name-Your-Price is available.
	 * @return array|null Promo donate config, or null when unusable.
	 */
	public static function evaluate_donate_configuration( $configuration, $can_use_nyp ) {
		if ( is_wp_error( $configuration ) || ! is_array( $configuration ) || 'wc' !== ( $configuration['platform'] ?? '' ) ) {
			return null;
		}
		return self::map_donate_configuration( $configuration, $can_use_nyp );
	}

	/**
	 * Derive the promo config for one Donate block instance found in content.
	 *
	 * @param array $attrs Block attributes from parse_blocks().
	 * @return array|null Promo donate config, or null when newspack-blocks is
	 *                    unavailable or the block can't take modal-checkout URLs.
	 */
	public static function get_donate_target_config( $attrs ) {
		if ( isset( $attrs['useModalCheckout'] ) && ! $attrs['useModalCheckout'] ) {
			return null;
		}
		if ( ! class_exists( '\Newspack_Blocks_Donate_Renderer_Base' ) || ! class_exists( '\Newspack_Blocks' ) ) {
			return null;
		}
		// parse_blocks() yields only explicitly-set attributes; fill schema defaults.
		$block_type = \WP_Block_Type_Registry::get_instance()->get_registered( 'newspack-blocks/donate' );
		if ( $block_type ) {
			$attrs = $block_type->prepare_attributes_for_render( $attrs );
		}
		$configuration = \Newspack_Blocks_Donate_Renderer_Base::get_configuration( $attrs );
		return self::evaluate_donate_configuration( $configuration, \Newspack_Blocks::can_use_name_your_price() );
	}

	/**
	 * Build the direct-path donation config from raw settings. Split from
	 * get_direct_donation_config() so the inversion/override logic is
	 * unit-testable without WooCommerce loaded.
	 *
	 * @param array $settings    Donations::get_donation_settings() output.
	 * @param array $product_ids Frequency slug => donation product ID (0/empty when missing).
	 * @param bool  $can_use_nyp Whether Name-Your-Price is available.
	 * @return array|null Promo donate config, or null when no frequency is usable
	 *                    (the UI then reports donations as not configured instead
	 *                    of emitting a URL the donation handler would ignore).
	 */
	public static function build_direct_donation_config( $settings, $product_ids, $can_use_nyp ) {
		$enabled_map = [];
		foreach ( [ 'once', 'month', 'year' ] as $slug ) {
			if ( empty( $settings['disabledFrequencies'][ $slug ] ) ) {
				$enabled_map[ $slug ] = $slug;
			}
		}
		$configuration = array_merge(
			$settings,
			[
				'frequencies'          => $enabled_map,
				'is_tier_based_layout' => false,
			]
		);
		$config = self::map_donate_configuration( $configuration, $can_use_nyp );
		foreach ( array_keys( $config['frequencies'] ) as $slug ) {
			$config['frequencies'][ $slug ]['supports_custom'] = true;
			if ( empty( $product_ids[ $slug ] ) ) {
				$config['frequencies'][ $slug ]['enabled'] = false;
			}
		}
		$has_enabled = false;
		foreach ( $config['frequencies'] as $frequency_config ) {
			if ( ! empty( $frequency_config['enabled'] ) ) {
				$has_enabled = true;
				break;
			}
		}
		if ( ! $has_enabled ) {
			return null;
		}
		return $config;
	}

	/**
	 * Promo config for the direct (PHP-side) donation path, from site settings.
	 * Direct URLs accept any value (NYP-standardized), so every frequency
	 * supports a custom amount; a frequency is only usable when its donation
	 * product exists.
	 *
	 * @return array|null Promo donate config, or null when donations aren't
	 *                    WC-based or no frequency has a donation product.
	 */
	public static function get_direct_donation_config() {
		if ( ! Donations::is_platform_wc() ) {
			return null;
		}
		$settings = Donations::get_donation_settings();
		if ( is_wp_error( $settings ) ) {
			return null;
		}
		$can_use_nyp = class_exists( '\Newspack_Blocks' ) ? \Newspack_Blocks::can_use_name_your_price() : false;
		return self::build_direct_donation_config( $settings, Donations::get_donation_product_child_products_ids(), $can_use_nyp );
	}

	/**
	 * Scan for pages/posts containing blocks compatible with the given promo
	 * type (and product family, for checkout buttons). Cached per type+product
	 * until content changes (see bump_cache_version) or CACHE_TTL elapses.
	 *
	 * @param string $type       'checkout_button' or 'donate'.
	 * @param int    $product_id Plan row product ID (checkout_button only).
	 * @return array{targets:array,truncated:bool}
	 */
	public static function get_targets( $type, $product_id = 0 ) {
		$version   = (string) get_option( self::CACHE_VERSION_OPTION, '0' );
		$cache_key = 'newspack_promo_targets_' . md5( $type . '|' . $product_id . '|' . $version );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$block_name = 'checkout_button' === $type ? 'newspack-blocks/checkout-button' : 'newspack-blocks/donate';
		$family     = 'checkout_button' === $type ? self::get_product_family( $product_id ) : null;
		list( $candidate_ids, $truncated ) = self::find_candidate_post_ids( $block_name );
		$result = [
			'targets'   => [],
			'truncated' => $truncated,
		];
		foreach ( $candidate_ids as $candidate_id ) {
			$post = get_post( $candidate_id );
			if ( ! $post ) {
				continue;
			}
			$configs = [];
			foreach ( self::extract_blocks( $post->post_content, $block_name ) as $attrs ) {
				$config = 'checkout_button' === $type
					? self::derive_checkout_button_config( $attrs, $family )
					: self::get_donate_target_config( $attrs );
				if ( $config ) {
					$configs[] = $config;
				}
			}
			if ( ! empty( $configs ) ) {
				$result['targets'][] = [
					'id'     => $post->ID,
					'title'  => get_the_title( $post ),
					'url'    => get_permalink( $post ),
					'blocks' => $configs,
				];
			}
		}
		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Name-Your-Price availability per family member, for the direct-path
	 * custom price field.
	 *
	 * @param array $family See get_product_family().
	 * @return array<int,bool> Empty when the NYP plugin is unavailable.
	 */
	public static function get_nyp_map( $family ) {
		$map = [];
		if ( ! class_exists( '\WC_Name_Your_Price_Helpers' ) ) {
			return $map;
		}
		foreach ( array_merge( [ $family['parent'] ], $family['variations'], $family['members'] ) as $id ) {
			$map[ $id ] = (bool) \WC_Name_Your_Price_Helpers::is_nyp( $id );
		}
		return $map;
	}

	/**
	 * Decide whether a coupon is usable for a promoted product, from plain
	 * extracted values. Pure so it is unit-testable without WooCommerce; the
	 * REST callback extracts these values from WC_Coupon and delegates.
	 *
	 * Mirrors the context-free subset of WC_Discounts::is_coupon_valid() plus
	 * manual product/amount checks — a bare WC_Discounts with no cart rejects
	 * product-restricted and minimum-amount coupons outright, which are the
	 * primary promotional-coupon shapes.
	 *
	 * @param array $coupon_data {
	 *     Extracted coupon state.
	 *     @type bool       $expired          Past its end date.
	 *     @type bool       $usage_exceeded   Usage limit reached.
	 *     @type int[]      $product_ids      Allowed product IDs ([] = no restriction).
	 *     @type int[]      $excluded_ids     Excluded product IDs.
	 *     @type int[]      $category_ids     Allowed product category IDs ([] = no restriction).
	 *     @type float      $minimum_amount   Minimum spend (0 = none).
	 * }
	 * @param array $product_context {
	 *     Promoted-product context; empty array skips product-dependent checks.
	 *     @type int[]      $family_ids       Product + variations/members IDs.
	 *     @type int[]      $family_category_ids Category term IDs of the product.
	 *     @type float|null $reference_price  Price used against minimum_amount.
	 * }
	 * @return array { valid: bool, reason?: string }
	 */
	public static function evaluate_coupon( $coupon_data, $product_context = [] ) {
		if ( ! empty( $coupon_data['expired'] ) ) {
			return [
				'valid'  => false,
				'reason' => __( 'This coupon has expired.', 'newspack-plugin' ),
			];
		}
		if ( ! empty( $coupon_data['usage_exceeded'] ) ) {
			return [
				'valid'  => false,
				'reason' => __( 'This coupon has reached its usage limit.', 'newspack-plugin' ),
			];
		}
		$family_ids = isset( $product_context['family_ids'] ) ? array_map( 'intval', $product_context['family_ids'] ) : [];
		if ( ! empty( $family_ids ) ) {
			$allowed = isset( $coupon_data['product_ids'] ) ? array_map( 'intval', $coupon_data['product_ids'] ) : [];
			if ( ! empty( $allowed ) && empty( array_intersect( $allowed, $family_ids ) ) ) {
				return [
					'valid'  => false,
					'reason' => __( 'This coupon does not apply to this plan.', 'newspack-plugin' ),
				];
			}
			$excluded = isset( $coupon_data['excluded_ids'] ) ? array_map( 'intval', $coupon_data['excluded_ids'] ) : [];
			if ( ! empty( $excluded ) && empty( array_diff( $family_ids, $excluded ) ) ) {
				return [
					'valid'  => false,
					'reason' => __( 'This coupon excludes this plan.', 'newspack-plugin' ),
				];
			}
			$allowed_cats = isset( $coupon_data['category_ids'] ) ? array_map( 'intval', $coupon_data['category_ids'] ) : [];
			$family_cats  = isset( $product_context['family_category_ids'] ) ? array_map( 'intval', $product_context['family_category_ids'] ) : [];
			if ( ! empty( $allowed_cats ) && empty( array_intersect( $allowed_cats, $family_cats ) ) ) {
				return [
					'valid'  => false,
					'reason' => __( 'This coupon is limited to product categories this plan is not in.', 'newspack-plugin' ),
				];
			}
			$minimum = isset( $coupon_data['minimum_amount'] ) ? (float) $coupon_data['minimum_amount'] : 0.0;
			$price   = isset( $product_context['reference_price'] ) ? $product_context['reference_price'] : null;
			if ( $minimum > 0 && null !== $price && (float) $price < $minimum ) {
				return [
					'valid'  => false,
					'reason' => __( 'The plan price is below this coupon’s minimum spend.', 'newspack-plugin' ),
				];
			}
		}
		return [ 'valid' => true ];
	}
}
