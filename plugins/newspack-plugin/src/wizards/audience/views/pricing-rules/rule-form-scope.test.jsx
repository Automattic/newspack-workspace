/**
 * The "Applies to" select on a saved rule whose scope the site doesn't register,
 * such as "all subscriptions" while WooCommerce Subscriptions is inactive. The form
 * holds and saves that scope, so the select has to show it.
 */

/**
 * External dependencies
 */
import { render, screen, act, fireEvent, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

/**
 * Internal dependencies
 */
import RuleForm from './rule-form';

jest.mock( '@wordpress/api-fetch', () => jest.fn( () => Promise.resolve( {} ) ) );
jest.mock( './scope-targets', () => () => null );
jest.mock( './rule-preview', () => () => null );

// A site without WooCommerce Subscriptions: no "all subscriptions" scope.
const VOCAB = {
	strategies: [ { id: 'simple_price', label: 'Flat Adjustment' } ],
	scopes: [
		{ id: 'all_products', label: 'All products' },
		{ id: 'product_ids', label: 'Specific products' },
	],
	calc_types: [ { value: 'fixed_price', label: 'Fixed' } ],
	currency: { code: 'USD', symbol: '$', decimals: 2 },
	conditions: [],
};

const SAVED_RULE = {
	id: 3,
	title: 'Subscribers',
	intent: 'retention',
	status: 'publish',
	deal_key: '121',
	strategy_id: 'simple_price',
	scope_type: 'all_subscriptions',
	// What the engine sends for a scope it doesn't register: the ID.
	scope_label: 'all_subscriptions',
	scope_ids: [],
	simple: { calc_type: 'fixed_price', value: 4, cycles_limit: 0, label: '' },
};

async function renderSavedRule() {
	await act( async () => {
		render(
			<MemoryRouter>
				<RuleForm isNew={ false } rule={ SAVED_RULE } vocab={ VOCAB } onDone={ jest.fn() } />
			</MemoryRouter>
		);
	} );
}

async function chooseScope( select, scopeId ) {
	await act( async () => {
		fireEvent.change( select, { target: { value: scopeId } } );
	} );
}

describe( 'a saved scope the site does not offer', () => {
	it( 'stays selected, marked as not available on this site', async () => {
		await renderSavedRule();

		const appliesTo = screen.getByLabelText( 'Applies to' );
		expect( appliesTo ).toHaveValue( 'all_subscriptions' );
		expect( within( appliesTo ).getByRole( 'option', { selected: true } ) ).toHaveTextContent( 'all_subscriptions (not available on this site)' );
	} );

	it( 'can be picked again after the publisher tries another scope', async () => {
		await renderSavedRule();

		const appliesTo = screen.getByLabelText( 'Applies to' );
		await chooseScope( appliesTo, 'all_products' );
		expect( appliesTo ).toHaveValue( 'all_products' );

		await chooseScope( appliesTo, 'all_subscriptions' );
		expect( appliesTo ).toHaveValue( 'all_subscriptions' );
	} );
} );
