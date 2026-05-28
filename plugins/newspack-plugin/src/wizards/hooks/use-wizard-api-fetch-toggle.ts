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
	 * @param dataToSend Data to send to endpoint.
	 * @param isToggleOn If set method will default to POST, otherwise GET.
	 */
	function apiFetchToggle( dataToSend?: T, isToggleOn?: boolean ) {
		const method = typeof isToggleOn === 'boolean' && isToggleOn ? 'POST' : 'GET';

		const options: ApiFetchOptions = {
			path,
			method,
		};
		if ( dataToSend ) {
			options.data = dataToSend;
		}
		wizardApiFetch< T >( options, {
			onSuccess: setApiData,
			onFinally() {
				if ( refreshOn.includes( method ) ) {
					setActionText( createElement( 'span', { className: 'gray' }, __( 'Page reloading…', 'newspack-plugin' ) ) );
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
