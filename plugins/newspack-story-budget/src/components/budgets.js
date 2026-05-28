/* eslint @wordpress/no-unsafe-wp-apis: 0 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState, useMemo, useEffect } from '@wordpress/element';
import {
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	__experimentalDivider as Divider,
	Spinner,
	ToggleControl,
	SelectControl,
	SearchControl,
} from '@wordpress/components';

/**
 * External dependencies.
 */
import debounce from 'lodash/debounce';

/**
 * Internal dependencies.
 */
import { NAMESPACE as storeNamespace } from '../store/constants';
import Pagination from './budget-pagination';
import BudgetRows from './budget-rows';

/**
 * Constants.
 */
export const BUDGET_STATUS = {
	ACTIVE: 'active',
	ARCHIVED: 'archived',
};

/**
 * Loading spinner component.
 * @return {JSX.Element} Loading spinner.
 */
const LoadingSpinner = () => (
	<div className="newspack-story-budget__loading">
		<Spinner aria-label={ __( 'Loading…', 'newspack-story-budget' ) } />
		<p>{ __( 'Fetching Budgets…', 'newspack-story-budget' ) }</p>
	</div>
);

export default () => {
	const { view, totalBudgets, isLoading } = useSelect( select => ( {
		view: select( storeNamespace ).getBudgetsView(),
		totalBudgets: select( storeNamespace ).getBudgetsCount(),
		isLoading: select( storeNamespace ).isBudgetsLoading(),
	} ) );

	const { setBudgetsView, setSearching, searchBudgets } = useDispatch( storeNamespace );

	const [ editMode, setEditMode ] = useState( false );

	useEffect( () => {
		setEditMode( applyFilters( 'newspack-story-budget.defaultEditMode', false ) );
	}, [] );

	const [ searchTerm, setSearchTerm ] = useState( view.search );
	const [ budgetStatus, setBudgetStatus ] = useState( view.filters?.find( filter => filter.field === 'status' )?.value ?? BUDGET_STATUS.ACTIVE );
	const [ page, setPage ] = useState( view.page );

	const doSearch = debounce( searchBudgets, 300 );

	useEffect( () => {
		if ( view.search ) {
			setSearching();
			doSearch( view.search );
		}
	}, [ view.search ] );

	useEffect( () => {
		setBudgetsView( {
			...view,
			search: searchTerm,
			filters: [ { field: 'status', value: budgetStatus } ],
			page,
		} );
	}, [ searchTerm, budgetStatus, page ] );

	/**
	 * Calculate total pages based on budget status.
	 */
	const totalPages = useMemo( () => {
		if ( ! totalBudgets.archived || budgetStatus === BUDGET_STATUS.OPEN ) {
			return 1;
		}
		return Math.ceil( totalBudgets.archived / view.perPage );
	}, [ totalBudgets, budgetStatus ] );

	return (
		<div className="newspack-story-budget__budgets">
			<HStack className="newspack-story-budget__budgets-actions" spacing={ 2 } align="center">
				<HStack className="newspack-story-budget__budgets-actions__primary" justify="start" spacing={ 4 } align="center" width="max-content">
					<SearchControl
						className="newspack-story-budget__search"
						value={ searchTerm }
						onChange={ setSearchTerm }
						size="compact"
						placeholder={ __( 'Search', 'newspack-story-budget' ) }
						__nextHasNoMarginBottom
					/>
					<Divider className="newspack-story-budget__divider" orientation="vertical" noshade />
					<SelectControl
						className="newspack-story-budget__status-filter"
						value={ budgetStatus }
						label={ __( 'Budget Status', 'newspack-story-budget' ) }
						labelPosition="side"
						options={ [
							{
								label: __( 'Active', 'newspack-story-budget' ),
								value: BUDGET_STATUS.ACTIVE,
							},
							{
								label: __( 'Archived', 'newspack-story-budget' ),
								value: BUDGET_STATUS.ARCHIVED,
							},
						] }
						onChange={ setBudgetStatus }
						__nextHasNoMarginBottom
					/>
				</HStack>
				<div className="newspack-story-budget__budgets-actions__secondary">
					<ToggleControl
						label={ __( 'Edit Mode', 'newspack-story-budget' ) }
						checked={ editMode }
						onChange={ () => setEditMode( ! editMode ) }
						__nextHasNoMarginBottom
					/>
				</div>
			</HStack>
			{ isLoading ? (
				<LoadingSpinner />
			) : (
				<VStack className="newspack-story-budget__budgets-list" spacing={ 2 } align="stretch">
					<BudgetRows allowEdit={ editMode } budgetStatus={ budgetStatus } isSearching={ searchTerm.length > 0 } />
					{ BUDGET_STATUS.ARCHIVED === budgetStatus && 0 === searchTerm.length && (
						<Pagination currentPage={ page } totalPages={ totalPages } onPageChange={ setPage } />
					) }
				</VStack>
			) }
		</div>
	);
};
