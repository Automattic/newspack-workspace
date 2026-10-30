/**
 * The schedule section of the rule form: a read-only table, with add and edit in
 * a drawer.
 */

/**
 * External dependencies
 */
import { render, screen, act, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { dispatch, select } from '@wordpress/data';

/**
 * Internal dependencies
 */
import RuleForm from './rule-form';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';

jest.mock( '@wordpress/api-fetch', () => jest.fn( () => Promise.resolve( {} ) ) );
jest.mock( './scope-targets', () => () => null );
jest.mock( './rule-preview', () => () => null );

const VOCAB = {
	strategies: [
		{ id: 'simple_price', label: 'Simple' },
		{ id: 'stepped_by_cycle', label: 'Schedule' },
	],
	scopes: [ { id: 'all_products', label: 'All products' } ],
	calc_types: [ { value: 'fixed_price', label: 'Set price to' } ],
	currency: { code: 'USD', symbol: '$', decimals: 2 },
	conditions: [],
};

const SCHEDULED_RULE = {
	id: 7,
	title: 'Welcome ladder',
	intent: 'acquisition',
	status: 'publish',
	deal_key: '121',
	strategy_id: 'stepped_by_cycle',
	steps: [
		{ at: 1, calc_type: 'fixed_price', value: 5, label: 'Intro' },
		{ at: 3, calc_type: 'fixed_price', value: 9, label: 'Year 1' },
	],
};

async function renderScheduleForm( rule = SCHEDULED_RULE ) {
	let view;
	await act( async () => {
		view = render(
			<MemoryRouter>
				<RuleForm isNew={ false } initialPath={ null } rule={ rule } vocab={ VOCAB } onDone={ jest.fn() } />
			</MemoryRouter>
		);
	} );
	return view;
}

// The Save action lives in the wizard header, so tests reach submit() through the
// header data the form published.
const headerSave = () => select( WIZARD_STORE_NAMESPACE ).getHeaderData().actions[ 0 ].action;

/** Fire the header's Save action and return the body it posted. */
async function save() {
	await act( async () => {
		headerSave()();
	} );
	return apiFetch.mock.calls[ apiFetch.mock.calls.length - 1 ][ 0 ].data;
}

const drawerField = ( label, value ) => act( async () => void fireEvent.change( screen.getByLabelText( label ), { target: { value } } ) );

const clickButton = name => act( async () => void fireEvent.click( screen.getByRole( 'button', { name } ) ) );

describe( 'the schedule section of the rule form', () => {
	// The store outlives each test, so a stale notice would satisfy the next assertion.
	beforeEach( () => {
		apiFetch.mockClear();
		dispatch( WIZARD_STORE_NAMESPACE ).resetNotices();
	} );

	it( 'lists the rule’s prices without putting controls in the cells', async () => {
		await renderScheduleForm();

		// By accessible name: the cell shows "1 → 2" and spells the arrow out for a reader.
		expect( screen.getByRole( 'button', { name: '1 to 2' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: '3 onward' } ) ).toBeInTheDocument();
		expect( screen.getByText( '$5.00' ) ).toBeInTheDocument();
		expect( screen.getByText( '$9.00' ) ).toBeInTheDocument();
		expect( screen.queryByLabelText( 'From cycle #' ) ).not.toBeInTheDocument();
	} );

	// rule-preview is mocked out, so the table cannot be the source: a hit here is
	// the Impact Preview header carrying the note, and it carries it only once.
	it( 'explains the cycle markers in the Impact Preview header', async () => {
		await renderScheduleForm();
		expect( screen.getAllByText( /c1 is the initial purchase/ ) ).toHaveLength( 1 );
	} );

	it( 'leaves the cycle markers unexplained when the rule has no cycle dimension', async () => {
		await renderScheduleForm( {
			...SCHEDULED_RULE,
			strategy_id: 'simple_price',
			steps: null,
			simple: { calc_type: 'fixed_price', value: 5, cycles_limit: 0, label: '' },
		} );
		expect( screen.queryByText( /c1 is the initial purchase/ ) ).not.toBeInTheDocument();
	} );

	it( 'opens the drawer to add a price', async () => {
		await renderScheduleForm();

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Add Price' } ) );
		} );

		expect( screen.getByLabelText( 'From cycle #' ) ).toHaveValue( 4 );
	} );

	it( 'posts the price added in the drawer along with the ones already listed', async () => {
		await renderScheduleForm();

		await clickButton( 'Add Price' );
		await drawerField( 'Value ($)', '12' );
		await clickButton( 'Save' );

		expect( ( await save() ).steps ).toEqual( [
			{ at: 1, calc_type: 'fixed_price', value: 5, label: 'Intro' },
			{ at: 3, calc_type: 'fixed_price', value: 9, label: 'Year 1' },
			{ at: 4, calc_type: 'fixed_price', value: 12, label: '' },
		] );
	} );

	// A rule saved before the redesign can hold its prices in any order.
	it( 'orders a rule stored out of sequence for both the table and the save', async () => {
		await renderScheduleForm( {
			...SCHEDULED_RULE,
			steps: [
				{ at: 3, calc_type: 'fixed_price', value: 9, label: 'Year 1' },
				{ at: 1, calc_type: 'fixed_price', value: 5, label: 'Intro' },
			],
		} );

		expect( screen.getByRole( 'button', { name: '1 to 2' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: '3 onward' } ) ).toBeInTheDocument();
		expect( ( await save() ).steps ).toEqual( [
			{ at: 1, calc_type: 'fixed_price', value: 5, label: 'Intro' },
			{ at: 3, calc_type: 'fixed_price', value: 9, label: 'Year 1' },
		] );
	} );

	it( 'refuses to save a schedule with no prices', async () => {
		await renderScheduleForm( { ...SCHEDULED_RULE, steps: [] } );

		// Nothing to preview yet, so the whole section stays out of the way.
		expect( screen.queryByRole( 'heading', { name: 'Impact Preview' } ) ).not.toBeInTheDocument();

		await act( async () => {
			headerSave()();
		} );

		expect( apiFetch ).not.toHaveBeenCalled();
		expect( select( WIZARD_STORE_NAMESPACE ).getNotices() ).toContainEqual(
			expect.objectContaining( { id: 'pricing-rule-steps', type: 'error', message: 'Add at least one price.' } )
		);
	} );
} );
