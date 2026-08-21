/**
 * The Deal ID on a saved rule. It is the key publishers join analytics on, so it
 * gets its own labelled field, read-only rather than disabled so the value can be
 * selected and copied by hand as well as by the button.
 */

/**
 * External dependencies
 */
import { render, screen, act, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

/**
 * WordPress dependencies
 */
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
	strategies: [ { id: 'simple_price', label: 'Simple' } ],
	scopes: [ { id: 'all_products', label: 'All products' } ],
	calc_types: [ { value: 'fixed_price', label: 'Fixed' } ],
	currency: { code: 'USD', symbol: '$', decimals: 2 },
	conditions: [],
};

const RULE = {
	id: 3,
	title: 'Saved',
	intent: 'retention',
	status: 'publish',
	deal_key: '121',
	simple: { calc_type: 'fixed_price', value: 4, cycles_limit: 0, label: '' },
};

async function renderForm( props ) {
	await act( async () => {
		render(
			<MemoryRouter>
				<RuleForm initialPath={ null } vocab={ VOCAB } onDone={ jest.fn() } { ...props } />
			</MemoryRouter>
		);
	} );
}

// The wizard snackbar renders notice.message; any other key is a blank toast.
const copyNotice = () =>
	select( WIZARD_STORE_NAMESPACE )
		.getNotices()
		.find( n => 'pricing-rule-copy' === n.id );

describe( 'the deal id on the rule form', () => {
	// The store outlives each test, so a stale notice would satisfy the next assertion.
	beforeEach( () => {
		dispatch( WIZARD_STORE_NAMESPACE ).resetNotices();
	} );

	it( 'labels the deal id and offers a way to copy it', async () => {
		await renderForm( { isNew: false, rule: RULE } );

		const field = screen.getByDisplayValue( '121' );
		expect( screen.getByLabelText( 'Deal ID' ) ).toBe( field );
		expect( field ).toHaveAttribute( 'readonly' );
		expect( field ).toBeEnabled();
		expect( field.closest( '.is-monospace' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Copy Deal ID' } ) ).toBeInTheDocument();
	} );

	it( 'confirms a copy in a snackbar', async () => {
		const writeText = jest.fn( () => Promise.resolve() );
		Object.defineProperty( window.navigator, 'clipboard', { value: { writeText }, configurable: true } );
		await renderForm( { isNew: false, rule: RULE } );

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Copy Deal ID' } ) );
		} );

		expect( writeText ).toHaveBeenCalledWith( '121' );
		expect( copyNotice() ).toMatchObject( { type: 'success', message: 'Deal ID copied to clipboard.' } );
	} );

	it( 'replaces the previous notice rather than stacking a second one', async () => {
		Object.defineProperty( window.navigator, 'clipboard', { value: { writeText: () => Promise.resolve() }, configurable: true } );
		await renderForm( { isNew: false, rule: RULE } );
		const button = screen.getByRole( 'button', { name: 'Copy Deal ID' } );

		await act( async () => {
			fireEvent.click( button );
		} );
		await act( async () => {
			fireEvent.click( button );
		} );

		const copyNotices = select( WIZARD_STORE_NAMESPACE )
			.getNotices()
			.filter( n => 'pricing-rule-copy' === n.id );
		expect( copyNotices ).toHaveLength( 1 );
	} );

	it( 'reports a failed copy rather than claiming success', async () => {
		Object.defineProperty( window.navigator, 'clipboard', {
			value: { writeText: () => Promise.reject( new Error( 'denied' ) ) },
			configurable: true,
		} );
		document.execCommand = jest.fn( () => false );
		await renderForm( { isNew: false, rule: RULE } );

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Copy Deal ID' } ) );
		} );

		expect( copyNotice() ).toMatchObject( { type: 'error', message: 'Could not copy the Deal ID.' } );
	} );

	it( 'falls back to a selection copy outside a secure context', async () => {
		Object.defineProperty( window.navigator, 'clipboard', { value: undefined, configurable: true } );
		document.execCommand = jest.fn( () => true );
		await renderForm( { isNew: false, rule: RULE } );

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Copy Deal ID' } ) );
		} );

		expect( document.execCommand ).toHaveBeenCalledWith( 'copy' );
		expect( copyNotice() ).toMatchObject( { type: 'success', message: 'Deal ID copied to clipboard.' } );
	} );

	it( 'has no deal id before the rule exists', async () => {
		await renderForm( { isNew: true, rule: null } );

		expect( screen.queryByText( 'Deal ID' ) ).not.toBeInTheDocument();
	} );
} );
