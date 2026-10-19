<?php
/**
 * Newspack Content Gate institutional access prompt.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Content_Gate\IP_Access_Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Offers an anonymous reader the institutional IP check from the foot of the gate.
 *
 * An anonymous reader is served the page from cache, so their IP is never matched
 * against an institution's range until they carry the IP-access cookie, and only the
 * IP check sets it. This class appends a muted link to that check below the gate
 * layout. The query-param flow it links to runs the check, sets the cookie on a match,
 * and redirects back with a result notice either way.
 *
 * The line renders whenever the gate could let an IP-matched anonymous reader in,
 * not only for readers who are on a matching network: the gate is cached and served
 * to every anonymous reader, so it cannot depend on who is asking.
 */
class Institutional_Access_Prompt {

	/**
	 * Blocks that are a gate layout's call to action.
	 */
	const CTA_BLOCKS = [
		'core/buttons',
		'newspack-blocks/checkout-button',
		'newspack-blocks/donate',
		'newspack/reader-registration',
	];

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		\add_filter( 'newspack_gate_layout_content', [ __CLASS__, 'append_prompt' ], 10, 2 );
	}

	/**
	 * Append the prompt to the gate layout content.
	 *
	 * @param string $content        The gate layout content.
	 * @param int    $gate_layout_id The gate layout ID.
	 *
	 * @return string
	 */
	public static function append_prompt( $content, $gate_layout_id ): string {
		$content = (string) $content;
		// Another layout on the page is not the gate that denied this reader.
		if ( (int) $gate_layout_id !== (int) Content_Gate::get_gate_layout_id() ) {
			return $content;
		}
		$post_id = self::get_prompt_post_id();
		if ( ! $post_id ) {
			return $content;
		}
		return self::insert_below_ctas( $content, self::render( $post_id ) );
	}

	/**
	 * Place the prompt inside the layout, directly below its calls to action.
	 *
	 * Layouts are publisher-editable block markup, so the prompt is placed by the
	 * blocks it follows rather than by position: it goes after the smallest block
	 * holding every CTA, or inside it as its last child when that block is a plain
	 * container, which keeps it within the gate's frame and next to the buttons. A
	 * row of columns or buttons is never entered, so the prompt cannot become a
	 * column or a button of its own. A layout with no recognisable CTA (classic
	 * markup, a synced pattern) gets the prompt after its content instead.
	 *
	 * @param string $content The gate layout content, before blocks are rendered.
	 * @param string $prompt  The prompt markup.
	 *
	 * @return string
	 */
	private static function insert_below_ctas( string $content, string $prompt ): string {
		if ( ! \has_blocks( $content ) ) {
			return $content . $prompt;
		}
		$blocks    = \parse_blocks( $content );
		$cta_paths = self::find_cta_paths( $blocks );
		if ( empty( $cta_paths ) ) {
			return $content . $prompt;
		}

		// The longest path prefix every CTA shares is the smallest block holding them all.
		$container_path = array_shift( $cta_paths );
		foreach ( $cta_paths as $cta_path ) {
			$shared = 0;
			while ( isset( $container_path[ $shared ], $cta_path[ $shared ] ) && $container_path[ $shared ] === $cta_path[ $shared ] ) {
				++$shared;
			}
			$container_path = array_slice( $container_path, 0, $shared );
		}

		$prompt_block = [
			'blockName'    => null,
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => $prompt,
			'innerContent' => [ $prompt ],
		];
		if ( empty( $container_path ) ) {
			$blocks[] = $prompt_block;
			return \serialize_blocks( $blocks );
		}

		$container = self::get_block_at( $blocks, $container_path );
		if ( in_array( $container['blockName'], array_merge( self::CTA_BLOCKS, [ 'core/columns', 'core/buttons' ] ), true ) ) {
			$parent_path = array_slice( $container_path, 0, -1 );
			$index       = end( $container_path ) + 1;
		} else {
			$parent_path = $container_path;
			$index       = count( $container['innerBlocks'] );
		}
		self::insert_block( $blocks, $parent_path, $index, $prompt_block );
		return \serialize_blocks( $blocks );
	}

	/**
	 * Paths to every CTA block in a block tree, in document order.
	 *
	 * @param array $blocks Parsed blocks.
	 * @param int[] $prefix Path of the blocks' parent.
	 *
	 * @return int[][] Each path is the list of inner-block indexes leading to a CTA.
	 */
	private static function find_cta_paths( array $blocks, array $prefix = [] ): array {
		$paths = [];
		foreach ( $blocks as $index => $block ) {
			$path = array_merge( $prefix, [ $index ] );
			if ( in_array( $block['blockName'], self::CTA_BLOCKS, true ) ) {
				$paths[] = $path;
			} elseif ( ! empty( $block['innerBlocks'] ) ) {
				$paths = array_merge( $paths, self::find_cta_paths( $block['innerBlocks'], $path ) );
			}
		}
		return $paths;
	}

	/**
	 * The block at a path in a block tree.
	 *
	 * @param array $blocks Parsed blocks.
	 * @param int[] $path   Inner-block indexes.
	 *
	 * @return array
	 */
	private static function get_block_at( array $blocks, array $path ): array {
		$block = [ 'innerBlocks' => $blocks ];
		foreach ( $path as $index ) {
			$block = $block['innerBlocks'][ $index ];
		}
		return $block;
	}

	/**
	 * Insert a block into a block tree as the inner block at `$index` of the block at `$parent_path`.
	 *
	 * A block's `innerContent` holds a `null` placeholder where each inner block is
	 * serialized, so the placeholder is inserted alongside the block.
	 *
	 * @param array $blocks      Parsed blocks, modified in place.
	 * @param int[] $parent_path Path of the parent block; empty for the top level.
	 * @param int   $index       Position among the parent's inner blocks.
	 * @param array $block       The block to insert.
	 */
	private static function insert_block( array &$blocks, array $parent_path, int $index, array $block ): void {
		if ( empty( $parent_path ) ) {
			array_splice( $blocks, $index, 0, [ $block ] );
			return;
		}
		$parent_index = array_shift( $parent_path );
		if ( ! empty( $parent_path ) ) {
			self::insert_block( $blocks[ $parent_index ]['innerBlocks'], $parent_path, $index, $block );
			return;
		}
		$parent = &$blocks[ $parent_index ];
		// Placeholder positions in innerContent, one per existing inner block.
		$placeholders = array_keys( $parent['innerContent'], null, true );
		$position     = $placeholders[ $index ] ?? ( empty( $placeholders ) ? count( $parent['innerContent'] ) - 1 : end( $placeholders ) + 1 );
		array_splice( $parent['innerContent'], max( 0, $position ), 0, [ null ] );
		array_splice( $parent['innerBlocks'], $index, 0, [ $block ] );
	}

	/**
	 * The post whose gate should offer the IP check to the current reader, if any.
	 *
	 * Only the gate that denied the reader is read. A lower-priority gate is never
	 * consulted once a gate above it restricts, so an institution on it could not open
	 * the post.
	 *
	 * @return int The post ID, or 0 when no prompt should render.
	 */
	private static function get_prompt_post_id(): int {
		if ( ! Content_Gate::is_gating_active() || Memberships::is_active() ) {
			return 0;
		}
		// A signed-in reader's request is uncached, so their IP was already matched
		// on this request and the check could only fail for them. This also keeps the
		// prompt out of gate previews, which need a signed-in editor.
		if ( \is_user_logged_in() ) {
			return 0;
		}
		$gate_id = (int) Content_Gate::get_gate_post_id();
		$post_id = (int) \get_queried_object_id();
		if ( ! $gate_id || ! $post_id ) {
			return 0;
		}
		foreach ( Content_Restriction_Control::get_post_gates( $post_id ) as $gate ) {
			if ( (int) $gate['id'] === $gate_id ) {
				return self::gate_admits_ip_institution( $gate ) ? $post_id : 0;
			}
		}
		return 0;
	}

	/**
	 * Whether a gate lets an anonymous reader in on an institution's IP range.
	 *
	 * Institutions in paid access skip both walls. Institutions in registered access
	 * skip only the registration wall, so they open the article only when no paid
	 * access follows it (NPPD-2310).
	 *
	 * @param array $gate The gate.
	 *
	 * @return bool
	 */
	private static function gate_admits_ip_institution( array $gate ): bool {
		$paid_rules = ! empty( $gate['custom_access']['active'] ) ? ( $gate['custom_access']['access_rules'] ?? [] ) : [];
		if ( self::rules_admit_ip_institution( $paid_rules ) ) {
			return true;
		}
		return ! empty( $gate['registration']['active'] )
			&& empty( $paid_rules )
			&& self::rules_admit_ip_institution( $gate['registration']['access_rules'] ?? [] );
	}

	/**
	 * Whether access rules let an anonymous reader in on an institution's IP range.
	 *
	 * A group qualifies only when it holds nothing but institution rules, and every one
	 * of them names an institution with a valid IP range. The rules in a group are ANDed,
	 * so any other rule, or an institution rule the reader can only meet by email, still
	 * fails for the reader after the check.
	 *
	 * @param array $access_rules Access rules, flat or grouped.
	 *
	 * @return bool
	 */
	private static function rules_admit_ip_institution( array $access_rules ): bool {
		if ( empty( $access_rules ) ) {
			return false;
		}
		$institutions = Institution::get_cached_institutions();
		foreach ( Access_Rules::normalize_rules( $access_rules ) as $group ) {
			if ( empty( $group ) || ! is_array( $group ) ) {
				continue;
			}
			$group_qualifies = true;
			foreach ( $group as $rule ) {
				if ( 'institution' !== ( $rule['slug'] ?? '' ) || empty( $rule['value'] ) || ! is_array( $rule['value'] ) ) {
					$group_qualifies = false;
					break;
				}
				$rule_has_ip_range = false;
				foreach ( $rule['value'] as $institution_id ) {
					$ip_range = $institutions[ absint( $institution_id ) ]['ip_range'] ?? '';
					if ( ! empty( $ip_range ) && ! empty( IP_Access_Rule::normalize_ip_ranges( $ip_range )['valid'] ) ) {
						$rule_has_ip_range = true;
						break;
					}
				}
				if ( ! $rule_has_ip_range ) {
					$group_qualifies = false;
					break;
				}
			}
			if ( $group_qualifies ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The prompt's link text: the publisher's copy, or the default.
	 *
	 * @return string
	 */
	private static function get_text(): string {
		$text = Content_Gate_Advanced_Settings::get_settings()['institutional_access_text'] ?? '';
		return '' !== $text ? $text : self::get_default_text();
	}

	/**
	 * The default link text. States the condition, not the mechanism.
	 *
	 * @return string
	 */
	public static function get_default_text(): string {
		return __( 'On a campus or library network? Check for access.', 'newspack-plugin' );
	}

	/**
	 * The check URL: the post's permalink with the query-param flow's trigger.
	 *
	 * Built from the permalink rather than the request because the gate is page-cached:
	 * query args the cache ignores, such as click IDs, would otherwise be baked into the
	 * link the first visitor warmed and served to everyone after them. Host-relative, so
	 * a rewriting library proxy keeps the reader on the proxy and the check sees the
	 * proxy's allowlisted IP (see NPPD-2039).
	 *
	 * @param int $post_id The gated post.
	 *
	 * @return string
	 */
	private static function get_check_url( int $post_id ): string {
		return \add_query_arg( IP_Access_Rule::ENDPOINT, '1', \wp_make_link_relative( \get_permalink( $post_id ) ) );
	}

	/**
	 * Render the prompt markup.
	 *
	 * Returned on one line: the gate content pipeline leaves `wpautop` in place for a
	 * layout carrying no block markup.
	 *
	 * @param int $post_id The gated post.
	 *
	 * @return string
	 */
	private static function render( int $post_id ): string {
		return sprintf(
			'<div class="newspack-ui newspack-content-gate__institutional-access"><p class="newspack-ui__font--xs newspack-ui__color--neutral-60"><a href="%1$s" rel="nofollow">%2$s</a></p></div>',
			\esc_url( self::get_check_url( $post_id ) ),
			\esc_html( self::get_text() )
		);
	}
}
Institutional_Access_Prompt::init();
