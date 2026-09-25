/**
 * Content Gate Priority component.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { decodeEntities } from '@wordpress/html-entities';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { Button, CardSortableList, Modal } from '../../../../../packages/components/src';
import { useWizardData } from '../../../../../packages/components/src/wizard/store/utils';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import { useWizardApiFetch } from '../../../hooks/use-wizard-api-fetch';
import { getGateStatus, getGateStatusBadgeIntent, getPriorityWarningLabel, getPriorityWarnings } from './utils';
import { AUDIENCE_CONTENT_GATES_WIZARD_SLUG } from './consts';

const ContentGatesPriority = ( {
	closeModal,
	showModal,
	updateGatesData,
}: {
	closeModal: () => void;
	showModal: boolean;
	updateGatesData: ( gates: Gate[] ) => void;
} ) => {
	const { gates = [] } = useWizardData( AUDIENCE_CONTENT_GATES_WIZARD_SLUG ) as { gates?: Gate[] };
	const { wizardApiFetch, isFetching, resetError } = useWizardApiFetch( AUDIENCE_CONTENT_GATES_WIZARD_SLUG );
	const { addNotice, resetNotices } = useDispatch( WIZARD_STORE_NAMESPACE );
	const [ sortedGates, setSortedGates ] = useState< Gate[] >( gates );
	// The modal stays mounted while closed, and card actions change gates in place, so
	// each opening starts from the latest gates rather than the ones first rendered.
	useEffect( () => {
		if ( showModal ) {
			setSortedGates( gates );
		}
	}, [ showModal ] ); // eslint-disable-line react-hooks/exhaustive-deps -- `gates` falls back to a fresh `[]` each render, which would re-seed in a loop.
	const gateItems = useMemo( () => {
		// Recomputed from the unsaved order, so a warning follows each drag.
		const priorityWarnings = getPriorityWarnings( sortedGates );
		return sortedGates.map( gate => ( {
			id: gate.id,
			title: gate.title,
			secondaryBadge: priorityWarnings[ gate.id ]
				? { label: getPriorityWarningLabel(), intent: 'low' as const, tooltip: priorityWarnings[ gate.id ] }
				: undefined,
			badge: { label: getGateStatus( gate.status ), intent: getGateStatusBadgeIntent( gate.status ) },
		} ) );
	}, [ sortedGates ] );

	const updatePriorities = useRef< ( updates: Gate[] ) => void >();
	const handleUpdateGatePriorities = ( updates: Gate[] ) => {
		if ( isFetching ) {
			return;
		}
		const oldGates = [ ...gates ];
		resetError();
		resetNotices();
		wizardApiFetch< Gate >(
			{
				path: `/newspack/v1/wizard/${ AUDIENCE_CONTENT_GATES_WIZARD_SLUG }/priority`,
				method: 'POST',
				data: {
					gates: updates.map( g => ( { id: g.id, priority: g.priority } ) ),
				},
			},
			{
				onSuccess: () => {
					updateGatesData( updates );
					addNotice( {
						message: __( 'Gate priority updated.', 'newspack-plugin' ),
						type: 'success',
						id: 'content-gates-priority-updated',
						actions: [ { label: __( 'Undo', 'newspack-plugin' ), onClick: () => updatePriorities.current?.( oldGates ) } ],
					} );
				},
				onError: ( fetchError: WpFetchError ) => {
					addNotice( {
						message: decodeEntities( fetchError.message ),
						type: 'error',
						id: 'content-gates-priority-error',
					} );
					updateGatesData( oldGates );
				},
				onFinally: () => {
					closeModal();
				},
			}
		);
	};
	updatePriorities.current = handleUpdateGatePriorities;

	const sortGates = ( index: number, targetIndex: number ) => {
		if ( isFetching ) {
			return;
		}

		const gate = sortedGates[ index ];
		const _sortedGates = [ ...sortedGates ];

		// Remove the gate and drop it back into the array at the target index.
		_sortedGates.splice( index, 1 );
		_sortedGates.splice( targetIndex, 0, gate );

		// Reindex priorities to avoid gaps and dupes.
		const reindexedGates = _sortedGates.map( ( _gate, _index ) => ( {
			..._gate,
			priority: _index,
		} ) );
		setSortedGates( reindexedGates );
	};

	return (
		showModal && (
			<Modal title={ __( 'Gate Priority', 'newspack-plugin' ) } size="large" onRequestClose={ closeModal }>
				<Stack direction="column" gap="xl">
					<span>
						{ __(
							'Gates are checked in this order. When content matches more than one gate, only the first matching gate decides who can read it.',
							'newspack-plugin'
						) }
					</span>
					<CardSortableList disabled={ isFetching } items={ gateItems } onDragCallback={ sortGates } />
					<Stack direction="row" gap="sm" justify="end">
						<Button variant="tertiary" disabled={ isFetching } onClick={ closeModal }>
							{ __( 'Cancel', 'newspack-plugin' ) }
						</Button>
						<Button
							variant="primary"
							disabled={ isFetching || JSON.stringify( sortedGates ) === JSON.stringify( gates ) }
							loading={ isFetching }
							onClick={ () => updatePriorities.current?.( sortedGates ) }
						>
							{ __( 'Save', 'newspack-plugin' ) }
						</Button>
					</Stack>
				</Stack>
			</Modal>
		)
	);
};

export default ContentGatesPriority;
