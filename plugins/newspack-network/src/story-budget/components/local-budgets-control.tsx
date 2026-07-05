/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { SelectControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

interface LocalBudget {
	id: number;
	name: string;
}

interface LocalBudgetsControlProps {
	value: string;
	onChange: ( value: string ) => void;
	disabled?: boolean;
	__next40pxDefaultSize?: boolean;
	__nextHasNoMarginBottom?: boolean;
}

export default function LocalBudgetsControl( { value, onChange, disabled, ...props }: LocalBudgetsControlProps ) {
	const [ isLoading, setIsLoading ] = useState( false );
	const [ localBudgets, setLocalBudgets ] = useState< LocalBudget[] >( [] );

	useEffect( () => {
		const fetchLocalBudgets = async () => {
			setIsLoading( true );
			const res = await apiFetch< { budgets: LocalBudget[] } >( {
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
				// `SelectControl`'s `options[].value` is typed as a plain `string` (it renders a
				// native `<option>`, whose `value` attribute is always a string); `budget.id` is
				// stringified here to match -- the DOM would coerce it identically either way, so
				// this is a type-only change with no behavioral difference.
				...localBudgets.map( budget => ( {
					label: budget.name,
					value: String( budget.id ),
				} ) ),
			] }
		/>
	);
}
