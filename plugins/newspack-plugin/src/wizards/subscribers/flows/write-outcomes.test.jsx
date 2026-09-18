// @jest-environment jsdom

/**
 * What the group write flows report back.
 *
 * Each of these writes can succeed at the HTTP level while applying nothing the
 * publisher asked for: `generate_invite()` stores the invitation row before it
 * dispatches the email and reports delivery separately, and `update_members()`
 * skips IDs it cannot act on and still answers 200. Both are invisible from the
 * promise alone, so these pin the flows to the response body rather than to the
 * absence of a rejection.
 *
 * The barrel is mocked because it re-exports the wizard Page, whose
 * `@wordpress/admin-ui` dependency ships ESM jest can't transform.
 */

/**
 * External dependencies
 */
import { render, screen, act, fireEvent } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { createElement } from '@wordpress/element';

jest.mock( '../../../../packages/components/src', () => {
	const el = require( '@wordpress/element' ).createElement;
	return {
		Button: ( { children, onClick, disabled } ) => el( 'button', { onClick, disabled }, children ),
		Modal: ( { title, children } ) => el( 'div', { role: 'dialog', 'aria-label': title }, children ),
		Notice: ( { noticeText } ) => el( 'div', { role: 'alert' }, noticeText ),
	};
} );

// eslint-disable-next-line import/first
import InviteMemberFlow from './InviteMemberFlow';
// eslint-disable-next-line import/first
import RemoveMemberFlow from './RemoveMemberFlow';

const group = {
	id: 7,
	status: 'active',
	seatLimit: 5,
	seatsReserved: 1,
	memberList: [],
	invites: [],
	canManage: true,
};

/** Type an address into the token field and commit it with Enter. */
const enterEmail = async ( label, email ) => {
	const input = screen.getByLabelText( label );
	await act( async () => {
		fireEvent.change( input, { target: { value: email } } );
		fireEvent.keyDown( input, { key: 'Enter', keyCode: 13 } );
	} );
};

const click = async name => {
	await act( async () => {
		fireEvent.click( screen.getByRole( 'button', { name } ) );
	} );
};

describe( 'sending invitations', () => {
	it( 'reports an invitation the server stored but could not email', async () => {
		const onDone = jest.fn();
		const invite = jest.fn().mockResolvedValue( { email: 'nobody@example.com', email_sent: false } );
		await act( async () => {
			render( createElement( InviteMemberFlow, { group, actions: { invite }, onClose: () => {}, onDone } ) );
		} );

		await enterEmail( 'Invite by email', 'nobody@example.com' );
		await click( 'Send invites' );

		// The row exists and holds a seat, so silence here would let the admin fill
		// the group with invitations nobody received.
		expect( screen.getByRole( 'alert' ).textContent ).toMatch( /could not be sent/ );
		expect( onDone ).not.toHaveBeenCalled();
	} );

	it( 'counts an invitation the server both stored and emailed', async () => {
		const onDone = jest.fn();
		const invite = jest.fn().mockResolvedValue( { email: 'reader@example.com', email_sent: true } );
		await act( async () => {
			render( createElement( InviteMemberFlow, { group, actions: { invite }, onClose: () => {}, onDone } ) );
		} );

		await enterEmail( 'Invite by email', 'reader@example.com' );
		await click( 'Send invites' );

		expect( onDone ).toHaveBeenCalledWith( '1 invitation sent.' );
	} );
} );

describe( 'removing members', () => {
	it( 'treats a removal the server applied to nobody as a failure', async () => {
		const onDone = jest.fn();
		const removeMembers = jest.fn().mockResolvedValue( { members_removed: {} } );
		await act( async () => {
			render(
				createElement( RemoveMemberFlow, {
					members: [ { id: 2, name: 'Mel Member' } ],
					actions: { removeMembers },
					onClose: () => {},
					onDone,
				} )
			);
		} );

		await click( 'Remove member' );

		// Reporting success here would put "0 members removed" in the snackbar.
		expect( screen.getByRole( 'alert' ).textContent ).toMatch( /Nobody was removed/ );
		expect( onDone ).not.toHaveBeenCalled();
	} );
} );
