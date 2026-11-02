import { getMeteringSettings, getMeteringStoreKey } from './utils/metering-settings';

/**
 * The reader's views for this meter, cleared if their period has ended.
 *
 * The server sends the next reset as `settings.expiration`, the same timestamp the
 * signed-in meter uses, and the one after it as `settings.next_expiration` for a page
 * served from cache after the first has passed. Views clear when the page's reset is
 * later than the one stored with them, which leaves the decision to the server's
 * calendar rather than the browser's clock. An earlier reset that is still ahead (a new
 * week start, a shorter period) is adopted without clearing them.
 *
 * @param {Object} store    Reader data store.
 * @param {Object} settings Metering settings.
 *
 * @return {Object} The reader's views and when they reset.
 */
function getUserData( store, settings ) {
	const storeKey = getMeteringStoreKey( settings );
	const now = Math.floor( Date.now() / 1000 );
	const expiration = parseInt( settings.expiration, 10 ) || 0;
	const nextReset = expiration > now ? expiration : parseInt( settings.next_expiration, 10 ) || 0;
	const data = store.get( storeKey ) || { content: [], expiration: 0 };
	data.expiration = parseInt( data.expiration, 10 ) || 0;
	if ( nextReset > data.expiration ) {
		data.content = [];
		data.expiration = nextReset;
	} else if ( nextReset < data.expiration && nextReset > now ) {
		data.expiration = nextReset;
	}
	store.set( storeKey, data );
	return data;
}

function lockContent( ras, settings ) {
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

function meter( ras ) {
	const settings = getMeteringSettings();
	// No settings means the server did not render a meter for this post, so there is
	// no allowance to spend and nothing for the meter to do.
	if ( ! settings ) {
		return;
	}
	const data = getUserData( ras.store, settings );
	let locked = false;
	// Lock content if reached limit, remove gate content if not.
	if ( settings.count <= data.content.length && ! data.content.includes( settings.post_id ) ) {
		lockContent( ras, settings );
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
		// Add current content to read content.
		if ( ! data.content.includes( settings.post_id ) ) {
			data.content.push( settings.post_id );
			ras.store.set( getMeteringStoreKey( settings ), data );
		}
	}
}

window.newspackRAS = window.newspackRAS || [];
window.newspackRAS.push( ras => meter( ras ) );
