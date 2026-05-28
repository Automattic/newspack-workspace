/**
 * Newspack - Dashboard, Site Status
 */

/**
 * Dependencies
 */
// WordPress
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useState, useEffect } from '@wordpress/element';
import { Tooltip } from '@wordpress/components';
// Internal
import SiteActionModal from './site-status-modal';

const defaultStatuses = {
	idle: undefined,
	success: __( 'Connected', 'newspack-plugin' ),
	pending: __( 'Fetching…', 'newspack-plugin' ),
	'pending-install': __( 'Installing…', 'newspack-plugin' ),
	// Error types
	error: __( 'Disconnected', 'newspack-plugin' ),
	'error-dependencies': undefined,
	'error-preflight': undefined,
	'error-request': undefined,
};

const SiteStatus = ( { label = '', isPreflightValid = true, dependencies: dependenciesProp, statuses, endpoint, configLink, then }: Status ) => {
	const parsedStatusLabels: Record< StatusLabels, string > = {
		...defaultStatuses,
		...statuses,
	};

	const [ requestCode, setRequestCode ] = useState( 200 );

	const [ requestStatus, setRequestStatus ] = useState< StatusLabels >( 'idle' );
	const [ failedDependencies, setFailedDependencies ] = useState< string[] >( [] );
	const [ isModalVisible, setIsModalVisible ] = useState( false );

	const dependencies = structuredClone< Dependencies | undefined >( dependenciesProp );

	useEffect( () => {
		makeRequest();
	}, [] );

	function makeRequest( pluginInfo = {} ) {
		// When/if a dependency is activated update reference.
		if ( dependencies && Object.keys( pluginInfo ).length > 0 ) {
			for ( const [ pluginName ] of Object.entries( pluginInfo ) ) {
				dependencies[ pluginName ].isActive = true;
			}
		}
		return new Promise< void | boolean >( resolve => {
			// Dependency check
			if ( dependencies && Object.keys( dependencies ).length > 0 ) {
				const failedDeps: string[] = [];
				for ( const [ dependencyName, dependencyInfo ] of Object.entries( dependencies ) ) {
					// Don't process active
					if ( dependencyInfo.isActive ) {
						continue;
					}
					failedDeps.push( dependencyName );
				}
				setFailedDependencies( failedDeps );
				if ( failedDeps.length > 0 ) {
					setRequestStatus( 'error-dependencies' );
					resolve( false );
					return;
				}
			}
			// Preflight check
			if ( ! isPreflightValid ) {
				setRequestStatus( 'error-preflight' );
				resolve( false );
				return;
			}
			// Pending API request
			setRequestStatus( 'pending' );
			apiFetch( {
				path: endpoint,
				parse: false,
			} )
				.then( async res => {
					const response = res as Response;
					setRequestCode( response.status );
					const data = await response.json();
					const apiRequest = then( data );
					setRequestStatus( apiRequest ? 'success' : 'error' );
					resolve( apiRequest );
				} )
				.catch( err => {
					const status = err?.status ?? 500;
					setRequestStatus( status > 399 ? 'error-request' : 'error' );
					setRequestCode( status );
					resolve();
				} );
		} );
	}

	const classes = `newspack-site-status newspack-site-status__${ requestStatus }`;

	return (
		<>
			{ isModalVisible && <SiteActionModal plugins={ failedDependencies } onSuccess={ makeRequest } onRequestClose={ setIsModalVisible } /> }
			{ /* Error UI, link user to config */ }
			{ requestStatus === 'error' && (
				<Tooltip text={ __( 'Click to navigate to configuration', 'newspack-plugin' ) }>
					<a href={ configLink } className={ classes }>
						{ label }: <span>{ parsedStatusLabels[ requestStatus ] }</span>
						<span className="hidden">{ __( 'Configure?' ) }</span>
					</a>
				</Tooltip>
			) }
			{ /* Error Dependencies, dependencies install modal */ }
			{ requestStatus === 'error-dependencies' && dependencies && (
				<Tooltip
					text={ sprintf(
						// translators: %s is a comma separated list of needed dependencies.
						__( '%s must be installed & activated!' ),
						failedDependencies.map( dep => dependencies[ dep ].label ).join( ', ' )
					) }
				>
					<button onClick={ () => setIsModalVisible( true ) } className={ classes }>
						{ label }: <span>{ _n( 'Missing dependency', 'Missing dependencies', failedDependencies.length, 'newspack-plugin' ) }</span>
						<span className="hidden">
							{ _n( 'Install dependency', 'Install dependencies', failedDependencies.length, 'newspack-plugin' ) }
						</span>
					</button>
				</Tooltip>
			) }
			{ /* Display standard UI for the rest */ }
			{ [ 'error-preflight', 'success', 'idle', 'pending', 'error-request' ].includes( requestStatus ) && (
				<div className={ classes }>
					{ label }:{ ' ' }
					<span>
						{ requestStatus === 'error-request'
							? sprintf(
									/* translators: %d is the HTTP status code */
									__( 'Request failed - %d', 'newspack-plugin' ),
									requestCode
							  )
							: parsedStatusLabels[ requestStatus ] }
					</span>
				</div>
			) }
		</>
	);
};

export default SiteStatus;
