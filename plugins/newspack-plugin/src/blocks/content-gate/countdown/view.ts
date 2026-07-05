/* globals newspack_metering_settings */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { domReady } from '../../../utils';

domReady( () => {
	if ( typeof newspack_metering_settings === 'undefined' ) {
		return;
	}
	const { count, gate_id } = newspack_metering_settings;
	window.newspackRAS = window.newspackRAS || [];
	window.newspackRAS.push( ras => {
		const { authenticated } = ras?.getReader() || { authenticated: false };
		if ( authenticated ) {
			return;
		}
		const storeKey = 'metering-' + ( gate_id || 0 );
		const { content } = ras?.store?.get( storeKey ) || { content: [] };
		const countdownEl = document.querySelector( '.newspack-content-gate-countdown' );
		if ( ! countdownEl ) {
			return;
		}
		// Replace countdown for anonymous users.
		const countdown = sprintf(
			/* translators: 1: current number of metered views, 2: total metered views. */ __( '%1$d/%2$d', 'newspack-plugin' ),
			content.length,
			count
		);
		countdownEl.textContent = countdown;
	} );
} );
