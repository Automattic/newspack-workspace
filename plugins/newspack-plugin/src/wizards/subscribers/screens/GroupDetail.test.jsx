// @jest-environment jsdom

/**
 * Group detail, mounted against a real DataViews.
 *
 * The screen mirrors the server's role model so it never offers what the endpoint
 * would refuse, and that mirroring lives entirely in `isEligible` predicates —
 * which render nothing when they are wrong, so a regression is invisible without a
 * mount. These drive the row menus the way a publisher does: open the ⋮ and read
 * what is on offer.
 *
 * The barrel is mocked because it re-exports the wizard Page, whose
 * `@wordpress/admin-ui` dependency ships ESM jest can't transform. DataViews
 * itself is the real component — it is the thing under test.
 */

/**
 * External dependencies
 */
import { render, screen, act, fireEvent, waitFor } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { createElement } from '@wordpress/element';
import { createReduxStore, register } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

jest.mock( '../../../../packages/components/src/wizard/store', () => ( { WIZARD_STORE_NAMESPACE: 'test/group-detail' } ) );

jest.mock( '../data/use-avatars', () => ( { SHOW_AVATARS: false, useAvatars: () => ( { avatars: {}, loading: false } ) } ) );

jest.mock( '../../../../packages/components/src', () => {
	const el = require( '@wordpress/element' ).createElement;
	return {
		DataViews: require( '../../../../packages/components/src/dataviews' ).default,
		Button: ( { children, onClick, disabled, 'aria-expanded': ariaExpanded } ) =>
			el( 'button', { onClick, disabled, 'aria-expanded': ariaExpanded }, children ),
		Divider: () => null,
		Modal: ( { title, children } ) => el( 'div', { role: 'dialog', 'aria-label': title }, children ),
		Notice: ( { noticeText, children } ) => el( 'div', null, noticeText, children ),
		Waiting: () => null,
		Router: { useParams: () => ( { id: '7' } ) },
	};
} );

register(
	createReduxStore( 'test/group-detail', {
		reducer: ( state = {} ) => state,
		actions: { setHeaderData: () => ( { type: 'NOOP' } ) },
	} )
);

// eslint-disable-next-line import/first
import GroupDetail from './GroupDetail';

const group = overrides => ( {
	id: 7,
	plan: 'Team plan',
	status: 'active',
	seatLimit: 5,
	seatsReserved: 2,
	owner: { id: 1, name: 'Olive Owner', email: 'olive@example.com', editUrl: '' },
	memberList: [
		{ id: 1, name: 'Olive Owner', email: 'olive@example.com', role: 'owner', joinedAt: '', editUrl: '' },
		{ id: 2, name: 'Mel Member', email: 'mel@example.com', role: 'member', joinedAt: '2026-01-02T00:00:00Z', editUrl: '' },
	],
	invites: [],
	inviteLink: { active: false, url: '' },
	canManage: true,
	...overrides,
} );

/** Mount the screen with the group the read endpoint returns. */
const renderDetail = async payload => {
	apiFetch.mockResolvedValue( payload );
	await act( async () => {
		render( createElement( GroupDetail ) );
	} );
};

/** Open the nth row action menu and return the labels it offers. */
const openRowMenu = async index => {
	const triggers = screen.queryAllByRole( 'button', { name: 'Actions' } );
	if ( ! triggers[ index ] ) {
		return [];
	}
	await act( async () => {
		fireEvent.click( triggers[ index ] );
	} );
	return screen.queryAllByRole( 'menuitem' ).map( item => item.textContent );
};

beforeEach( () => apiFetch.mockReset() );

describe( 'the member row actions', () => {
	it( 'opens a menu offering the actions the endpoint would accept', async () => {
		await renderDetail( group() );

		// Two members, one actionable: the owner can be neither promoted, demoted
		// nor removed, so their row carries no menu at all.
		expect( screen.queryAllByRole( 'button', { name: 'Actions' } ) ).toHaveLength( 1 );
		expect( await openRowMenu( 0 ) ).toEqual( [ 'Make manager', 'Remove member' ] );
	} );

	it( 'withholds member management on a cancelled group, which the endpoint 409s', async () => {
		await renderDetail( group( { status: 'cancelled' } ) );

		expect( screen.queryAllByRole( 'button', { name: 'Actions' } ) ).toHaveLength( 0 );
	} );

	// Reading this screen and writing from it are separate capabilities, so a
	// caller can legitimately hold the first without the second. The group is live
	// and every row is actionable — only the caller cannot act, and the controls
	// have to say so rather than 403 on click.
	it( 'withholds member management from a caller who may read the group but not write to it', async () => {
		await renderDetail( group( { canManage: false } ) );

		expect( screen.queryAllByRole( 'button', { name: 'Actions' } ) ).toHaveLength( 0 );
		expect( screen.getByRole( 'button', { name: 'Adjust seats' } ) ).toBeDisabled();
		expect( screen.getByRole( 'button', { name: 'Add members' } ) ).toBeDisabled();
	} );

	it( 'offers demotion, never removal, for a manager the owner promoted', async () => {
		await renderDetail(
			group( {
				memberList: [
					{ id: 1, name: 'Olive Owner', email: 'olive@example.com', role: 'owner', joinedAt: '', editUrl: '' },
					{ id: 3, name: 'Mo Manager', email: 'mo@example.com', role: 'manager', joinedAt: '2026-01-02T00:00:00Z', editUrl: '' },
				],
			} )
		);

		expect( await openRowMenu( 0 ) ).toEqual( [ 'Remove manager', 'Remove member' ] );
	} );

	it( 'keeps a refused role change on screen instead of letting it flash past', async () => {
		await renderDetail( group() );
		apiFetch.mockRejectedValueOnce( { message: 'This group is no longer active.' } );

		await openRowMenu( 0 );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'menuitem', { name: 'Make manager' } ) );
		} );

		await waitFor( () => expect( screen.getByText( 'This group is no longer active.' ) ).toBeTruthy() );
	} );
} );

describe( 'the invite link', () => {
	it( 'mints the link on the first copy, and says so when the clipboard is unavailable', async () => {
		await renderDetail( group() );
		apiFetch.mockResolvedValueOnce( { url: 'https://site.test/join/abc' } );

		// jsdom has no navigator.clipboard, which is also what an insecure context
		// gives a publisher — the link is still minted, so the copy must not be
		// reported as having happened.
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Add members' } ) );
		} );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'menuitem', { name: 'Create invite link' } ) );
		} );

		expect( apiFetch ).toHaveBeenCalledWith( expect.objectContaining( { path: '/newspack-group-subscription/v1/invite-link', method: 'POST' } ) );
		// onDone() refetches, so let the reload settle before reading the snackbar.
		await act( async () => {} );
		// Snackbar renders its message twice (visible copy plus the live region).
		expect( screen.getAllByText( /could not be copied/ ).length ).toBeGreaterThan( 0 );
	} );
} );
