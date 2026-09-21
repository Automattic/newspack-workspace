/**
 * The localized settings object is read on demand rather than at module evaluation.
 * An optimizer that delays scripts (Perfmatters' "Delay JavaScript", and anything
 * like it) can replay the inline settings tag after this file has already run, so a
 * read at parse time throws and the meter never runs — which serves every metered
 * article in full. By the time the reader-activation queue drains, the settings are
 * there.
 *
 * @return {Object|null} The metering settings, or null when they are unavailable.
 */
function getSettings() {
	const settings = window.newspack_metering_settings;
	if ( ! settings || 'object' !== typeof settings ) {
		if ( ! getSettings.warned ) {
			getSettings.warned = true;
			// eslint-disable-next-line no-console
			console.warn( 'Newspack: metering settings are unavailable, so the meter did not run.' );
		}
		return null;
	}
	return settings;
}

function getStoreKey( settings ) {
	return 'metering-' + ( settings.meter_key || settings.gate_id || 0 );
}

function getCurrentExpiration( settings ) {
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

function getUserData( store, settings ) {
	const storeKey = getStoreKey( settings );
	const currentExpiration = getCurrentExpiration( settings );
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
	const settings = getSettings();
	// Without settings there is no allowance to spend, so leave the server-rendered
	// gate in place instead of removing it.
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
			ras.store.set( getStoreKey( settings ), data );
		}
	}
}

window.newspackRAS = window.newspackRAS || [];
window.newspackRAS.push( ras => meter( ras ) );
