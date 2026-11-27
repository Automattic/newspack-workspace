<?php
/**
 * Newspack Multibranded site: brand menus and template parts on block themes.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Block_Theme;

use Newspack_Multibranded_Site\Meta\Block_Theme_Menus;
use Newspack_Multibranded_Site\Meta\Block_Theme_Parts;

/**
 * Brands use the main site's menus, header, footer and sidebar unless they choose another
 * existing menu or template part. Choices are applied when the page renders, so nothing
 * per brand is a copy unless an admin makes one, and the page cache (keyed by URL) is safe.
 */
class Layouts {

	/**
	 * Back-dated so a new menu never becomes Core's fallback (the most recently published
	 * menu), which would change what an unset Navigation block shows on the main site.
	 */
	const BACKDATE = '2000-01-01 00:00:00';

	/**
	 * Post meta on a template part copied for a brand: "{brand term ID}:{slot}".
	 */
	const COPY_META_KEY = '_newspack_brand_copy';

	/**
	 * For each template part being rendered, whether it's one the brand chose instead of the
	 * main one. Pushed in filter_block_data(), popped in leave_part().
	 *
	 * @var bool[]
	 */
	private static $part_stack = [];

	/**
	 * Initializes.
	 */
	public static function init() {
		add_filter( 'render_block_data', [ __CLASS__, 'filter_block_data' ] );
		add_filter( 'render_block', [ __CLASS__, 'leave_part' ], 10, 2 );
		add_filter( 'render_block_core/site-title', [ __CLASS__, 'filter_site_title' ] );
	}

	/**
	 * Term meta that should be an array.
	 *
	 * @param \WP_Term $brand Brand.
	 * @param string   $key   Meta key.
	 * @return array
	 */
	private static function meta_array( $brand, $key ) {
		$value = get_term_meta( $brand->term_id, $key, true );
		return is_array( $value ) ? $value : [];
	}

	/**
	 * Swap menus and template parts for the current brand.
	 *
	 * @param array $block Parsed block.
	 * @return array
	 */
	public static function filter_block_data( $block ) {
		$name = $block['blockName'] ?? '';

		if ( 'core/navigation' === $name ) {
			$brand = Block_Theme::current_brand();
			// A brand's own part holds the menu the brand wants; it's edited in that part.
			if ( ! $brand || self::in_own_part() ) {
				return $block;
			}
			$ref = isset( $block['attrs']['ref'] ) ? (int) $block['attrs']['ref'] : 0;
			// No menu selected: Core shows its fallback menu, so swap based on that one.
			if ( ! $ref && empty( $block['innerBlocks'] ) ) {
				$ref = self::fallback_nav_id();
			}
			$replacement = $ref ? self::brand_nav_id( $brand, $ref ) : 0;
			if ( $replacement ) {
				$block['attrs']['ref'] = $replacement;
			}
			return $block;
		}

		if ( 'core/template-part' === $name ) {
			$own   = self::in_own_part();
			$brand = Block_Theme::current_brand();
			$slot  = Block_Theme::slot_of( $block['attrs'] ?? [] );
			if ( $brand && $slot && ! empty( $block['attrs']['slug'] ) ) {
				$replacement = self::brand_part_slug( $brand, $slot );
				if ( $replacement && $replacement !== $block['attrs']['slug'] ) {
					$block['attrs']['slug']  = $replacement;
					$block['attrs']['theme'] = get_stylesheet();
					$own                     = true;
				}
			}
			self::$part_stack[] = $own;
		}

		return $block;
	}

	/**
	 * Whether the block being rendered sits inside a part the brand chose instead of the main one.
	 *
	 * @return bool
	 */
	public static function in_own_part() {
		return self::$part_stack ? (bool) end( self::$part_stack ) : false;
	}

	/**
	 * Close the part opened in filter_block_data(). Core fires render_block_data right before
	 * a block renders and render_block right after, so the two pair up.
	 *
	 * @param string $content Rendered block.
	 * @param array  $block   Parsed block.
	 * @return string
	 */
	public static function leave_part( $content, $block ) {
		if ( 'core/template-part' === ( $block['blockName'] ?? '' ) && self::$part_stack ) {
			array_pop( self::$part_stack );
		}
		return $content;
	}

	/**
	 * Point the Site Title block at the brand.
	 *
	 * @param string $html Block HTML.
	 * @return string
	 */
	public static function filter_site_title( $html ) {
		$brand = Block_Theme::current_brand();
		if ( ! $brand ) {
			return $html;
		}
		$link = get_term_link( $brand );
		if ( is_wp_error( $link ) ) {
			return $html;
		}
		return preg_replace( '|href="[^"]*"|', 'href="' . esc_url( $link ) . '"', $html, 1 );
	}

	/**
	 * The menu Core shows in a Navigation block with no menu selected: the most recently
	 * published one. Menus copied for brands are back-dated so they never become it.
	 *
	 * @return int
	 */
	public static function fallback_nav_id() {
		static $id = null;
		if ( null === $id ) {
			$fallback = class_exists( '\WP_Navigation_Fallback' ) ? \WP_Navigation_Fallback::get_fallback() : null;
			$id       = $fallback instanceof \WP_Post ? (int) $fallback->ID : 0;
		}
		return $id;
	}

	/**
	 * The menu a brand shows in place of a main site menu, or 0 for the main one.
	 *
	 * @param \WP_Term $brand  Brand.
	 * @param int      $nav_id Main site menu ID.
	 * @return int
	 */
	public static function brand_nav_id( $brand, $nav_id ) {
		$map    = self::meta_array( $brand, Block_Theme_Menus::get_key() );
		$chosen = isset( $map[ $nav_id ] ) ? (int) $map[ $nav_id ] : 0;
		return $chosen && $chosen !== (int) $nav_id && 'wp_navigation' === get_post_type( $chosen ) && 'publish' === get_post_status( $chosen ) ? $chosen : 0;
	}

	/**
	 * The part a brand has chosen for a slot, as saved.
	 *
	 * @param \WP_Term $brand Brand.
	 * @param string   $slot  Slot.
	 * @return string
	 */
	public static function saved_part_slug( $brand, $slot ) {
		$map = self::meta_array( $brand, Block_Theme_Parts::get_key() );
		return isset( $map[ $slot ] ) && is_string( $map[ $slot ] ) ? $map[ $slot ] : '';
	}

	/**
	 * The part to render in a slot on a brand's pages, or '' for the main one.
	 *
	 * Template parts belong to a theme. After a theme switch the chosen part may not exist,
	 * and swapping in a missing slug renders nothing at all, so fall back to the main one.
	 *
	 * @param \WP_Term $brand Brand.
	 * @param string   $slot  Slot.
	 * @return string
	 */
	public static function brand_part_slug( $brand, $slot ) {
		$chosen = self::saved_part_slug( $brand, $slot );
		return $chosen && $chosen !== Block_Theme::main_part_slug( $slot ) && Block_Theme::get_part( $chosen ) ? $chosen : '';
	}

	/**
	 * Collect the menus in blocks, following pattern references.
	 *
	 * @param array $blocks Parsed blocks.
	 * @param array $found  Accumulator: nav ID => whether it's only there as Core's fallback.
	 * @param int   $depth  Recursion guard.
	 */
	private static function collect_navs( $blocks, &$found, $depth = 0 ) {
		if ( $depth > 6 ) {
			return;
		}
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? '';
			if ( 'core/navigation' === $name ) {
				$ref      = isset( $block['attrs']['ref'] ) ? (int) $block['attrs']['ref'] : 0;
				$fallback = false;
				if ( ! $ref && empty( $block['innerBlocks'] ) ) {
					$ref      = self::fallback_nav_id();
					$fallback = true;
				}
				if ( $ref ) {
					$found[ $ref ] = isset( $found[ $ref ] ) ? $found[ $ref ] && $fallback : $fallback;
				}
			}
			if ( 'core/pattern' === $name && ! empty( $block['attrs']['slug'] ) ) {
				$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( $block['attrs']['slug'] );
				if ( $pattern && ! empty( $pattern['content'] ) ) {
					self::collect_navs( parse_blocks( $pattern['content'] ), $found, $depth + 1 );
				}
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				self::collect_navs( $block['innerBlocks'], $found, $depth + 1 );
			}
		}
	}

	/**
	 * The menus in a template part.
	 *
	 * @param \WP_Block_Template $part Template part.
	 * @return array Nav ID => whether it's only there because a Navigation block has no menu selected.
	 */
	public static function part_navs( $part ) {
		$found = [];
		self::collect_navs( parse_blocks( $part->content ), $found );
		return $found;
	}

	/**
	 * Make a regular menu named "Original [Brand]", copied from another or empty. It isn't
	 * tied to the brand or deleted with it.
	 *
	 * @param \WP_Term $brand     Brand.
	 * @param int      $source_id Menu to copy.
	 * @param bool     $empty     Start with no links instead of a copy.
	 * @return \WP_Post|\WP_Error
	 */
	public static function copy_nav( $brand, $source_id, $empty = false ) {
		$source = get_post( $source_id );
		if ( ! $source || 'wp_navigation' !== $source->post_type ) {
			return new \WP_Error( 'newspack_multibranded_site_invalid_menu', __( 'That menu does not exist.', 'newspack-multibranded-site' ), [ 'status' => 404 ] );
		}
		$id = wp_insert_post(
			[
				'post_type'     => 'wp_navigation',
				'post_status'   => 'publish',
				'post_title'    => self::generated_title( $source->post_title, $brand ),
				'post_content'  => $empty ? '' : wp_slash( $source->post_content ),
				'post_date'     => self::BACKDATE,
				'post_date_gmt' => self::BACKDATE,
			],
			true
		);
		return is_wp_error( $id ) ? $id : get_post( $id );
	}

	/**
	 * Name for anything made for a brand: "Original Name [Brand]".
	 *
	 * @param string   $name  Original name.
	 * @param \WP_Term $brand Brand.
	 * @return string
	 */
	public static function generated_title( $name, $brand ) {
		/* translators: 1: name of the menu or template part copied, 2: brand name. */
		return sprintf( __( '%1$s [%2$s]', 'newspack-multibranded-site' ), $name, Block_Theme::brand_name( $brand ) );
	}

	/**
	 * Copy a slot's main part into a new regular part named "Original [Brand]". It isn't
	 * deleted with the brand.
	 *
	 * With $replace, overwrite the brand's earlier copy with that slug instead, so there's
	 * one "[Brand]" copy rather than two with the same name.
	 *
	 * @param \WP_Term $brand   Brand.
	 * @param string   $slot    Slot.
	 * @param string   $replace Slug of the brand's earlier copy to overwrite, or '' for a new copy.
	 * @return \WP_Block_Template|\WP_Error
	 */
	public static function copy_part( $brand, $slot, $replace = '' ) {
		$source = Block_Theme::get_part( Block_Theme::main_part_slug( $slot ) );
		if ( ! $source ) {
			return new \WP_Error( 'newspack_multibranded_site_invalid_part', __( 'The main template part for this slot could not be found.', 'newspack-multibranded-site' ), [ 'status' => 404 ] );
		}

		if ( $replace ) {
			$copy = Block_Theme::get_part( $replace );
			if ( ! $copy || ! $copy->wp_id || get_post_meta( $copy->wp_id, self::COPY_META_KEY, true ) !== $brand->term_id . ':' . $slot ) {
				return new \WP_Error( 'newspack_multibranded_site_no_copy', __( 'This brand has no earlier copy to replace.', 'newspack-multibranded-site' ), [ 'status' => 404 ] );
			}
			$result = wp_update_post(
				[
					'ID'           => $copy->wp_id,
					'post_content' => wp_slash( $source->content ),
				],
				true
			);
			return is_wp_error( $result ) ? $result : Block_Theme::get_part( $copy->slug );
		}

		$base     = $slot . '-' . $brand->slug;
		$new_slug = $base;
		$i        = 2;
		while ( Block_Theme::get_part( $new_slug ) ) {
			$new_slug = $base . '-' . ( $i++ );
		}
		$id = wp_insert_post(
			[
				'post_type'    => 'wp_template_part',
				'post_status'  => 'publish',
				'post_name'    => $new_slug,
				'post_title'   => self::generated_title( $source->title ? $source->title : $slot, $brand ),
				'post_content' => wp_slash( $source->content ),
			],
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		wp_set_post_terms( $id, get_stylesheet(), 'wp_theme' );
		wp_set_post_terms( $id, Block_Theme::slot_area( $slot ), 'wp_template_part_area' );
		update_post_meta( $id, self::COPY_META_KEY, $brand->term_id . ':' . $slot );
		return Block_Theme::get_part( get_post_field( 'post_name', $id ) );
	}

	/**
	 * Which brands, and whether the main site, use each menu and template part. Uses the
	 * saved brand choices.
	 *
	 * @return array [ 'navs' => [ nav ID => entry ], 'parts' => [ slug => entry ] ], each entry
	 *               [ 'main' => bool, 'brands' => [ term ID => name ], 'area' => (parts only) ].
	 */
	public static function usage() {
		$navs  = [];
		$parts = [];
		$add   = function ( &$list, $key, $brand = null, $extra = [] ) {
			if ( ! isset( $list[ $key ] ) ) {
				$list[ $key ] = array_merge(
					[
						'main'   => false,
						'brands' => [],
					],
					$extra
				);
			}
			if ( $brand ) {
				$list[ $key ]['brands'][ $brand->term_id ] = Block_Theme::brand_name( $brand );
			} else {
				$list[ $key ]['main'] = true;
			}
		};

		foreach ( Block_Theme::SLOTS as $slot ) {
			$main = Block_Theme::get_part( Block_Theme::main_part_slug( $slot ) );
			$add( $parts, Block_Theme::main_part_slug( $slot ), null, [ 'area' => Block_Theme::slot_area( $slot ) ] );
			foreach ( $main ? array_keys( self::part_navs( $main ) ) : [] as $nav_id ) {
				$add( $navs, $nav_id );
			}
		}

		foreach ( Block_Theme::get_brands() as $brand ) {
			foreach ( Block_Theme::SLOTS as $slot ) {
				$own  = self::brand_part_slug( $brand, $slot );
				$slug = $own ? $own : Block_Theme::main_part_slug( $slot );
				$add( $parts, $slug, $brand, [ 'area' => Block_Theme::slot_area( $slot ) ] );
				$part = Block_Theme::get_part( $slug );
				foreach ( $part ? array_keys( self::part_navs( $part ) ) : [] as $nav_id ) {
					// Menus in a brand's own part show as they are; in a main part, the brand's pick applies.
					$shown = $own ? 0 : self::brand_nav_id( $brand, $nav_id );
					$add( $navs, $shown ? $shown : $nav_id, $brand );
				}
			}
		}

		return [
			'navs'  => $navs,
			'parts' => $parts,
		];
	}
}
