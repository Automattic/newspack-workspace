/**
 * External dependencies
 */
import { speak } from '@wordpress/a11y';
import { escapeHTML } from '@wordpress/escape-html';
import { __, sprintf } from '@wordpress/i18n';
import { prefersReducedMotion } from 'newspack-motion';
// eslint-disable-next-line import/no-unresolved
import Swiper from 'swiper/bundle';
// eslint-disable-next-line import/no-unresolved
import 'swiper/css/bundle';

const autoplayClassName = 'wp-block-newspack-blocks-carousel__autoplay-playing';

/**
 * A helper for IE11-compatible iteration over NodeList elements.
 *
 * @param {Object}   nodeList List of nodes to be iterated over.
 * @param {Function} cb       Invoked for each iteratee.
 */
function forEachNode( nodeList, cb ) {
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
 * @param {HTMLElement} slide Slide DOM element
 */
function activateSlide( slide ) {
	if ( slide ) {
		slide.setAttribute( 'aria-hidden', 'false' );
		forEachNode( slide.querySelectorAll( 'a' ), el => el.removeAttribute( 'tabindex' ) );
	}
}

/**
 * Modifies attributes on slide HTML to make it accessible.
 *
 * @param {HTMLElement} slide Slide DOM element
 */
function deactivateSlide( slide ) {
	if ( slide ) {
		slide.setAttribute( 'aria-hidden', 'true' );
		forEachNode( slide.querySelectorAll( 'a' ), el => el.setAttribute( 'tabindex', '-1' ) );
	}
}

/**
 * Creates a Swiper instance with predefined config used by the Articles
 * Carousel block in both front-end and editor.
 *
 * @param {Object}  els            Swiper elements
 * @param {Element} els.block      Block element
 * @param {Element} els.container  Swiper container element
 * @param {Element} els.next       Next button element
 * @param {Element} els.prev       Previous button element
 * @param {Element} els.play       Play button element
 * @param {Element} els.pause      Pause button element
 * @param {Element} els.pagination Pagination element
 * @param {Object}  config         Swiper config
 * @return {Object} Swiper instance
 */
export default function createSwiper( els, config = {} ) {
	const isVisible = 0 < els.container.offsetWidth && 0 < els.container.offsetHeight;

	// Don't initialize if the swiper is hidden on initial mount.
	if ( ! isVisible ) {
		return false;
	}

	const reduceMotion = prefersReducedMotion();

	const swiper = new Swiper( els.container, {
		/**
		 * Remove the messages, as we're announcing the slide content and number.
		 * These messages are overwriting the slide announcement.
		 */
		a11y: false,
		autoplay: !! config.autoplay && {
			enabled: true,
			delay: config.delay,
			disableOnInteraction: false,
			waitForTransition: ! reduceMotion,
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
			renderBullet: ( index, className ) => {
				// Use a custom render, as Swiper's render is inaccessible.
				return `<button class="${ className }"><span>${ sprintf(
					/* translators: %s: indicates which slide the slider is on. */
					__( 'Slide %s', 'newspack-content-blocks' ),
					index + 1
				) }</span></button>`;
			},
		},
		watchSlidesProgress: config.slidesPerView > 1,
		preventClicksPropagation: false, // Necessary for normal block interactions.
		releaseFormElements: false,
		setWrapperSize: true,
		slidesPerView: config.slidesPerView,
		spaceBetween: 16,
		speed: reduceMotion ? 0 : 300,
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
			init() {
				forEachNode( this.wrapperEl.querySelectorAll( '.swiper-slide' ), slide => deactivateSlide( slide ) );

				setAspectRatio.call( this ); // Set the aspect ratio on init.
				activateSlide( this.slides[ this.activeIndex ] ); // Set-up our active slide.
			},

			slideChange() {
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
						__( 'Slide %1$s of %2$s', 'newspack-content-blocks' ),
						this.realIndex + 1,
						this.pagination?.bullets?.length || 0
					);

					speak(
						escapeHTML(
							`${ currentSlide.innerText },
							${ alt ? /* translators: %s: the title of the image. */ sprintf( __( 'Image: %s,', 'newspack-content-blocks' ), alt ) : '' }
							${ slideInfo }`
						),
						'assertive'
					);
				}
			},
		},
	} );

	/**
	 * Forces an aspect ratio for each slide.
	 */
	function setAspectRatio() {
		const { aspectRatio } = config;
		const slides = Array.from( this.slides );

		slides.forEach( slide => {
			slide.style.height = `${ slide.clientWidth * aspectRatio }px`;
		} );
	}

	swiper.on( 'imagesReady', setAspectRatio );
	swiper.on( 'resize', setAspectRatio );

	if ( config.autoplay ) {
		const holds = new Set();
		let isHolding = false;
		let resumeAfterHold = false;
		let isInitializing = reduceMotion;

		/**
		 * Stops autoplay while a mouse is over the carousel or keyboard focus is inside it,
		 * except on Play and Pause, leaving the play/pause button and announcements untouched.
		 *
		 * @param {string} reason Either 'pointer' or 'focus'.
		 */
		function hold( reason ) {
			holds.add( reason );
			if ( swiper.autoplay.running ) {
				resumeAfterHold = true;
				isHolding = true;
				swiper.autoplay.stop();
				isHolding = false;
			}
		}

		/**
		 * Restarts autoplay once nothing holds it, if a hold stopped it.
		 *
		 * @param {string} reason Either 'pointer' or 'focus'.
		 */
		function release( reason ) {
			holds.delete( reason );
			if ( ! holds.size && resumeAfterHold ) {
				resumeAfterHold = false;
				isHolding = true;
				swiper.autoplay.start();
				isHolding = false;
			}
		}

		/**
		 * Holds autoplay while a mouse is over the carousel.
		 *
		 * @param {PointerEvent} event Pointer event.
		 */
		function handlePointerEnter( event ) {
			if ( 'mouse' === event.pointerType ) {
				hold( 'pointer' );
			}
		}

		/**
		 * Releases the mouse hold.
		 *
		 * @param {PointerEvent} event Pointer event.
		 */
		function handlePointerLeave( event ) {
			if ( 'mouse' === event.pointerType ) {
				release( 'pointer' );
			}
		}

		/**
		 * Holds autoplay on keyboard focus inside the carousel, except on Play and Pause.
		 *
		 * @param {FocusEvent} event Focus event.
		 */
		function handleFocusIn( event ) {
			if ( ! event.target.matches( ':focus-visible' ) || event.target === els.play || event.target === els.pause ) {
				release( 'focus' );
			} else {
				hold( 'focus' );
			}
		}

		/**
		 * Releases the focus hold when focus leaves the carousel.
		 *
		 * @param {FocusEvent} event Focus event.
		 */
		function handleFocusOut( event ) {
			if ( event.target === event.target.ownerDocument.activeElement ) {
				return;
			}
			if ( ! els.block.contains( event.relatedTarget ) ) {
				release( 'focus' );
			}
		}

		/**
		 * Handles the Pause button click.
		 */
		function handlePauseButtonClick() {
			resumeAfterHold = false;
			swiper.autoplay.stop();
			els.play.focus(); // Move focus to the play button.
		}

		/**
		 * Handles the Play button click.
		 */
		function handlePlayButtonClick() {
			resumeAfterHold = false;
			swiper.autoplay.start();
			els.pause.focus(); // Move focus to the pause button.
		}

		swiper.on( 'init', function () {
			els.play.addEventListener( 'click', handlePlayButtonClick );
			els.pause.addEventListener( 'click', handlePauseButtonClick );
			els.block.addEventListener( 'pointerenter', handlePointerEnter );
			els.block.addEventListener( 'pointerleave', handlePointerLeave );
			els.block.addEventListener( 'focusin', handleFocusIn );
			els.block.addEventListener( 'focusout', handleFocusOut );
			if ( reduceMotion ) {
				swiper.autoplay.stop();
				isInitializing = false;
				els.block.classList.remove( autoplayClassName );
			}
		} );

		swiper.on( 'autoplayStart', function () {
			if ( isHolding || isInitializing ) {
				return;
			}
			els.block.classList.add( autoplayClassName ); // Hide play & show pause button.
			speak( __( 'Playing', 'newspack-content-blocks' ), 'assertive' );
		} );

		swiper.on( 'autoplayStop', function () {
			if ( isHolding || isInitializing ) {
				return;
			}
			els.block.classList.remove( autoplayClassName ); // Hide pause & show play button.
			speak( __( 'Paused', 'newspack-content-blocks' ), 'assertive' );
		} );

		swiper.on( 'beforeDestroy', function () {
			els.play.removeEventListener( 'click', handlePlayButtonClick );
			els.pause.removeEventListener( 'click', handlePauseButtonClick );
			els.block.removeEventListener( 'pointerenter', handlePointerEnter );
			els.block.removeEventListener( 'pointerleave', handlePointerLeave );
			els.block.removeEventListener( 'focusin', handleFocusIn );
			els.block.removeEventListener( 'focusout', handleFocusOut );
		} );
	}

	swiper.init();

	return swiper;
}
