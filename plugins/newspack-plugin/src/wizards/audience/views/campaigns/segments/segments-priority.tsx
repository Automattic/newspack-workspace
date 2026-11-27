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
import type { CardListItem } from '../../../../../../packages/components/src/card-sortable-list/card-list-row';

const idsOf = ( segments: CampaignsSegment[] ): string[] => segments.map( ( { id } ) => id );

type SegmentsPriorityProps = {
	closeModal: () => void;
	segments: CampaignsSegment[];
	setSegments: ( segments: CampaignsSegment[] ) => void;
	wizardApiFetch: CampaignsWizardSharedProps[ 'wizardApiFetch' ];
};

/**
 * Modal for choosing the order segments are matched in. Mounted only while
 * open, so each opening starts from the current segments.
 */
const SegmentsPriority = ( { closeModal, segments, setSegments, wizardApiFetch }: SegmentsPriorityProps ) => {
	const [ sortedSegments, setSortedSegments ] = useState( segments );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	const items = useMemo< CardListItem[] >(
		() =>
			sortedSegments.map( ( { id, name, configuration } ) => ( {
				id,
				title: decodeEntities( name ),
				badge: configuration?.is_disabled ? { label: __( 'Disabled', 'newspack-plugin' ), intent: 'draft' } : undefined,
			} ) ),
		[ sortedSegments ]
	);
	const hasChanged = idsOf( sortedSegments ).join() !== idsOf( segments ).join();

	const sortSegments = ( index: number, targetIndex: number ) => {
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
			path: `${ window.newspackAudienceCampaigns.api }/segmentation-sort`,
			method: 'POST',
			data: { segmentIds: idsOf( sortedSegments ) },
			quiet: true,
		} )
			.then( _segments => {
				setSegments( _segments as CampaignsSegment[] );
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
				<span>{ __( 'Readers in more than one segment see prompts from the highest one.', 'newspack-plugin' ) }</span>
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
