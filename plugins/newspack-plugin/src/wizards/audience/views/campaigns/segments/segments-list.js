/* globals newspackAudienceCampaigns */
/**
 * WordPress dependencies.
 */
import { Notice, SearchControl } from '@wordpress/components';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, sprintf } from '@wordpress/i18n';
import { people, plus } from '@wordpress/icons';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import { Button, CardSortableList, EmptyState, Router } from '../../../../../../packages/components/src';
import { segmentDescriptionItems, splitCriteriaMessage } from '../utils';
import SegmentsScreen from './screen';

const { useHistory } = Router;

const idsOf = segments => segments.map( ( { id } ) => id );

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
	const [ order, setOrder ] = useState( null );
	const [ isSavingOrder, setIsSavingOrder ] = useState( false );
	const lastRequest = useRef( 0 );
	const lastAppliedRequest = useRef( 0 );
	const toggleToRefocus = useRef( null );
	const rowsRef = useRef( null );
	const priorityButtonRef = useRef( null );
	const focusAfterModeChange = useRef( false );
	const history = useHistory();
	const isReordering = null !== order;

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

	// The button that switched the mode is gone, so focus moves to where the new mode starts.
	useEffect( () => {
		if ( ! focusAfterModeChange.current ) {
			return;
		}
		focusAfterModeChange.current = false;
		if ( isReordering ) {
			rowsRef.current?.querySelector( '.newspack-card--core--sortable-list__move-buttons button:not(:disabled)' )?.focus();
		} else {
			priorityButtonRef.current?.focus();
		}
	}, [ isReordering ] );

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

	const listedSegments = useMemo( () => {
		if ( isReordering ) {
			// Built from the ids so a reply that lands meanwhile still shows, and the list is never a search result.
			const byId = new Map( segments.map( segment => [ segment.id, segment ] ) );
			const ordered = order.map( id => byId.get( id ) ).filter( Boolean );
			return [ ...ordered, ...segments.filter( segment => ! order.includes( segment.id ) ) ];
		}
		const searchTerm = search.trim().toLowerCase();
		return segments.filter( segment => decodeEntities( segment.name ).toLowerCase().includes( searchTerm ) );
	}, [ isReordering, order, search, segments ] );

	const items = useMemo(
		() =>
			listedSegments.map( segment => ( {
				id: segment.id,
				title: decodeEntities( segment.name ),
				description: renderCriteria( segment ),
				badge: segment.is_criteria_duplicated ? { label: __( 'Duplicate', 'newspack-plugin' ), intent: 'medium' } : undefined,
				disabled: busyIds.includes( segment.id ),
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
			} ) ),
		[ listedSegments, busyIds, history, wizardApiFetch, setSegments ] // eslint-disable-line react-hooks/exhaustive-deps
	);

	const startReordering = () => {
		setError( null );
		focusAfterModeChange.current = true;
		setOrder( idsOf( segments ) );
	};

	const stopReordering = () => {
		focusAfterModeChange.current = true;
		setOrder( null );
	};

	const cancelReordering = () => {
		setError( null );
		stopReordering();
	};

	const moveSegment = ( index, targetIndex ) => {
		const reordered = idsOf( listedSegments );
		const [ moved ] = reordered.splice( index, 1 );
		reordered.splice( targetIndex, 0, moved );
		setOrder( reordered );
	};

	const saveOrder = () => {
		setError( null );
		setIsSavingOrder( true );
		wizardApiFetch( {
			path: `${ newspackAudienceCampaigns.api }/segmentation-sort`,
			method: 'POST',
			data: { segmentIds: idsOf( listedSegments ) },
			quiet: true,
		} )
			.then( _segments => {
				setSegments( _segments );
				stopReordering();
			} )
			.catch( e =>
				setError( decodeEntities( e?.message || __( 'There was an error sorting segments. Please try again.', 'newspack-plugin' ) ) )
			)
			.finally( () => setIsSavingOrder( false ) );
	};

	const hasOrderChanged = isReordering && idsOf( listedSegments ).join() !== idsOf( segments ).join();

	const headerActions = [
		{
			type: 'primary',
			label: __( 'Add Segment', 'newspack-plugin' ),
			mobileIcon: plus,
			href: '#/segments/new',
			disabled: isReordering,
		},
	];

	return (
		<SegmentsScreen { ...screenProps } headerActions={ headerActions }>
			{ error && (
				<Notice className="newspack-campaigns-wizard-segments__notice" status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			{ segments.length ? (
				<>
					<Stack direction="row" justify="space-between" align="center" gap="lg" className="newspack-campaigns-wizard-segments__toolbar">
						{ isReordering ? (
							<>
								<p className="newspack-campaigns-wizard-segments__reorder-help">
									{ __(
										'Segments are checked in this order. When a reader matches more than one segment, only the first matching segment decides which targeted prompts they see.',
										'newspack-plugin'
									) }
								</p>
								<Stack direction="row" gap="sm" className="newspack-campaigns-wizard-segments__toolbar-actions">
									<Button variant="tertiary" size="compact" disabled={ isSavingOrder } onClick={ cancelReordering }>
										{ __( 'Cancel', 'newspack-plugin' ) }
									</Button>
									<Button
										variant="primary"
										size="compact"
										disabled={ isSavingOrder || ! hasOrderChanged }
										accessibleWhenDisabled
										isBusy={ isSavingOrder }
										onClick={ saveOrder }
									>
										{ __( 'Save', 'newspack-plugin' ) }
									</Button>
								</Stack>
							</>
						) : (
							<>
								<SearchControl
									className="newspack-campaigns-wizard-segments__search"
									label={ __( 'Search segments', 'newspack-plugin' ) }
									value={ search }
									onChange={ setSearch }
									size="compact"
									__nextHasNoMarginBottom
								/>
								{ segments.length > 1 && (
									<Button
										ref={ priorityButtonRef }
										variant="secondary"
										size="compact"
										className="newspack-campaigns-wizard-segments__toolbar-actions"
										onClick={ startReordering }
									>
										{ __( 'Segment Priority', 'newspack-plugin' ) }
									</Button>
								) }
							</>
						) }
					</Stack>
					<div ref={ rowsRef }>
						{ items.length ? (
							<CardSortableList
								// A fresh list per mode starts from the right rows, and drops a move still animating when Cancel is pressed.
								key={ isReordering ? 'reordering' : 'listing' }
								size="large"
								items={ items }
								sortable={ isReordering && ! isSavingOrder }
								reordering={ isReordering }
								disabled={ isSavingOrder }
								onDragCallback={ moveSegment }
							/>
						) : (
							<p className="newspack-campaigns__no-results">{ __( 'No segments match your search.', 'newspack-plugin' ) }</p>
						) }
					</div>
				</>
			) : (
				<EmptyState.Root>
					<EmptyState.Header
						icon={ people }
						title={ __( 'No segments yet', 'newspack-plugin' ) }
						description={ __( 'Group readers by what they do, then show them the right prompts.', 'newspack-plugin' ) }
					/>
					<EmptyState.Actions>
						<Button variant="primary" href="#/segments/new">
							{ __( 'Add Segment', 'newspack-plugin' ) }
						</Button>
					</EmptyState.Actions>
				</EmptyState.Root>
			) }
		</SegmentsScreen>
	);
};

export default SegmentsList;
