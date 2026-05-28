<?php
/**
 * Content Gate Layout - Shared layout meta registration for content gates.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Trait for content gate layout functionality.
 *
 * Handles registration of layout-related meta fields and rendering logic that are shared
 * between Content_Gate and Memberships gate implementations.
 */
trait Content_Gate_Layout {

	/**
	 * Get the meta fields configuration for content gate layouts.
	 *
	 * @return array Associative array of meta field configurations.
	 */
	protected static function get_layout_meta_config() {
		return [
			'style'              => [
				'type'    => 'string',
				'default' => 'inline',
			],
			'inline_fade'        => [
				'type'    => 'boolean',
				'default' => true,
			],
			'use_more_tag'       => [
				'type'    => 'boolean',
				'default' => true,
			],
			'visible_paragraphs' => [
				'type'    => 'integer',
				'default' => 2,
			],
			'overlay_position'   => [
				'type'    => 'string',
				'default' => 'center',
			],
			'overlay_size'       => [
				'type'    => 'string',
				'default' => 'medium',
			],
		];
	}

	/**
	 * Get the default value for a layout meta field.
	 *
	 * @param string $key The meta field key.
	 *
	 * @return mixed The default value, or null if not found.
	 */
	protected static function get_layout_meta_default( $key ) {
		$config = self::get_layout_meta_config();
		return $config[ $key ]['default'] ?? null;
	}

	/**
	 * Register layout meta fields for a given post type.
	 *
	 * @param string $post_type The post type to register meta for.
	 */
	protected static function register_layout_meta( $post_type ) {
		$meta = self::get_layout_meta_config();

		foreach ( $meta as $key => $config ) {
			\register_meta(
				'post',
				$key,
				[
					'object_subtype' => $post_type,
					'show_in_rest'   => $config['show_in_rest'] ?? true,
					'type'           => $config['type'],
					'default'        => $config['default'],
					'single'         => true,
				]
			);
		}
	}

	/**
	 * Register a gate custom post type with common configuration.
	 *
	 * @param string $post_type    The post type slug.
	 * @param string $label        The singular label for the post type.
	 * @param string $label_plural Optional plural label. Defaults to singular + 's'.
	 */
	public static function register_layout_post_type( $post_type, $label, $label_plural = '' ) {
		if ( empty( $label_plural ) ) {
			$label_plural = $label . 's';
		}

		\register_post_type(
			$post_type,
			[
				'label'        => $label,
				'labels'       => [
					// Translators: %s is the gate label.
					'item_published'         => sprintf( __( '%s published.', 'newspack' ), $label ),
					// Translators: %s is the gate label.
					'item_reverted_to_draft' => sprintf( __( '%s reverted to draft.', 'newspack' ), $label ),
					// Translators: %s is the gate label.
					'item_updated'           => sprintf( __( '%s updated.', 'newspack' ), $label ),
					// Translators: %s is the gate label.
					'new_item'               => sprintf( __( 'New %s', 'newspack' ), $label ),
					// Translators: %s is the gate label.
					'edit_item'              => sprintf( __( 'Edit %s', 'newspack' ), $label ),
					// Translators: %s is the gate label.
					'view_item'              => sprintf( __( 'View %s', 'newspack' ), $label ),
				],
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => false,
				'show_in_rest' => true,
				'supports'     => [ 'editor', 'custom-fields', 'revisions', 'title' ],
			]
		);

		self::register_layout_meta( $post_type );

		add_action(
			'enqueue_block_editor_assets',
			function() use ( $post_type ) {
				self::enqueue_block_editor_layout_assets( $post_type );
			}
		);
	}

	/**
	 * Enqueue block editor assets.
	 *
	 * @param string $post_type The post type to enqueue assets for.
	 */
	protected static function enqueue_block_editor_layout_assets( $post_type ) {
		if ( $post_type !== get_post_type() ) {
			return;
		}
		$asset = require dirname( NEWSPACK_PLUGIN_FILE ) . '/dist/content-gate-editor.asset.php';
		wp_enqueue_script( 'newspack-content-gate', Newspack::plugin_url() . '/dist/content-gate-editor.js', $asset['dependencies'], $asset['version'], true );
		wp_localize_script( 'newspack-content-gate', 'newspack_content_gate', [ 'has_campaigns' => class_exists( 'Newspack_Popups' ) ] );
		wp_enqueue_style( 'newspack-content-gate', Newspack::plugin_url() . '/dist/content-gate-editor.css', [], $asset['version'] );
	}

	/**
	 * Check if the current theme is a block theme.
	 *
	 * @return boolean True if the current theme is a block theme.
	 */
	private static function is_block_theme() {
		return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
	}


	/**
	 * Get the number of visible paragraphs for the gate.
	 *
	 * @param int $gate_post_id Gate post ID.
	 *
	 * @return int
	 */
	protected static function get_visible_paragraphs( $gate_post_id ) {
		$visible_paragraphs = \get_post_meta( $gate_post_id, 'visible_paragraphs', true );
		return '' === $visible_paragraphs ? self::get_layout_meta_default( 'visible_paragraphs' ) : max( 0, (int) $visible_paragraphs );
	}

	/**
	 * Get the default gate content.
	 *
	 * @return string
	 */
	protected static function get_default_gate_content() {
		return '<!-- wp:paragraph --><p>' . __( 'This post is only available to members.', 'newspack' ) . '</p><!-- /wp:paragraph -->';
	}

	/**
	 * Get the inline gate content with fade effect.
	 *
	 * @param int $gate_layout_id The gate layout ID.
	 *
	 * @return string The inline gate HTML content.
	 */
	public static function get_inline_gate_content_for_post( $gate_layout_id ) {
		$gate_layout_post = \get_post( $gate_layout_id );

		// Get style, defaulting if post doesn't exist or meta is not set.
		$style = $gate_layout_post ? \get_post_meta( $gate_layout_id, 'style', true ) : '';
		if ( empty( $style ) ) {
			$style = self::get_layout_meta_default( 'style' );
		}

		if ( 'inline' !== $style ) {
			return '';
		}

		// Build gate content.
		$gate_content = '<div style=\'content:"";clear:both;display:table;\'></div>';
		if ( $gate_layout_post ) {
			$gate_content        .= \get_the_content( null, false, $gate_layout_post );
			$visible_paragraphs   = self::get_visible_paragraphs( $gate_layout_id );
			$inline_fade          = \get_post_meta( $gate_layout_id, 'inline_fade', true );
		} else {
			// Use defaults when layout post doesn't exist.
			$gate_content       .= self::get_default_gate_content();
			$visible_paragraphs  = self::get_layout_meta_default( 'visible_paragraphs' );
			$inline_fade         = self::get_layout_meta_default( 'inline_fade' );
		}

		// Apply inline fade.
		if ( $visible_paragraphs > 0 && $inline_fade ) {
			$gate_content = '<div style="pointer-events: none; height: 10em; margin-top: -10em; width: 100%; position: absolute; background: linear-gradient(180deg, rgba(255,255,255,0) 14%, rgba(255,255,255,1) 76%); max-width: 100%;"></div>' . $gate_content;
		}

		$gate_content_classes = [ 'newspack-content-gate__gate', 'newspack-content-gate__inline-gate' ];
		// Add a class if the current theme is a block theme.
		if ( self::is_block_theme() ) {
			$gate_content_classes[] = 'is-layout-constrained';
		}

		// Wrap gate in a div for styling.
		$gate_content = '<div class="' . esc_attr( implode( ' ', $gate_content_classes ) ) . '">' . $gate_content . '</div>';
		return $gate_content;
	}

	/**
	 * Get the restricted post excerpt based on gate settings.
	 *
	 * @param \WP_Post $post         The post object to get excerpt from.
	 * @param int      $gate_layout_id The gate layout ID.
	 *
	 * @return string The restricted post excerpt HTML.
	 */
	public static function get_restricted_post_excerpt_for_gate( $post, $gate_layout_id ) {
		$content          = $post->post_content;
		$gate_layout_post = \get_post( $gate_layout_id );

		// Get settings from layout post, or use defaults if post doesn't exist.
		if ( $gate_layout_post ) {
			$style        = \get_post_meta( $gate_layout_id, 'style', true );
			$use_more_tag = \get_post_meta( $gate_layout_id, 'use_more_tag', true );
			$count        = self::get_visible_paragraphs( $gate_layout_id );
		} else {
			$style        = '';
			$use_more_tag = self::get_layout_meta_default( 'use_more_tag' );
			$count        = self::get_layout_meta_default( 'visible_paragraphs' );
		}

		// Default to configured style if not set.
		if ( empty( $style ) ) {
			$style = self::get_layout_meta_default( 'style' );
		}

		// Use <!--more--> as threshold if it exists.
		if ( $use_more_tag && strpos( $content, '<!--more-->' ) ) {
			$content = apply_filters( 'newspack_gate_content', explode( '<!--more-->', $content )[0] );
		} else {
			if ( 0 === $count ) {
				return '';
			}

			$content = apply_filters( 'newspack_gate_content', $content );
			// Split into paragraphs.
			$content = explode( '</p>', $content );
			// Extract the first $x paragraphs only.
			$content = array_slice( $content, 0, $count );
			if ( 'overlay' === $style ) {
				// Append ellipsis to the last paragraph.
				$content[ count( $content ) - 1 ] .= ' [&hellip;]';
			}
			// Rejoin the paragraphs into a single string again.
			$content = \force_balance_tags( \wp_kses_post( implode( '</p>', $content ) . '</p>' ) );
		}
		return $content;
	}

	/**
	 * Get the inline gate content.
	 */
	public static function get_inline_gate_content() {
		return self::get_inline_gate_content_for_post( self::get_gate_layout_id() );
	}

	/**
	 * Get the inline gate HTML for rendering.
	 *
	 * @return string
	 */
	public static function get_inline_gate_html() {
		return apply_filters( 'newspack_gate_content', self::get_inline_gate_content() );
	}

	/**
	 * Render the overlay gate HTML.
	 *
	 * @param int $gate_post_id The gate post ID.
	 */
	public static function render_overlay_gate_html( $gate_post_id ) {
		$position = \get_post_meta( $gate_post_id, 'overlay_position', true );
		$size     = \get_post_meta( $gate_post_id, 'overlay_size', true );
		?>
		<div class="newspack-content-gate__gate newspack-content-gate__overlay-gate" style="display:none;" data-position="<?php echo \esc_attr( $position ); ?>" data-size="<?php echo \esc_attr( $size ); ?>">
			<div class="newspack-content-gate__overlay-gate__container">
				<div class="newspack-content-gate__overlay-gate__content">
					<?php echo \apply_filters( 'newspack_gate_content', \get_the_content( null, null, $gate_post_id ) );  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</div>
		</div>
		<?php
	}
}
