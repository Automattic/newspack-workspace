/**
 * External dependencies
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { bundledLinks, gateLinks, ruleLinks } from './item-links';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const handoffDestination = () => apiFetch.mock.calls.at( -1 )[ 0 ].data.destinationUrl;

describe( 'item links', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		// A rejected handoff falls back to plain navigation, which jsdom can't do;
		// the request's destination is the contract here.
		apiFetch.mockReturnValue( new Promise( () => {} ) );
	} );

	it( 'hands a gate off to its Access Control editor, decoding its title', () => {
		render( <>{ gateLinks( [ { id: 7, title: 'Reader&#8217;s wall' } ] ) }</> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Reader’s wall' } ) );

		expect( handoffDestination() ).toBe( 'admin.php?page=newspack-audience-access-control#/edit/7' );
	} );

	it( 'names an untitled gate by its id', () => {
		render( <>{ gateLinks( [ { id: 7, title: '' } ] ) }</> );

		expect( screen.getByRole( 'button', { name: '#7' } ) ).toBeInTheDocument();
	} );

	it( 'hands a rule off to its Pricing Rules editor', () => {
		render( <>{ ruleLinks( { policies: [ { id: 12, label: 'Student discount', adjustment_label: '20% off' } ] } ) }</> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Student discount' } ) );

		expect( handoffDestination() ).toBe( 'admin.php?page=newspack-audience-pricing-rules#/edit/12' );
	} );

	it( 'waits for the unsaved-changes guard and disarms it before leaving', async () => {
		const guard = { requestConfirm: jest.fn(), allowNextUnload: jest.fn() };
		apiFetch.mockResolvedValue( { HandoffLink: '#handoff' } );
		render( <>{ gateLinks( [ { id: 7, title: 'Wall' } ], guard ) }</> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Wall' } ) );

		expect( apiFetch ).not.toHaveBeenCalled();

		guard.requestConfirm.mock.calls[ 0 ][ 0 ]();
		await waitFor( () => expect( guard.allowNextUnload ).toHaveBeenCalled() );
		expect( handoffDestination() ).toBe( 'admin.php?page=newspack-audience-access-control#/edit/7' );
	} );

	it( 'links a bundled plan Plans can edit, and leaves any other as text', () => {
		render(
			<>
				{ bundledLinks( [
					{ id: 3, name: 'Monthly', type: 'subscription' },
					{ id: 4, name: 'Tote bag', type: 'simple' },
				] ) }
			</>
		);

		expect( screen.getByRole( 'link', { name: 'Monthly' } ) ).toHaveAttribute( 'href', '#/edit/3' );
		expect( screen.getByText( 'Tote bag' ).tagName ).toBe( 'SPAN' );
	} );
} );
