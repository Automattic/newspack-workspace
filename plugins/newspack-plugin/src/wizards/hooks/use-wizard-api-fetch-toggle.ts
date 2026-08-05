/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect, createElement } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { Waiting } from '../../../packages/components/src';
import { useWizardApiFetch } from './use-wizard-api-fetch';

/**
 * Hook to perform toggle operations using the Wizard API.
 */
function useWizardApiFetchToggle< T >( {
	path,
	apiNamespace,
	refreshOn = [],
	data,
	description,
}: {
	path: `/newspack/v${ string }`;
	apiNamespace: string;
	refreshOn?: ApiMethods[];
	data: T;
	description: string;
} ) {
	const [ apiData, setApiData ] = useState< T >( data );

	const [ actionText, setActionText ] = useState< React.ReactNode >( null );

	const { wizardApiFetch, isFetching, errorMessage } = useWizardApiFetch( apiNamespace );

	/**
	 * Perform `GET` request on initial load.
	 */
	useEffect( () => {
		apiFetchToggle();
	}, [] );

	/**
	 * Toggle function for the Wizard API fetch.
	 *
	 * `dataToSend` is a `Partial< T >` so callers can send only the writable
	 * fields and omit server-derived, read-only ones. The fetched response
	 * (always the full `T`) is what gets written back into state.
	 *
	 * @param dataToSend Data to send to endpoint.
	 * @param isToggleOn If set method will default to POST, otherwise GET.
	 * @return The request promise, so callers can react to failures. Rejects
	 *         with the API error (already surfaced via `errorMessage`).
	 */
	function apiFetchToggle( dataToSend?: Partial< T >, isToggleOn?: boolean ) {
		const method = typeof isToggleOn === 'boolean' && isToggleOn ? 'POST' : 'GET';

		const options: ApiFetchOptions = {
			path,
			method,
		};
		if ( dataToSend ) {
			options.data = dataToSend;
		}
		if ( method === 'POST' ) {
			// Mirror a successful save into the store's GET cache. The mount
			// GET is served from that cache, so without this a remount (e.g.
			// revisiting a settings tab) would show — and a later save could
			// write back — the stale first-load snapshot.
			options.updateCacheMethods = [ 'GET' ];
		}
		return wizardApiFetch< T >( options, {
			onSuccess: setApiData,
			onFinally() {
				if ( refreshOn.includes( method ) ) {
					setActionText( createElement( 'span', { className: 'newspack-text-muted' }, __( 'Page reloading…', 'newspack-plugin' ) ) );
					if ( ! errorMessage ) {
						window.location.reload();
					}
				}
			},
		} );
	}
	return {
		actionText: isFetching ? createElement( Waiting ) : actionText,
		apiData,
		apiFetchToggle,
		description: isFetching ? __( 'Loading…', 'newspack-plugin' ) : description,
		errorMessage,
		isFetching,
	};
}

export default useWizardApiFetchToggle;
