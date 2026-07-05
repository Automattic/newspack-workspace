/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';

/**
 * Internal dependencies
 */
import createSwiper from './create-swiper';
import './view.scss';

if ( typeof window !== 'undefined' ) {
	domReady( () => {
		const blocksArray = Array.from( document.querySelectorAll< HTMLElement >( '.wp-block-newspack-blocks-carousel' ) );
		blocksArray.forEach( block => {
			// Initialize Swiper only when the carousel becomes visible.
			const observer = new IntersectionObserver(
				entries => {
					entries.forEach( entry => {
						if ( entry.isIntersecting ) {
							// These `data-*` attributes and child elements are always
							// rendered by view.php alongside this markup.
							const slidesPerView = parseInt( block.dataset.slidesPerView! );
							const slideCount = parseInt( block.dataset.slideCount! );
							createSwiper(
								{
									block,
									container: block.querySelector< HTMLElement >( '.swiper' )!,
									prev: block.querySelector< HTMLElement >( '.swiper-button-prev' )!,
									next: block.querySelector< HTMLElement >( '.swiper-button-next' )!,
									pagination: block.querySelector< HTMLElement >( '.swiper-pagination-bullets' )!,
									pause: block.querySelector< HTMLElement >( '.swiper-button-pause' )!,
									play: block.querySelector< HTMLElement >( '.swiper-button-play' )!,
								},
								{
									aspectRatio: parseFloat( block.dataset.aspectRatio! ),
									autoplay: !! parseInt( block.dataset.autoplay! ),
									delay: parseInt( block.dataset.autoplay_delay! ) * 1000,
									slidesPerView: slidesPerView <= slideCount ? slidesPerView : slideCount,
									spaceBetween: 16,
								}
							);
						}
					} );
				},
				{ threshold: 0.25 }
			);
			observer.observe( block );
		} );
	} );
}
