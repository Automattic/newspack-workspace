/**
 * Custom hook for making API fetch requests using the wizard API.
 */

/**
 * WordPress dependencies
 */
import { useDispatch, useSelect } from '@wordpress/data';
import { decodeEntities } from '@wordpress/html-entities';
import { useState, useCallback, useEffect, useRef } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { WIZARD_STORE_NAMESPACE } from '../../../packages/components/src/wizard/store';
import { WizardApiError } from '../errors';

/**
 * Remove query arguments from a path. Similar to `removeQueryArgs` in `@wordpress/url`, but this function
 * removes all query arguments from a string and returns it.
 *
 * @param str String to remove query arguments from.
 * @return The string without query arguments.
 */
function removeQueryArgs( str: string ) {
	return str.split( '?' ).at( 0 ) ?? str;
}

/**
 * Holds in-progress promises for each fetch request.
 */
let promiseCache: Record< string, any > = {};

/**
 * Parses the API error response into a WizardApiError object.
 *
 * @param error The error response from the API.
 * @return      Parsed error object or null if no error.
 */
const parseApiError = ( error: WpFetchError | string ): WizardApiError | null => {
	const newError = {
		message: 'An unknown API error occurred.',
		statusCode: 500,
		errorCode: 'api_unknown_error',
		details: '',
	};

	if ( ! error ) {
		return null;
	} else if ( typeof error === 'string' ) {
		newError.message = error;
	} else if ( error instanceof Error || 'message' in error ) {
		newError.message = error.message ?? newError.message;
		newError.statusCode = error.data?.status ?? newError.statusCode;
		newError.errorCode = error.code ?? newError.errorCode;
		newError.details = '';
	}

	return new WizardApiError( newError.message, newError.statusCode, newError.errorCode, newError.details );
};

/**
 * Executes the provided callback function if it exists.
 *
 * @template T
 * @param    callbacks Object containing callback functions.
 * @return             Object with an `on` method to trigger callbacks.
 */
const onCallbacks = < T >( callbacks: ApiFetchCallbacks< T > ) => ( {
	on( cb: keyof ApiFetchCallbacks< T >, d: any = null ) {
		const callback = callbacks?.[ cb ];
		if ( callback && typeof callback === 'function' ) {
			callback( d );
		}
	},
} );

/**
 * Custom hook to perform API fetch requests using the wizard API.
 *
 * @param slug Unique identifier for the wizard data.
 * @return     Object containing fetch function, error handlers and state.
 */
export function useWizardApiFetch( slug: string ) {
	const [ isFetching, setIsFetching ] = useState( false );
	const { wizardApiFetch, updateWizardSettings } = useDispatch( WIZARD_STORE_NAMESPACE );
	const wizardData: WizardData = useSelect(
		( select: ( namespace: string ) => WizardSelector ) => select( WIZARD_STORE_NAMESPACE ).getWizardData( slug ),
		[ slug ]
	);
	const [ error, setError ] = useState< WizardApiError | null >( wizardData.error ?? null );

	const requests = useRef< string[] >( [] );

	useEffect( () => {
		if ( wizardData?.error !== error ) {
			setError( wizardData?.error ?? null );
		}
	}, [ wizardData?.error, error ] );

	useEffect( () => {
		updateWizardSettings( {
			slug,
			path: [ 'error' ],
			value: error,
		} );
	}, [ error, updateWizardSettings, slug ] );

	function resetError() {
		setError( null );
	}

	/**
	 * Updates the wizard data at the specified path.
	 *
	 * @param cacheKeyPath The cacheKeyPath to update in the wizard data.
	 * @return     Function to update the wizard data.
	 */
	function updateWizardData( cacheKeyPath: string | null ) {
		/**
		 * Updates the wizard data prop at the specified path.
		 *
		 * @param prop                 The property to update in the wizard path data. i.e. 'GET'
		 * @param value                The value to set for the property.
		 * @param cacheKeyPathOverride The path to update in the wizard data.
		 */
		return ( prop: string | string[], value: any, cacheKeyPathOverride = cacheKeyPath ) => {
			// Remove query parameters from the cacheKeyPath

			const normalizedPath = cacheKeyPathOverride ? removeQueryArgs( cacheKeyPathOverride ) : cacheKeyPathOverride;

			updateWizardSettings( {
				slug,
				path: [ normalizedPath, ...( Array.isArray( prop ) ? prop : [ prop ] ) ].filter( str => typeof str === 'string' ),
				value,
			} );
		};
	}

	/**
	 * Makes an API fetch request using the wizard API.
	 *
	 * @template T
	 * @param    opts        The options for the API fetch request.
	 * @param    [callbacks] Optional callback functions for different stages of the fetch request.
	 * @return               The result of the API fetch request.
	 */
	const apiFetch = useCallback(
		async < T = any >( opts: ApiFetchOptions, callbacks?: ApiFetchCallbacks< T > ) => {
			const { on } = onCallbacks< T >( callbacks ?? {} );
			const updateSettings = updateWizardData( opts.path );
			const { path, method = 'GET' } = opts;
			const cacheKeyPath = removeQueryArgs( path ?? '' );
			const { isCached = method === 'GET', updateCacheKey = null, updateCacheMethods = [], ...options } = opts;

			const { error: cachedError, [ cacheKeyPath ]: { [ method ]: cachedMethod = null } = {} }: WizardData = wizardData;

			function thenCallback( response: T ) {
				if ( isCached ) {
					updateSettings( method, response );
				}

				if ( updateCacheKey && updateCacheKey.constructor === Object ) {
					// Derive the key and method from the updateCacheKey object.
					const [ updateCacheKeyKey, updateCacheKeyMethod ]: [ keyof WizardData, ApiMethods ] = Object.entries( updateCacheKey )[ 0 ];

					const cachedValue = wizardData[ updateCacheKeyKey ][ updateCacheKeyMethod ];

					let newCache;

					if ( cachedValue && cachedValue.constructor === Object ) {
						newCache = {
							...cachedValue,
							...response,
						};
					} else {
						newCache = response;
					}

					updateSettings( Object.entries( updateCacheKey )[ 0 ], newCache, null );
				}

				for ( const replaceMethod of updateCacheMethods ) {
					updateSettings( replaceMethod, response );
				}
				on( 'onSuccess', response );
				return response;
			}

			function catchCallback( err: WpFetchError ) {
				const newError = parseApiError( err );
				setError( newError );
				on( 'onError', newError );
				throw newError;
			}

			function finallyCallback() {
				// eslint-disable-next-line @typescript-eslint/no-unused-vars
				const { [ cacheKeyPath ]: _removed, ...newData } = promiseCache;
				promiseCache = newData;
				requests.current = requests.current.filter( request => request !== cacheKeyPath );
				setIsFetching( requests.current.length > 0 );
				on( 'onFinally' );
			}

			// If the promise is already in progress, return it before making a new request.
			if ( promiseCache[ cacheKeyPath ] ) {
				setIsFetching( true );
				return promiseCache[ cacheKeyPath ].then( thenCallback ).catch( catchCallback ).finally( finallyCallback );
			}

			// Cache exists and is not empty, return it.
			if ( isCached && ( cachedError || cachedMethod ) ) {
				setError( cachedError );
				on( 'onSuccess', cachedMethod );
				return cachedMethod;
			}

			setIsFetching( true );
			on( 'onStart' );
			requests.current.push( cacheKeyPath );

			promiseCache[ cacheKeyPath ] = wizardApiFetch( {
				isQuietFetch: true,
				isLocalError: true,
				...options,
			} )
				.then( thenCallback )
				.catch( catchCallback )
				.finally( finallyCallback );

			// Return the promise we just stored, keyed by `cacheKeyPath` —
			// the same key it was written under. Returning `promiseCache[ slug ]`
			// (the hook's slug, a different key) handed callers `undefined`,
			// so a `.catch()` at the call site attached to the resolved async
			// wrapper instead of the real request and never saw the rejection
			// `catchCallback` re-throws.
			return promiseCache[ cacheKeyPath ];
		},
		[ wizardApiFetch, wizardData, updateWizardSettings, isFetching, slug ]
	);

	return {
		wizardApiFetch: apiFetch,
		isFetching,
		errorMessage: error ? decodeEntities( error.message ) : null,
		error,
		cache( cacheKey: string ) {
			return {
				get( method: ApiMethods = 'GET' ) {
					return wizardData[ cacheKey ][ method ];
				},
				set( value: any, method: ApiMethods = 'GET' ) {
					updateWizardSettings( {
						slug,
						path: [ cacheKey, method ],
						value,
					} );
				},
			};
		},
		setError( value: string | WizardErrorType | null | { message: string } ) {
			if ( value === null ) {
				resetError();
			} else {
				setError( parseApiError( value as WpFetchError ) );
			}
		},
		resetError,
	};
}
