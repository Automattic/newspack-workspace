<?php
/**
 * Contextual Prompt block default styles.
 *
 * Publisher-set defaults for the Contextual Prompt block, edited in the
 * Contextual Prompts wizard on classic themes (block themes use Global Styles
 * directly). Stored as a block-supports-shaped object, rendered to CSS by the
 * style engine, and delivered at :root :where() specificity AFTER the block's
 * theme.json default design so it overrides the default while any per-block
 * style still wins.
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contextual Prompt styles class.
 */
final class Newspack_Popups_Contextual_Prompt_Styles {
	const OPTION_NAME = 'newspack_popups_contextual_prompt_styles';

	/**
	 * Leaf values: CSS-safe fragments only. Permits hex/named colors, units,
	 * var:preset|…|… refs and var() lookups; rejects anything that could close
	 * a declaration or rule.
	 */
	const VALUE_PATTERN = '/^[a-zA-Z0-9 #%().,\/|:_-]+$/';

	/**
	 * Register hooks. Classic themes only: on block themes Global Styles owns
	 * the block's styles and this class must stay inert.
	 */
	public static function init() {
		if ( wp_is_block_theme() ) {
			return;
		}
		// Same hook and priority as core's wp_enqueue_global_styles on classic
		// themes, registered later so it runs after: the inline CSS then lands
		// behind the block's default design in the same handle.
		add_action( 'wp_footer', [ __CLASS__, 'enqueue_front_end_styles' ], 1 );
		add_filter( 'block_editor_settings_all', [ __CLASS__, 'filter_block_editor_settings' ] );
	}

	/**
	 * The saved style overrides.
	 *
	 * @return array
	 */
	public static function get_styles() {
		$styles = get_option( self::OPTION_NAME, [] );
		return is_array( $styles ) ? $styles : [];
	}

	/**
	 * Sanitize and persist style overrides. An empty result removes the option.
	 *
	 * @param array $styles Block-supports-shaped style object.
	 */
	public static function save_styles( $styles ) {
		$sanitized = self::sanitize( (array) $styles );
		if ( empty( $sanitized ) ) {
			delete_option( self::OPTION_NAME );
			return;
		}
		update_option( self::OPTION_NAME, $sanitized );
	}

	/**
	 * Allowlist filter for the stored shape. Anything not explicitly allowed is
	 * dropped; any leaf failing the value pattern drops its whole branch.
	 *
	 * @param array $styles Raw style object.
	 * @return array Sanitized style object.
	 */
	public static function sanitize( $styles ) {
		$side_schema = [
			'color' => true,
			'width' => true,
			'style' => true,
		];
		$schema      = [
			'color'      => [
				'background' => true,
				'text'       => true,
			],
			'typography' => [ 'fontSize' => true ],
			'spacing'    => [
				'padding' => [
					'top'    => true,
					'right'  => true,
					'bottom' => true,
					'left'   => true,
				],
			],
			'border'     => array_merge(
				$side_schema,
				[
					'radius' => [
						'topLeft'     => true,
						'topRight'    => true,
						'bottomLeft'  => true,
						'bottomRight' => true,
					],
					'top'    => $side_schema,
					'right'  => $side_schema,
					'bottom' => $side_schema,
					'left'   => $side_schema,
				]
			),
		];

		return self::sanitize_node( $styles, $schema );
	}

	/**
	 * Recursively apply a schema node: keys must exist in the schema; leaves
	 * must be pattern-safe strings. A schema of `true` accepts a string leaf; a
	 * schema array accepts either a string leaf (border.radius shorthand) or a
	 * matching sub-object.
	 *
	 * @param mixed $node   Incoming value.
	 * @param mixed $schema Schema node.
	 * @return array Sanitized node (possibly empty).
	 */
	private static function sanitize_node( $node, $schema ) {
		$clean = [];
		if ( ! is_array( $node ) ) {
			return $clean;
		}
		foreach ( $node as $key => $value ) {
			if ( ! isset( $schema[ $key ] ) ) {
				continue;
			}
			if ( is_string( $value ) ) {
				if ( preg_match( self::VALUE_PATTERN, $value ) ) {
					$clean[ $key ] = $value;
				}
				continue;
			}
			if ( is_array( $schema[ $key ] ) && is_array( $value ) ) {
				$sub = self::sanitize_node( $value, $schema[ $key ] );
				if ( ! empty( $sub ) ) {
					$clean[ $key ] = $sub;
				}
			}
		}
		return $clean;
	}

	/**
	 * The overrides as a single CSS rule, or an empty string.
	 *
	 * @return string
	 */
	public static function get_css() {
		$styles = self::get_styles();
		if ( empty( $styles ) ) {
			return '';
		}
		$result = wp_style_engine_get_styles(
			$styles,
			[ 'selector' => ':root :where(.wp-block-newspack-popups-contextual-prompt)' ]
		);
		return isset( $result['css'] ) ? $result['css'] : '';
	}

	/**
	 * The block's effective default styles (theme.json cascade, presets
	 * resolved), for display in the wizard. Never includes this class's own
	 * overrides: they live in an option, not in theme.json data.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		$defaults = wp_get_global_styles(
			[ 'blocks', Newspack_Popups_Contextual_Prompt_Block::BLOCK_NAME ],
			[ 'transforms' => [ 'resolve-variables' ] ]
		);
		return is_array( $defaults ) ? $defaults : [];
	}

	/**
	 * Front end: append the CSS to the global-styles handle so it prints after
	 * the block's default design. Falls back to a standalone handle when core
	 * has not registered global-styles, or has already printed it — inline CSS
	 * added to a printed handle is silently dropped.
	 */
	public static function enqueue_front_end_styles() {
		$css = self::get_css();
		if ( '' === $css ) {
			return;
		}
		if ( wp_style_is( 'global-styles', 'enqueued' ) && ! wp_style_is( 'global-styles', 'done' ) ) {
			wp_add_inline_style( 'global-styles', $css );
			return;
		}
		$handle = 'newspack-popups-contextual-prompt-styles';
		wp_register_style( $handle, false ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Inline styles only, no source file to version.
		wp_enqueue_style( $handle );
		wp_add_inline_style( $handle, $css );
	}

	/**
	 * Editor: append the CSS to the canvas styles, after the entries core added
	 * from theme.json, so order decides at equal specificity.
	 *
	 * @param array $settings Block editor settings.
	 * @return array
	 */
	public static function filter_block_editor_settings( $settings ) {
		$css = self::get_css();
		if ( '' === $css ) {
			return $settings;
		}
		if ( ! isset( $settings['styles'] ) || ! is_array( $settings['styles'] ) ) {
			$settings['styles'] = [];
		}
		$settings['styles'][] = [ 'css' => $css ];
		return $settings;
	}
}
