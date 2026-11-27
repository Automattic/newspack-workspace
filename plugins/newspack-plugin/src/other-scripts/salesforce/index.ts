/* globals newspack_salesforce_data */

/**
 * WordPress imports.
 */
import { __ } from '@wordpress/i18n';

( function () {
	const statusMarker = document.getElementById( 'newspack-salesforce-sync-status' );
	// Non-null assertions preserve the original behavior: this line runs before
	// the `if ( statusMarker )` guard below, so a missing marker throws here.
	const statusMarkerLabel = statusMarker!.querySelector( 'span' )!;
	const { base_url: baseUrl, order_id: orderId, salesforce_url: salesforceUrl, nonce } = newspack_salesforce_data;

	if ( statusMarker ) {
		fetch( `${ baseUrl }newspack/salesforce/v1/order?orderId=${ orderId }`, {
			headers: {
				'X-WP-Nonce': nonce,
			},
		} )
			.then( response => response.json() )
			.then( ( opportunityId: unknown ) => {
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
			.catch( ( e: unknown ) => {
				statusMarker.classList.add( 'status-failed' );
				// String() replicates the DOMString coercion textContent applies to non-string values.
				statusMarkerLabel.textContent = String( e || __( 'Error fetching status', 'newspack-plugin' ) );
			} );
	}
} )();
