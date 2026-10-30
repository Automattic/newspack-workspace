/* globals newspackAudienceCampaigns */
/**
 * WordPress dependencies.
 */
import { useState, Fragment, useEffect, useCallback, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { people } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { Button, CardSortableList, EmptyState, Notice, Router } from '../../../../../../packages/components/src';
import { segmentDescriptionItems, splitCriteriaMessage } from '../utils';

const { useHistory } = Router;

const SegmentsList = ( { wizardApiFetch, segments, setSegments, isLoading } ) => {
	const [ inFlight, setInFlight ] = useState( false );
	const [ error, setError ] = useState( null );
	const history = useHistory();
	useEffect( () => {
		window.scrollTo( 0, 0 );
	}, [] );

	const toggleSegmentStatus = useCallback(
		segment => {
			setInFlight( true );
			setError( null );
			wizardApiFetch( {
				path: `${ newspackAudienceCampaigns.api }/segmentation/${ segment.id }`,
				method: 'POST',
				quiet: true,
				data: {
					name: segment.name,
					configuration: {
						...segment.configuration,
						is_disabled: ! segment.configuration.is_disabled,
					},
					criteria: segment.criteria,
				},
			} )
				.then( _segments => {
					setInFlight( false );
					setSegments( _segments );
				} )
				.catch( () => {
					setInFlight( false );
				} );
		},
		[ wizardApiFetch, setSegments ]
	);

	const deleteSegment = useCallback(
		segment => {
			setInFlight( true );
			setError( null );
			wizardApiFetch( {
				path: `${ newspackAudienceCampaigns.api }/segmentation/${ segment.id }`,
				method: 'DELETE',
				quiet: true,
			} )
				.then( _segments => {
					setInFlight( false );
					setSegments( _segments );
				} )
				.catch( () => {
					setInFlight( false );
				} );
		},
		[ wizardApiFetch ]
	);

	const sortSegments = ( fromIndex, targetIndex ) => {
		setError( null );
		const sortedSegments = [ ...segments ];
		const [ moved ] = sortedSegments.splice( fromIndex, 1 );
		sortedSegments.splice( targetIndex, 0, moved );
		sortedSegments.forEach( ( _segment, _index ) => ( _segment.priority = _index ) );

		setInFlight( true );
		wizardApiFetch( {
			path: `${ newspackAudienceCampaigns.api }/segmentation-sort`,
			method: 'POST',
			data: { segmentIds: sortedSegments.map( _segment => _segment.id ) },
			quiet: true,
		} )
			.then( _segments => {
				setInFlight( false );
				setSegments( _segments );
			} )
			.catch( e => {
				setInFlight( false );
				setError( e.message || __( 'There was an error sorting segments. Please try again.', 'newspack-plugin' ) );
				setSegments( [ ...segments ] );
			} );
	};
	const items = useMemo(
		() =>
			segments.map( segment => {
				const criteria = segmentDescriptionItems( { ...segment, configuration: { ...segment.configuration, is_disabled: false } } );
				return {
					id: segment.id,
					title: segment.name,
					description: criteria.length > 0 && (
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
					),
					badge: segment.is_criteria_duplicated ? { label: __( 'Duplicate', 'newspack-plugin' ), intent: 'medium' } : undefined,
					toggleChecked: ! segment.configuration.is_disabled,
					onToggleChange: () => toggleSegmentStatus( segment ),
					actionsLabel: sprintf(
						// Translators: %s: segment name.
						__( 'Segment actions: %s', 'newspack-plugin' ),
						segment.name
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
				};
			} ),
		[ segments, toggleSegmentStatus, deleteSegment, history ]
	);

	if ( segments === null ) {
		return null;
	}

	return segments.length ? (
		<Fragment>
			{ error && <Notice noticeText={ error } isError /> }
			<CardSortableList disabled={ inFlight || isLoading > 0 } items={ items } onDragCallback={ sortSegments } />
		</Fragment>
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
	);
};

export default SegmentsList;
