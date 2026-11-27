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
		// The reader-activation store is typed as a string-keyed unknown bag; assert the
		// store accessor and the metering entry's shape at the boundary.
		const { content } = ( ( ras?.store as { get: ( key: string ) => unknown } | undefined )?.get( storeKey ) as
			| { content: unknown[] }
			| undefined ) || { content: [] };
		const countdownEl = document.querySelector( '.newspack-content-gate-countdown' );
		if ( ! countdownEl ) {
			return;
		}
		// Replace countdown for anonymous users.
		const countdown = sprintf(
			/* translators: 1: current number of metered views, 2: total metered views. */ __( '%1$d/%2$d', 'newspack-plugin' ),
			content.length,
			// %d coerces the localized value, which wp_localize_script() may emit as a string.
			count as number
		);
		countdownEl.textContent = countdown;
	} );
} );
