// Spy on the network layer; everything else (`@wordpress/data`,
// `@wordpress/notices`) stays real so jsdom can host the notice store
// the way it does in production.
jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn( () => Promise.resolve() ),
} ) );

import apiFetch from '@wordpress/api-fetch';
import { getActions } from './actions';

import type { ListAction, PostItem } from '../../types';

const mockApiFetch = jest.mocked( apiFetch );

// `Action<Item>` from `@wordpress/dataviews` is a discriminated union:
// `callback` only exists on the plain-button variant, `RenderModal` /
// `modalSize` only on the modal variant. These helpers narrow via `in`
// checks (not casts) so the tests can call the member each action
// actually has while staying honest about the union.
function callbackOf( action: ListAction ) {
	if ( ! ( 'callback' in action ) ) {
		throw new Error( `Action "${ action.id }" has no callback (it is a RenderModal action).` );
	}
	return action.callback;
}

function modalActionOf( action: ListAction ) {
	if ( ! ( 'RenderModal' in action ) ) {
		throw new Error( `Action "${ action.id }" has no RenderModal (it is a callback action).` );
	}
	return action;
}

// DataViews always calls `callback` with a `{ registry, onActionPerformed }`
// context; none of this screen's callbacks read it, so a stub satisfies the
// (non-optional) parameter without changing what the callback actually does.
const NOOP_CONTEXT = { registry: undefined };

describe( 'ads list actions', () => {
	const refresh = jest.fn();
	const openQuickEdit = jest.fn();

	const draftRow: PostItem = { id: 1, status: 'draft' };
	const publishedRow: PostItem = { id: 2, status: 'publish' };
	const trashedRow: PostItem = { id: 3, status: 'trash' };

	const byId = ( id: string ): ListAction => {
		const action = getActions( { refresh, openQuickEdit } ).find( a => a.id === id );
		if ( ! action ) {
			throw new Error( `No action with id "${ id }"` );
		}
		return action;
	};

	it( 'exposes the expected action ids in order', () => {
		const ids = getActions( { refresh, openQuickEdit } ).map( action => action.id );
		expect( ids ).toEqual( [ 'quick-edit', 'trash', 'edit', 'rename', 'restore', 'delete-permanently' ] );
	} );

	it( 'marks Quick Edit and Trash as primary actions', () => {
		expect( byId( 'quick-edit' ).isPrimary ).toBe( true );
		expect( byId( 'trash' ).isPrimary ).toBe( true );
	} );

	it( 'does not mark Edit or Rename as primary actions', () => {
		expect( byId( 'edit' ).isPrimary ).toBeFalsy();
		expect( byId( 'rename' ).isPrimary ).toBeFalsy();
	} );

	it( 'Quick Edit is eligible on non-trashed rows only', () => {
		const action = byId( 'quick-edit' );
		expect( action.isEligible?.( draftRow ) ).toBe( true );
		expect( action.isEligible?.( publishedRow ) ).toBe( true );
		expect( action.isEligible?.( trashedRow ) ).toBe( false );
	} );

	it( 'Quick Edit delegates to openQuickEdit with the row item', () => {
		openQuickEdit.mockClear();
		callbackOf( byId( 'quick-edit' ) )( [ draftRow ], NOOP_CONTEXT );
		expect( openQuickEdit ).toHaveBeenCalledWith( draftRow );
	} );

	it( 'Rename opens a medium modal and is eligible on non-trashed rows only', () => {
		const action = modalActionOf( byId( 'rename' ) );
		expect( action.modalSize ).toBe( 'medium' );
		expect( typeof action.RenderModal ).toBe( 'function' );
		expect( action.isEligible?.( draftRow ) ).toBe( true );
		expect( action.isEligible?.( trashedRow ) ).toBe( false );
	} );

	it( 'Trash is eligible only when the row is not already trashed', () => {
		const action = byId( 'trash' );
		expect( action.isEligible?.( draftRow ) ).toBe( true );
		expect( action.isEligible?.( publishedRow ) ).toBe( true );
		expect( action.isEligible?.( trashedRow ) ).toBe( false );
	} );

	it( 'Trash supports bulk and is destructive', () => {
		const action = byId( 'trash' );
		expect( action.supportsBulk ).toBe( true );
		expect( action.isDestructive ).toBe( true );
	} );

	it( 'Restore is eligible only on trashed rows', () => {
		const action = byId( 'restore' );
		expect( action.isEligible?.( trashedRow ) ).toBe( true );
		expect( action.isEligible?.( draftRow ) ).toBe( false );
		expect( action.isEligible?.( publishedRow ) ).toBe( false );
	} );

	it( 'Restore supports bulk', () => {
		expect( byId( 'restore' ).supportsBulk ).toBe( true );
	} );

	it( 'Delete permanently is eligible only on trashed rows and is destructive', () => {
		const action = byId( 'delete-permanently' );
		expect( action.isEligible?.( trashedRow ) ).toBe( true );
		expect( action.isEligible?.( draftRow ) ).toBe( false );
		expect( action.isDestructive ).toBe( true );
		expect( action.supportsBulk ).toBe( true );
	} );

	describe( 'bulk callbacks filter to eligible rows before mutating', () => {
		// DataViews only filters by `isEligible` automatically for **modal**
		// bulk actions; plain callback bulk actions get the full selected
		// set. Restore is the only such callback on this screen — each
		// callback must re-apply its predicate so a mixed selection
		// doesn't accidentally undo state on a draft or published row.

		beforeEach( () => {
			mockApiFetch.mockClear();
			refresh.mockClear();
		} );

		it( 'restore only POSTs trashed rows out of a mixed selection', async () => {
			const action = byId( 'restore' );
			const selection = [ trashedRow, draftRow, publishedRow ];

			await callbackOf( action )( selection, NOOP_CONTEXT );

			expect( mockApiFetch ).toHaveBeenCalledTimes( 1 );
			const [ call ] = mockApiFetch.mock.calls[ 0 ];
			expect( call.path ).toContain( `/${ trashedRow.id }` );
			expect( call.method ).toBe( 'POST' );
			expect( call.data ).toEqual( { status: 'draft' } );
		} );

		it( 'no-eligible selection does not call apiFetch and does not refresh', async () => {
			// Restore on a selection that has no trashed rows.
			await callbackOf( byId( 'restore' ) )( [ draftRow, publishedRow ], NOOP_CONTEXT );
			expect( mockApiFetch ).not.toHaveBeenCalled();
			expect( refresh ).not.toHaveBeenCalled();
		} );
	} );

	it( 'never exposes a publish or status-changing bulk action — ads list has no equivalent of newsletters make-public toggles', () => {
		// Ads activation is date-driven (start_date / expiry_date), not a
		// per-row toggle. This guard prevents future drift toward
		// newsletters-style status mutations on the ads screen.
		const ids = getActions( { refresh, openQuickEdit } ).map( action => action.id );
		expect( ids ).not.toEqual( expect.arrayContaining( [ 'publish', 'make-public', 'make-non-public', 'transition-status' ] ) );
	} );
} );
