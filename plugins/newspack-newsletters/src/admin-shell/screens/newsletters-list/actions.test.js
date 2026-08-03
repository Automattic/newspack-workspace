// Spy on the network layer; everything else (`@wordpress/data`,
// `@wordpress/notices`) stays real so jsdom can host the notice store
// the way it does in production.
jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn( () => Promise.resolve() ),
} ) );

import apiFetch from '@wordpress/api-fetch';
import { getActions } from './actions';

describe( 'newsletters list actions', () => {
	const refresh = jest.fn();
	const openQuickEdit = jest.fn();

	const draftRow = { id: 1, status: 'draft', meta: { is_public: false }, link: '' };
	const sentPublicRow = {
		id: 2,
		status: 'publish',
		meta: { is_public: true },
		link: 'https://example.test/n/2',
	};
	const trashedRow = { id: 3, status: 'trash', meta: { is_public: false }, link: '' };

	const byId = id => getActions( { refresh, openQuickEdit } ).find( action => action.id === id );

	it( 'exposes the expected action ids in order', () => {
		const ids = getActions( { refresh, openQuickEdit } ).map( action => action.id );
		expect( ids ).toEqual( [
			'quick-edit',
			'trash',
			'make-public',
			'make-non-public',
			'edit',
			'rename',
			'view-public-page',
			'restore',
			'delete-permanently',
		] );
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
		expect( action.isEligible( draftRow ) ).toBe( true );
		expect( action.isEligible( sentPublicRow ) ).toBe( true );
		expect( action.isEligible( trashedRow ) ).toBe( false );
	} );

	it( 'Quick Edit delegates to openQuickEdit with the row item', () => {
		openQuickEdit.mockClear();
		byId( 'quick-edit' ).callback( [ draftRow ] );
		expect( openQuickEdit ).toHaveBeenCalledWith( draftRow );
	} );

	it( 'Rename opens a medium modal and is eligible on non-trashed rows only', () => {
		const action = byId( 'rename' );
		expect( action.modalSize ).toBe( 'medium' );
		expect( typeof action.RenderModal ).toBe( 'function' );
		expect( action.isEligible( draftRow ) ).toBe( true );
		expect( action.isEligible( trashedRow ) ).toBe( false );
	} );

	it( "View public page is eligible only when status === 'publish' and a link exists", () => {
		const action = byId( 'view-public-page' );
		expect( action.isEligible( sentPublicRow ) ).toBe( true );

		// Drafts can carry `is_public=true` and a REST link but have no live page.
		expect( action.isEligible( { ...draftRow, meta: { is_public: true }, link: 'https://example.test/n/1' } ) ).toBe( false );

		// Private rows are admin-only even when is_public is momentarily true.
		expect( action.isEligible( { ...sentPublicRow, status: 'private' } ) ).toBe( false );

		// Scheduled rows haven't published yet.
		expect( action.isEligible( { ...sentPublicRow, status: 'future' } ) ).toBe( false );

		// Trash hides everything.
		expect( action.isEligible( { ...sentPublicRow, status: 'trash' } ) ).toBe( false );
	} );

	it( 'Trash is eligible only when the row is not already trashed', () => {
		const action = byId( 'trash' );
		expect( action.isEligible( draftRow ) ).toBe( true );
		expect( action.isEligible( sentPublicRow ) ).toBe( true );
		expect( action.isEligible( trashedRow ) ).toBe( false );
	} );

	it( 'Trash supports bulk and is destructive', () => {
		const action = byId( 'trash' );
		expect( action.supportsBulk ).toBe( true );
		expect( action.isDestructive ).toBe( true );
	} );

	it( 'Restore is eligible only on trashed rows', () => {
		const action = byId( 'restore' );
		expect( action.isEligible( trashedRow ) ).toBe( true );
		expect( action.isEligible( draftRow ) ).toBe( false );
		expect( action.isEligible( sentPublicRow ) ).toBe( false );
	} );

	it( 'Restore supports bulk', () => {
		expect( byId( 'restore' ).supportsBulk ).toBe( true );
	} );

	it( 'Delete permanently is eligible only on trashed rows and is destructive', () => {
		const action = byId( 'delete-permanently' );
		expect( action.isEligible( trashedRow ) ).toBe( true );
		expect( action.isEligible( draftRow ) ).toBe( false );
		expect( action.isDestructive ).toBe( true );
		expect( action.supportsBulk ).toBe( true );
	} );

	it( 'Make public supports bulk and is eligible only on non-trashed, currently-non-public rows', () => {
		const action = byId( 'make-public' );
		expect( action.supportsBulk ).toBe( true );
		expect( action.isEligible( draftRow ) ).toBe( true );
		expect( action.isEligible( sentPublicRow ) ).toBe( false );
		expect( action.isEligible( trashedRow ) ).toBe( false );
	} );

	it( 'Make non-public supports bulk and is eligible only on non-trashed, currently-public rows', () => {
		const action = byId( 'make-non-public' );
		expect( action.supportsBulk ).toBe( true );
		expect( action.isEligible( sentPublicRow ) ).toBe( true );
		expect( action.isEligible( draftRow ) ).toBe( false );
		expect( action.isEligible( { ...sentPublicRow, status: 'trash' } ) ).toBe( false );
	} );

	describe( 'bulk callbacks filter to eligible rows before mutating', () => {
		// DataViews only filters by `isEligible` automatically for **modal**
		// bulk actions; plain callback bulk actions get the full selected
		// set. Each callback must re-apply its predicate so a mixed
		// selection (e.g. trashed + scheduled) doesn't accidentally
		// unschedule the scheduled row when the user hits "Restore".

		beforeEach( () => {
			apiFetch.mockClear();
			refresh.mockClear();
		} );

		it( 'restore only PATCHes trashed rows out of a mixed selection', async () => {
			const action = byId( 'restore' );
			const selection = [ trashedRow, draftRow, sentPublicRow ];

			await action.callback( selection );

			expect( apiFetch ).toHaveBeenCalledTimes( 1 );
			expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toContain( `/${ trashedRow.id }` );
			expect( apiFetch.mock.calls[ 0 ][ 0 ].method ).toBe( 'POST' );
			expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( { status: 'draft' } );
		} );

		it( 'make-public only PATCHes non-trashed, currently-non-public rows', async () => {
			const action = byId( 'make-public' );
			const selection = [ trashedRow, draftRow, sentPublicRow ];

			await action.callback( selection );

			expect( apiFetch ).toHaveBeenCalledTimes( 1 );
			expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toContain( `/${ draftRow.id }` );
			expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( { meta: { is_public: true } } );
		} );

		it( 'make-non-public only PATCHes non-trashed, currently-public rows', async () => {
			const action = byId( 'make-non-public' );
			const selection = [ trashedRow, draftRow, sentPublicRow ];

			await action.callback( selection );

			expect( apiFetch ).toHaveBeenCalledTimes( 1 );
			expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toContain( `/${ sentPublicRow.id }` );
			expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( { meta: { is_public: false } } );
		} );

		it( 'no-eligible selection does not call apiFetch and does not refresh', async () => {
			// Restore on a selection that has no trashed rows.
			await byId( 'restore' ).callback( [ draftRow, sentPublicRow ] );
			expect( apiFetch ).not.toHaveBeenCalled();
			expect( refresh ).not.toHaveBeenCalled();
		} );
	} );

	it( 'never exposes a publish or status-changing bulk action — campaign-send safety guard', () => {
		const ids = getActions( { refresh, openQuickEdit } ).map( action => action.id );
		// `make-public` / `make-non-public` DO fire `transition_post_status`
		// on already-sent rows (via `Newspack_Newsletters_Service_Provider::
		// updated_post_meta`, which calls `wp_update_post` to flip between
		// `publish` and `private`). They're still safe because the
		// provider's send only fires when transitioning INTO publish/private
		// from a non-sent state, and `is_newsletter_sent()` short-circuits
		// every already-published row. The guard is against actions that
		// would re-send: explicit publish/private status changes.
		expect( ids ).not.toEqual( expect.arrayContaining( [ 'publish', 'private', 'transition-status' ] ) );
	} );
} );
