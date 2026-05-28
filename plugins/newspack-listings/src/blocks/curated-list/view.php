<?php
/**
 * Front-end render functions for the Curated List block.
 *
 * @package Newspack_Listings
 */

namespace Newspack_Listings\Curated_List_Block;

use Newspack_Listings\Core;
use Newspack_Listings\Api;
use Newspack_Listings\Utils;

/**
 * Dynamic block registration.
 */
function register_block() {
	// Register Curated List block.
	register_block_type(
		__DIR__,
		[
			'render_callback' => __NAMESPACE__ . '\render_block',
		]
	);
}

/**
 * Block render callback.
 *
 * @param Array  $attributes Block attributes.
 * @param String $inner_content InnerBlock content.
 */
function render_block( $attributes, $inner_content ) {
	// REST API URL for Listings.
	$rest_url = rest_url( '/newspack-listings/v1/listings' );

	// Is current page an AMP page?
	$is_amp = Utils\is_amp();

	// Conditional class names based on attributes.
	$classes = [ 'newspack-listings__curated-list', esc_attr( $attributes['className'] ) ];

	if ( $attributes['showNumbers'] ) {
		$classes[] = 'show-numbers';
	}
	if ( $attributes['showMap'] ) {
		$classes[] = 'show-map';
	}
	if ( $attributes['showSortUi'] ) {
		$classes[] = 'show-sort-ui';
	}
	if ( $attributes['showImage'] ) {
		$classes[] = 'show-image';
		$classes[] = 'media-position-' . $attributes['mediaPosition'];
		$classes[] = 'media-size-' . $attributes['imageScale'];
	}
	if ( $attributes['backgroundColor'] ) {
		if ( $attributes['hasDarkBackground'] ) {
			$classes[] = 'has-dark-background';
		}
		$classes[] = 'has-background-color';
	}

	$classes[] = 'type-scale-' . $attributes['typeScale'];

	// Color styles for listings.
	$styles = [];

	if ( ! empty( $attributes['textColor'] ) ) {
		$styles[] = 'color:' . $attributes['textColor'];
	}
	if ( ! empty( $attributes['backgroundColor'] ) ) {
		$styles[] = 'background-color:' . $attributes['backgroundColor'];
	}

	// Extend wp_kses_post to allow jetpack/map required elements and attributes.
	$allowed_elements = wp_kses_allowed_html( 'post' );

	// Allow amp-iframe with jetpack/map attributes.
	if ( empty( $allowed_elements['amp-iframe'] ) ) {
		$allowed_elements['amp-iframe'] = [
			'allowfullscreen' => true,
			'frameborder'     => true,
			'height'          => true,
			'layout'          => true,
			'sandbox'         => true,
			'src'             => true,
			'width'           => true,
		];
	}

	// Allow placeholder attribute on divs.
	if ( empty( $allowed_elements['div']['placeholder'] ) ) {
		$allowed_elements['div']['placeholder'] = true;
	}

	// Allow form, select and option elements.
	if ( empty( $allowed_elements['form'] ) ) {
		$allowed_elements['form'] = [
			'class'    => true,
			'data-url' => true,
		];
	}
	if ( empty( $allowed_elements['select'] ) ) {
		$allowed_elements['select'] = [
			'class' => true,
			'id'    => true,
		];
	}
	if ( empty( $allowed_elements['option'] ) ) {
		$allowed_elements['option'] = [
			'disabled' => true,
			'selected' => true,
			'value'    => true,
		];
	}

	// Allow radio input elements.
	if ( empty( $allowed_elements['input'] ) ) {
		$allowed_elements['input'] = [
			'class'       => true,
			'checked'     => true,
			'disabled'    => true,
			'id'          => true,
			'name'        => true,
			'type'        => true,
			'value'       => true,
			'placeholder' => true,
		];
	}

	// Default: we can't have more pages unless a.) the block is in query mode, and b.) the number of queried posts exceeds max_num_pages.
	$has_more_pages = false;

	// If in query mode, dynamically build the list based on query terms.
	if ( $attributes['queryMode'] && ! empty( $attributes['queryOptions'] ) ) {
		$args           = Api::build_listings_query( $attributes['queryOptions'] );
		$listings       = new \WP_Query( $args );
		$page           = $listings->paged ?? 1;
		$has_more_pages = $attributes['showLoadMore'] && ( ++$page ) <= $listings->max_num_pages;

		// Only include the attributes we care about for individual listings.
		$request_attributes = Utils\get_request_attributes( $attributes );

		// REST API URL to fetch more listings.
		$next_url = add_query_arg(
			[
				'attributes' => $request_attributes,
				'query'      => $attributes['queryOptions'],
				'page'       => 2,
				'amp'        => $is_amp,
				'_fields'    => 'html',
			],
			$rest_url
		);

		if ( $has_more_pages ) {
			$classes[] = 'has-more-button';
		}

		if ( $listings->have_posts() ) {
			$inner_content .= '<ol class="newspack-listings__list-container newspack-listings__query-mode">';

			while ( $listings->have_posts() ) {
				$listings->the_post();
				$listing_content = Utils\template_include(
					'listing',
					[
						'attributes' => $attributes,
						'post'       => get_post(),
					]
				);

				$inner_content .= $listing_content;
			}

			$inner_content .= '</ol>';
		}
	}

	// Load AMP script if a.) we're in an AMP page, and b.) we have either more pages or a sort UI.
	$amp_script = Utils\is_amp() && ( $attributes['showSortUi'] || $has_more_pages );

	// Begin front-end output.
	ob_start();

	?>
	<?php if ( $amp_script ) : ?>
		<amp-script layout="container" sandbox="allow-forms" src="<?php echo esc_url( NEWSPACK_LISTINGS_URL . 'amp/curated-list/view.js' ); ?>">
	<?php endif; ?>
	<div
		class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
		style="<?php echo esc_attr( implode( ';', $styles ) ); ?>"
	>
		<?php echo wp_kses( $inner_content, $allowed_elements ); ?>
		<?php if ( $attributes['queryMode'] && $has_more_pages ) : ?>
			<button class="newspack-listings__load-more-button" type="button" data-next="<?php echo esc_url( $next_url ); ?>">
			<?php
			if ( ! empty( $attributes['loadMoreText'] ) ) {
				echo esc_html( $attributes['loadMoreText'] );
			} else {
				esc_html_e( 'Load more listings', 'newspack-listings' );
			}
			?>
			</button>
			<p class="loading">
				<?php esc_html_e( 'Loading...', 'newspack-listings' ); ?>
			</p>
			<p class="error">
				<?php esc_html_e( 'Something went wrong. Please refresh the page and/or try again.', 'newspack-listings' ); ?>
			</p>
		<?php endif; ?>
	</div>
	<?php if ( $amp_script ) : ?>
		</amp-script>
	<?php endif; ?>
	<?php

	$content = ob_get_clean();

	return $content;
}

register_block();
