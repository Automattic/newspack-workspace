/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { domReady } from '../../../utils';
import { getMeteringSettings, getMeteringStoreKey } from '../../../content-gate/utils/metering-settings';

domReady( () => {
	window.newspackRAS = window.newspackRAS || [];
	window.newspackRAS.push( ras => {
		const settings = getMeteringSettings();
		if ( ! settings ) {
			return;
		}
		const { count } = settings;
		const { authenticated } = ras?.getReader() || { authenticated: false };
		if ( authenticated ) {
			return;
		}
		const storeKey = getMeteringStoreKey( settings );
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
