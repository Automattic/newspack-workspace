<?php
/**
 * Collections Content Inserter.
 *
 * @package Newspack\Collections
 */

namespace Newspack\Collections;

defined( 'ABSPATH' ) || exit;

/**
 * Content inserter class for Collections indicators.
 */
class Content_Inserter {

	/**
	 * The maximum number of collections to render by default in the post content indicator.
	 *
	 * @var int
	 */
	public const MAX_COLLECTIONS_TO_RENDER = 1;

	/**
	 * The block number to insert the indicator after.
	 *
	 * @var int
	 */
	public const INSERT_AFTER_BLOCK_NUMBER = 2;

	/**
	 * Whether we've already inserted indicators into the content.
	 * Prevents duplicate execution.
	 *
	 * @var boolean
	 */
	public static $the_content_has_rendered = false;

	/**
	 * The collections the current post is in.
	 *
	 * @var WP_Post[]
	 */
	public static $post_collections = [];

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Use template_redirect as it runs on the frontend before wp_enqueue_scripts.
		add_action( 'template_redirect', [ __CLASS__, 'check_if_post_is_in_collection' ] );
	}

	/**
	 * Check if the post is in a collection and add a filter for the post content.
	 */
	public static function check_if_post_is_in_collection() {
		if ( ! is_singular( 'post' ) ) {
			return;
		}

		$post = get_post();
		if ( ! $post ) {
			return;
		}

		// Only show indicators for published collections.
		$collections = array_filter(
			Query_Helper::get_post_collections( $post ),
			function ( $collection_id ) {
				return 'publish' === get_post_status( $collection_id );
			}
		);
		if ( empty( $collections ) ) {
			return;
		}

		// Sort by post date descending.
		usort(
			$collections,
			function ( $a, $b ) {
				return get_the_date( 'U', $b ) - get_the_date( 'U', $a );
			}
		);

		self::$post_collections = $collections;
		Enqueuer::add_data( 'post_is_in_collections', true );
		add_filter( 'the_content', [ __CLASS__, 'maybe_insert_collection_indicators' ], 1 );
	}

	/**
	 * Maybe insert collection indicators into post content.
	 *
	 * @param string $content The post content.
	 * @return string The modified content.
	 */
	public static function maybe_insert_collection_indicators( $content ) {
		if ( self::$the_content_has_rendered || ! in_the_loop() || empty( trim( $content ) ) ) {
			return $content;
		}
		$post_style = Settings::get_setting( 'post_indicator_style', 'default' );

		switch ( $post_style ) {
			case 'card':
				// Insert indicator at the specified position.
				$content_with_indicators = self::insert_after_nth_block(
					$content,
					self::build_card_html( self::$post_collections )
				);
				break;
			case 'default':
			default:
				// Insert indicator at the end of the content.
				$content_with_indicators = $content . self::build_default_indicator_html( self::$post_collections );
				break;
		}

		self::$the_content_has_rendered = true;

		/**
		 * Filters the content with collection indicators inserted.
		 *
		 * @param string          $content_with_indicators The content with indicators.
		 * @param string          $content                 The original content.
		 * @param string          $post_style              The post style.
		 * @param int[]|WP_Post[] $collections             The collections.
		 */
		return apply_filters( 'newspack_collections_content_with_indicators', $content_with_indicators, $content, $post_style, self::$post_collections );
	}

	/**
	 * Build the card HTML using output buffering.
	 *
	 * @param int[]|WP_Post[] $collections The collection objects.
	 * @param int             $limit       The number of collections to render. Default is self::MAX_COLLECTIONS_TO_RENDER.
	 * @return string The card HTML.
	 */
	public static function build_card_html( $collections, $limit = self::MAX_COLLECTIONS_TO_RENDER ) {
		if ( empty( $collections ) ) {
			return '';
		}

		$collections  = is_array( $collections ) ? array_slice( $collections, 0, $limit ) : [];
		$card_message = Settings::get_setting(
			'card_message',
			__( "Keep reading. There's plenty more to discover.", 'newspack-plugin' )
		);
		ob_start();

		foreach ( $collections as $collection ) {
			$collection_link  = get_permalink( $collection );
			$collection_title = get_the_title( $collection );

			// Get collection image.
			$image_html = Template_Helper::render_image(
				$collection,
				false,
				[ 144, 192 ] // 2x of a 72px width.
			);
			?>
			<!-- wp:group {"layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"}} -->
			<div class="collection-card wp-block-group">
				<!-- wp:separator -->
				<hr class="wp-block-separator has-light-gray-background-color has-background is-style-wide"/>
				<!-- /wp:separator -->

				<!-- wp:columns -->
				<div class="collection-card__columns wp-block-columns">
					<div class="collection-card__content-column wp-block-column is-vertically-aligned-center">
						<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap"}} -->
						<div class="wp-block-group">
							<?php echo wp_kses_post( $image_html ); ?>
							<p>
								<strong>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s is replaced with the collection title */
											_x( 'Browse %s', 'browse collection', 'newspack-plugin' ),
											$collection_title
										)
									);
									?>
								</strong>
								<br>
								<?php echo esc_html( $card_message ); ?>
							</p>
						</div>
						<!-- /wp:group -->
					</div>

					<div class="wp-block-column collection-card__buttons-column is-vertically-aligned-center">
						<?php
						echo wp_kses_post(
							Template_Helper::render_cta(
								[
									'url'   => $collection_link,
									'label' => __( 'See more', 'newspack-plugin' ),
								]
							)
						);
						?>
					</div>
				</div>
				<!-- /wp:columns -->

				<!-- wp:separator -->
				<hr class="wp-block-separator has-light-gray-background-color has-background is-style-wide"/>
				<!-- /wp:separator -->
			</div>
			<!-- /wp:group -->
			<?php
		}

		$card_html = do_blocks( ob_get_clean() );

		/**
		 * Filters the collection card HTML.
		 *
		 * @param string          $card_html   The card HTML.
		 * @param int[]|WP_Post[] $collections The collections.
		 */
		return apply_filters( 'newspack_collections_card_html', $card_html, $collections );
	}

	/**
	 * Insert content after the nth block using block parsing.
	 *
	 * @param string $content     The content.
	 * @param string $insert_html The HTML to insert.
	 * @param int    $nth_block   The block number (1-based). Default is self::INSERT_AFTER_BLOCK_NUMBER.
	 * @return string The modified content.
	 */
	public static function insert_after_nth_block( $content, $insert_html, $nth_block = self::INSERT_AFTER_BLOCK_NUMBER ) {
		$parsed_blocks = parse_blocks( $content );

		// Filter out empty blocks.
		$blocks_to_skip_empty = [
			'core/paragraph',
			'core/heading',
			'core/list',
			'core/quote',
			'core/html',
			'core/freeform',
		];

		$parsed_blocks = array_values(
			array_filter(
				$parsed_blocks,
				function ( $block ) use ( $blocks_to_skip_empty ) {
					$null_block_name     = null === $block['blockName'];
					$is_skip_empty_block = in_array( $block['blockName'], $blocks_to_skip_empty, true );
					$is_empty            = empty( trim( $block['innerHTML'] ) );
					return ! ( $is_empty && ( $null_block_name || $is_skip_empty_block ) );
				}
			)
		);

		// If we don't have enough blocks, append at the end.
		if ( count( $parsed_blocks ) < $nth_block ) {
			return $content . $insert_html;
		}

		// Insert after the nth block.
		$rendered_content = '';
		foreach ( $parsed_blocks as $index => $block ) {
			$rendered_content .= $block['innerHTML'];

			// Insert after the nth block (index is 0-based).
			if ( $index === $nth_block - 1 ) {
				$rendered_content .= $insert_html;
			}
		}

		return $rendered_content;
	}

	/**
	 * Build the default indicator HTML.
	 *
	 * @param int[]|WP_Post[] $collections The collections.
	 * @param int|null        $limit       The number of collections to render. Default is null (show all).
	 * @return string The indicator HTML.
	 */
	public static function build_default_indicator_html( $collections, $limit = null ) {
		if ( ! is_array( $collections ) ) {
			return '';
		}

		if ( $limit ) {
			$collections = array_slice( $collections, 0, $limit );
		}

		if ( empty( $collections ) ) {
			return '';
		}

		$collection_links = array_map(
			function ( $collection ) {
				return sprintf(
					'<a href="%s">%s</a>',
					esc_url( get_permalink( $collection ) ),
					esc_html( get_the_title( $collection ) )
				);
			},
			$collections
		);

		$indicator_html = sprintf(
			'<p class="collection-link has-small-font-size">%s</p>',
			wp_sprintf(
				/* translators: %l is replaced with the collection name(s) */
				__( 'This article appears in %l.', 'newspack-plugin' ),
				$collection_links
			)
		);

		/**
		 * Filters the collection default indicator HTML.
		 *
		 * @param string          $indicator_html The indicator HTML.
		 * @param int[]|WP_Post[] $collections    The collections.
		 * @param int             $limit          The number of collections rendered. If a falsey value, all collections were rendered.
		 */
		return apply_filters( 'newspack_collections_default_indicator_html', $indicator_html, $collections, $limit );
	}
}