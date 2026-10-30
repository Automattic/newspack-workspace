/* globals newspackAudienceCampaigns */
/**
 * WordPress dependencies.
 */
import { Notice } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import { Button, CardSortableList, Modal } from '../../../../../../packages/components/src';

const idsOf = segments => segments.map( ( { id } ) => id );

/**
 * Modal for choosing the order segments are matched in. Mounted only while
 * open, so each opening starts from the current segments.
 */
const SegmentsPriority = ( { closeModal, segments, setSegments, wizardApiFetch } ) => {
	const [ sortedSegments, setSortedSegments ] = useState( segments );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState( null );

	const items = useMemo( () => sortedSegments.map( ( { id, name } ) => ( { id, title: decodeEntities( name ) } ) ), [ sortedSegments ] );
	const hasChanged = idsOf( sortedSegments ).join() !== idsOf( segments ).join();

	const sortSegments = ( index, targetIndex ) => {
		setSortedSegments( current => {
			const reordered = [ ...current ];
			const [ moved ] = reordered.splice( index, 1 );
			reordered.splice( targetIndex, 0, moved );
			return reordered;
		} );
	};

	const saveOrder = () => {
		setError( null );
		setIsSaving( true );
		wizardApiFetch( {
			path: `${ newspackAudienceCampaigns.api }/segmentation-sort`,
			method: 'POST',
			data: { segmentIds: idsOf( sortedSegments ) },
			quiet: true,
		} )
			.then( _segments => {
				setSegments( _segments );
				closeModal();
			} )
			.catch( e => {
				setError( decodeEntities( e?.message || __( 'There was an error sorting segments. Please try again.', 'newspack-plugin' ) ) );
				setIsSaving( false );
			} );
	};

	return (
		<Modal
			title={ __( 'Segment Priority', 'newspack-plugin' ) }
			size="large"
			onRequestClose={ () => {
				if ( ! isSaving ) {
					closeModal();
				}
			} }
		>
			<Stack direction="column" gap="xl">
				<span>
					{ __(
						'Segments are checked in this order. When a reader matches more than one segment, only the first matching segment decides which targeted prompts they see.',
						'newspack-plugin'
					) }
				</span>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				<CardSortableList disabled={ isSaving } items={ items } onDragCallback={ sortSegments } />
				<Stack direction="row" gap="sm" justify="end">
					<Button variant="tertiary" disabled={ isSaving } onClick={ closeModal }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button variant="primary" disabled={ isSaving || ! hasChanged } accessibleWhenDisabled loading={ isSaving } onClick={ saveOrder }>
						{ __( 'Save', 'newspack-plugin' ) }
					</Button>
				</Stack>
			</Stack>
		</Modal>
	);
};

export default SegmentsPriority;
