<?php
/**
 * Newspack Multibranded site: block theme support.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Block_Theme;

use Newspack_Multibranded_Site\Taxonomy;

/**
 * Brand colors, menus and template parts for block themes, and the template part slots
 * brands choose parts for.
 *
 * A slot is a template part block in the theme's Header, Footer or sidebar template, marked
 * with a class (e.g. `header-desktop`). Brand choices are keyed by slot rather than by the
 * part's slug: Replace in the Site Editor changes the slug but keeps the class, so a brand's
 * choice still applies after the main part in that slot is swapped.
 */
class Block_Theme {

	/**
	 * Slots brands can choose a template part for.
	 */
	const SLOTS = [ 'header-desktop', 'header-mobile', 'footer-desktop', 'footer-mobile', 'sidebar' ];

	/**
	 * The template that holds the sidebar slot.
	 */
	const SIDEBAR_TEMPLATE = 'single/sidebar-layout';

	/**
	 * Initializes.
	 */
	public static function init() {
		add_action( 'after_setup_theme', [ __CLASS__, 'setup' ], 99 );
	}

	/**
	 * Hooks everything up once the theme is known.
	 */
	public static function setup() {
		if ( ! self::is_active() ) {
			return;
		}
		Colors::init();
		Layouts::init();
		Editor_Notices::init();
		Api::init();
	}

	/**
	 * Whether the active theme is a block theme.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
	}

	/**
	 * Labels for the slots.
	 *
	 * @return array Slot => label.
	 */
	public static function get_slot_labels() {
		return [
			'header-desktop' => __( 'Desktop header', 'newspack-multibranded-site' ),
			'header-mobile'  => __( 'Mobile header', 'newspack-multibranded-site' ),
			'footer-desktop' => __( 'Desktop footer', 'newspack-multibranded-site' ),
			'footer-mobile'  => __( 'Mobile footer', 'newspack-multibranded-site' ),
			'sidebar'        => __( 'Sidebar', 'newspack-multibranded-site' ),
		];
	}

	/**
	 * Current brand on the front end, if any.
	 *
	 * @return \WP_Term|null
	 */
	public static function current_brand() {
		if ( is_admin() ) {
			return null;
		}
		return Taxonomy::get_current();
	}

	/**
	 * All brands.
	 *
	 * @return \WP_Term[]
	 */
	public static function get_brands() {
		$brands = get_terms(
			[
				'taxonomy'   => Taxonomy::SLUG,
				'hide_empty' => false,
			]
		);
		return is_array( $brands ) ? $brands : [];
	}

	/**
	 * Brand name as plain text (term names are stored HTML-encoded, e.g. "&amp;").
	 *
	 * @param \WP_Term $brand Brand.
	 * @return string
	 */
	public static function brand_name( $brand ) {
		return wp_specialchars_decode( $brand->name, ENT_QUOTES );
	}

	/**
	 * Resolved (saved in the Site Editor, or the theme's file) template part.
	 *
	 * @param string $slug Part slug.
	 * @return \WP_Block_Template|null
	 */
	public static function get_part( $slug ) {
		return get_block_template( get_stylesheet() . '//' . $slug, 'wp_template_part' );
	}

	/**
	 * Template part area (header, footer or sidebar) of a slot.
	 *
	 * @param string $slot Slot.
	 * @return string
	 */
	public static function slot_area( $slot ) {
		if ( 0 === strpos( $slot, 'footer' ) ) {
			return 'footer';
		}
		return 'sidebar' === $slot ? 'sidebar' : 'header';
	}

	/**
	 * The slot a template part block fills, from its class name.
	 *
	 * @param array $attrs Template part block attributes.
	 * @return string Slot, or '' when the block isn't in one.
	 */
	public static function slot_of( $attrs ) {
		$classes = preg_split( '/\s+/', (string) ( $attrs['className'] ?? '' ) );
		$slots   = array_values( array_intersect( self::SLOTS, $classes ) );
		return $slots ? $slots[0] : '';
	}

	/**
	 * Slug of the part the theme's Header, Footer or sidebar template currently loads in a slot.
	 *
	 * @param string $slot Slot.
	 * @return string
	 */
	public static function main_part_slug( $slot ) {
		static $cache = [];
		if ( ! isset( $cache[ $slot ] ) ) {
			$cache[ $slot ] = 'sidebar' === $slot ? 'sidebar-contents' : $slot;
			$wrapper        = 'sidebar' === $slot
				? get_block_template( get_stylesheet() . '//' . self::SIDEBAR_TEMPLATE, 'wp_template' )
				: self::get_part( self::slot_area( $slot ) );
			if ( $wrapper ) {
				$found = self::find_slot_part( parse_blocks( $wrapper->content ), $slot );
				if ( $found ) {
					$cache[ $slot ] = $found;
				}
			}
		}
		return $cache[ $slot ];
	}

	/**
	 * Find the slug of the template part block filling a slot.
	 *
	 * @param array  $blocks Parsed blocks.
	 * @param string $slot   Slot.
	 * @return string
	 */
	public static function find_slot_part( $blocks, $slot ) {
		foreach ( $blocks as $block ) {
			if ( 'core/template-part' === ( $block['blockName'] ?? '' ) && self::slot_of( $block['attrs'] ) === $slot && ! empty( $block['attrs']['slug'] ) ) {
				return $block['attrs']['slug'];
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$found = self::find_slot_part( $block['innerBlocks'], $slot );
				if ( $found ) {
					return $found;
				}
			}
		}
		return '';
	}

	/**
	 * Whether a part is a wrapper: the theme's Header or Footer, which holds the responsive
	 * container and loads the desktop and mobile parts.
	 *
	 * @param \WP_Block_Template $part Template part.
	 * @return bool
	 */
	public static function is_wrapper( $part ) {
		return false !== strpos( $part->content, '/responsive-container' ) || false !== strpos( $part->content, '<!-- wp:template-part' );
	}

	/**
	 * Template parts a brand can choose for a slot in an area.
	 *
	 * Leaves out wrappers: they would nest the header inside itself.
	 *
	 * @param string $area Template part area.
	 * @return \WP_Block_Template[]
	 */
	public static function parts_in_area( $area ) {
		$parts = array_filter(
			get_block_templates( [ 'area' => $area ], 'wp_template_part' ),
			function ( $part ) {
				return ! self::is_wrapper( $part );
			}
		);
		usort(
			$parts,
			function ( $a, $b ) {
				return strcasecmp( $a->title, $b->title );
			}
		);
		return array_values( $parts );
	}

	/**
	 * Site Editor link.
	 *
	 * @param string     $type       wp_template_part or wp_navigation.
	 * @param string|int $slug_or_id Slug for parts, ID for menus.
	 * @return string
	 */
	public static function site_editor_url( $type, $slug_or_id ) {
		$path = 'wp_navigation' === $type ? '/wp_navigation/' . $slug_or_id : '/' . $type . '/' . get_stylesheet() . '//' . $slug_or_id;
		return add_query_arg(
			[
				'p'      => rawurlencode( $path ),
				'canvas' => 'edit',
			],
			admin_url( 'site-editor.php' )
		);
	}
}
