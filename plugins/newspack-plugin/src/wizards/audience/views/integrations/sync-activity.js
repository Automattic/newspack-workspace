/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect, useCallback, useMemo, useRef } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { Spinner } from '@wordpress/components';
import { Stack } from '@wordpress/ui';
import { DataViews as WPDataViews } from '@wordpress/dataviews';

/**
 * Internal dependencies
 */
import { DataViews, StatusIndicator } from '../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import { API_BASE, PUSH_LOG_STATUS_MAP, PUSH_LOG_OPERATION_LABELS, formatTimestamp } from './constants';
import { NEEDS_ATTENTION_VALUE, buildPushLogQuery, getAttemptLabel, getRetryNote, getStatusDisplay, getEmptyMessage } from './push-log-utils';
import { SyncActivityDetails } from './sync-activity-details';
import { useRunAction } from './use-run-action';

const DEFAULT_VIEW = {
	type: 'table',
	page: 1,
	perPage: 25,
	sort: { field: 'updated_at', direction: 'desc' },
	search: '',
	fields: [ 'updated_at', 'email', 'operation', 'status' ],
	filters: [],
	layout: {
		styles: {
			updated_at: { width: '25%' },
			email: { width: '35%' },
			operation: { width: '15%' },
			status: { width: '25%' },
		},
	},
};

// The retry's action finishing does not say the push worked, so the notice
// sends the reader to the entry rather than calling it a success.
const RETRY_RAN_NOTICE = { message: __( 'The retry ran. Its entry shows the result.', 'newspack-plugin' ), type: 'info' };

const toElements = labels => Object.entries( labels ).map( ( [ value, label ] ) => ( { value, label } ) );

/**
 * The integration's push log: what was sent for each reader, and whether it
 * arrived. One row is one sync, its retries included.
 *
 * @param {Object} props               Props.
 * @param {string} props.integrationId The integration to list.
 */
export const SyncActivity = ( { integrationId } ) => {
	const { addNotice } = useDispatch( WIZARD_STORE_NAMESPACE );

	const [ data, setData ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ retentionDays, setRetentionDays ] = useState( null );
	const [ hasFailed, setHasFailed ] = useState( false );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ hasLoadedOnce, setHasLoadedOnce ] = useState( false );
	const [ view, setView ] = useState( DEFAULT_VIEW );

	// The request only depends on these parts of the view; hiding a column
	// must not refetch.
	const queryKey = JSON.stringify( buildPushLogQuery( view ) );

	// Requests answer in whatever order the server gets to them, so each one
	// takes a number and only the newest may speak for the table.
	const latestRequest = useRef( 0 );

	const fetchEntries = useCallback( () => {
		const request = ++latestRequest.current;
		const isCurrent = () => request === latestRequest.current;
		setIsLoading( true );
		return apiFetch( { path: addQueryArgs( `${ API_BASE }/${ integrationId }/push-log`, JSON.parse( queryKey ) ) } )
			.then( response => {
				if ( ! isCurrent() ) {
					return;
				}
				setData( response.items );
				setTotal( response.total );
				setRetentionDays( response.retention_days ?? null );
				setHasFailed( false );
			} )
			.catch( () => {
				if ( ! isCurrent() ) {
					return;
				}
				// A load that failed left the table empty; the empty message
				// says so rather than reporting a log with nothing in it.
				setHasFailed( true );
				addNotice( {
					message: __( 'Failed to load the sync activity. Please try again.', 'newspack-plugin' ),
					type: 'error',
					id: 'integration-push-log-fetch-error',
				} );
			} )
			.finally( () => {
				if ( ! isCurrent() ) {
					return;
				}
				setIsLoading( false );
				setHasLoadedOnce( true );
			} );
	}, [ integrationId, queryKey, addNotice ] );

	useEffect( () => {
		fetchEntries();
	}, [ fetchEntries ] );

	const { runAction, runningActionIds } = useRunAction( integrationId, { onSettled: fetchEntries, completeNotice: RETRY_RAN_NOTICE } );

	const fields = useMemo(
		() => [
			{
				id: 'updated_at',
				label: __( 'Last activity', 'newspack-plugin' ),
				render: ( { item } ) => formatTimestamp( item.updated_at ),
				enableSorting: true,
				enableHiding: false,
			},
			{
				id: 'email',
				label: __( 'Reader', 'newspack-plugin' ),
				getValue: ( { item } ) => item.email,
				enableSorting: false,
			},
			{
				id: 'operation',
				label: __( 'Operation', 'newspack-plugin' ),
				render: ( { item } ) => PUSH_LOG_OPERATION_LABELS[ item.operation ] || item.operation,
				enableSorting: false,
				elements: toElements( PUSH_LOG_OPERATION_LABELS ),
				filterBy: { operators: [ 'is' ] },
			},
			{
				id: 'status',
				label: __( 'Status', 'newspack-plugin' ),
				render: ( { item } ) => {
					const mapped = getStatusDisplay( item );
					const notes = [ getAttemptLabel( item ), getRetryNote( item ) ].filter( Boolean );
					// The cell DataViews renders this into lays its children
					// out in a row and does not wrap, so the notes need a
					// column of their own to sit under the status.
					return (
						<Stack direction="column" gap="xs">
							<StatusIndicator status={ mapped.status }>{ mapped.label }</StatusIndicator>
							{ notes.map( note => (
								<span key={ note } className="newspack-integration-logs__status-note">
									{ note }
								</span>
							) ) }
						</Stack>
					);
				},
				enableSorting: false,
				elements: Object.entries( PUSH_LOG_STATUS_MAP ).map( ( [ value, { label } ] ) => ( { value, label } ) ),
				filterBy: { operators: [ 'is' ] },
			},
			{
				id: 'context',
				label: __( 'Trigger', 'newspack-plugin' ),
				render: ( { item } ) => item.context || '—',
				enableSorting: false,
			},
			{
				id: 'created_at',
				label: __( 'First attempt', 'newspack-plugin' ),
				render: ( { item } ) => formatTimestamp( item.created_at ),
				enableSorting: false,
			},
			{
				id: 'repeat_count',
				label: __( 'Repeats', 'newspack-plugin' ),
				getValue: ( { item } ) => item.repeat_count,
				enableSorting: false,
			},
			{
				// Carries the filter only: what needs attention is worked out by
				// the server from later pushes, so there is no column to show.
				id: 'needs_attention',
				label: __( 'Needs attention', 'newspack-plugin' ),
				getValue: () => '',
				enableSorting: false,
				enableHiding: false,
				elements: [ { value: NEEDS_ATTENTION_VALUE, label: __( 'Retrying, or failed with no later success', 'newspack-plugin' ) } ],
				filterBy: { operators: [ 'is' ] },
			},
		],
		[]
	);

	const actions = useMemo(
		() => [
			{
				id: 'view-details',
				label: __( 'View details', 'newspack-plugin' ),
				modalHeader: __( 'Sync details', 'newspack-plugin' ),
				RenderModal: ( { items } ) => <SyncActivityDetails integrationId={ integrationId } entryId={ items[ 0 ].id } />,
			},
			{
				id: 'run-retry',
				label: __( 'Run retry now', 'newspack-plugin' ),
				isEligible: item => item.status === 'retrying' && Boolean( item.retry?.is_pending ) && ! runningActionIds.has( item.retry.action_id ),
				callback: items => runAction( items[ 0 ].retry.action_id ),
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
			<Stack justify="center" align="center">
				<Spinner />
			</Stack>
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
			empty={
				<p>{ hasFailed ? __( 'The sync activity could not be loaded.', 'newspack-plugin' ) : getEmptyMessage( view, retentionDays ) }</p>
			}
			search
		>
			<div className="dataviews__view-actions">
				<div className="dataviews__search">
					<WPDataViews.Search label={ __( 'Search by reader email', 'newspack-plugin' ) } />
					<WPDataViews.FiltersToggle />
				</div>
			</div>
			<WPDataViews.FiltersToggled className="dataviews-filters__container" />
			<WPDataViews.Layout />
			<WPDataViews.Footer />
		</DataViews>
	);
};
