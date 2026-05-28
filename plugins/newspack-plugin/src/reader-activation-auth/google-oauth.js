/* globals newspack_reader_activation_labels */

/**
 * Internal dependencies.
 */
import { domReady, convertFormDataToObject } from '../utils';
import { debugLog } from '../reader-activation/utils';

domReady( function () {
	const loginsElements = document.querySelectorAll( '.newspack-reader__logins' );
	[ ...loginsElements ].forEach( element => {
		element.classList.remove( 'newspack-reader__logins--disabled' );
	} );
	const googleLoginElements = document.querySelectorAll( '.newspack-ui__button--google-oauth' );
	googleLoginElements.forEach( googleLoginElement => {
		const googleLoginForm = googleLoginElement.closest( 'form' );
		const checkLoginStatus = metadata => {
			debugLog( 'log', '[Google OAuth] checkLoginStatus called with metadata:', metadata );
			fetch( `/wp-json/newspack/v1/login/google/register?metadata=${ JSON.stringify( metadata ) }` )
				.then( res => {
					debugLog( 'log', '[Google OAuth] Register response status:', res.status );
					res.json()
						.then( ( { message, data } ) => {
							debugLog( 'log', '[Google OAuth] Register response data:', { message, data } );
							if ( googleLoginForm?.endLoginFlow ) {
								googleLoginForm.endLoginFlow( message, res.status, data );
							}
						} )
						.catch( error => {
							debugLog( 'error', '[Google OAuth] Error parsing register response:', error );
							if ( googleLoginForm?.endLoginFlow ) {
								googleLoginForm.endLoginFlow( error?.message, res.status );
							}
						} );
				} )
				.catch( error => {
					debugLog( 'error', '[Google OAuth] Error fetching register endpoint:', error );
					if ( googleLoginForm?.endLoginFlow ) {
						googleLoginForm.endLoginFlow( error?.message );
					}
				} );
		};

		googleLoginElement.addEventListener( 'click', () => {
			debugLog( 'log', '[Google OAuth] Login button clicked' );
			if ( googleLoginForm?.startLoginFlow ) {
				googleLoginForm.startLoginFlow();
			}

			const metadata = googleLoginForm ? convertFormDataToObject( new FormData( googleLoginForm ), [ 'lists[]' ] ) : {};
			metadata.current_page_url = window.location.href;
			debugLog( 'log', '[Google OAuth] Opening auth window with metadata:', metadata );
			const authWindow = window.open( 'about:blank', 'newspack_google_login', 'width=500,height=600' );
			let messageListener = null;

			// Clean up function to remove listeners.
			const cleanup = () => {
				debugLog( 'log', '[Google OAuth] Cleanup called' );
				if ( messageListener ) {
					window.removeEventListener( 'message', messageListener );
					messageListener = null;
				}
			};

			// Listen for postMessage from OAuth callback.
			messageListener = event => {
				debugLog( 'log', '[Google OAuth] Received postMessage:', {
					origin: event.origin,
					data: event.data,
					expectedOrigin: window.location.origin,
				} );
				// Validate the message origin matches our domain.
				if ( event.origin === window.location.origin && event.data === 'google-oauth-success' ) {
					debugLog( 'log', '[Google OAuth] Valid success message received, calling checkLoginStatus' );
					cleanup();
					checkLoginStatus( metadata );
				}
			};
			window.addEventListener( 'message', messageListener );

			fetch( '/wp-json/newspack/v1/login/google?r=' + Math.random() )
				.then( res => res.json().then( data => Promise.resolve( { data, status: res.status } ) ) )
				.then( ( { data, status } ) => {
					if ( status !== 200 ) {
						if ( authWindow ) {
							authWindow.close();
						}
						cleanup();
						if ( googleLoginForm?.endLoginFlow ) {
							googleLoginForm.endLoginFlow( data.message, status );
						}
					} else if ( authWindow ) {
						authWindow.location = data;
					} else if ( googleLoginForm?.endLoginFlow ) {
						cleanup();
						googleLoginForm.endLoginFlow( newspack_reader_activation_labels.blocked_popup );
					}
				} )
				.catch( error => {
					cleanup();
					if ( googleLoginForm?.endLoginFlow ) {
						googleLoginForm.endLoginFlow( error?.message, 400 );
					}
					if ( authWindow ) {
						authWindow.close();
					}
				} );
		} );
	} );
} );
