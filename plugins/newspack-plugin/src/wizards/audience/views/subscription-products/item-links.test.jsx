/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { bundledLinks, gateLinks, ruleLinks } from './item-links';

// The handoff POSTs before it navigates, so its destination is the contract here.
jest.mock( '../../../../../packages/components/src/handoff', () => ( { url, children } ) => <a href={ url }>{ children }</a> );

describe( 'item links', () => {
	it( 'hands a gate off to its Access Control editor, decoding its title', () => {
		render( <>{ gateLinks( [ { id: 7, title: 'Reader&#8217;s wall' } ] ) }</> );

		expect( screen.getByRole( 'link', { name: 'Reader’s wall' } ) ).toHaveAttribute(
			'href',
			'admin.php?page=newspack-audience-access-control#/edit/7'
		);
	} );

	it( 'names an untitled gate by its id', () => {
		render( <>{ gateLinks( [ { id: 7, title: '' } ] ) }</> );

		expect( screen.getByRole( 'link', { name: '#7' } ) ).toBeInTheDocument();
	} );

	it( 'hands a rule off to its Pricing Rules editor', () => {
		render( <>{ ruleLinks( { policies: [ { id: 12, label: 'Student discount', adjustment_label: '20% off' } ] } ) }</> );

		expect( screen.getByRole( 'link', { name: 'Student discount' } ) ).toHaveAttribute(
			'href',
			'admin.php?page=newspack-audience-pricing-rules#/edit/12'
		);
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
