/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import type { APIFetchOptions } from '@wordpress/api-fetch';
import { dispatch, select } from '@wordpress/data';
import { createHigherOrderComponent } from '@wordpress/compose';
import { useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { SHARE_BLOCK_NOTICE_ID } from '../../editor/blocks/share/consts';

/** A notice from the `core/notices` store, as read by this handler. */
interface EditorNotice {
	id: string;
	status?: string;
	content: string;
}

/** Response shape read from the wrapped API requests. */
interface ApiHandlerResponse {
	message?: string;
	[ key: string ]: unknown;
}

const successNote = __( 'Campaign sent on', 'newspack-newsletters' );
const shouldRemoveNotice = ( notice: EditorNotice ) => {
	return (
		notice.id !== SHARE_BLOCK_NOTICE_ID &&
		notice.id !== 'newspack-newsletters-email-content-too-large' &&
		'error' !== notice.status &&
		( 'success' !== notice.status || -1 === notice.content.indexOf( successNote ) )
	);
};

export default () =>
	createHigherOrderComponent(
		OriginalComponent => ( props: Record< string, unknown > ) => {
			const [ inFlight, setInFlight ] = useState( false );
			const [ errors, setErrors ] = useState< Record< string, boolean > >( {} );
			const { createSuccessNotice, createErrorNotice, removeNotice } = dispatch( 'core/notices' ) as {
				createSuccessNotice: ( content: string ) => void;
				createErrorNotice: ( content: string ) => void;
				removeNotice: ( id: string ) => void;
			};
			// `getNotices` isn't present on this store's shipped selector types; cast at
			// this opaque `core/notices` store boundary (the method is always present at
			// runtime, hence the optional chaining below rather than a fallback).
			const { getNotices } = select( 'core/notices' ) as {
				getNotices?: () => EditorNotice[];
			};
			const setInFlightForAsync = ( value = true ) => {
				setInFlight( value );
			};
			const apiFetchWithErrorHandling = ( apiRequest: APIFetchOptions< true > ) => {
				setInFlight( true );
				return new Promise( resolve => {
					apiFetch< ApiHandlerResponse >( apiRequest )
						.then( response => {
							( getNotices?.() ?? [] ).forEach( notice => {
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
							( getNotices?.() ?? [] ).forEach( notice => {
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
