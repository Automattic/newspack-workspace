/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { domReady } from '../../../utils';

domReady( () => {
	window.newspackRAS = window.newspackRAS || [];
	window.newspackRAS.push( ras => {
		// Read the settings here rather than at module evaluation: an optimizer that
		// delays scripts can replay the inline settings tag after this file has run.
		const settings = window.newspack_metering_settings;
		if ( ! settings ) {
			return;
		}
		const { count, gate_id, meter_key } = settings;
		const { authenticated } = ras?.getReader() || { authenticated: false };
		if ( authenticated ) {
			return;
		}
		const storeKey = 'metering-' + ( meter_key || gate_id || 0 );
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
