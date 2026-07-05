/* globals newspackAudienceCampaigns */
/**
 * WordPress dependencies.
 */
import { useState, Fragment, useEffect, useCallback, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { Button, Card, CardSortableList, Notice, Router } from '../../../../../../packages/components/src';
import { segmentDescription } from '../utils';

const { NavLink, useHistory } = Router;

const AddNewSegmentLink = () => (
	<NavLink to="segments/new">
		<Button variant="primary">{ __( 'Add New Segment', 'newspack-plugin' ) }</Button>
	</NavLink>
);

type SegmentsListProps = {
	wizardApiFetch: CampaignsWizardSharedProps[ 'wizardApiFetch' ];
	segments: CampaignsSegment[];
	setSegments: ( segments: CampaignsSegment[] ) => void;
	isLoading: number;
};

const SegmentsList = ( { wizardApiFetch, segments, setSegments, isLoading }: SegmentsListProps ) => {
	const [ inFlight, setInFlight ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const history = useHistory();
	useEffect( () => {
		window.scrollTo( 0, 0 );
	}, [] );

	const toggleSegmentStatus = useCallback(
		( segment: CampaignsSegment ) => {
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
					setSegments( _segments as CampaignsSegment[] );
				} )
				.catch( () => {
					setInFlight( false );
				} );
		},
		[ wizardApiFetch, setSegments ]
	);

	const deleteSegment = useCallback(
		( segment: CampaignsSegment ) => {
			setInFlight( true );
			setError( null );
			wizardApiFetch( {
				path: `${ newspackAudienceCampaigns.api }/segmentation/${ segment.id }`,
				method: 'DELETE',
				quiet: true,
			} )
				.then( _segments => {
					setInFlight( false );
					setSegments( _segments as CampaignsSegment[] );
				} )
				.catch( () => {
					setInFlight( false );
				} );
		},
		[ wizardApiFetch ]
	);

	const sortSegments = ( fromIndex: number, targetIndex: number ) => {
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
				setSegments( _segments as CampaignsSegment[] );
			} )
			.catch( ( e: { message?: string } ) => {
				setInFlight( false );
				setError( e.message || __( 'There was an error sorting segments. Please try again.', 'newspack-plugin' ) );
				setSegments( [ ...segments ] );
			} );
	};
	const items = useMemo(
		() =>
			segments.map( segment => ( {
				id: segment.id,
				title: segment.name,
				description: segmentDescription( segment ),
				badgeLevel: segment.is_criteria_duplicated ? ( 'warning' as const ) : ( 'default' as const ),
				badgeText: ( segment.is_criteria_duplicated ? __( 'Duplicate', 'newspack-plugin' ) : undefined ) as string,
				toggleChecked: ! segment.configuration.is_disabled,
				onToggleChange: () => toggleSegmentStatus( segment ),
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
		[ segments, toggleSegmentStatus, deleteSegment, history ]
	);

	// Defensive check predating the typed props (widening assertion keeps it type-legal).
	if ( ( segments as CampaignsSegment[] | null ) === null ) {
		return null;
	}

	return segments.length ? (
		<Fragment>
			{ error && <Notice noticeText={ error } isError /> }
			<Card headerActions noBorder>
				<h2>{ __( 'Audience segments', 'newspack-plugin' ) }</h2>
				<AddNewSegmentLink />
			</Card>
			<CardSortableList disabled={ inFlight || isLoading > 0 } items={ items } onDragCallback={ sortSegments } />
		</Fragment>
	) : (
		<Fragment>
			<Card headerActions noBorder>
				<h2>{ __( 'You have no saved audience segments.', 'newspack-plugin' ) }</h2>
				<AddNewSegmentLink />
			</Card>
			<p>{ __( 'Create audience segments to target visitors by engagement, activity, and more.', 'newspack-plugin' ) }</p>
		</Fragment>
	);
};

export default SegmentsList;
