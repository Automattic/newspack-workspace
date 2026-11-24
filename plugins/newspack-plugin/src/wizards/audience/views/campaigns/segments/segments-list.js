/* globals newspackAudienceCampaigns */
/**
 * WordPress dependencies.
 */
import { Disabled, Notice } from '@wordpress/components';
import { DataViews as BaseDataViews } from '@wordpress/dataviews';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, sprintf } from '@wordpress/i18n';
import { people, plus } from '@wordpress/icons';
import { EmptyState, Stack } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import { Button, CardListRow, DataViews, Router } from '../../../../../../packages/components/src';
import { segmentDescriptionItems, splitCriteriaMessage } from '../utils';
import SegmentsScreen from './screen';
import SegmentsPriority from './segments-priority';

const { useHistory } = Router;

const renderCriteria = segment => {
	const criteria = segmentDescriptionItems( { ...segment, configuration: { ...segment.configuration, is_disabled: false } } );
	if ( ! criteria.length ) {
		return null;
	}
	return (
		<span className="newspack-campaigns__details">
			{ criteria.map( ( item, index ) => {
				const { label, value } = splitCriteriaMessage( item );
				return (
					<span key={ index }>
						{ label && (
							<>
								<span className="newspack-campaigns__details-key">{ label }</span>{ ' ' }
							</>
						) }
						<span className="newspack-campaigns__details-value">{ value }</span>
					</span>
				);
			} ) }
		</span>
	);
};

const SegmentsList = ( { wizardApiFetch, segments, setSegments, ...screenProps } ) => {
	const [ busyIds, setBusyIds ] = useState( [] );
	const [ error, setError ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ showPriorityModal, setShowPriorityModal ] = useState( false );
	const lastRequest = useRef( 0 );
	const lastAppliedRequest = useRef( 0 );
	const toggleToRefocus = useRef( null );
	const rowsRef = useRef( null );
	const history = useHistory();

	useEffect( () => {
		window.scrollTo( 0, 0 );
	}, [] );

	// Disabling a row drops focus from the toggle that was just pressed.
	useEffect( () => {
		const toggle = toggleToRefocus.current;
		if ( ! toggle || toggle.closest( '[inert]' ) ) {
			return;
		}
		toggleToRefocus.current = null;
		if ( toggle.isConnected && toggle.ownerDocument.activeElement === toggle.ownerDocument.body ) {
			toggle.focus();
		}
	}, [ busyIds ] );

	const sendRowRequest = ( segment, request, fallbackError ) => {
		setError( null );
		setBusyIds( ids => [ ...ids, segment.id ] );
		const thisRequest = ++lastRequest.current;
		wizardApiFetch( {
			path: `${ newspackAudienceCampaigns.api }/segmentation/${ segment.id }`,
			quiet: true,
			...request,
		} )
			.then( _segments => {
				// Other rows stay usable meanwhile, and each reply holds every segment, so an older reply must not undo a newer one.
				if ( thisRequest > lastAppliedRequest.current ) {
					lastAppliedRequest.current = thisRequest;
					setSegments( _segments );
				}
			} )
			.catch( e => setError( decodeEntities( e?.message || fallbackError ) ) )
			.finally( () => setBusyIds( ids => ids.filter( id => id !== segment.id ) ) );
	};

	const toggleSegmentStatus = segment => {
		toggleToRefocus.current = rowsRef.current?.ownerDocument.activeElement;
		sendRowRequest(
			segment,
			{
				method: 'POST',
				data: {
					name: segment.name,
					configuration: {
						...segment.configuration,
						is_disabled: ! segment.configuration.is_disabled,
					},
					criteria: segment.criteria,
				},
			},
			__( 'There was an error updating the segment. Please try again.', 'newspack-plugin' )
		);
	};

	const deleteSegment = segment => {
		sendRowRequest( segment, { method: 'DELETE' }, __( 'There was an error deleting the segment. Please try again.', 'newspack-plugin' ) );
	};

	const fields = useMemo(
		() => [
			{
				id: 'name',
				label: __( 'Name', 'newspack-plugin' ),
				enableGlobalSearch: true,
				enableSorting: false,
				getValue: ( { item } ) => item.name,
			},
		],
		[]
	);

	const view = {
		type: 'table',
		search,
		page: 1,
		perPage: 100,
		fields: [],
	};

	const onChangeView = next => {
		setSearch( next.search || '' );
	};

	const searchTerm = search.trim().toLowerCase();
	const visibleSegments = segments.filter( segment => decodeEntities( segment.name ).toLowerCase().includes( searchTerm ) );

	const headerActions = [
		{
			type: 'primary',
			label: __( 'Add Segment', 'newspack-plugin' ),
			mobileIcon: plus,
			href: '#/segments/new',
		},
	];

	return (
		<SegmentsScreen { ...screenProps } headerActions={ segments.length ? headerActions : undefined }>
			{ error && (
				<Notice className="newspack-campaigns-wizard-segments__notice" status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			{ showPriorityModal && (
				<SegmentsPriority
					closeModal={ () => setShowPriorityModal( false ) }
					segments={ segments }
					setSegments={ setSegments }
					wizardApiFetch={ wizardApiFetch }
				/>
			) }
			{ segments.length ? (
				<>
					<DataViews
						className="newspack-campaigns__toolbar"
						view={ view }
						onChangeView={ onChangeView }
						fields={ fields }
						data={ visibleSegments }
						getItemId={ item => String( item.id ) }
						paginationInfo={ { totalItems: visibleSegments.length, totalPages: 1 } }
						defaultLayouts={ { table: {} } }
					>
						<Stack direction="row" justify="space-between" align="flex-start" gap="xs" className="dataviews__view-actions">
							<Stack direction="row" align="center" gap="sm" wrap="wrap" className="dataviews__search">
								<BaseDataViews.Search label={ __( 'Search segments', 'newspack-plugin' ) } />
							</Stack>
							{ segments.length > 1 && (
								<Button variant="secondary" size="compact" onClick={ () => setShowPriorityModal( true ) }>
									{ __( 'Segment Priority', 'newspack-plugin' ) }
								</Button>
							) }
						</Stack>
					</DataViews>
					<Stack direction="column" gap="lg" ref={ rowsRef }>
						{ visibleSegments.map( segment => (
							<Disabled key={ segment.id } isDisabled={ busyIds.includes( segment.id ) }>
								<CardListRow
									size="large"
									item={ {
										id: segment.id,
										title: decodeEntities( segment.name ),
										href: `#/segments/${ segment.id }`,
										description: renderCriteria( segment ),
										badge: segment.is_criteria_duplicated
											? { label: __( 'Duplicate', 'newspack-plugin' ), intent: 'medium' }
											: undefined,
										toggleChecked: ! segment.configuration.is_disabled,
										onToggleChange: () => toggleSegmentStatus( segment ),
										actionsLabel: sprintf(
											// Translators: %s: segment name.
											__( 'Segment actions: %s', 'newspack-plugin' ),
											decodeEntities( segment.name )
										),
										actions: [
											{
												label: __( 'Edit', 'newspack-plugin' ),
												action: () => history.push( `/segments/${ segment.id }` ),
											},
											{
												label: __( 'Delete', 'newspack-plugin' ),
												action: () => deleteSegment( segment ),
												destructive: true,
											},
										],
									} }
								/>
							</Disabled>
						) ) }
						{ ! visibleSegments.length && (
							<p className="newspack-campaigns__no-results">{ __( 'No segments match your search.', 'newspack-plugin' ) }</p>
						) }
					</Stack>
				</>
			) : (
				<Stack direction="column" align="center">
					<EmptyState.Root>
						<EmptyState.Icon icon={ people } />
						<EmptyState.Title>{ __( 'No segments yet', 'newspack-plugin' ) }</EmptyState.Title>
						<EmptyState.Description>
							{ __( 'Group readers by what they do, then show them the right prompts.', 'newspack-plugin' ) }
						</EmptyState.Description>
						<EmptyState.Actions>
							<Button variant="primary" href="#/segments/new">
								{ __( 'Add Segment', 'newspack-plugin' ) }
							</Button>
						</EmptyState.Actions>
					</EmptyState.Root>
				</Stack>
			) }
		</SegmentsScreen>
	);
};

export default SegmentsList;
