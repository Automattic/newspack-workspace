/**
 * The pricing model picker: absent when the site offers a single model, and locked
 * once the rule exists, since the REST controller keeps the saved strategy.
 */

/**
 * External dependencies
 */
import { render, screen, act, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

/**
 * Internal dependencies
 */
import RuleForm from './rule-form';

jest.mock( '@wordpress/api-fetch', () => jest.fn( () => Promise.resolve( {} ) ) );
jest.mock( './scope-targets', () => () => null );
jest.mock( './rule-preview', () => () => null );

const SIMPLE = { id: 'simple_price', label: 'Flat Adjustment — one price for matching products' };
const SCHEDULE = { id: 'stepped_by_cycle', label: 'Price Schedule — different prices for the purchase and renewals' };

const vocabWith = strategies => ( {
	strategies,
	scopes: [ { id: 'all_products', label: 'All products' } ],
	calc_types: [ { value: 'fixed_price', label: 'Fixed' } ],
	currency: { code: 'USD', symbol: '$', decimals: 2 },
	conditions: [],
} );

const SAVED_RULE = {
	id: 3,
	title: 'Saved',
	intent: 'retention',
	status: 'publish',
	deal_key: '121',
	strategy_id: 'simple_price',
	simple: { calc_type: 'fixed_price', value: 4, cycles_limit: 0, label: '' },
};

// A new rule with no goal opens the goal modal over the form, hiding the fields.
async function renderForm( { strategies, ...props } ) {
	await act( async () => {
		render(
			<MemoryRouter>
				<RuleForm isNew initialPath="custom" rule={ null } vocab={ vocabWith( strategies ) } onDone={ jest.fn() } { ...props } />
			</MemoryRouter>
		);
	} );
}

describe( 'choosing a pricing model', () => {
	it( 'offers both models as a toggle when the site has both', async () => {
		await renderForm( { strategies: [ SIMPLE, SCHEDULE ] } );

		const group = screen.getByRole( 'radiogroup', { name: 'Pricing model' } );
		expect( group ).toBeInTheDocument();
		expect( screen.getByRole( 'radio', { name: 'Flat Adjustment' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'radio', { name: 'Price Schedule' } ) ).toBeInTheDocument();
	} );

	it( 'describes the model that is selected, and follows the selection', async () => {
		await renderForm( { strategies: [ SIMPLE, SCHEDULE ] } );

		expect( screen.getByText( 'One price for matching products. Fixed once the rule is created.' ) ).toBeInTheDocument();

		await act( async () => {
			fireEvent.click( screen.getByRole( 'radio', { name: 'Price Schedule' } ) );
		} );

		expect( screen.getByText( 'Different prices for the purchase and renewals. Fixed once the rule is created.' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'One price for matching products. Fixed once the rule is created.' ) ).not.toBeInTheDocument();
	} );

	it( 'drops the picker when only one model exists, keeping the section itself', async () => {
		await renderForm( { strategies: [ SIMPLE ] } );

		expect( screen.queryByRole( 'radiogroup', { name: 'Pricing model' } ) ).not.toBeInTheDocument();
		expect( screen.queryByText( /Fixed once the rule is created/ ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'Pricing' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'Calculation' ) ).toBeInTheDocument();
	} );

	it( 'names both disclosure states and gates the reader-facing name on them', async () => {
		await renderForm( { strategies: [ SIMPLE, SCHEDULE ] } );

		await act( async () => {
			fireEvent.click( screen.getByRole( 'radio', { name: 'Shown' } ) );
		} );
		expect( screen.getByLabelText( 'Name shown to reader' ) ).toBeInTheDocument();

		await act( async () => {
			fireEvent.click( screen.getByRole( 'radio', { name: 'Hidden' } ) );
		} );
		expect( screen.queryByLabelText( 'Name shown to reader' ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'The adjusted price applies with no explanation to the reader.' ) ).toBeInTheDocument();
	} );

	it( 'shows the saved model on an existing rule, but refuses to change it', async () => {
		await renderForm( { strategies: [ SIMPLE, SCHEDULE ], isNew: false, rule: SAVED_RULE } );

		const saved = screen.getByRole( 'radio', { name: 'Flat Adjustment' } );
		const other = screen.getByRole( 'radio', { name: 'Price Schedule' } );
		expect( saved ).toBeChecked();
		expect( saved ).toBeDisabled();
		expect( other ).toBeDisabled();

		await act( async () => {
			fireEvent.click( other );
		} );

		expect( screen.getByRole( 'radio', { name: 'Flat Adjustment' } ) ).toBeChecked();
	} );
} );
