import domReady from '@wordpress/dom-ready';
import { queuePageReload } from '../reader-activation/utils';
import { getMeteringSettings, getMeteringStoreKey } from './utils/metering-settings';
import { getNoticeIcon } from '../newspack-ui/js/icons';

import './content-banner.scss';

// Content-gifting modal config localized as `newspack_content_gifting` by Content_Gifting.
declare const newspack_content_gifting: {
	ajax_url: string;
	post_id: string;
	copied_label: string;
	expiration_time: string;
};

window.newspackRAS = window.newspackRAS || [];

domReady( () => {
	const cta = document.querySelector< HTMLElement >( '.newspack-content-gifting__cta,.newspack-countdown-banner__cta' );
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
			// `'ResizeObserver' in window` narrows `window` to `never` in this branch; re-widen to call it.
			( window as Window ).addEventListener( 'resize', updateOffset );
		}
	};

	setBodyOffset();

	// Countdown banner.
	window.newspackRAS?.push( ras => {
		const views = document.querySelector< HTMLElement >( '.newspack-countdown-banner__views' );
		if ( ! views || 0 < parseInt( views.textContent as string ) ) {
			return;
		}
		const settings = getMeteringSettings();
		// The reader-activation store is a string-keyed unknown bag; assert the accessor and entry shape.
		const data = ( ( settings && ( ras?.store as { get: ( key: string ) => unknown } | undefined )?.get( getMeteringStoreKey( settings ) ) ) as
			| { content: unknown[] }
			| undefined ) || {
			content: [],
		};
		const total = parseInt( ( document.querySelector( '.newspack-countdown-banner__total_views' )?.textContent || 0 ) as string );
		if ( ! total || 0 >= total ) {
			return;
		}
		if ( data.content.length > 0 ) {
			// textContent coerces to string; the DOM type disallows a bare number.
			views.textContent = String( Math.min( data.content.length, total ) );
			cta!.classList.remove( 'newspack-countdown-banner__cta--hidden' );
		}
	} );

	// Content gifting modal.
	const modal = document.getElementById( 'newspack-content-gifting-modal' );
	if ( ! modal ) {
		return;
	}
	// These modal elements are guaranteed present in the gifting markup; assert type and non-null at the DOM boundary.
	const spinner = modal.querySelector( '.newspack-ui__spinner' ) as HTMLElement;
	const info = modal.querySelector( '.newspack-content-gifting__info' ) as HTMLElement;
	const errorMessage = modal.querySelector( '.newspack-ui__notice--error' ) as HTMLElement;
	const linkContainer = modal.querySelector( '.newspack-content-gifting__link-container' ) as HTMLElement;
	const urlInput = modal.querySelector( '#content-gifting-url' ) as HTMLInputElement;
	const copyButton = modal.querySelector( '[data-copy-button]' ) as HTMLButtonElement;

	spinner.style.display = 'none';
	linkContainer.style.display = 'none';
	info.style.display = 'none';

	const copy = ( ev: Event ) => {
		ev.preventDefault();
		if ( copyButton.hasAttribute( 'data-copied' ) ) {
			return;
		}
		urlInput.select();
		document.execCommand( 'copy' );
		const originalText = copyButton.textContent;
		// A data flag rather than disabled or aria-disabled: disabled drops focus out of the modal, and both would style the confirmation as unavailable.
		copyButton.setAttribute( 'data-copied', '' );
		copyButton.textContent = newspack_content_gifting.copied_label;
		copyButton.focus();
		setTimeout( () => {
			copyButton.textContent = originalText;
			copyButton.removeAttribute( 'data-copied' );
		}, 5000 );
	};

	copyButton.addEventListener( 'click', copy );

	const buttons = document.querySelectorAll( 'a.share-newspack-gift-article,.newspack-content-gifting__gift-button' );
	[ ...buttons ].forEach( button => {
		button.addEventListener( 'click', ev => {
			ev.preventDefault();
			ev.stopPropagation();
			linkContainer.style.display = 'none';
			info.style.display = 'none';
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
						// `delete` needs an optional operand; the inline-style property is declared required.
						delete ( urlInput.style as { pointerEvents?: string } ).pointerEvents;
						copyButton.disabled = false;
						// The modal opens before its link arrives, so focus moves to Copy link once it's
						// there, unless the reader has already moved it.
						const active = modal.ownerDocument.activeElement;
						if (
							'open' === modal.dataset.state &&
							( ! modal.contains( active ) || active === modal || ( active as Element ).matches( 'h2' ) )
						) {
							copyButton.focus();
						}
					}
				} )
				.catch( err => {
					errorMessage.textContent = err.message || 'An error occurred. Please try again.';
					const errorIcon = getNoticeIcon( 'error' );
					if ( errorIcon ) {
						errorMessage.prepend( errorIcon );
					}
					errorMessage.style.display = '';
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
		window.newspackRAS!.push( ras => {
			ras.on( 'reader', ( { detail: { authenticated } } ) => {
				if ( authenticated ) {
					queuePageReload();
				}
			} );
		} );
	}
} );
