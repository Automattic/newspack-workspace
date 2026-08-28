/* globals newspack_metering_settings */

import { whenActivated } from '../reader-activation/prerender.js';

const settings = newspack_metering_settings;

const storeKey = 'metering-' + settings.gate_id || 0;

function getCurrentExpiration() {
	const date = new Date();
	// Reset time to 00:00:00:000.
	date.setHours( 0 );
	date.setMinutes( 0 );
	date.setSeconds( 0 );
	date.setMilliseconds( 0 );
	switch ( settings.period ) {
		case 'day':
			date.setDate( date.getDate() + 1 );
			break;
		case 'week':
			const day = date.getDay();
			const daysToSaturday = 6 - day;
			date.setDate( date.getDate() + daysToSaturday );
			break;
		case 'month':
			date.setMonth( date.getMonth() + 1 );
			date.setDate( 1 );
			break;
	}
	return parseInt( date.getTime() / 1000, 10 );
}

function getUserData( store ) {
	const currentExpiration = getCurrentExpiration();
	const data = store.get( storeKey ) || {
		content: [],
		expiration: currentExpiration,
	};
	data.expiration = parseInt( data.expiration, 10 ) || 0;
	if ( data.expiration !== currentExpiration ) {
		// Clear content if expired.
		if ( data.expiration < currentExpiration ) {
			data.content = [];
		}
		// Reset expiration.
		data.expiration = currentExpiration;
	}
	store.set( storeKey, data );
	return data;
}

function lockContent( ras ) {
	const content = document.querySelector( '.entry-content' );
	if ( ! content ) {
		return;
	}
	document.body.classList.add( 'newspack-content-locked' );

	// Remove campaign prompts.
	const prompts = document.querySelectorAll( '.newspack-popup' );
	const overlays = ras?.overlays?.get() || [];
	prompts.forEach( prompt => {
		prompt.parentNode.removeChild( prompt );
		if ( overlays.length ) {
			overlays.forEach( overlay => {
				if ( 0 === overlay.indexOf( 'prompt_' ) ) {
					ras.overlays.remove( overlay );
				}
			} );
		}
	} );
	// Replace content.
	content.innerHTML = settings.excerpt;
	// Remove comments.
	const commentsEl = document.getElementById( 'comments' );
	if ( commentsEl ) {
		commentsEl.remove();
	}
	// Append inline gate, if any.
	const inlineGate = document.querySelector( '.newspack-content-gate__inline-gate' );
	if ( inlineGate ) {
		content.appendChild( inlineGate );
	}

	// Remove countdown banner, if any.
	const countdownBanner = document.querySelector( '.newspack-countdown-banner__cta' );
	if ( countdownBanner ) {
		countdownBanner.remove();
	}
}

/**
 * Apply the metering gate to the current page.
 *
 * The decision half — whether to lock the content — runs immediately, including
 * during a prerender, so the page never activates and then changes under the
 * reader. Only the recording half waits: an article the reader never opens must
 * not spend one of their free reads.
 *
 * @param {Object} ras Reader Data Library object.
 */
export function meter( ras ) {
	const data = getUserData( ras.store );
	let locked = false;
	// Lock content if reached limit, remove gate content if not.
	if ( settings.count <= data.content.length && ! data.content.includes( settings.post_id ) ) {
		lockContent( ras );
		ras.dispatchActivity( 'metering_restricted', { post_id: settings.post_id, metering: data } );
		locked = true;
	} else {
		const gates = document.querySelectorAll( '.newspack-content-gate__gate' );
		gates.forEach( gate => {
			gate.parentNode.removeChild( gate );
		} );
	}
	if ( ! locked ) {
		// Push article_view activity.
		if ( settings.article_view ) {
			ras.dispatchActivity( settings.article_view.action, settings.article_view.data );
		}
		// Add current content to read content. Re-read the stored data inside the
		// deferred callback rather than reusing `data`: a prerendered page can sit
		// idle for minutes, and writing back a snapshot taken before activation
		// would drop reads the reader made in another tab meanwhile.
		whenActivated( () => {
			const current = getUserData( ras.store );
			if ( ! current.content.includes( settings.post_id ) ) {
				current.content.push( settings.post_id );
				ras.store.set( storeKey, current );
			}
		} );
	}
}

window.newspackRAS = window.newspackRAS || [];
window.newspackRAS.push( ras => meter( ras ) );
