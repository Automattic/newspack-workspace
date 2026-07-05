/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

/**
 * Handle JSON from a file input.
 *
 * @param file The file to extract JSON content.
 * @return Promise which resolves into a JSON object or reject with message.
 */
export function handleJSONFile( file: File ): Promise< unknown > {
	return new Promise( ( resolve, reject ) => {
		const reader = new FileReader();
		reader.readAsText( file, 'UTF-8' );
		reader.onload = function ( ev ) {
			let json: unknown;
			try {
				// readAsText always yields a string result.
				json = JSON.parse( ev.target?.result as string );
			} catch ( error ) {
				reject( __( 'Invalid JSON file', 'newspack-plugin' ) );
			}
			resolve( json );
		};
		reader.onerror = function () {
			reject( __( 'Unable to read file', 'newspack-plugin' ) );
		};
	} );
}
