<?php
/**
 * Republish Button Block.
 *
 * @package Republication_Tracker_Tool
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Republish Button Block class.
 */
final class Republication_Tracker_Tool_Republish_Button_Block {

	/**
	 * Initialize the block.
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_editor_data' ] );
	}

	/**
	 * Inject the current site's Creative Commons license data into the editor
	 * so the block preview can render the real badge image without a REST
	 * round-trip. Data is read on every page load — admin setting changes
	 * surface on the next editor refresh.
	 */
	public static function enqueue_editor_data(): void {
		$license_key = get_option( 'republication_tracker_tool_license', REPUBLICATION_TRACKER_TOOL_DEFAULT_LICENSE );

		$license = isset( REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ] )
			? [
				'url'         => REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ]['url'],
				'badge'       => REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ]['badge'],
				'description' => REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ]['description'],
			]
			: null;

		wp_add_inline_script(
			'republication-tracker-tool-republish-button-editor-script',
			'window.republicationTrackerToolEditor = ' . wp_json_encode( [ 'license' => $license ] ) . ';',
			'before'
		);
	}

	/**
	 * Register the block.
	 *
	 * On block themes the block is fully available. On classic themes it is
	 * still registered (so existing content does not become an "unknown block")
	 * but hidden from the inserter.
	 */
	public static function register_block(): void {
		// register_block_type_from_metadata() is only available on WP 5.5+.
		// Bail gracefully on older installs instead of fataling on init.
		if ( ! function_exists( 'register_block_type_from_metadata' ) ) {
			return;
		}

		$block_dir = REPUBLICATION_TRACKER_TOOL_PATH . 'src/blocks/republish-button';
		$args      = [
			'render_callback' => [ __CLASS__, 'render_block' ],
		];

		if ( function_exists( 'wp_is_block_theme' ) && ! wp_is_block_theme() ) {
			// Deep-merge into the declared supports rather than replacing them.
			// register_block_type_from_metadata() shallow-merges $args over the
			// block.json metadata, so a bare supports override would drop the
			// block's color/typography/spacing/border support and strip saved
			// styles from existing instances rendered on a classic theme.
			$metadata = wp_json_file_decode( $block_dir . '/block.json', [ 'associative' => true ] );
			$supports = ( is_array( $metadata ) && isset( $metadata['supports'] ) && is_array( $metadata['supports'] ) )
				? $metadata['supports']
				: [];

			$supports['inserter'] = false;
			$args['supports']     = $supports;
		}

		register_block_type_from_metadata( $block_dir, $args );
	}

	/**
	 * Render the block on the frontend.
	 *
	 * @param array $attrs Block attributes.
	 * @return string Rendered block HTML.
	 */
	public static function render_block( array $attrs ): string {
		global $post;

		// Guard: only render on singular views.
		if ( ! is_singular() || ! $post instanceof \WP_Post ) {
			return '';
		}

		// Guard: check allowed post types.
		$allowed_post_types = (array) apply_filters( 'republication_tracker_tool_post_types', [ 'post' ] );
		if ( ! in_array( get_post_type( $post ), $allowed_post_types, true ) ) {
			return '';
		}

		// Guard: check if widget is hidden for this post (same filter as the widget).
		$hide = apply_filters(
			'hide_republication_widget',
			get_post_meta( $post->ID, 'republication-tracker-tool-hide-widget', true ),
			$post
		);
		if ( true == $hide ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
			return '';
		}

		// Translated defaults (block.json defaults are not translatable).
		$default_attrs = [
			'buttonText'  => __( 'Republish This Story', 'republication-tracker-tool' ),
			'showLicense' => true,
		];
		$attrs = wp_parse_args( $attrs, $default_attrs );

		// Fall back to translated default when attribute is empty string.
		$button_text  = '' === trim( (string) $attrs['buttonText'] ) ? $default_attrs['buttonText'] : $attrs['buttonText'];
		$show_license = (bool) $attrs['showLicense'];

		// Block supports (color, typography, spacing, border, shadow) apply to the inner
		// <button> so theme button styles cascade via the standard core button classes.
		$button_attributes = get_block_wrapper_attributes(
			[
				'class'              => 'wp-block-button__link wp-element-button wp-block-republication-tracker-tool-republish-button',
				'data-modal-trigger' => 'republish',
			]
		);

		// Three-layer button markup matches core/button so theme styles apply.
		$html  = '<div class="wp-block-buttons is-layout-flex">';
		$html .= '<div class="wp-block-button">';
		$html .= '<button ' . $button_attributes . '>' . esc_html( $button_text ) . '</button>';
		$html .= '</div>';
		$html .= '</div>';

		// Optional Creative Commons license badge, sourced from the site setting.
		// Rendered as a sibling outside the buttons container so the flex layout
		// doesn't affect the image.
		if ( $show_license ) {
			$html .= self::render_license_badge();
		}

		// Modal markup — only rendered once per page across all block instances.
		self::enqueue_modal_assets();

		if ( ! Republication_Tracker_Tool::$modal_rendered ) {
			Republication_Tracker_Tool::$modal_rendered = true;

			$is_amp             = false; // Used by shareable-content.php; block themes do not support AMP.
			$modal_content_path = REPUBLICATION_TRACKER_TOOL_PATH . 'includes/shareable-content.php';

			ob_start();
			?>
			<div id="republication-tracker-tool-modal" style="display:none;" data-postid="<?php echo esc_attr( $post->ID ); ?>" data-pluginsdir="<?php echo esc_attr( plugins_url() ); ?>" role="dialog" aria-modal="true" aria-labelledby="republish-modal-label">
				<?php include $modal_content_path; ?>
			</div>
			<?php
			$html .= ob_get_clean();
		}

		return $html;
	}

	/**
	 * Render the Creative Commons license badge based on the site's current
	 * configured license. Returns an empty string when no recognizable license
	 * is set.
	 *
	 * @return string Rendered HTML or empty string.
	 */
	private static function render_license_badge(): string {
		$license_key = get_option( 'republication_tracker_tool_license', REPUBLICATION_TRACKER_TOOL_DEFAULT_LICENSE );

		if ( ! isset( REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ] ) ) {
			return '';
		}

		$license = REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ];

		return sprintf(
			'<div class="wp-block-republication-tracker-tool-republish-button__license"><a rel="noreferrer license" href="%1$s" target="_blank"><img alt="%2$s" style="border-width:0" src="%3$s" /></a></div>',
			esc_url( $license['url'] ),
			esc_attr( $license['description'] ),
			esc_url( $license['badge'] )
		);
	}

	/**
	 * Enqueue modal-specific assets.
	 */
	private static function enqueue_modal_assets(): void {
		// Modal styles (same handle as widget for deduplication).
		wp_enqueue_style(
			'republication-tracker-tool-css',
			REPUBLICATION_TRACKER_TOOL_URL . 'assets/widget.css',
			[],
			REPUBLICATION_TRACKER_TOOL_VERSION
		);

		// Clipboard utilities (shared with widget).
		wp_enqueue_script(
			'republication-tracker-tool-clipboard-utils',
			REPUBLICATION_TRACKER_TOOL_URL . 'assets/clipboard-utils.js',
			[],
			REPUBLICATION_TRACKER_TOOL_VERSION,
			true
		);

		// Block frontend script.
		$asset_file = REPUBLICATION_TRACKER_TOOL_PATH . 'dist/republish-button-view.asset.php';
		$asset      = file_exists( $asset_file )
			? include $asset_file
			: [
				'dependencies' => [],
				'version'      => REPUBLICATION_TRACKER_TOOL_VERSION,
			];

		wp_enqueue_script(
			'republication-tracker-tool-republish-button-view',
			REPUBLICATION_TRACKER_TOOL_URL . 'dist/republish-button-view.js',
			array_merge( $asset['dependencies'], [ 'republication-tracker-tool-clipboard-utils' ] ),
			$asset['version'],
			true
		);
	}
}

Republication_Tracker_Tool_Republish_Button_Block::init();
