/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { dispatch, select } from '@wordpress/data';
import { createHigherOrderComponent } from '@wordpress/compose';
import { useState } from '@wordpress/element';

import { SHARE_BLOCK_NOTICE_ID } from '../../editor/blocks/share/consts';
import { CAMPAIGN_SENT_NOTICE_ID } from '../../utils/consts';
import { isManualProvider } from '../../utils/service-provider';

// The manual provider doesn't send through an ESP, so the post-publish notice uses publish wording.
// Read lazily (like the other provider checks in this PR) so the wording resolves from the global at
// use time rather than being frozen at module load, independent of script-enqueue ordering.
const getSuccessNote = () => ( isManualProvider() ? __( 'Published on', 'newspack-newsletters' ) : __( 'Campaign sent on', 'newspack-newsletters' ) );
const shouldRemoveNotice = notice => {
	return (
		notice.id !== SHARE_BLOCK_NOTICE_ID &&
		notice.id !== 'newspack-newsletters-email-content-too-large' &&
		// Keep the post-publish notice by its stable id, so this doesn't depend on its wording.
		notice.id !== CAMPAIGN_SENT_NOTICE_ID &&
		'error' !== notice.status &&
		( 'success' !== notice.status || -1 === notice.content.indexOf( getSuccessNote() ) )
	);
};

export default () =>
	createHigherOrderComponent(
		OriginalComponent => props => {
			const [ inFlight, setInFlight ] = useState( false );
			const [ errors, setErrors ] = useState( {} );
			const { createSuccessNotice, createErrorNotice, removeNotice } = dispatch( 'core/notices' );
			const { getNotices } = select( 'core/notices' );
			const setInFlightForAsync = ( value = true ) => {
				setInFlight( value );
			};
			const apiFetchWithErrorHandling = apiRequest => {
				setInFlight( true );
				return new Promise( resolve => {
					apiFetch( apiRequest )
						.then( response => {
							getNotices().forEach( notice => {
								if ( shouldRemoveNotice( notice ) ) {
									removeNotice( notice.id );
								}
							} );
							if ( response.message ) {
								createSuccessNotice( response.message );
							}
							setInFlight( false );
							setErrors( {} );
							resolve( response );
						} )
						.catch( error => {
							getNotices().forEach( notice => {
								if ( shouldRemoveNotice( notice ) ) {
									removeNotice( notice.id );
								}
							} );
							createErrorNotice( error.message );
							setInFlight( false );
							setErrors( { [ error.code ]: true } );
						} );
				} );
			};
			return (
				<OriginalComponent
					{ ...props }
					apiFetchWithErrorHandling={ apiFetchWithErrorHandling }
					errors={ errors }
					setInFlightForAsync={ setInFlightForAsync }
					inFlight={ inFlight }
					successNote={ getSuccessNote() }
				/>
			);
		},
		'with-api-handler'
	);
