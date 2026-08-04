<?php
/**
 * Contextual Prompt synced pattern.
 *
 * Owns the `wp_block` post every Contextual Prompt instance references: seeding
 * it on demand with a locked Group holding the bound copy paragraph and the CTA
 * for the site's donation platform, and the one slash-safe write helper every
 * later change to its markup goes through.
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contextual Prompt pattern class.
 */
final class Newspack_Popups_Contextual_Prompt_Pattern {
	const OPTION_PATTERN_ID     = 'newspack_contextual_prompts_pattern_id';
	const OPTION_STAMPED_ACCENT = 'newspack_contextual_prompts_stamped_accent';
	const MARKER_CLASS          = 'newspack-contextual-prompt';
	const BOUND_NAME            = 'Prompt copy';

	/**
	 * The pattern's own name, in its Group metadata. Not translated: it is stored
	 * in post content, where a locale switch must not rewrite it.
	 */
	const PATTERN_NAME = 'Contextual Prompt';

	/**
	 * Every block in the pattern is editable but fixed in place: instances are
	 * meant to differ by copy alone.
	 */
	const BLOCK_LOCK = [
		'move'   => true,
		'remove' => true,
	];

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'map_meta_cap', [ __CLASS__, 'protect_pattern' ], 10, 4 );
		add_filter( 'block_editor_settings_all', [ __CLASS__, 'lock_pattern_editor' ], 10, 2 );
	}

	/**
	 * Deny deleting the pattern: every instance references it, so losing it would
	 * empty them all. The raw option is what the guard compares against — a
	 * capability check must never seed.
	 *
	 * @param string[] $caps    Primitive capabilities required of the user.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User ID.
	 * @param array    $args    Context, with the object ID at index 0.
	 *
	 * @return string[]
	 */
	public static function protect_pattern( $caps, $cap, $user_id, $args ) {
		if ( 'delete_post' === $cap && ! empty( $args[0] ) && (int) $args[0] === (int) get_option( self::OPTION_PATTERN_ID, 0 ) ) {
			return [ 'do_not_allow' ];
		}

		return $caps;
	}

	/**
	 * Hide block locking in the editor that opens the pattern: its locks are what
	 * keep instances uniform, so they are not the publisher's to lift.
	 *
	 * @param array                   $settings Block editor settings.
	 * @param WP_Block_Editor_Context $context  Editor context.
	 *
	 * @return array
	 */
	public static function lock_pattern_editor( $settings, $context ) {
		if ( ! empty( $context->post ) && (int) $context->post->ID === (int) get_option( self::OPTION_PATTERN_ID, 0 ) ) {
			$settings['canLockBlocks'] = false;
		}

		return $settings;
	}

	/**
	 * The pattern post ID, seeding on demand. A record pointing at a missing or
	 * invalid post is re-seeded rather than left dangling.
	 *
	 * @return int Pattern post ID, or 0 when seeding failed.
	 */
	public static function get_pattern_id() {
		$id = (int) get_option( self::OPTION_PATTERN_ID, 0 );
		if ( $id && 'wp_block' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) {
			return $id;
		}

		$id = wp_insert_post(
			wp_slash(
				[
					'post_type'    => 'wp_block',
					'post_status'  => 'publish',
					'post_title'   => __( 'Contextual Prompt', 'newspack-popups' ),
					'post_content' => self::build_pattern_content(),
				]
			)
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}

		update_option( self::OPTION_PATTERN_ID, $id );

		return (int) $id;
	}

	/**
	 * Write markup to the pattern post. Every write goes through here: unslashing
	 * strips the escapes serialize_blocks() emits, so the content has to be
	 * slashed on the way in.
	 *
	 * @param int    $pattern_id Pattern post ID.
	 * @param string $content    Serialized block markup.
	 */
	public static function save_pattern_content( $pattern_id, $content ) {
		wp_update_post(
			wp_slash(
				[
					'ID'           => $pattern_id,
					'post_content' => $content,
				]
			)
		);
	}

	/**
	 * The pattern's serialized markup.
	 *
	 * @return string
	 */
	public static function build_pattern_content() {
		return serialize_blocks( [ self::build_group() ] );
	}

	/**
	 * The prompt card: a marker-classed Group that takes no further blocks,
	 * holding the bound copy paragraph and the CTA.
	 *
	 * @return array Parsed core/group block.
	 */
	private static function build_group() {
		$wrapper = '<div class="wp-block-group ' . self::MARKER_CLASS . ' has-background has-medium-font-size" style="border-radius:10px;background-color:#f7f7f7;padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)">';

		return [
			'blockName'    => 'core/group',
			'attrs'        => [
				'metadata'     => [ 'name' => self::PATTERN_NAME ],
				'className'    => self::MARKER_CLASS,
				'templateLock' => 'insert',
				'lock'         => self::BLOCK_LOCK,
				'style'        => [
					'color'   => [ 'background' => '#f7f7f7' ],
					'border'  => [ 'radius' => '10px' ],
					'spacing' => [
						'padding'  => [
							'top'    => 'var:preset|spacing|50',
							'right'  => 'var:preset|spacing|50',
							'bottom' => 'var:preset|spacing|50',
							'left'   => 'var:preset|spacing|50',
						],
						'blockGap' => 'var:preset|spacing|30',
					],
				],
				'fontSize'     => 'medium',
				'layout'       => [ 'type' => 'constrained' ],
			],
			'innerBlocks'  => [ self::build_copy_child(), self::build_cta_child() ],
			'innerHTML'    => $wrapper . '</div>',
			'innerContent' => [ "\n" . $wrapper, null, "\n\n", null, "</div>\n" ],
		];
	}

	/**
	 * The copy paragraph, bound to pattern overrides so each instance carries its
	 * own story-specific copy. Seeded empty: an instance nobody has written copy
	 * for renders nothing rather than placeholder text.
	 *
	 * @return array Parsed core/paragraph block.
	 */
	private static function build_copy_child() {
		return [
			'blockName'    => 'core/paragraph',
			'attrs'        => [
				'metadata' => [
					'name'     => self::BOUND_NAME,
					'bindings' => [ '__default' => [ 'source' => 'core/pattern-overrides' ] ],
				],
				'lock'     => self::BLOCK_LOCK,
			],
			'innerBlocks'  => [],
			'innerHTML'    => "\n<p></p>\n",
			'innerContent' => [ "\n<p></p>\n" ],
		];
	}

	/**
	 * The CTA for the site's donation platform.
	 *
	 * @return array Parsed CTA block.
	 */
	private static function build_cta_child() {
		return self::use_donate_block() ? self::build_donate_child() : self::build_buttons_child();
	}

	/**
	 * The native donate block, stamped with the theme's accent color. The stamp
	 * is recorded so a later restamp can tell the seeded color from one the
	 * publisher chose; with no accent to stamp, the record goes too.
	 *
	 * @return array Parsed newspack-blocks/donate block.
	 */
	private static function build_donate_child() {
		$attrs  = [ 'className' => 'is-style-modern' ];
		$accent = self::get_accent_color();
		if ( $accent ) {
			$attrs['buttonColor'] = $accent;
			update_option( self::OPTION_STAMPED_ACCENT, $accent );
		} else {
			delete_option( self::OPTION_STAMPED_ACCENT );
		}
		$attrs['lock'] = self::BLOCK_LOCK;

		return [
			'blockName'    => 'newspack-blocks/donate',
			'attrs'        => $attrs,
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];
	}

	/**
	 * A single link button to the donor landing page. Seeded without a
	 * destination when none is configured — core saves such a button href-less,
	 * and the render pipeline drops it rather than showing a dead ask.
	 *
	 * @return array Parsed core/buttons block.
	 */
	private static function build_buttons_child() {
		$url    = self::get_button_url();
		$href   = '' !== $url ? ' href="' . esc_url( $url ) . '"' : '';
		$anchor = '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"' . $href . '>' . esc_html( self::get_button_text() ) . '</a></div>';

		return [
			'blockName'    => 'core/buttons',
			'attrs'        => [ 'lock' => self::BLOCK_LOCK ],
			'innerBlocks'  => [
				[
					'blockName'    => 'core/button',
					'attrs'        => '' !== $url ? [ 'url' => $url ] : [],
					'innerBlocks'  => [],
					'innerHTML'    => "\n" . $anchor . "\n",
					'innerContent' => [ "\n" . $anchor . "\n" ],
				],
			],
			'innerHTML'    => '<div class="wp-block-buttons"></div>',
			'innerContent' => [ "\n" . '<div class="wp-block-buttons">', null, "</div>\n" ],
		];
	}

	/**
	 * Whether the CTA is the native Newspack donate block rather than a plain
	 * button. Defaults to true when the publisher uses Newspack (WooCommerce)
	 * donations — then reader conversions classify as donations in analytics /
	 * Insights. Falls back to a plain button for off-site donation setups, and
	 * whenever the donate block itself isn't registered (Newspack Blocks
	 * inactive) — an unregistered child would render nothing, losing the ask.
	 *
	 * @return bool
	 */
	public static function use_donate_block() {
		$default = method_exists( '\Newspack\Donations', 'is_platform_wc' ) && \Newspack\Donations::is_platform_wc();

		/**
		 * Filters whether Contextual Prompts render the native donate block.
		 *
		 * @param bool $use_donate_block Whether to use the donate block.
		 */
		$use_donate_block = (bool) apply_filters( 'newspack_contextual_prompts_use_donate_block', $default );

		return $use_donate_block && \WP_Block_Type_Registry::get_instance()->is_registered( 'newspack-blocks/donate' );
	}

	/**
	 * The theme's accent color: the "accent" palette color on block themes, the
	 * Newspack primary color on the classic theme.
	 *
	 * @return string|null Hex color, or null when none can be resolved.
	 */
	public static function get_accent_color() {
		$palette = wp_get_global_settings( [ 'color', 'palette' ] );
		foreach ( [ 'custom', 'theme' ] as $origin ) {
			foreach ( $palette[ $origin ] ?? [] as $color ) {
				if ( 'accent' === ( $color['slug'] ?? '' ) && ! empty( $color['color'] ) ) {
					return $color['color'];
				}
			}
		}

		if ( function_exists( 'Newspack\newspack_get_theme_colors' ) ) {
			$colors = \Newspack\newspack_get_theme_colors();
			if ( ! empty( $colors['primary_color'] ) ) {
				return $colors['primary_color'];
			}
		}

		return null;
	}

	/**
	 * The button CTA's destination.
	 *
	 * @return string
	 */
	public static function get_button_url() {
		return Newspack_Popups::get_donor_landing_url();
	}

	/**
	 * The button CTA's label.
	 *
	 * @return string
	 */
	public static function get_button_text() {
		return __( 'Donate', 'newspack-popups' );
	}
}
