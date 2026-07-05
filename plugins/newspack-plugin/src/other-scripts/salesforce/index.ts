/* globals newspack_salesforce_data */

/**
 * WordPress imports.
 */
import { __ } from '@wordpress/i18n';

( function () {
	const statusMarker = document.getElementById( 'newspack-salesforce-sync-status' );
	const statusMarkerLabel = statusMarker.querySelector( 'span' );
	const { base_url: baseUrl, order_id: orderId, salesforce_url: salesforceUrl, nonce } = newspack_salesforce_data;

	if ( statusMarker ) {
		fetch( `${ baseUrl }newspack/salesforce/v1/order?orderId=${ orderId }`, {
			headers: {
				'X-WP-Nonce': nonce,
			},
		} )
			.then( response => response.json() )
			.then( opportunityId => {
				if ( false === opportunityId ) {
					statusMarker.classList.add( 'status-failed' );
					statusMarkerLabel.textContent = __( 'Not synced', 'newspack-plugin' );
				} else if ( 'string' === typeof opportunityId ) {
					const anchor = document.createElement( 'a' );
					statusMarker.classList.add( 'status-completed' );
					anchor.href = `${ salesforceUrl }/lightning/r/Opportunity/${ opportunityId }/view`;
					anchor.setAttribute( 'target', '_blank' );
					anchor.setAttribute( 'rel', 'noopener noreferrer' );
					anchor.textContent = __( 'Synced', 'newspack-plugin' );
					statusMarkerLabel.textContent = '';
					statusMarkerLabel.appendChild( anchor );
				} else {
					throw __( 'Error fetching status', 'newspack-plugin' );
				}
			} )
			.catch( e => {
				statusMarker.classList.add( 'status-failed' );
				statusMarkerLabel.textContent = e || __( 'Error fetching status', 'newspack-plugin' );
			} );
	}
} )();
