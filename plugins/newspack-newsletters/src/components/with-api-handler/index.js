/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { dispatch, select } from '@wordpress/data';
import { createHigherOrderComponent } from '@wordpress/compose';
import { useState } from '@wordpress/element';

import { SHARE_BLOCK_NOTICE_ID } from '../../editor/blocks/share/consts';

const successNote = __( 'Campaign sent on', 'newspack-newsletters' );
const shouldRemoveNotice = notice => {
	return (
		notice.id !== SHARE_BLOCK_NOTICE_ID &&
		notice.id !== 'newspack-newsletters-email-content-too-large' &&
		'error' !== notice.status &&
		( 'success' !== notice.status || -1 === notice.content.indexOf( successNote ) )
	);
};

export default () =>
	createHigherOrderComponent(
		OriginalComponent => props => {
			const [ inFlight, setInFlight ] = useState( false );
			const [ errors, setErrors ] = useState( {} );
			const { createSuccessNotice, createErrorNotice, removeNotice } = dispatch( 'core/notices' );
			const { getNotices } = select( 'core/notices' );
			const setInFlightForAsync = () => {
				setInFlight( true );
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
					successNote={ successNote }
				/>
			);
		},
		'with-api-handler'
	);
