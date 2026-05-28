/* globals newspack_content_gifting */
import domReady from '@wordpress/dom-ready';
import { queuePageReload } from '../reader-activation/utils';

import './content-banner.scss';

const settings = window.newspack_metering_settings || {};
const storeKey = 'metering-' + settings.gate_id || 0;

window.newspackRAS = window.newspackRAS || [];

domReady( () => {
	const cta = document.querySelector( '.newspack-content-gifting__cta,.newspack-countdown-banner__cta' );
	const setBodyOffset = () => {
		if (
			! cta ||
			( ! document.body.classList.contains( 'newspack-is-gifted-post' ) &&
				! document.body.classList.contains( 'newspack-has-countdown-banner' ) )
		) {
			return;
		}

		const updateOffset = () => {
			document.body.style.setProperty( '--newspack-content-banner-cta-offset', `${ cta.offsetHeight }px` );
		};

		updateOffset();

		if ( 'ResizeObserver' in window ) {
			const resizeObserver = new ResizeObserver( updateOffset );
			resizeObserver.observe( cta );
		} else {
			window.addEventListener( 'resize', updateOffset );
		}
	};

	setBodyOffset();

	// Countdown banner.
	window.newspackRAS?.push( ras => {
		const views = document.querySelector( '.newspack-countdown-banner__views' );
		if ( ! views || 0 < parseInt( views.textContent ) ) {
			return;
		}
		const data = ras?.store?.get( storeKey ) || {
			content: [],
		};
		const total = parseInt( document.querySelector( '.newspack-countdown-banner__total_views' )?.textContent || 0 );
		if ( ! total || 0 >= total ) {
			return;
		}
		if ( data.content.length > 0 ) {
			views.textContent = Math.min( data.content.length, total );
			cta.classList.remove( 'newspack-countdown-banner__cta--hidden' );
		}
	} );

	// Content gifting modal.
	const modal = document.getElementById( 'newspack-content-gifting-modal' );
	if ( ! modal ) {
		return;
	}
	const spinner = modal.querySelector( '.newspack-ui__spinner' );
	const info = modal.querySelector( '.newspack-content-gifting__info' );
	const errorMessage = modal.querySelector( '.newspack-ui__notice--error' );
	const linkContainer = modal.querySelector( '.newspack-content-gifting__link-container' );
	const urlInput = modal.querySelector( '#content-gifting-url' );
	const copyButton = modal.querySelector( '[data-copy-button]' );

	spinner.style.display = 'none';
	linkContainer.style.display = 'none';
	info.style.display = 'none';

	const copy = ev => {
		ev.preventDefault();
		urlInput.select();
		document.execCommand( 'copy' );
		const originalText = copyButton.textContent;
		copyButton.setAttribute( 'disabled', true );
		copyButton.textContent = newspack_content_gifting.copied_label;
		setTimeout( () => {
			copyButton.textContent = originalText;
			copyButton.removeAttribute( 'disabled' );
		}, 5000 );
	};

	copyButton.addEventListener( 'click', copy );

	const buttons = document.querySelectorAll( 'a.share-newspack-gift-article,.newspack-content-gifting__gift-button' );
	[ ...buttons ].forEach( button => {
		button.addEventListener( 'click', ev => {
			ev.preventDefault();
			modal.setAttribute( 'data-state', 'open' );
			spinner.style.display = 'flex';
			fetch( newspack_content_gifting.ajax_url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams( {
					post_id: newspack_content_gifting.post_id,
				} ),
			} )
				.then( response => response.json() )
				.then( data => {
					info.innerHTML = data.body;
					urlInput.value = data.url;
					errorMessage.style.display = 'none';
					info.style.display = 'block';
					linkContainer.style.display = 'block';
					if ( ! data.key ) {
						urlInput.disabled = true;
						urlInput.style.pointerEvents = 'none';
						copyButton.disabled = true;
					} else {
						urlInput.disabled = false;
						delete urlInput.style.pointerEvents;
						copyButton.disabled = false;
					}
				} )
				.catch( err => {
					errorMessage.innerHTML = err.message || 'An error occurred. Please try again.';
					errorMessage.style.display = 'block';
					info.style.display = 'none';
					linkContainer.style.display = 'none';
				} )
				.finally( () => {
					spinner.style.display = 'none';
				} );
		} );
	} );

	// Replace the `content_key` parameter with a cookie.
	const params = new URLSearchParams( window.location.search );
	const contentKey = params.get( 'content_key' );
	if ( contentKey ) {
		document.cookie = `wp_newspack_content_key=${ contentKey }; path=/; max-age=${ newspack_content_gifting.expiration_time }`;
		params.delete( 'content_key' );
		window.history.replaceState( {}, '', window.location.pathname + ( params.toString() ? '?' + params.toString() : '' ) );
	}

	// Refresh the gifted post page after authenticating.
	if ( document.body.classList.contains( 'newspack-is-gifted-post' ) ) {
		window.newspackRAS.push( ras => {
			ras.on( 'reader', ( { detail: { authenticated } } ) => {
				if ( authenticated ) {
					queuePageReload();
				}
			} );
		} );
	}
} );
