/**
 * The editor's header, exercised through the real wizard and router. The wizard
 * blanks the header on every navigation, and the form outlives them: a route
 * change that leaves it mounted must leave Save and the Add Rule crumb standing.
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
import Router from '../../../../../packages/components/src/proxied-imports/router';
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
	scopes: [
		{ id: 'all_products', label: 'All products' },
		{ id: 'all_subscriptions', label: 'All subscriptions' },
	],
	calc_types: [ { value: 'fixed_price', label: 'Fixed' } ],
	currency: { code: 'USD', symbol: '$', decimals: 2 },
	conditions: [],
};

let routerHistory;
const HistoryProbe = () => {
	routerHistory = Router.useHistory();
	return null;
};

const headerSave = () => screen.queryByRole( 'button', { name: 'Save' } );
const currentPage = () => screen.getByRole( 'heading', { level: 1 } );

describe( 'the rule editor header', () => {
	beforeEach( async () => {
		apiFetch.mockImplementation( () => Promise.resolve( VOCAB ) );
		window.location.hash = '#/new';
		await act( async () => {
			render( <Wizard sections={ SECTIONS } renderAboveSections={ () => <HistoryProbe /> } /> );
		} );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'radio', { name: /Win-Back/ } ) );
		} );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Select Goal' } ) );
		} );
	} );

	it( 'publishes Save and the leaf crumb once a goal is chosen', () => {
		expect( apiFetch ).toHaveBeenCalledWith( { path: API_PATH } );
		expect( window.location.hash ).toBe( '#/new/winback' );
		expect( headerSave() ).toBeInTheDocument();
		expect( currentPage() ).toHaveTextContent( 'Add Rule' );
	} );

	it( 'links the ancestor crumb back to the list', () => {
		expect( screen.getByRole( 'link', { name: 'Pricing Rules' } ) ).toHaveAttribute( 'href', '#/' );
	} );

	it( 'keeps them when a goal-less URL is canonicalised back to the goal on screen', async () => {
		await act( async () => {
			routerHistory.replace( '/new' );
		} );

		expect( window.location.hash ).toBe( '#/new/winback' );
		expect( screen.getByLabelText( 'Goal' ) ).toHaveValue( 'Win-Back' );
		expect( headerSave() ).toBeInTheDocument();
		expect( headerSave() ).not.toBeDisabled();
		expect( currentPage() ).toHaveTextContent( 'Add Rule' );
	} );
} );
