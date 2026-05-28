/* eslint @wordpress/no-unsafe-wp-apis: 0 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useDispatch, useSelect } from '@wordpress/data';
import { useState, useEffect } from '@wordpress/element';
import {
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	__experimentalText as Text,
	Icon,
	DropdownMenu,
} from '@wordpress/components';
import { ActionCard } from 'newspack-components';
import { moreVertical, chevronUp, chevronDown, dragHandle } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { NAMESPACE as storeNamespace } from '../store/constants';
import { BUDGET_STATUS } from './budgets';
import BudgetNameField from './budget-field/budget-field-name';
import BudgetAutoArchiveDateField from './budget-field/budget-field-auto-archive-date';

const BudgetRows = ( { allowEdit, budgetStatus, isSearching } ) => {
	const [ draggedItem, setDraggedItem ] = useState( null );
	const [ currentBudgets, setCurrentBudgets ] = useState( [] );

	const { updateBudget, saveActiveBudgetOrder } = useDispatch( storeNamespace );

	const { budgets } = useSelect(
		select => ( {
			budgets: select( storeNamespace ).getBudgets(),
		} ),
		[ budgetStatus ]
	);

	useEffect( () => {
		if ( budgets ) {
			setCurrentBudgets( budgets );
		}
	}, [ budgets ] );

	useEffect( () => {
		if (
			true === allowEdit &&
			BUDGET_STATUS.ACTIVE === budgetStatus &&
			! isSearching &&
			JSON.stringify( currentBudgets ) !== JSON.stringify( budgets )
		) {
			const budgetIds = currentBudgets.map( budget => budget.id );
			saveActiveBudgetOrder( budgetIds );
		}
	}, [ currentBudgets ] );

	/**
	 * Sort handlers.
	 */
	const handleDragStart = ( event, budgetId ) => {
		// Only allow dragging if the target is the drag handle icon.
		if ( ! event.target.classList.contains( 'newspack-story-budget__budget-drag-handle' ) ) {
			event.preventDefault();
			return;
		}

		// Find the closest wrapper element and add classes/set the drag image.
		const wrapper = event.target.closest( '.newspack-story-budget__budget-wrapper' );
		if ( wrapper ) {
			wrapper.classList.add( 'is-dragging' );
			event.dataTransfer.effectAllowed = 'move';
			event.dataTransfer.setDragImage( wrapper, 25, 25 );
		}

		setDraggedItem( budgetId );
	};

	const handleDragEnd = event => {
		// Find the closest wrapper element and remove the class
		const wrapper = event.target.closest( '.newspack-story-budget__budget-wrapper' );
		if ( wrapper ) {
			wrapper.classList.remove( 'is-dragging' );
		}

		setDraggedItem( null );
	};

	const handleDragLeave = event => {
		const wrapper = event.currentTarget;
		wrapper.classList.remove( 'is-drag-over' );
	};

	const handleDragOver = ( event, targetBudgetId ) => {
		event.preventDefault();
		const wrapper = event.currentTarget;

		if ( draggedItem === targetBudgetId || wrapper.classList.contains( 'is-drag-over' ) ) {
			return;
		}
		wrapper.classList.add( 'is-drag-over' );
	};

	const handleDrop = ( event, targetBudgetId ) => {
		event.preventDefault();
		if ( draggedItem === targetBudgetId ) {
			return;
		}

		const draggedIndex = currentBudgets.findIndex( budget => budget.id === draggedItem );
		const targetIndex = currentBudgets.findIndex( budget => budget.id === targetBudgetId );
		const newBudgets = [ ...currentBudgets ];
		const [ removed ] = newBudgets.splice( draggedIndex, 1 );
		newBudgets.splice( targetIndex, 0, removed );

		const wrapper = event.currentTarget;
		wrapper.classList.remove( 'is-drag-over' );

		setCurrentBudgets( newBudgets );
	};

	const onUpClick = targetBudgetId => {
		const index = currentBudgets.findIndex( budget => budget.id === targetBudgetId );
		if ( 0 === index ) {
			return;
		}
		const targetBudget = currentBudgets[ index ];
		const newBudgets = [ ...currentBudgets ];
		newBudgets.splice( index, 1 );
		newBudgets.splice( index - 1, 0, targetBudget );
		setCurrentBudgets( newBudgets );
	};

	const onDownClick = targetBudgetId => {
		const index = currentBudgets.findIndex( budget => budget.id === targetBudgetId );
		const targetBudget = currentBudgets[ index ];
		const newBudgets = [ ...currentBudgets ];
		newBudgets.splice( index, 1 );
		newBudgets.splice( index + 1, 0, targetBudget );
		setCurrentBudgets( newBudgets );
	};

	/**
	 * Callback handlers.
	 */
	const onUpdateBudget = async ( budgetId, updatedBudget ) => {
		try {
			await updateBudget( budgetId, updatedBudget );
		} catch ( e ) {
			console.error( e ); // eslint-disable-line no-console
		}
	};

	/**
	 * Budget actions.
	 */
	const getBudgetControls = budget => [
		{
			title: __( 'View Stories', 'newspack-story-budget' ),
			onClick: () => {
				const url = new URL( window.location.href );
				url.hash = '#/stories';
				url.searchParams.set( 'budget_id', budget.id );

				window.location.assign( url.toString() );
			},
		},
		{
			title: budget.archived ? __( 'Unarchive', 'newspack-story-budget' ) : __( 'Archive', 'newspack-story-budget' ),
			onClick: async () => {
				await onUpdateBudget( budget.id, {
					...budget,
					archived: ! budget.archived,
					order: 0,
				} );
			},
			label: budget.archived ? 'budget-unarchive' : 'budget-archive',
		},
	];

	return (
		<>
			{ currentBudgets.map( budget => (
				<div
					key={ budget.id }
					draggable={ allowEdit }
					onDragStart={ event => handleDragStart( event, budget.id ) }
					onDragEnd={ handleDragEnd }
					onDragOver={ event => handleDragOver( event, budget.id ) }
					onDragLeave={ handleDragLeave }
					onDrop={ event => handleDrop( event, budget.id ) }
					className="newspack-story-budget__budget-wrapper"
				>
					<ActionCard
						key={ budget.id }
						className="newspack-story-budget__budget"
						badge={ budget.story_count ?? 0 }
						title={
							<>
								<div className="newspack-story-budget__budget-title">
									<HStack justify="between" spacing={ 2 } align="center">
										{ allowEdit && BUDGET_STATUS.ACTIVE === budgetStatus && (
											<>
												<span className="newspack-story-budget__budget-drag-handle" draggable>
													<Icon icon={ dragHandle } />
												</span>
												<span className="newspack-story-budget__budget__sort-buttons">
													<button
														className="newspack-story-budget__sort-buttons__up"
														onClick={ () => onUpClick( budget.id ) }
														disabled={ 0 === currentBudgets.findIndex( b => b.id === budget.id ) }
													>
														<Icon icon={ chevronUp } />
													</button>
													<button
														className="newspack-story-budget__sort-buttons__down"
														onClick={ () => onDownClick( budget.id ) }
														disabled={ currentBudgets.length - 1 === currentBudgets.findIndex( b => b.id === budget.id ) }
													>
														<Icon icon={ chevronDown } />
													</button>
												</span>
											</>
										) }
										<span className="newspack-story-budget__budget-title__name">
											{ allowEdit ? (
												<div className="newspack-story-budget__field">
													<BudgetNameField budget={ budget } onUpdateBudget={ onUpdateBudget } />
													<BudgetAutoArchiveDateField budget={ budget } onUpdateBudget={ onUpdateBudget } />
												</div>
											) : (
												<VStack>
													<span>{ budget.name }</span>
													{ budget.archive_at && (
														<Text size="small" variant="muted">
															{ __( 'Auto-archive set for ', 'newspack-story-budget' ) }
															{ new Date( budget.archive_at ).toLocaleDateString( 'en-US', {
																year: 'numeric',
																month: 'long',
																day: 'numeric',
															} ) }
														</Text>
													) }
												</VStack>
											) }
										</span>
									</HStack>
								</div>
							</>
						}
						actionText={
							<DropdownMenu
								icon={ moreVertical }
								label={ __( 'Actions', 'newspack-story-budget' ) }
								controls={ getBudgetControls( budget ) }
								popoverProps={ {
									placement: 'bottom-end',
									className: 'newspack-story-budget__budget-actions',
								} }
							/>
						}
					/>
				</div>
			) ) }
			{ 0 === currentBudgets.length && (
				<div className="newspack-story-budget__no-budgets">{ __( 'No budgets found.', 'newspack-story-budget' ) }</div>
			) }
		</>
	);
};

export default BudgetRows;
