<?php
/**
 * Collections Block.
 *
 * @package Newspack
 */

namespace Newspack\Blocks\Collections;

use Newspack\Collections\Post_Type;
use Newspack\Collections\Collection_Category_Taxonomy;
use Newspack\Collections\Query_Helper;
use Newspack\Collections\Collection_Meta;
use Newspack\Collections\Template_Helper;
use Newspack\Collections\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Collections Block Class.
 */
final class Collections_Block {

	/**
	 * The block name.
	 *
	 * @var string
	 */
	public const BLOCK_NAME = 'newspack/collections';

	/**
	 * Default block attributes.
	 */
	public const DEFAULT_ATTRIBUTES = [
		'queryType'           => 'recent',
		'numberOfItems'       => 4,
		'offset'              => 0,
		'selectedCollections' => [],
		'includeCategories'   => [],
		'excludeCategories'   => [],
		'layout'              => 'grid',
		'columns'             => 4,
		'imageAlignment'      => 'top',
		'imageSize'           => 'small',
		'showFeaturedImage'   => true,
		'showCategory'        => true,
		'showTitle'           => true,
		'showExcerpt'         => false,
		'showVolume'          => true,
		'showNumber'          => true,
		'showPeriod'          => true,
		'showSubscriptionUrl' => true,
		'showOrderUrl'        => true,
		'showCTAs'            => true,
		'numberOfCTAs'        => 1,
		'specificCTAs'        => '',
		'headingText'         => '',
		'noPermalinks'        => false,
	];

	/**
	 * Initializes the block.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
		add_action( 'after_setup_theme', [ __CLASS__, 'register_image_sizes' ] );
	}

	/**
	 * Register custom image sizes for collections block.
	 *
	 * @return void
	 */
	public static function register_image_sizes() {
		add_image_size( 'newspack_collection_small', 550, 9999 );
		add_image_size( 'newspack_collection_medium', 800, 9999 );
		add_image_size( 'newspack_collection_large', 1200, 9999 );
	}

	/**
	 * Register newspack collections block.
	 *
	 * @return void
	 */
	public static function register_block() {
		register_block_type_from_metadata(
			__DIR__ . '/block.json',
			[
				'render_callback' => [ __CLASS__, 'render_block' ],
			]
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array $attributes The block attributes.
	 *
	 * @return string The block HTML.
	 */
	public static function render_block( array $attributes ) {
		// Sanitize and normalize attributes.
		$attributes = self::sanitize_attributes( wp_parse_args( $attributes, self::DEFAULT_ATTRIBUTES ) );

		// Normalize selectedCollections to determine if we have post objects or IDs.
		$normalized_posts = Template_Helper::normalize_post_list( (array) $attributes['selectedCollections'] );

		if ( 'objects' === $normalized_posts['type'] ) {
			// Use provided WP_Post objects directly.
			$collections = $normalized_posts['items'];
		} else {
			// Use normalized IDs and query collections.
			$attributes['selectedCollections'] = $normalized_posts['items'];
			$attributes['includeCategories']   = array_map( 'absint', (array) $attributes['includeCategories'] );
			$attributes['excludeCategories']   = array_map( 'absint', (array) $attributes['excludeCategories'] );
			$collections                       = Query_Helper::get_collections_by_attributes( $attributes );
		}

		if ( empty( $collections ) ) {
			return '<div class="wp-block-newspack-collections"><p>' . esc_html__( 'No collections found.', 'newspack-plugin' ) . '</p></div>';
		}

		/**
		 * Filter the CSS classes for the collections block wrapper.
		 *
		 * @param string $classes    Wrapper classes string.
		 * @param array  $attributes Block attributes.
		 */
		$classes = apply_filters( 'newspack_collections_block_wrapper_classes', self::get_block_classes( $attributes ), $attributes );

		$wrapper_attributes = get_block_wrapper_attributes(
			[
				'class' => esc_attr( $classes ),
			]
		);

		ob_start();
		?>
		<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php self::render_collections( $collections, $attributes ); ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Sanitize and normalize attributes that are used in queries/output.
	 *
	 * @param array $attributes The block attributes.
	 * @return array Sanitized attributes.
	 */
	public static function sanitize_attributes( array $attributes ) {
		foreach ( [ 'numberOfItems', 'offset', 'columns', 'numberOfCTAs' ] as $attr ) {
			if ( ! isset( $attributes[ $attr ] ) ) {
				continue;
			}

			if ( 'numberOfCTAs' === $attr && -1 === (int) $attributes[ $attr ] ) {
				$attributes[ $attr ] = -1;
			} else {
				$value               = absint( $attributes[ $attr ] );
				$attributes[ $attr ] = $value > 0
					? $value
					: self::DEFAULT_ATTRIBUTES[ $attr ];
			}
		}

		return $attributes;
	}

	/**
	 * Get CSS classes for the block wrapper.
	 *
	 * @param array $attributes Block attributes.
	 * @return string CSS classes.
	 */
	public static function get_block_classes( $attributes ) {
		$classes = [ 'wp-block-newspack-collections' ];

		$classes[] = 'layout-' . sanitize_html_class( $attributes['layout'] );

		if ( 'grid' === $attributes['layout'] ) {
			$classes[] = 'columns-' . absint( $attributes['columns'] );
		}

		$classes[] = 'image-' . sanitize_html_class( $attributes['imageAlignment'] );

		if ( 'list' === $attributes['layout'] ) {
			$classes[] = 'image-size-' . sanitize_html_class( $attributes['imageSize'] );
		}

		return implode( ' ', $classes );
	}

	/**
	 * Render collections HTML.
	 *
	 * @param array $collections Array of WP_Post objects.
	 * @param array $attributes  Block attributes.
	 */
	protected static function render_collections( $collections, $attributes ) {
		foreach ( $collections as $collection ) {
			self::render_collection( $collection, $attributes );
		}
	}

	/**
	 * Render individual collection HTML.
	 *
	 * @param \WP_Post $collection Collection post object.
	 * @param array    $attributes Block attributes.
	 */
	protected static function render_collection( $collection, $attributes ) {
		$collection_url = get_permalink( $collection );
		$image_size     = self::get_image_size_from_attributes( $attributes );
		?>
		<article class="wp-block-newspack-collections__item">
			<?php if ( $attributes['showFeaturedImage'] ) : ?>
				<div class="wp-block-newspack-collections__image">
					<?php
					$image_permalink = $attributes['noPermalinks'] ? false : $collection_url;
					echo wp_kses_post( Template_Helper::render_image( $collection->ID, $image_permalink, $image_size ) );
					?>
				</div>
			<?php endif; ?>

			<div class="wp-block-newspack-collections__content">
				<?php if ( ! empty( $attributes['headingText'] ) ) : ?>
					<h6 class="wp-block-newspack-collections__heading has-primary-color has-text-color has-link-color has-normal-font-size">
						<?php echo esc_html( $attributes['headingText'] ); ?>
					</h6>
				<?php endif; ?>

				<?php if ( $attributes['showCategory'] ) : ?>
					<?php self::render_collection_categories( $collection ); ?>
				<?php endif; ?>

				<?php if ( $attributes['showTitle'] ) : ?>
					<h2 class="wp-block-newspack-collections__title">
						<?php if ( ! $attributes['noPermalinks'] ) : ?>
							<a href="<?php echo esc_url( $collection_url ); ?>">
								<?php echo esc_html( get_the_title( $collection ) ); ?>
							</a>
						<?php else : ?>
							<?php echo esc_html( get_the_title( $collection ) ); ?>
						<?php endif; ?>
					</h2>
				<?php endif; ?>

				<?php
				if ( $attributes['showVolume'] || $attributes['showNumber'] || $attributes['showPeriod'] ) {
					self::render_collection_meta( $collection, $attributes );
				}
				?>

				<?php if ( $attributes['showExcerpt'] ) : ?>
					<div class="wp-block-newspack-collections__excerpt">
						<?php echo wp_kses_post( get_the_excerpt( $collection ) ); ?>
					</div>
				<?php endif; ?>

				<?php if ( $attributes['showCTAs'] ) : ?>
					<?php self::render_collection_ctas( $collection, $attributes ); ?>
				<?php endif; ?>
			</div>
		</article>
		<?php
	}

	/**
	 * Map editor image size attribute to an image size name used on the frontend.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Image size name.
	 */
	public static function get_image_size_from_attributes( $attributes ) {
		if ( isset( $attributes['layout'], $attributes['columns'] ) && 'grid' === $attributes['layout'] && 4 <= $attributes['columns'] ) {
			return 'newspack_collection_small';
		}

		if ( isset( $attributes['layout'], $attributes['columns'] ) && 'grid' === $attributes['layout'] && 3 === $attributes['columns'] ) {
			return 'newspack_collection_medium';
		}

		if ( ! isset( $attributes['layout'] ) || 'grid' === $attributes['layout'] ) {
			return 'newspack_collection_large';
		}

		$size = isset( $attributes['imageSize'] ) ? $attributes['imageSize'] : 'small';
		switch ( $size ) {
			case 'large':
				return 'newspack_collection_large';
			case 'medium':
				return 'newspack_collection_medium';
			case 'small':
			default:
				return 'newspack_collection_small';
		}
	}

	/**
	 * Render collection categories.
	 *
	 * @param \WP_Post $collection Collection post object.
	 */
	public static function render_collection_categories( $collection ) {
		$categories = get_the_terms( $collection, Collection_Category_Taxonomy::get_taxonomy() );

		if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
			?>
			<div class="wp-block-newspack-collections__categories">
				<?php foreach ( $categories as $category ) : ?>
					<?php $collections_archive_url = get_post_type_archive_link( Post_Type::get_post_type() ); ?>
					<?php if ( $collections_archive_url ) : ?>
						<?php $category_filter_url = add_query_arg( Settings::CATEGORY_QUERY_PARAM, $category->slug, $collections_archive_url ); ?>
						<a href="<?php echo esc_url( $category_filter_url ); ?>" class="wp-block-newspack-collections__category">
							<?php echo esc_html( $category->name ); ?>
						</a>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
			<?php
		}
	}

	/**
	 * Render collection meta information using Template_Helper pattern.
	 *
	 * @param \WP_Post $collection Collection post object.
	 * @param array    $attributes Block attributes.
	 */
	public static function render_collection_meta( $collection, $attributes ) {
		$meta_parts = [];

		// Period.
		if ( $attributes['showPeriod'] ) {
			$period = Collection_Meta::get( $collection->ID, 'period' );
			if ( $period ) {
				$meta_parts[] = '<span class="wp-block-newspack-collections__period">' . esc_html( $period ) . '</span>';
			}
		}

		// Volume and number.
		$vol_number = [];

		if ( $attributes['showVolume'] ) {
			$volume = Collection_Meta::get( $collection->ID, 'volume' );
			if ( $volume ) {
				$vol_number[] = '<span class="wp-block-newspack-collections__volume">' .
					sprintf(
						/* translators: %s is the volume number of a collection */
						_x( 'Vol. %s', 'collection volume number', 'newspack-plugin' ),
						esc_html( $volume )
					) .
					'</span>';
			}
		}

		if ( $attributes['showNumber'] ) {
			$number = Collection_Meta::get( $collection->ID, 'number' );
			if ( $number ) {
				$vol_number[] = '<span class="wp-block-newspack-collections__number">' .
					sprintf(
						/* translators: %s is the issue number of a collection */
						_x( 'No. %s', 'collection issue number', 'newspack-plugin' ),
						esc_html( $number )
					) .
					'</span>';
			}
		}

		if ( $vol_number ) {
			$meta_parts[] = implode( ' <span class="wp-block-newspack-collections__divider">/</span> ', $vol_number );
		}

		// Render meta text.
		if ( ! empty( $meta_parts ) ) {
			// Use different separator based on layout.
			$separator = 'list' === $attributes['layout'] ? ' <span class="wp-block-newspack-collections__divider">/</span> ' : '';
			$meta_text = implode( $separator, $meta_parts );
			?>
			<div class="wp-block-newspack-collections__meta has-medium-gray-color has-text-color has-small-font-size">
				<?php echo wp_kses_post( $meta_text ); ?>
			</div>
			<?php
		}
	}

	/**
	 * Render collection CTAs.
	 *
	 * @param \WP_Post $collection Collection post object.
	 * @param array    $attributes Block attributes.
	 */
	public static function render_collection_ctas( $collection, $attributes ) {
		// Get all CTAs.
		$all_ctas = Query_Helper::get_ctas( $collection->ID );

		// Filter CTAs based on toggle settings.
		$filtered_ctas = array_filter(
			$all_ctas,
			function ( $cta ) use ( $attributes ) {
				$cta_label = $cta['label'] ?? '';

				if ( __( 'Subscribe', 'newspack-plugin' ) === $cta_label && ! $attributes['showSubscriptionUrl'] ) {
					return false;
				}

				if ( __( 'Order', 'newspack-plugin' ) === $cta_label && ! $attributes['showOrderUrl'] ) {
					return false;
				}

				return true;
			}
		);

		// Filter by specific labels if provided.
		if ( ! empty( $attributes['specificCTAs'] ) ) {
			$specific_labels = array_map( 'trim', explode( ',', $attributes['specificCTAs'] ) );
			$filtered_ctas   = array_filter(
				$filtered_ctas,
				function ( $cta ) use ( $specific_labels ) {
					$cta_label = $cta['label'] ?? '';
					return in_array( $cta_label, $specific_labels, true );
				}
			);
		}

		// Limit to numberOfCTAs (-1 means show all).
		$max_ctas = $attributes['numberOfCTAs'] ?? 1;
		if ( -1 !== $max_ctas ) {
			$filtered_ctas = array_slice( $filtered_ctas, 0, $max_ctas );
		}

		/**
		 * Filter the CTAs rendered by the collections block for a given collection.
		 *
		 * @param array   $filtered_ctas Filtered CTAs.
		 * @param array   $all_ctas      All available CTAs.
		 * @param \WP_Post $collection   The collection post object.
		 * @param array   $attributes    Block attributes.
		 */
		$filtered_ctas = apply_filters( 'newspack_collections_block_ctas', $filtered_ctas, $all_ctas, $collection, $attributes );

		// Render CTAs.
		if ( ! empty( $filtered_ctas ) ) {
			?>
			<div class="wp-block-newspack-collections__ctas">
				<?php foreach ( $filtered_ctas as $cta ) : ?>
					<?php
					$rendered_cta = Template_Helper::render_cta( $cta );
					if ( ! empty( $rendered_cta ) ) {
						echo wp_kses_post( $rendered_cta );
					}
					?>
				<?php endforeach; ?>
			</div>
			<?php
		}
	}
}

Collections_Block::init();
