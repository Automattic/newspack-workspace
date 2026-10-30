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

/** Paste addresses into the textarea. */
const pasteEmails = async value => {
	await act( async () => {
		fireEvent.change( screen.getByLabelText( 'Email addresses' ), { target: { value } } );
	} );
};

const click = async name => {
	await act( async () => {
		fireEvent.click( screen.getByRole( 'button', { name } ) );
	} );
};

const renderInvite = ( inviteBulk, onDone ) =>
	act( async () => {
		render( createElement( InviteMemberFlow, { group, actions: { inviteBulk }, onClose: () => {}, onDone } ) );
	} );

const preview = ( toInvite, extra = {} ) => ( {
	to_invite: toInvite,
	skipped: { duplicate: 0, member: 0, invited: 0 },
	summary: [ `${ toInvite.length } new addresses to invite.` ],
	over_limit: null,
	...extra,
} );

describe( 'sending invitations', () => {
	it( 'sends nothing until the reviewed list is confirmed', async () => {
		const onDone = jest.fn();
		const inviteBulk = jest
			.fn()
			.mockResolvedValueOnce( preview( [ 'a@example.test', 'b@example.test' ] ) )
			.mockResolvedValueOnce( { invited: [ 'a@example.test', 'b@example.test' ], not_sent: [], failed: [], summary: [] } );
		await renderInvite( inviteBulk, onDone );

		await pasteEmails( 'a@example.test, b@example.test' );
		await click( 'Review' );

		expect( inviteBulk ).toHaveBeenLastCalledWith( { emails: 'a@example.test, b@example.test', csv: '' } );
		expect( screen.getByText( '2 new addresses to invite.' ) ).toBeTruthy();
		expect( onDone ).not.toHaveBeenCalled();

		await click( 'Send 2 invites' );

		expect( inviteBulk ).toHaveBeenLastCalledWith( { emails: 'a@example.test, b@example.test', csv: '', confirm: true } );
		expect( onDone ).toHaveBeenCalledWith( '2 invitations sent.' );
	} );

	it( 'offers no way to send a list the server refused for want of seats', async () => {
		const inviteBulk = jest
			.fn()
			.mockResolvedValue( preview( [ 'a@example.test', 'b@example.test' ], { over_limit: "There aren't enough free seats for this list." } ) );
		await renderInvite( inviteBulk, jest.fn() );

		await pasteEmails( 'a@example.test b@example.test' );
		await click( 'Review' );

		expect( document.querySelector( '.components-notice' ).textContent ).toMatch( /enough free seats/ );
		expect( screen.getByRole( 'button', { name: 'Send 2 invites' } ).disabled ).toBe( true );
	} );

	it( 'reports an invitation the server stored but could not email, and refreshes for it', async () => {
		const onDone = jest.fn();
		const inviteBulk = jest
			.fn()
			.mockResolvedValueOnce( preview( [ 'nobody@example.test' ] ) )
			.mockResolvedValueOnce( {
				invited: [],
				not_sent: [ 'nobody@example.test' ],
				failed: [],
				summary: [ 'These invitations were created, but the email could not be sent.' ],
			} );
		await renderInvite( inviteBulk, onDone );

		await pasteEmails( 'nobody@example.test' );
		await click( 'Review' );
		await click( 'Send 1 invite' );

		// The row exists and holds a seat, so silence here would let the admin fill
		// the group with invitations nobody received. The report says the seat is held
		// until they cancel it, which needs the invitation in the table behind the
		// modal — hence a refresh with no snackbar, rather than no refresh at all.
		expect( document.querySelector( '.components-notice' ).textContent ).toMatch( /could not be sent/ );
		expect( onDone ).toHaveBeenCalledWith( '', { keepOpen: true } );
	} );

	it( 'keeps the review and disables Send when the server refuses the confirmed list', async () => {
		const onDone = jest.fn();
		const inviteBulk = jest
			.fn()
			.mockResolvedValueOnce( preview( [ 'a@example.test', 'b@example.test' ] ) )
			.mockRejectedValueOnce( { code: 'over_limit', message: 'Not enough free seats now.', data: { status: 409 } } );
		await renderInvite( inviteBulk, onDone );

		await pasteEmails( 'a@example.test b@example.test' );
		await click( 'Review' );
		await click( 'Send 2 invites' );

		// A refusal created nothing, so nothing refreshes and the same list can't be resent.
		expect( document.querySelector( '.components-notice' ).textContent ).toMatch( /Not enough free seats now/ );
		expect( screen.getByRole( 'button', { name: 'Send 2 invites' } ).disabled ).toBe( true );
		expect( onDone ).not.toHaveBeenCalled();
	} );

	it( 'treats a send that failed without a reason as possibly partial', async () => {
		const onDone = jest.fn();
		const inviteBulk = jest
			.fn()
			.mockResolvedValueOnce( preview( [ 'a@example.test' ] ) )
			.mockRejectedValueOnce( { code: 'invalid_json', message: 'The response is not a valid JSON response.' } );
		await renderInvite( inviteBulk, onDone );

		await pasteEmails( 'a@example.test' );
		await click( 'Review' );
		await click( 'Send 1 invite' );

		// The request may have stopped partway, so the table refreshes and Send is gone.
		expect( document.querySelector( '.components-notice' ).textContent ).toMatch( /may have been sent/ );
		expect( screen.queryByRole( 'button', { name: 'Send 1 invite' } ) ).toBeNull();
		expect( onDone ).toHaveBeenCalledWith( '', { keepOpen: true } );
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
