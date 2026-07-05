/**
 * External dependencies
 */
import { speak } from '@wordpress/a11y';
import { escapeHTML } from '@wordpress/escape-html';
import { __, sprintf } from '@wordpress/i18n';
// eslint-disable-next-line import/no-unresolved
import Swiper from 'swiper/bundle';
// eslint-disable-next-line import/no-unresolved
import 'swiper/css/bundle';
// `swiper/bundle`'s types are only reachable via the package's "exports" map,
// which this project's `moduleResolution: "node"` setting doesn't consult
// (see ./swiper-bundle.d.ts); import the real class type from the main entry
// for annotations - it's the identical class at runtime, just pre-loaded with
// all modules.
import type SwiperInstance from 'swiper';
import type { SwiperOptions } from 'swiper/types';

const autoplayClassName = 'wp-block-newspack-blocks-carousel__autoplay-playing';

// `releaseFormElements` predates the installed Swiper version and is no longer
// part of its published `SwiperOptions` type (nor read by its runtime), but is
// preserved here unchanged from the original config rather than silently dropped.
type SwiperOptionsWithLegacyKeys = SwiperOptions & { releaseFormElements?: boolean };

type SwiperElements = {
	block: HTMLElement;
	container: HTMLElement;
	next: HTMLElement;
	prev: HTMLElement;
	play: HTMLElement;
	pause: HTMLElement;
	pagination: HTMLElement;
};

type SwiperCreationConfig = {
	autoplay?: boolean;
	delay?: number;
	initialSlide?: number;
	slidesPerView: number;
	aspectRatio?: number;
	// Accepted for parity with callers, but not read here - `spaceBetween` is
	// always hardcoded below instead.
	spaceBetween?: number;
};

/**
 * A helper for IE11-compatible iteration over NodeList elements.
 *
 * @param nodeList List of nodes to be iterated over.
 * @param cb       Invoked for each iteratee.
 */
function forEachNode( nodeList: NodeListOf< Element >, cb: ( el: Element ) => void ) {
	/**
	 * Calls Array.prototype.forEach for IE11 compatibility.
	 *
	 * @see https://developer.mozilla.org/en-US/docs/Web/API/NodeList
	 */
	Array.prototype.forEach.call( nodeList, cb );
}

/**
 * Modifies attributes on slide HTML to make it accessible.
 *
 * @param slide Slide DOM element
 */
function activateSlide( slide: Element ) {
	if ( slide ) {
		slide.setAttribute( 'aria-hidden', 'false' );
		forEachNode( slide.querySelectorAll( 'a' ), el => el.removeAttribute( 'tabindex' ) );
	}
}

/**
 * Modifies attributes on slide HTML to make it accessible.
 *
 * @param slide Slide DOM element
 */
function deactivateSlide( slide: Element ) {
	if ( slide ) {
		slide.setAttribute( 'aria-hidden', 'true' );
		forEachNode( slide.querySelectorAll( 'a' ), el => el.setAttribute( 'tabindex', '-1' ) );
	}
}

/**
 * Creates a Swiper instance with predefined config used by the Articles
 * Carousel block in both front-end and editor.
 *
 * @param els    Swiper elements
 * @param config Swiper config
 * @return Swiper instance, or `false` if the block isn't visible yet.
 */
export default function createSwiper( els: SwiperElements, config: SwiperCreationConfig ) {
	const isVisible = 0 < els.container.offsetWidth && 0 < els.container.offsetHeight;

	// Don't initialize if the swiper is hidden on initial mount.
	if ( ! isVisible ) {
		return false;
	}

	const swiperOptions: SwiperOptionsWithLegacyKeys = {
		/**
		 * Remove the messages, as we're announcing the slide content and number.
		 * These messages are overwriting the slide announcement.
		 */
		a11y: false,
		autoplay: !! config.autoplay && {
			delay: config.delay,
			disableOnInteraction: false,
		},
		effect: 'slide',
		grabCursor: true,
		init: false,
		initialSlide: config.initialSlide || 0,
		loop: true,
		navigation: {
			nextEl: els.next,
			prevEl: els.prev,
		},
		pagination: {
			bulletElement: 'button',
			clickable: true,
			el: els.pagination,
			type: 'bullets',
			renderBullet: ( index: number, className: string ) => {
				// Use a custom render, as Swiper's render is inaccessible.
				return `<button class="${ className }"><span>${ sprintf(
					/* translators: %s: indicates which slide the slider is on. */
					__( 'Slide %s', 'newspack-blocks' ),
					String( index + 1 )
				) }</span></button>`;
			},
		},
		watchSlidesProgress: config.slidesPerView > 1,
		preventClicksPropagation: false, // Necessary for normal block interactions.
		releaseFormElements: false,
		setWrapperSize: true,
		slidesPerView: config.slidesPerView,
		spaceBetween: 16,
		touchStartPreventDefault: false,
		breakpoints: {
			320: {
				slidesPerView: 1,
			},
			782: {
				slidesPerView: config.slidesPerView > 1 ? 2 : 1,
			},
			1168: {
				slidesPerView: config.slidesPerView,
			},
		},
		on: {
			init( this: SwiperInstance ) {
				forEachNode( this.wrapperEl.querySelectorAll( '.swiper-slide' ), slide => deactivateSlide( slide ) );

				setAspectRatio.call( this ); // Set the aspect ratio on init.
				activateSlide( this.slides[ this.activeIndex ] ); // Set-up our active slide.
			},

			slideChange( this: SwiperInstance ) {
				if ( this.slides.length < 1 ) {
					return; // No slides, no need to do anything.
				}
				const currentSlide = this.slides[ this.activeIndex ];

				deactivateSlide( this.slides[ this.previousIndex ] );

				activateSlide( currentSlide );

				/**
				 * If we're autoplaying, don't announce the slide change, as that would
				 * be supremely annoying.
				 */
				if ( ! this.autoplay?.running ) {
					// Announce the contents of the slide.
					const currentImage = currentSlide.querySelector( 'img' );
					const alt = currentImage ? currentImage?.alt : false;

					const slideInfo = sprintf(
						/* translators: %1$s: current slide number, %2$s: total number of slides */
						__( 'Slide %1$s of %2$s', 'newspack-blocks' ),
						String( this.realIndex + 1 ),
						String( this.pagination?.bullets?.length || 0 )
					);

					speak(
						escapeHTML(
							`${ ( currentSlide as HTMLElement ).innerText },
							${ alt ? /* translators: %s: the title of the image. */ sprintf( __( 'Image: %s,', 'newspack-blocks' ), alt ) : '' }
							${ slideInfo }`
						),
						'assertive'
					);
				}
			},
		},
	};

	const swiper = new Swiper( els.container, swiperOptions );

	/**
	 * Forces an aspect ratio for each slide.
	 */
	function setAspectRatio( this: SwiperInstance ) {
		const { aspectRatio } = config;
		const slides = Array.from( this.slides ) as HTMLElement[];

		slides.forEach( slide => {
			slide.style.height = `${ slide.clientWidth * ( aspectRatio || 0 ) }px`;
		} );
	}

	// `imagesReady` is a real Swiper runtime event that isn't part of the
	// published `SwiperEvents` type, so `.on()`'s event-name overload doesn't
	// recognize it - cast at this boundary only.
	type SwiperWithImagesReady = SwiperInstance & { on: ( event: 'imagesReady', handler: ( swiper: SwiperInstance ) => void ) => void };
	( swiper as SwiperWithImagesReady ).on( 'imagesReady', setAspectRatio );
	swiper.on( 'resize', setAspectRatio );

	if ( config.autoplay ) {
		/**
		 * Handles the Pause button click.
		 */
		function handlePauseButtonClick() {
			swiper.autoplay.stop();
			els.play.focus(); // Move focus to the play button.
		}

		/**
		 * Handles the Play button click.
		 */
		function handlePlayButtonClick() {
			swiper.autoplay.start();
			els.pause.focus(); // Move focus to the pause button.
		}

		swiper.on( 'init', function () {
			els.play.addEventListener( 'click', handlePlayButtonClick );
			els.pause.addEventListener( 'click', handlePauseButtonClick );
		} );

		swiper.on( 'autoplayStart', function () {
			els.block.classList.add( autoplayClassName ); // Hide play & show pause button.
			speak( __( 'Playing', 'newspack-blocks' ), 'assertive' );
		} );

		swiper.on( 'autoplayStop', function () {
			els.block.classList.remove( autoplayClassName ); // Hide pause & show play button.
			speak( __( 'Paused', 'newspack-blocks' ), 'assertive' );
		} );

		swiper.on( 'beforeDestroy', function () {
			els.play.removeEventListener( 'click', handlePlayButtonClick );
			els.pause.removeEventListener( 'click', handlePauseButtonClick );
		} );
	}

	swiper.init();

	return swiper;
}
