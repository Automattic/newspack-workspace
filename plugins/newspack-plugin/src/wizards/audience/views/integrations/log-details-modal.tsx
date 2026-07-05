/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Spinner, Notice } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { Badge } from '../../../../../packages/components/src';
import { API_BASE, STATUS_MAP, formatTimestamp } from './constants';
import type { IntegrationActionDetails, IntegrationActionStatusBadge } from './constants';

/**
 * Format the action's decoded payload for display.
 *
 * @param args The decoded action payload.
 * @return The formatted payload, or an empty string.
 */
function formatArgs( args: unknown ): string {
	if ( null === args || undefined === args ) {
		return '';
	}
	if ( typeof args === 'string' ) {
		return args;
	}
	try {
		return JSON.stringify( args, null, 2 );
	} catch ( e ) {
		return String( args );
	}
}

type LogDetailsModalProps = {
	integrationId: string;
	actionId: string;
};

export const LogDetailsModal = ( { integrationId, actionId }: LogDetailsModalProps ) => {
	const [ data, setData ] = useState< IntegrationActionDetails | null >( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		let cancelled = false;
		setIsLoading( true );
		setError( null );

		apiFetch< IntegrationActionDetails >( { path: `${ API_BASE }/${ integrationId }/logs/${ actionId }` } )
			.then( response => {
				if ( ! cancelled ) {
					setData( response );
				}
			} )
			.catch( ( err: WpFetchError ) => {
				if ( cancelled ) {
					return;
				}
				if ( err && err.data && err.data.status === 404 ) {
					setError( __( 'This action no longer exists.', 'newspack-plugin' ) );
				} else {
					setError( __( 'Failed to load action details.', 'newspack-plugin' ) );
				}
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setIsLoading( false );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ integrationId, actionId ] );

	if ( isLoading ) {
		return (
			<div className="newspack-integration-log-details newspack-integration-log-details--loading">
				<Spinner />
			</div>
		);
	}

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}

	if ( ! data || ! data.action ) {
		return null;
	}

	const { action, logs } = data;
	const status: IntegrationActionStatusBadge = STATUS_MAP[ action.status ] || { label: action.status, level: 'default' };
	const formattedArgs = formatArgs( action.args );

	return (
		<div className="newspack-integration-log-details">
			<div className="newspack-integration-log-details__header">
				<h3>{ action.event }</h3>
				<Badge text={ status.label } level={ status.level } />
			</div>

			<dl className="newspack-integration-log-details__meta">
				<dt>{ __( 'Action ID', 'newspack-plugin' ) }</dt>
				<dd>{ action.id }</dd>

				<dt>{ __( 'Email', 'newspack-plugin' ) }</dt>
				<dd>{ action.email || '—' }</dd>

				<dt>{ __( 'Scheduled', 'newspack-plugin' ) }</dt>
				<dd>{ formatTimestamp( action.scheduled_date_gmt ) }</dd>

				<dt>{ __( 'Group', 'newspack-plugin' ) }</dt>
				<dd>{ action.group }</dd>

				<dt>{ __( 'Attempts', 'newspack-plugin' ) }</dt>
				<dd>{ action.attempts }</dd>

				<dt>{ __( 'Last attempt', 'newspack-plugin' ) }</dt>
				<dd>{ action.last_attempt_gmt ? formatTimestamp( action.last_attempt_gmt ) : '—' }</dd>
			</dl>

			<section className="newspack-integration-log-details__section">
				<h4>{ __( 'Arguments', 'newspack-plugin' ) }</h4>
				{ formattedArgs ? (
					<pre className="newspack-integration-log-details__args">{ formattedArgs }</pre>
				) : (
					<p>{ __( 'No arguments.', 'newspack-plugin' ) }</p>
				) }
			</section>

			<section className="newspack-integration-log-details__section">
				<h4>{ __( 'Logs', 'newspack-plugin' ) }</h4>
				{ logs && logs.length > 0 ? (
					<ul className="newspack-integration-log-details__logs">
						{ logs.map( ( log, index ) => (
							<li key={ `${ log.date_gmt }-${ index }` }>
								<span className="newspack-integration-log-details__log-date">{ formatTimestamp( log.date_gmt ) }</span>
								<span className="newspack-integration-log-details__log-message">{ log.message }</span>
							</li>
						) ) }
					</ul>
				) : (
					<p>{ __( 'No log entries.', 'newspack-plugin' ) }</p>
				) }
			</section>
		</div>
	);
};
