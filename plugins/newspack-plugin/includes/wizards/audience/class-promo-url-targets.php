<?php
/**
 * Promotional URL targets: the server-side knowledge the promo link generator
 * needs so it only emits URLs that actually trigger the modal checkout
 * (NPPD-1707).
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Server-side knowledge for the promo link generator: pages carrying Donate
 * blocks (donation links must target one, with the effective per-block config),
 * and the product-family/picker facts that decide which plan children a
 * product link may name. Product links themselves work on any URL —
 * newspack-blocks renders the checkout button for the requested product when
 * the trigger params are present.
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
			add_action( 'save_post', [ __CLASS__, 'bump_cache_version' ], 10, 1 );
		}
	}

	/**
	 * Invalidate cached scans when content that can carry the blocks changes.
	 * Note: save_post also fires on trash, which is desirable here.
	 *
	 * Only the content scan is cached (see get_scanned_blocks()), so this covers
	 * everything the cache depends on: donation settings and product families
	 * are resolved per request.
	 *
	 * @param int $post_id Saved post ID.
	 */
	public static function bump_cache_version( $post_id ) {
		if ( ! in_array( get_post_type( $post_id ), [ 'page', 'post', 'wp_block' ], true ) ) {
			return;
		}
		// microtime() rather than time(): two saves in the same second would
		// otherwise write an identical value, which update_option() skips,
		// leaving a pre-save scan cached for the rest of its TTL.
		update_option( self::CACHE_VERSION_OPTION, (string) microtime( true ), false );
	}

	/**
	 * Find published pages/posts whose content contains the given block —
	 * directly, or via a published synced pattern (wp_block ref).
	 *
	 * Two deliberate limits: only `page` and `post` are searched (a block in a
	 * custom post type or an FSE template is not discovered, which the UI's
	 * empty state says out loud), and pattern refs are followed one level — a
	 * page referencing a pattern that only nests the block inside *another*
	 * pattern is not found here, even though extract_blocks() would resolve
	 * that chain for a page already discovered.
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
	 * `picker_members` is the subset a grouped plan's runtime picker can actually
	 * serve — see get_picker_members().
	 *
	 * @param int $product_id Plan row product ID.
	 * @return array{parent:int,variations:int[],members:int[],picker_members:int[]}
	 */
	public static function get_product_family( $product_id ) {
		$family = [
			'parent'         => (int) $product_id,
			'variations'     => [],
			'members'        => [],
			'picker_members' => [],
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
			$family['members']        = $children;
			$family['picker_members'] = self::get_picker_members( $children );
		} else {
			$family['variations'] = $children;
		}
		return $family;
	}

	/**
	 * Reduce a grouped product's children to the ids its picker will render.
	 *
	 * A Checkout Button pointing at a grouped parent renders the tiers picker
	 * (Subscriptions_Tiers::render_form()), and that form's
	 * get_tiers_by_frequency() keeps only subscription-typed, non-private
	 * children, expanding a variable subscription into its variations. Offering
	 * any other child would emit a URL whose radio never renders — the JS
	 * trigger then finds no form and only logs a console warning.
	 *
	 * @param int[] $child_ids Grouped product child IDs.
	 * @return int[] Ids the picker will render.
	 */
	public static function get_picker_members( $child_ids ) {
		$members = [];
		if ( ! function_exists( 'wc_get_product' ) ) {
			return $members;
		}
		foreach ( $child_ids as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( ! $child || ! in_array( $child->get_type(), [ 'subscription', 'variable-subscription' ], true ) ) {
				continue;
			}
			if ( 'private' === $child->get_status() ) {
				continue;
			}
			if ( $child->is_type( 'variable-subscription' ) ) {
				foreach ( $child->get_available_variations() as $variation ) {
					if ( ! empty( $variation['variation_id'] ) ) {
						$members[] = (int) $variation['variation_id'];
					}
				}
				continue;
			}
			$members[] = (int) $child_id;
		}
		return array_values( array_unique( $members ) );
	}

	/**
	 * Child ids a promo URL may name for a plan: variations for a variable
	 * plan, picker-servable members for a grouped one. The generator UI uses
	 * this to constrain the "reader chooses" option to children the target
	 * page's picker will actually offer.
	 *
	 * @param array $family See get_product_family().
	 * @return int[] Eligible child IDs.
	 */
	public static function get_eligible_children( $family ) {
		$variations = isset( $family['variations'] ) ? array_map( 'intval', $family['variations'] ) : [];
		$members    = isset( $family['picker_members'] ) ? array_map( 'intval', $family['picker_members'] ) : [];
		return array_values( array_unique( array_merge( $variations, $members ) ) );
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
	 * Scan content once per block type: every page/post carrying the block,
	 * with that block's raw attributes.
	 *
	 * This is the expensive half (unindexed LIKE queries over post_content plus
	 * a parse_blocks() pass per match) and it depends only on the block name, so
	 * it is cached per block name and shared across plan rows instead of being
	 * repeated for each one. Deriving a config from these attributes is cheap
	 * and stays out of the cache, so a change to donation settings or to a
	 * product's variations is reflected on the next request rather than
	 * outliving it by up to CACHE_TTL.
	 *
	 * @param string $block_name Block name to scan for.
	 * @return array{posts:array,truncated:bool}
	 */
	public static function get_scanned_blocks( $block_name ) {
		$version   = (string) get_option( self::CACHE_VERSION_OPTION, '0' );
		$cache_key = 'newspack_promo_blocks_' . md5( $block_name . '|' . $version );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		list( $candidate_ids, $truncated ) = self::find_candidate_post_ids( $block_name );
		$posts = [];
		foreach ( $candidate_ids as $candidate_id ) {
			$post = get_post( $candidate_id );
			if ( ! $post ) {
				continue;
			}
			$attrs = self::extract_blocks( $post->post_content, $block_name );
			if ( empty( $attrs ) ) {
				continue;
			}
			$posts[] = [
				'id'    => $post->ID,
				'title' => get_the_title( $post ),
				'url'   => get_permalink( $post ),
				'attrs' => $attrs,
			];
		}
		$result = [
			'posts'     => $posts,
			'truncated' => $truncated,
		];
		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Pages/posts carrying Donate blocks that can take a promotional link,
	 * derived from the cached scan.
	 *
	 * Donation links still need a page with a Donate block: the link's
	 * frequency/amount/layout params only mean something against that block's
	 * rendered configuration. Product links have no such dependency —
	 * newspack-blocks serves their trigger on any URL by rendering the
	 * checkout button for the requested product (see
	 * Modal_Checkout::maybe_setup_url_triggered_button()).
	 *
	 * @return array{targets:array,truncated:bool}
	 */
	public static function get_donate_targets() {
		$scanned = self::get_scanned_blocks( 'newspack-blocks/donate' );
		$result  = [
			'targets'   => [],
			'truncated' => $scanned['truncated'],
		];
		foreach ( $scanned['posts'] as $scanned_post ) {
			$configs = [];
			foreach ( $scanned_post['attrs'] as $attrs ) {
				$config = self::get_donate_target_config( $attrs );
				if ( $config ) {
					$configs[] = $config;
				}
			}
			if ( ! empty( $configs ) ) {
				$result['targets'][] = [
					'id'     => $scanned_post['id'],
					'title'  => $scanned_post['title'],
					'url'    => $scanned_post['url'],
					'blocks' => $configs,
				];
			}
		}
		return $result;
	}
}
