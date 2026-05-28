/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { SelectControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

export default function LocalBudgetsControl( {
	value,
	onChange,
	disabled,
	...props
} ) {
	const [ isLoading, setIsLoading ] = useState( false );
	const [ localBudgets, setLocalBudgets ] = useState( [] );

	useEffect( () => {
		const fetchLocalBudgets = async () => {
			setIsLoading( true );
			const res = await apiFetch( {
				path: 'newspack-story-budget/v1/budgets?include_archived=false',
			} );
			setLocalBudgets( res.budgets );
			setIsLoading( false );
		};
		fetchLocalBudgets();
	}, [] );

	return (
		<SelectControl
			{ ...props }
			disabled={ isLoading || disabled }
			label={ __( 'Budget', 'newspack-network' ) }
			value={ value }
			onChange={ onChange }
			options={ [
				{
					label: __( 'Select a budget', 'newspack-network' ),
					value: '',
				},
				...localBudgets.map( ( budget ) => ( {
					label: budget.name,
					value: budget.id,
				} ) ),
			] }
		/>
	);
}
