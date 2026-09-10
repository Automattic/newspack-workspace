/**
 * Saving hands the publisher back to the list, through the real wizard and router.
 * The "Change goal?" warning belongs to the goal cards alone: a Custom rule whose
 * priority or compose mode differs from the defaults must not see it on Save.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent, act } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { Wizard } from '../../../../../packages/components/src';
import { SECTIONS } from './index';
import { RULES_API_PATH as API_PATH } from './constants';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( './list', () => () => <p>Rules list</p> );
jest.mock( './scope-targets', () => () => null );
jest.mock( './rule-preview', () => () => null );

global.newspack_aux_data = { is_debug_mode: false };
global.newspack_urls = { support: 'https://newspack.com/support/' };
window.scrollTo = jest.fn();

const VOCAB = {
	strategies: [ { id: 'simple_price', label: 'Simple' } ],
	scopes: [ { id: 'all_products', label: 'All products' } ],
	calc_types: [ { value: 'fixed_price', label: 'Fixed' } ],
	currency: { code: 'USD', symbol: '$', decimals: 2 },
	conditions: [],
};

// A Custom rule with a priority of its own: the one kind of rule whose goal
// change has something to warn about.
const SAVED_RULE = {
	id: 3,
	title: 'Loyalty deal',
	intent: 'custom',
	status: 'publish',
	priority: 5,
	compose_mode: 'priority_exclusive',
	application: 'current',
	simple: { calc_type: 'fixed_price', value: 4, cycles_limit: 0, label: '' },
};

describe( 'saving a Custom rule with its own priority', () => {
	beforeEach( async () => {
		apiFetch.mockImplementation( ( { path, method } ) => {
			if ( method ) {
				return Promise.resolve( {} );
			}
			return Promise.resolve( path === `${ API_PATH }/${ SAVED_RULE.id }` ? SAVED_RULE : VOCAB );
		} );
		window.location.hash = `#/edit/${ SAVED_RULE.id }`;
		await act( async () => {
			render( <Wizard sections={ SECTIONS } /> );
		} );
	} );

	it( 'returns to the list without asking about the goal', async () => {
		expect( screen.getByLabelText( 'Priority' ) ).toHaveValue( 5 );

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		} );

		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		expect( window.location.hash ).toBe( '#/' );
		expect( screen.getByText( 'Rules list' ) ).toBeInTheDocument();
	} );
} );
