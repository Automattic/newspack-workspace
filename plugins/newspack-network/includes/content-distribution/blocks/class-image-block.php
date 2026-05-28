<?php
/**
 * Content Distribution Custom Handling for the Image block
 *
 * @package Newspack_Network
 */

namespace Newspack_Network\Content_Distribution;

use Newspack_Network\Content_Distribution as Content_Distribution_Class;

/**
 * Image block class.
 */
class Image_Block {
	/**
	 * Post payload to be used for the lightbox rendering.
	 *
	 * @var array|null
	 */
	private static $post_payload = null;

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'the_post', [ __CLASS__, 'hook_incoming_post_filters' ] );
	}

	/**
	 * Hook the custom lightbox rendering strategy if this is an incoming post.
	 *
	 * @param \WP_Post $post The post object.
	 *
	 * @return void
	 */
	public static function hook_incoming_post_filters( $post ) {
		if ( Content_Distribution_Class::is_post_incoming( $post ) ) {
			try {
				$incoming_post = new Incoming_Post( $post->ID );
				self::$post_payload = $incoming_post->get_post_payload();
			} catch ( \InvalidArgumentException $e ) {
				// Treat an invalid incoming post as "not incoming": clear state and filters.
				remove_filter( 'render_block_core/image', [ __CLASS__, 'render_lightbox' ], 16 );
				remove_filter( 'the_content', [ __CLASS__, 'filter_content_image_attributes' ], PHP_INT_MAX );
				self::$post_payload = null;
				return;
			}

			add_filter( 'render_block_core/image', [ __CLASS__, 'render_lightbox' ], 16, 2 ); // 16 is right after the core filter.
			add_filter( 'the_content', [ __CLASS__, 'filter_content_image_attributes' ], PHP_INT_MAX );
		} else {
			remove_filter( 'render_block_core/image', [ __CLASS__, 'render_lightbox' ], 16 );
			remove_filter( 'the_content', [ __CLASS__, 'filter_content_image_attributes' ], PHP_INT_MAX );
			self::$post_payload = null;
		}
	}

	/**
	 * Adds the directives and layout needed for the lightbox behavior.
	 *
	 * This is a slightly modified version from Gutenberg's
	 * `block_core_image_render_lightbox`.
	 *
	 * @see https://github.com/WordPress/gutenberg/blob/0186ae622a99a6e3e54ae4f9dfab325780fe5254/packages/block-library/src/image/index.php#L179
	 *
	 * @param string $block_content Rendered block content.
	 * @param array  $block         Block object.
	 *
	 * @return string Filtered block content.
	 */
	public static function render_lightbox( $block_content, $block ) {
		/**
		 * If the core filter is not applied the lightbox is not enabled and should
		 * not be used.
		 */
		if ( ! has_filter( 'render_block_core/image', 'block_core_image_render_lightbox' ) ) {
			return $block_content;
		}

		/*
		 * If there's no IMG tag in the block then return the given block content
		 * as-is. There's nothing that this code can knowingly modify to add the
		 * lightbox behavior.
		 */
		$processor = new \WP_HTML_Tag_Processor( $block_content );
		if ( $processor->next_tag( 'figure' ) ) {
			$processor->set_bookmark( 'figure' );
		}
		if ( ! $processor->next_tag( 'img' ) ) {
			return $block_content;
		}

		$alt               = $processor->get_attribute( 'alt' );
		$img_uploaded_src  = $processor->get_attribute( 'src' );
		$img_class_names   = $processor->get_attribute( 'class' );
		$img_styles        = $processor->get_attribute( 'style' );
		$img_width         = 'none';
		$img_height        = 'none';
		$img_srcset        = false;
		$aria_label        = __( 'Enlarge' );
		$dialog_aria_label = __( 'Enlarged image' );

		/**
		 * Fetch media data from the original post payload.
		 */
		if ( isset( $block['attrs']['id'] ) && isset( self::$post_payload['post_data']['media_data'][ $block['attrs']['id'] ] ) ) {
			$media_data       = self::$post_payload['post_data']['media_data'][ $block['attrs']['id'] ];
			$img_uploaded_src = $media_data['url'] ?? null;
			$img_srcset       = $media_data['srcset'] ?? null;
			$img_width        = $media_data['width'] ?? 'none';
			$img_height       = $media_data['height'] ?? 'none';
		}

		// Figure.
		$processor->seek( 'figure' );
		$figure_class_names = $processor->get_attribute( 'class' );
		$figure_styles      = $processor->get_attribute( 'style' );

		// Create unique id and set the image metadata in the state.
		$unique_image_id = uniqid();

		wp_interactivity_state(
			'core/image',
			[
				'metadata' => [
					$unique_image_id => [
						'uploadedSrc'      => $img_uploaded_src,
						'lightboxSrcset'   => $img_srcset,
						'figureClassNames' => $figure_class_names,
						'figureStyles'     => $figure_styles,
						'imgClassNames'    => $img_class_names,
						'imgStyles'        => $img_styles,
						'targetWidth'      => $img_width,
						'targetHeight'     => $img_height,
						'scaleAttr'        => $block['attrs']['scale'] ?? false,
						'ariaLabel'        => $dialog_aria_label,
						'alt'              => $alt,
					],
				],
			]
		);

		$processor->add_class( 'wp-lightbox-container' );
		$processor->set_attribute( 'data-wp-interactive', 'core/image' );
		$processor->set_attribute(
			'data-wp-context',
			wp_json_encode(
				[
					'imageId' => $unique_image_id,
				],
				JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
			)
		);
		$processor->set_attribute( 'data-wp-key', $unique_image_id );

		// Image.
		$processor->next_tag( 'img' );
		$processor->set_attribute( 'data-wp-init', 'callbacks.setButtonStyles' );
		$processor->set_attribute( 'data-wp-on--load', 'callbacks.setButtonStyles' );
		$processor->set_attribute( 'data-wp-on-window--resize', 'callbacks.setButtonStyles' );

		// Set an event to preload the image on pointerenter and pointerdown(mobile).
		// Pointerleave is used to cancel the preload if the user hovers away from the image
		// before the predefined delay.
		$processor->set_attribute( 'data-wp-on--pointerenter', 'actions.preloadImageWithDelay' );
		$processor->set_attribute( 'data-wp-on--pointerdown', 'actions.preloadImage' );
		$processor->set_attribute( 'data-wp-on--pointerleave', 'actions.cancelPreload' );

		// Sets an event callback on the `img` because the `figure` element can also
		// contain a caption, and we don't want to trigger the lightbox when the
		// caption is clicked.
		$processor->set_attribute( 'data-wp-on--click', 'actions.showLightbox' );
		$processor->set_attribute( 'data-wp-class--hide', 'state.isContentHidden' );
		$processor->set_attribute( 'data-wp-class--show', 'state.isContentVisible' );

		$body_content = $processor->get_updated_html();

		// Adds a button alongside image in the body content.
		$img = null;
		preg_match( '/<img[^>]+>/', $body_content, $img );

		// Remove the button added from the core filter.
		$body_content = preg_replace( '/<button[^>]*class=["\'][^"\']*lightbox-trigger[^"\']*["\'][^>]*>/', '', $body_content );

		$button =
			$img[0]
			. '<button
				class="lightbox-trigger"
				type="button"
				aria-haspopup="dialog"
				aria-label="' . esc_attr( $aria_label ) . '"
				data-wp-init="callbacks.initTriggerButton"
				data-wp-on--click="actions.showLightbox"
				data-wp-style--right="state.imageButtonRight"
				data-wp-style--top="state.imageButtonTop"
			>
				<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="none" viewBox="0 0 12 12">
					<path fill="#fff" d="M2 0a2 2 0 0 0-2 2v2h1.5V2a.5.5 0 0 1 .5-.5h2V0H2Zm2 10.5H2a.5.5 0 0 1-.5-.5V8H0v2a2 2 0 0 0 2 2h2v-1.5ZM8 12v-1.5h2a.5.5 0 0 0 .5-.5V8H12v2a2 2 0 0 1-2 2H8Zm2-12a2 2 0 0 1 2 2v2h-1.5V2a.5.5 0 0 0-.5-.5H8V0h2Z" />
				</svg>
			</button>';

		$body_content = preg_replace( '/<img[^>]+>/', $button, $body_content );

		add_action( 'wp_footer', 'block_core_image_print_lightbox_overlay' );

		return $body_content;
	}

	/**
	 * Filter the image tags to set distributed post attributes.
	 *
	 * @param string $content The content to filter.
	 *
	 * @return string The filtered content.
	 */
	public static function filter_content_image_attributes( $content ) {
		$processor = new \WP_HTML_Tag_Processor( $content );

		while ( $processor->next_tag( 'img' ) ) {
			$attachment_id = $processor->get_attribute( 'data-id' );
			if ( empty( $attachment_id ) ) {
				continue;
			}

			if ( ! isset( self::$post_payload['post_data']['media_data'][ $attachment_id ] ) ) {
				continue;
			}

			$data = self::$post_payload['post_data']['media_data'][ $attachment_id ];

			$img_meta = ( ! empty( $data['metadata']['image_meta'] ) ) ? (array) $data['metadata']['image_meta'] : array();
			if ( isset( $img_meta['keywords'] ) ) {
				unset( $img_meta['keywords'] );
			}
			$img_meta = wp_json_encode( array_map( 'strval', array_filter( $img_meta, 'is_scalar' ) ), JSON_UNESCAPED_SLASHES | JSON_HEX_AMP );

			$attrs = [];

			$attrs['srcset']                 = $data['srcset'];
			$attrs['data-permalink']         = $data['url'];
			$attrs['data-orig-file']         = $data['url'];
			$attrs['data-orig-size']         = ! empty( $data['width'] ) ? absint( $data['width'] ) . ',' . absint( $data['height'] ) : '';
			$attrs['data-comments-opened']   = 0;
			$attrs['data-image-meta']        = $img_meta;
			$attrs['data-image-title']       = $data['title'] ?? '';
			$attrs['data-image-description'] = $data['description'] ?? '';
			$attrs['data-image-caption']     = $data['caption'] ?? '';
			$attrs['data-medium-file']       = $data['url'];
			$attrs['data-large-file']        = $data['url'];

			foreach ( $attrs as $attr_name => $attr_value ) {
				$processor->set_attribute( $attr_name, $attr_value );
			}
		}

		return $processor->get_updated_html();
	}
}
