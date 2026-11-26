/**
 * External dependencies.
 */
import { render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';

/**
 * Internal dependencies.
 */
import SubscriptionDetailsDrawer from './SubscriptionDetailsDrawer';

const group = overrides => ( {
	id: 12,
	plan: 'Team plan',
	status: 'active',
	owner: { name: 'Olive Owner' },
	members: 4,
	seatLimit: 8,
	seatsReserved: 6,
	billing: {},
	...overrides,
} );

const renderDrawer = data => render( <SubscriptionDetailsDrawer group={ data } onViewOwner={ jest.fn() } onClose={ jest.fn() } /> );

describe( 'SubscriptionDetailsDrawer invitations', () => {
	it( 'shows the pending invitations under the seats', () => {
		renderDrawer( group() );
		expect( screen.getByText( 'Invitations' ) ).toBeInTheDocument();
		expect( screen.getByText( '2 invitations pending' ) ).toBeInTheDocument();
	} );

	it( 'shows the singular count', () => {
		renderDrawer( group( { members: 5 } ) );
		expect( screen.getByText( '1 invitation pending' ) ).toBeInTheDocument();
	} );

	it( 'omits the row when none are pending', () => {
		renderDrawer( group( { seatsReserved: 4 } ) );
		expect( screen.queryByText( 'Invitations' ) ).not.toBeInTheDocument();
	} );
} );
