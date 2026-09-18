/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect, useCallback, useMemo } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { Spinner } from '@wordpress/components';
import { DataViews as WPDataViews } from '@wordpress/dataviews';

/**
 * Internal dependencies
 */
import { DataViews, StatusIndicator } from '../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import { API_BASE, STATUS_MAP, formatTimestamp } from './constants';
import { LogDetailsModal } from './log-details-modal';
import { useRunAction } from './use-run-action';

const DEFAULT_VIEW = {
	type: 'table',
	page: 1,
	perPage: 25,
	sort: { field: 'timestamp', direction: 'desc' },
	search: '',
	fields: [ 'timestamp', 'email', 'event', 'status' ],
	filters: [],
	layout: {
		styles: {
			timestamp: { width: '35%' },
			email: { width: '30%' },
			event: { width: '20%' },
			status: { width: '15%' },
		},
	},
};

const ORDERBY_MAP = {
	timestamp: 'scheduled_date_gmt',
	status: 'status',
};

/**
 * The integration's Action Scheduler jobs: retries, pulls and anything else
 * queued for it. It says whether a job ran, not whether a sync worked; the
 * sync activity tab is where that is answered.
 *
 * @param {Object} props               Props.
 * @param {string} props.integrationId The integration to list.
 */
export const ScheduledActions = ( { integrationId } ) => {
	const { addNotice } = useDispatch( WIZARD_STORE_NAMESPACE );

	const [ data, setData ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ hasLoadedOnce, setHasLoadedOnce ] = useState( false );
	const [ view, setView ] = useState( DEFAULT_VIEW );

	const statusFilter = view.filters?.find( f => f.field === 'status' )?.value;

	const fetchLogs = useCallback( () => {
		setIsLoading( true );

		const path = addQueryArgs( `${ API_BASE }/${ integrationId }/logs`, {
			page: view.page,
			per_page: view.perPage,
			orderby: ORDERBY_MAP[ view.sort?.field || 'timestamp' ] || 'scheduled_date_gmt',
			order: ( view.sort?.direction || 'desc' ).toUpperCase(),
			search: view.search || undefined,
			status: statusFilter || undefined,
		} );

		apiFetch( { path } )
			.then( response => {
				setData( response.items );
				setTotal( response.total );
			} )
			.catch( () => {
				addNotice( {
					message: __( 'Failed to load scheduled actions. Please try again.', 'newspack-plugin' ),
					type: 'error',
					id: 'integration-logs-fetch-error',
				} );
			} )
			.finally( () => {
				setIsLoading( false );
				setHasLoadedOnce( true );
			} );
	}, [ integrationId, view.page, view.perPage, view.sort?.field, view.sort?.direction, view.search, statusFilter, addNotice ] );

	useEffect( () => {
		fetchLogs();
	}, [ fetchLogs ] );

	const { runAction, runningActionIds } = useRunAction( integrationId, { onSettled: fetchLogs } );

	const fields = useMemo(
		() => [
			{
				id: 'timestamp',
				label: __( 'Timestamp', 'newspack-plugin' ),
				render: ( { item } ) => formatTimestamp( item.timestamp ),
				enableSorting: true,
			},
			{
				id: 'email',
				label: __( 'Email', 'newspack-plugin' ),
				render: ( { item } ) => item.email || '—',
				enableSorting: false,
			},
			{
				id: 'event',
				label: __( 'Event', 'newspack-plugin' ),
				getValue: ( { item } ) => item.event,
				enableSorting: false,
			},
			{
				id: 'status',
				label: __( 'Status', 'newspack-plugin' ),
				render: ( { item } ) => {
					// A status Action Scheduler grows later is one to look at, not a deliberate stop.
					const mapped = STATUS_MAP[ item.status ] || { label: item.status, status: 'attention' };
					return <StatusIndicator status={ mapped.status }>{ mapped.label }</StatusIndicator>;
				},
				enableSorting: true,
				elements: Object.entries( STATUS_MAP ).map( ( [ value, { label } ] ) => ( { value, label } ) ),
				filterBy: {
					operators: [ 'is' ],
				},
			},
		],
		[]
	);

	const actions = useMemo(
		() => [
			{
				id: 'view-details',
				label: __( 'View details', 'newspack-plugin' ),
				modalHeader: __( 'Action details', 'newspack-plugin' ),
				RenderModal: ( { items } ) => <LogDetailsModal integrationId={ integrationId } actionId={ items[ 0 ].id } />,
			},
			{
				id: 'run-now',
				label: __( 'Run now', 'newspack-plugin' ),
				isEligible: item => item.status === 'pending' && ! runningActionIds.has( item.id ),
				callback: items => runAction( items[ 0 ].id ),
			},
		],
		[ integrationId, runAction, runningActionIds ]
	);

	const paginationInfo = useMemo(
		() => ( {
			totalItems: total,
			totalPages: Math.ceil( total / ( view.perPage || 25 ) ),
		} ),
		[ total, view.perPage ]
	);

	if ( ! hasLoadedOnce ) {
		return (
			<div className="newspack-integration-logs__loading">
				<Spinner />
			</div>
		);
	}

	return (
		<DataViews
			className="newspack-integration-logs"
			data={ data }
			fields={ fields }
			actions={ actions }
			view={ view }
			onChangeView={ setView }
			paginationInfo={ paginationInfo }
			defaultLayouts={ { table: {} } }
			isLoading={ isLoading }
			getItemId={ item => item.id }
			search
		>
			<div className="dataviews__view-actions">
				<div className="dataviews__search">
					<WPDataViews.Search />
					<WPDataViews.FiltersToggle />
				</div>
			</div>
			<WPDataViews.FiltersToggled className="dataviews-filters__container" />
			<WPDataViews.Layout />
			<WPDataViews.Footer />
		</DataViews>
	);
};
