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
	const SEEDING_LOCK_OPTION   = 'newspack_contextual_prompts_seeding';
	const SEEDING_LOCK_TTL      = 30;

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
	 * Register the hooks that keep the pattern out of reach. Registered whether
	 * or not the feature is on: rolling it back must not expose the pattern to
	 * deletion, since deleting it would empty every instance a re-enabled site
	 * still carries. Each callback reads the record raw and no-ops without one,
	 * so a site that never seeded a pattern is unaffected.
	 */
	public static function init_protection() {
		add_filter( 'map_meta_cap', [ __CLASS__, 'protect_pattern' ], 10, 4 );
		add_filter( 'block_editor_settings_all', [ __CLASS__, 'lock_pattern_editor' ], 10, 2 );
		add_filter( 'rest_wp_block_query', [ __CLASS__, 'hide_pattern_from_collections' ] );
	}

	/**
	 * Register hooks.
	 */
	public static function init() {
		// \Newspack\Donations may not be loaded when hooks register, so the option
		// name it owns is spelled out rather than read off the class. Configuring
		// the platform for the first time adds the option rather than updating
		// one, so both hooks are needed.
		add_action( 'update_option_newspack_reader_revenue_platform', [ __CLASS__, 'repair' ] );
		add_action( 'add_option_newspack_reader_revenue_platform', [ __CLASS__, 'repair' ] );
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
	 * Keep the pattern out of the patterns browser and the inserter: a post takes
	 * one prompt, placed from the Contextual Prompt panel, so the pattern is not a
	 * thing to insert by hand. Only collections are filtered — the single-item
	 * route is how an instance resolves its content, and how the editor opens the
	 * pattern to edit its design.
	 *
	 * @param array $args Query arguments for the collection.
	 *
	 * @return array
	 */
	public static function hide_pattern_from_collections( $args ) {
		$pattern_id = (int) get_option( self::OPTION_PATTERN_ID, 0 );
		if ( ! $pattern_id ) {
			return $args;
		}

		$exclude   = isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : [];
		$exclude[] = $pattern_id;

		// One id, on a collection only the editor requests: the VIP caution is about
		// exclusion sets large enough to defeat the index.
		$args['post__not_in'] = $exclude; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in

		return $args;
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
	 * The pattern post ID, seeding on demand. A record pointing at a post that is
	 * merely unpublished — trashed, drafted — is restored in place: every instance
	 * references it by id, so a replacement would empty them all. Only a record
	 * pointing at nothing is re-seeded.
	 *
	 * @return int Pattern post ID, or 0 when seeding failed.
	 */
	public static function get_pattern_id() {
		$id = (int) get_option( self::OPTION_PATTERN_ID, 0 );
		if ( $id && 'wp_block' === get_post_type( $id ) ) {
			if ( 'publish' !== get_post_status( $id ) ) {
				self::restore_pattern( $id );
			}
			return $id;
		}

		// Hold a lock while inserting, so two concurrent first calls can't both seed
		// a pattern. A caller that loses the claim yields rather than seeding a
		// second one: instances made against it would never be managed again.
		if ( ! self::claim_seeding_lock() ) {
			return (int) get_option( self::OPTION_PATTERN_ID, 0 );
		}

		try {
			$new_id = wp_insert_post(
				wp_slash(
					[
						'post_type'    => 'wp_block',
						'post_status'  => 'publish',
						'post_title'   => __( 'Contextual Prompt', 'newspack-popups' ),
						'post_content' => self::build_pattern_content(),
					]
				)
			);
			if ( is_wp_error( $new_id ) || ! $new_id ) {
				return 0;
			}

			return self::finish_seed( (int) $new_id );
		} finally {
			delete_option( self::SEEDING_LOCK_OPTION );
		}
	}

	/**
	 * Claim the right to seed. add_option() is the atomic INSERT: a check
	 * followed by a write would let two concurrent first loads both pass. The
	 * claim is timestamped rather than expiring on its own, so a request that
	 * died mid-seed blocks seeding for seconds rather than for good.
	 *
	 * @return bool Whether this caller may seed.
	 */
	private static function claim_seeding_lock() {
		if ( add_option( self::SEEDING_LOCK_OPTION, time(), '', false ) ) {
			return true;
		}

		$claimed = (int) get_option( self::SEEDING_LOCK_OPTION, 0 );
		if ( $claimed && time() - $claimed < self::SEEDING_LOCK_TTL ) {
			return false;
		}

		delete_option( self::SEEDING_LOCK_OPTION );

		return (bool) add_option( self::SEEDING_LOCK_OPTION, time(), '', false );
	}

	/**
	 * Record the pattern just inserted, unless another request recorded a live one
	 * while it was being written. The record is what every instance is made
	 * against, so a losing seeder adopts that pattern and drops its own: keeping
	 * it would leave a pattern nothing manages, and instances nothing can repair.
	 * A record pointing at nothing is the stale one this call is replacing.
	 *
	 * The delete guard denies the recorded id only, so the orphan is deletable —
	 * and wp_delete_post() checks no capability in any case.
	 *
	 * @param int $new_id The inserted pattern post ID.
	 *
	 * @return int The pattern ID to use.
	 */
	private static function finish_seed( $new_id ) {
		$recorded = (int) get_option( self::OPTION_PATTERN_ID, 0 );
		if ( $recorded && $recorded !== $new_id && 'wp_block' === get_post_type( $recorded ) ) {
			wp_delete_post( $new_id, true );
			return $recorded;
		}

		update_option( self::OPTION_PATTERN_ID, $new_id );

		return $new_id;
	}

	/**
	 * Republish the pattern rather than seeding a replacement. wp_untrash_post()
	 * restores to draft, so the publish follows it.
	 *
	 * @param int $pattern_id Pattern post ID.
	 */
	private static function restore_pattern( $pattern_id ) {
		if ( 'trash' === get_post_status( $pattern_id ) ) {
			wp_untrash_post( $pattern_id );
		}

		if ( 'publish' !== get_post_status( $pattern_id ) ) {
			self::update_pattern_post(
				[
					'ID'          => $pattern_id,
					'post_status' => 'publish',
				]
			);
		}
	}

	/**
	 * Write markup to the pattern post. Every write goes through here: unslashing
	 * strips the escapes serialize_blocks() emits, so the content has to be
	 * slashed on the way in.
	 *
	 * @param int    $pattern_id Pattern post ID.
	 * @param string $content    Serialized block markup.
	 *
	 * @return bool Whether the write landed.
	 */
	public static function save_pattern_content( $pattern_id, $content ) {
		return self::update_pattern_post(
			[
				'ID'           => $pattern_id,
				'post_content' => $content,
			]
		);
	}

	/**
	 * Update the pattern post with KSES suspended. The markup is programmatic,
	 * parsed from what is already stored, and a write can land in a context with
	 * no unfiltered_html — where filtering would mangle the publisher's own
	 * content and repair would then stabilize on the mangled copy.
	 *
	 * @param array $postarr Post data, unslashed.
	 *
	 * @return bool Whether the write landed. Callers record state describing the
	 *              stored pattern, which a failed write leaves untouched.
	 */
	private static function update_pattern_post( $postarr ) {
		// Restored rather than re-initialized unconditionally: a context that never
		// had the filters must not come out of this with them installed.
		$filtered = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $filtered ) {
			kses_remove_filters();
		}

		try {
			$result = wp_update_post( wp_slash( $postarr ) );
		} finally {
			if ( $filtered ) {
				kses_init_filters();
			}
		}

		return ! is_wp_error( $result ) && (bool) $result;
	}

	/**
	 * Reconcile the stored pattern with the site: its CTA with the current
	 * donation platform, its donate color with the theme's accent, the name its
	 * copy paragraph is bound under with the one instances key their overrides by.
	 * Runs when the platform changes and, defensively, on the first instance of a
	 * request — a pattern the editor opens has to show what readers actually see.
	 *
	 * The record is read raw and never seeded: a hook must not create the
	 * pattern.
	 */
	public static function repair() {
		$pattern_id = (int) get_option( self::OPTION_PATTERN_ID, 0 );
		if ( ! $pattern_id || 'wp_block' !== get_post_type( $pattern_id ) ) {
			return;
		}

		// The site wants the native CTA but the block is gone (Newspack Blocks
		// deactivated). Persisting that fallback would discard the publisher's
		// donate configuration for good; the render path still stands in.
		if ( self::wants_donate_block() && ! self::use_donate_block() ) {
			return;
		}

		$post   = get_post( $pattern_id );
		$blocks = parse_blocks( $post->post_content );
		$stamp  = null;

		foreach ( $blocks as $index => $group ) {
			if (
				'core/group' !== ( $group['blockName'] ?? '' )
				|| false === strpos( (string) ( $group['attrs']['className'] ?? '' ), self::MARKER_CLASS )
			) {
				continue;
			}

			// Written back on its own, because the CTA branches below can bail out of
			// persisting the group.
			if ( self::repin_bound_name( $group['innerBlocks'] ) ) {
				$blocks[ $index ] = $group;
			}

			// Before normalization, which can replace the child the record
			// describes.
			$restamped = self::maybe_restamp_accent( $group );

			$before = Newspack_Popups_Contextual_Prompt_Render::find_cta( $group );
			$group  = Newspack_Popups_Contextual_Prompt_Render::normalize_cta( $group );
			$after  = Newspack_Popups_Contextual_Prompt_Render::find_cta( $group );

			// Nothing is configured to point a CTA at, so normalization dropped it.
			// Persisting that fallback would discard the publisher's CTA for good —
			// the pattern takes no inserts, so nothing could put one back; the
			// render path still stands in.
			if ( null !== $before && null === $after ) {
				continue;
			}

			$was_donate = 'newspack-blocks/donate' === ( $before['name'] ?? '' );
			$is_donate  = 'newspack-blocks/donate' === ( $after['name'] ?? '' );
			if ( $was_donate !== $is_donate ) {
				$stamp = $is_donate ? (string) ( $group['innerBlocks'][ $after['index'] ]['attrs']['buttonColor'] ?? '' ) : '';
			} elseif ( null !== $restamped ) {
				$stamp = $restamped;
			}

			$blocks[ $index ] = $group;
		}

		$content = serialize_blocks( $blocks );
		if ( $content === $post->post_content ) {
			return;
		}

		// The record describes the stored pattern's donate child, so it is only
		// truthful once that pattern has actually been written.
		if ( self::save_pattern_content( $pattern_id, $content ) && null !== $stamp ) {
			self::record_stamp( $stamp );
		}
	}

	/**
	 * Hold the pattern to exactly one bound field: the copy paragraph. Its name is
	 * the key every instance's copy is stored under, not a label — renaming it in
	 * the pattern editor would orphan the copy of every prompt already written, so
	 * the seeded name is pinned back. Overrides enabled on any other child would
	 * share that one key, so their bindings are dropped.
	 *
	 * @param array $blocks Parsed blocks, mutated in place.
	 * @return bool Whether anything changed.
	 */
	private static function repin_bound_name( &$blocks ) {
		$changed = false;

		foreach ( $blocks as $index => $block ) {
			$bound = false;
			foreach ( $block['attrs']['metadata']['bindings'] ?? [] as $binding ) {
				if ( 'core/pattern-overrides' === ( $binding['source'] ?? '' ) ) {
					$bound = true;
					break;
				}
			}

			if ( $bound && 'core/paragraph' === ( $block['blockName'] ?? '' ) ) {
				if ( self::BOUND_NAME !== ( $block['attrs']['metadata']['name'] ?? '' ) ) {
					$blocks[ $index ]['attrs']['metadata']['name'] = self::BOUND_NAME;
					$changed = true;
				}
			} elseif ( $bound ) {
				self::strip_overrides_binding( $blocks[ $index ] );
				$changed = true;
			}

			if ( ! empty( $block['innerBlocks'] ) && self::repin_bound_name( $blocks[ $index ]['innerBlocks'] ) ) {
				$changed = true;
			}
		}

		return $changed;
	}

	/**
	 * Drop a block's pattern-overrides binding, and the name that keyed it once
	 * nothing else binds by it. Bindings from other sources are the publisher's.
	 *
	 * @param array $block Parsed block, mutated in place.
	 */
	private static function strip_overrides_binding( &$block ) {
		$bindings = $block['attrs']['metadata']['bindings'];
		foreach ( $bindings as $key => $binding ) {
			if ( 'core/pattern-overrides' === ( $binding['source'] ?? '' ) ) {
				unset( $bindings[ $key ] );
			}
		}

		if ( ! empty( $bindings ) ) {
			$block['attrs']['metadata']['bindings'] = $bindings;
			return;
		}

		unset( $block['attrs']['metadata']['bindings'], $block['attrs']['metadata']['name'] );
		if ( empty( $block['attrs']['metadata'] ) ) {
			unset( $block['attrs']['metadata'] );
		}
	}

	/**
	 * Follow the theme's accent color, but only on a donate child still carrying
	 * the color the seed stamped: anything else is the publisher's own choice.
	 * With no record — a site seeded off-site, or before the record existed —
	 * seeded and chosen colors are indistinguishable, so nothing is touched.
	 *
	 * The record is not written here: it describes the stored pattern, so only a
	 * caller that goes on to store the mutated group may move it.
	 *
	 * @param array $group Parsed prompt card, mutated in place.
	 * @return string|null The color stamped, or null when nothing was restamped.
	 */
	public static function maybe_restamp_accent( &$group ) {
		$recorded = (string) get_option( self::OPTION_STAMPED_ACCENT, '' );
		if ( '' === $recorded || ! self::use_donate_block() ) {
			return null;
		}

		$accent = self::get_accent_color();
		if ( ! $accent || $accent === $recorded ) {
			return null;
		}

		foreach ( $group['innerBlocks'] ?? [] as $index => $child ) {
			if ( 'newspack-blocks/donate' !== ( $child['blockName'] ?? '' ) || $recorded !== ( $child['attrs']['buttonColor'] ?? '' ) ) {
				continue;
			}
			$group['innerBlocks'][ $index ]['attrs']['buttonColor'] = $accent;
			return $accent;
		}

		return null;
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
		$text_color = self::get_text_color_slug();
		$font_size  = self::get_font_size_slug();
		$classes    = 'wp-block-group ' . self::MARKER_CLASS . ' has-text-color has-' . $text_color . '-color has-background has-' . $font_size . '-font-size';
		$wrapper    = '<div class="' . $classes . '" style="border-radius:10px;background-color:#f7f7f7;padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)">';

		return [
			'blockName'    => 'core/group',
			'attrs'        => [
				'metadata'     => [ 'name' => self::PATTERN_NAME ],
				'className'    => self::MARKER_CLASS,
				'templateLock' => 'insert',
				'lock'         => self::BLOCK_LOCK,
				'textColor'    => $text_color,
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
				'fontSize'     => $font_size,
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
	 * for renders nothing rather than placeholder text. The placeholder is editor
	 * chrome — core never renders it — so it says what the empty block is for
	 * without putting words in front of readers.
	 *
	 * @return array Parsed core/paragraph block.
	 */
	private static function build_copy_child() {
		return [
			'blockName'    => 'core/paragraph',
			'attrs'        => [
				'metadata'    => [
					'name'     => self::BOUND_NAME,
					'bindings' => [ '__default' => [ 'source' => 'core/pattern-overrides' ] ],
				],
				'lock'        => self::BLOCK_LOCK,
				'placeholder' => __( 'Copy is generated for each story.', 'newspack-popups' ),
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
	 * The native donate block, stamped with the theme's accent color.
	 *
	 * Only the callers that persist the result record the stamp. A render-time
	 * rebuild is thrown away when the request ends, and recording it would leave
	 * the record describing a child no stored pattern carries — which the restamp
	 * would then read as a color the publisher chose, and never touch again.
	 *
	 * @param bool $record Whether to record the stamp.
	 *
	 * @return array Parsed newspack-blocks/donate block.
	 */
	public static function build_donate_child( $record = true ) {
		$attrs  = [ 'className' => 'is-style-modern' ];
		$accent = self::get_accent_color();
		if ( $accent ) {
			$attrs['buttonColor'] = $accent;
		}
		$attrs['lock'] = self::BLOCK_LOCK;

		if ( $record ) {
			self::record_stamp( (string) $accent );
		}

		return [
			'blockName'    => 'newspack-blocks/donate',
			'attrs'        => $attrs,
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];
	}

	/**
	 * Record the color the stored pattern's donate child was stamped with, so a
	 * later restamp can tell it from one the publisher chose. Nothing stamped
	 * means nothing to record.
	 *
	 * @param string $color Hex color, or an empty string.
	 */
	private static function record_stamp( $color ) {
		if ( '' === $color ) {
			delete_option( self::OPTION_STAMPED_ACCENT );
			return;
		}

		update_option( self::OPTION_STAMPED_ACCENT, $color );
	}

	/**
	 * A single link button to the donor landing page. Seeded without a
	 * destination when none is configured — core saves such a button href-less,
	 * and the render pipeline drops it rather than showing a dead ask.
	 *
	 * The site-wide override passes its own destination and label; everything
	 * else takes the donation settings.
	 *
	 * @param string|null $url  Button destination, or null for the configured one.
	 * @param string|null $text Button label, unescaped, or null for the default.
	 *
	 * @return array Parsed core/buttons block.
	 */
	public static function build_buttons_child( $url = null, $text = null ) {
		$url    = null === $url ? self::get_button_url() : (string) $url;
		$text   = null === $text ? self::get_button_text() : (string) $text;
		$href   = '' !== $url ? ' href="' . esc_url( $url ) . '"' : '';
		$anchor = '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"' . $href . '>' . esc_html( $text ) . '</a></div>';

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
		return self::wants_donate_block() && \WP_Block_Type_Registry::get_instance()->is_registered( 'newspack-blocks/donate' );
	}

	/**
	 * Whether the site's donation settings call for the native CTA, before asking
	 * whether the block is there to render it. The two differ exactly while
	 * Newspack Blocks is inactive, which is a reason to fall back for one render
	 * — not to rewrite what the publisher configured.
	 *
	 * The class guard is method_exists(), which is false for a class that isn't
	 * loaded — and \Newspack\Donations may well not be.
	 *
	 * @return bool
	 */
	public static function wants_donate_block() {
		$default = method_exists( '\Newspack\Donations', 'is_platform_wc' ) && \Newspack\Donations::is_platform_wc();

		/**
		 * Filters whether Contextual Prompts render the native donate block.
		 *
		 * @param bool $use_donate_block Whether to use the donate block.
		 */
		return (bool) apply_filters( 'newspack_contextual_prompts_use_donate_block', $default );
	}

	/**
	 * The palette slug the card's text is seeded with. The two theme families name
	 * their body-text color differently, and a slug the active palette does not
	 * declare would leave the editor showing a color it cannot resolve.
	 *
	 * @return string Palette color slug.
	 */
	public static function get_text_color_slug() {
		return wp_is_block_theme() ? 'contrast' : 'dark-gray';
	}

	/**
	 * The typography preset the card's text is seeded with. Both families offer an
	 * "M" step, under different slugs — the classic theme declares no `medium`, and
	 * a slug it does not declare would leave the size control empty with no CSS
	 * behind the class.
	 *
	 * @return string Font size slug.
	 */
	public static function get_font_size_slug() {
		return wp_is_block_theme() ? 'medium' : 'normal';
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
