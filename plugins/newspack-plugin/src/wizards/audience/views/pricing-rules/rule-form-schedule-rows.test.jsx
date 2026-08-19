/**
 * The schedule table's row hairlines, which close every row including the last so
 * the Add Row button sits below the table rather than against the final row.
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

const VOCAB = {
	strategies: [
		{ id: 'simple_price', label: 'Simple' },
		{ id: 'stepped_by_cycle', label: 'Schedule' },
	],
	scopes: [ { id: 'all_products', label: 'All products' } ],
	calc_types: [ { value: 'fixed_price', label: 'Fixed' } ],
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

// Section dividers span the page; only row hairlines keep the default alignment.
const rowDividers = container => container.querySelectorAll( 'hr.newspack-divider--alignment-none' );

async function renderScheduleForm() {
	let view;
	await act( async () => {
		view = render(
			<MemoryRouter>
				<RuleForm isNew={ false } initialPath={ null } rule={ SCHEDULED_RULE } vocab={ VOCAB } onDone={ jest.fn() } />
			</MemoryRouter>
		);
	} );
	return view;
}

describe( 'the schedule rows on the rule form', () => {
	it( 'closes every row with a hairline', async () => {
		const { container } = await renderScheduleForm();

		expect( screen.getAllByLabelText( 'From cycle #' ) ).toHaveLength( 2 );
		expect( rowDividers( container ) ).toHaveLength( 2 );
	} );

	it( 'puts each hairline directly after the row it closes', async () => {
		const { container } = await renderScheduleForm();

		rowDividers( container ).forEach( hairline => {
			expect( hairline.previousElementSibling ).not.toBeNull();
			expect( hairline.previousElementSibling.querySelector( 'input[type="number"]' ) ).not.toBeNull();
		} );
	} );

	it( 'gives a newly added row its own hairline', async () => {
		const { container } = await renderScheduleForm();

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: '+ Add Row' } ) );
		} );

		expect( screen.getAllByLabelText( 'From cycle #' ) ).toHaveLength( 3 );
		expect( rowDividers( container ) ).toHaveLength( 3 );
	} );
} );
