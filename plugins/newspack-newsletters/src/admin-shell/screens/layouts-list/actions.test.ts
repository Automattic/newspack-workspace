// Spy on the network layer; everything else (`@wordpress/data`,
// `@wordpress/notices`) stays real so jsdom can host the notice store
// the way it does in production.
jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn( () => Promise.resolve() ),
} ) );

import apiFetch from '@wordpress/api-fetch';
import { getActions, renameLayout } from './actions';

import type { LayoutItem } from './types';
import type { ListAction } from '../../types';

const mockApiFetch = jest.mocked( apiFetch );

const COLLECTION_PATH = '/wp/v2/newspack_nl_layo_cpt';

const savedRow: LayoutItem = {
	id: 42,
	is_prebuilt: false,
	title: { raw: 'My Layout', rendered: 'My Layout' },
	content: { raw: '<!-- wp:paragraph -->Hi<!-- /wp:paragraph -->', rendered: '' },
	meta: {
		font_header: 'Arial',
		font_body: 'Georgia',
		background_color: '#fff',
		text_color: '#000',
		custom_css: '',
		campaign_defaults: '',
		disable_auto_ads: false,
	},
};

const prebuiltRow: LayoutItem = {
	id: 'prebuilt-1',
	is_prebuilt: true,
	title: { raw: 'Newsletter Plain', rendered: 'Newsletter Plain' },
	content: { raw: '<!-- wp:paragraph -->Prebuilt<!-- /wp:paragraph -->', rendered: '' },
	meta: {},
};

// Narrows the `ActionModal | ActionButton` union to the `callback` variant —
// every action this test invokes directly (not via `RenderModal`) is one.
function callbackOf( action: ListAction< LayoutItem > ) {
	if ( 'callback' in action ) {
		return action.callback;
	}
	throw new Error( `Action "${ action.id }" has no callback` );
}

describe( 'layouts list actions', () => {
	const onRenameStart = jest.fn();
	const onMutated = jest.fn();
	const byId = ( id: string ): ListAction< LayoutItem > => {
		const action = getActions( { onRenameStart, onMutated } ).find( a => a.id === id );
		if ( ! action ) {
			throw new Error( `Action "${ id }" not found` );
		}
		return action;
	};

	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'exposes the expected action ids in order', () => {
		const ids = getActions( { onRenameStart, onMutated } ).map( action => action.id );
		expect( ids ).toEqual( [ 'edit', 'duplicate', 'rename', 'delete-permanently' ] );
	} );

	it( 'marks Edit as the primary action', () => {
		expect( byId( 'edit' ).isPrimary ).toBe( true );
	} );

	it( 'edit, rename and delete are eligible only for user-owned rows', () => {
		[ 'edit', 'rename', 'delete-permanently' ].forEach( id => {
			const action = byId( id );
			expect( action.isEligible?.( savedRow ) ).toBe( true );
			expect( action.isEligible?.( prebuiltRow ) ).toBe( false );
		} );
	} );

	it( 'Duplicate has no eligibility gate (prebuilts and saved both qualify)', () => {
		expect( byId( 'duplicate' ).isEligible ).toBeUndefined();
	} );

	it( 'Delete supports bulk and is destructive', () => {
		const action = byId( 'delete-permanently' );
		expect( action.supportsBulk ).toBe( true );
		expect( action.isDestructive ).toBe( true );
	} );

	it( 'Rename invokes onRenameStart with the row', () => {
		callbackOf( byId( 'rename' ) )( [ savedRow ], { registry: {} } );
		expect( onRenameStart ).toHaveBeenCalledWith( savedRow );
	} );

	describe( 'Duplicate', () => {
		it( 'fetches the saved row via context=edit and POSTs a Copy payload', async () => {
			mockApiFetch
				.mockResolvedValueOnce( { ...savedRow } ) // GET
				.mockResolvedValueOnce( { id: 99 } ); // POST

			await callbackOf( byId( 'duplicate' ) )( [ savedRow ], { registry: {} } );

			expect( mockApiFetch ).toHaveBeenNthCalledWith( 1, {
				path: `${ COLLECTION_PATH }/${ savedRow.id }?context=edit`,
			} );
			const postCall = mockApiFetch.mock.calls[ 1 ][ 0 ];
			expect( postCall.path ).toBe( COLLECTION_PATH );
			expect( postCall.method ).toBe( 'POST' );
			expect( postCall.data.title ).toBe( 'Copy of My Layout' );
			expect( postCall.data.content ).toBe( savedRow.content?.raw );
			expect( postCall.data.meta.font_header ).toBe( 'Arial' );
			expect( onMutated ).toHaveBeenCalled();
		} );

		it( 'does not bump mutationKey when the API rejects', async () => {
			mockApiFetch.mockRejectedValueOnce( new Error( 'boom' ) );

			await callbackOf( byId( 'duplicate' ) )( [ savedRow ], { registry: {} } );

			expect( onMutated ).not.toHaveBeenCalled();
		} );

		it( 'falls back to "Copy of Untitled" when the source has an empty title', async () => {
			const untitled: LayoutItem = {
				...prebuiltRow,
				title: { raw: '   ', rendered: '' },
				content: { raw: '<!-- wp:paragraph -->Hi<!-- /wp:paragraph -->', rendered: '' },
			};
			mockApiFetch.mockResolvedValueOnce( { id: 101 } );

			await callbackOf( byId( 'duplicate' ) )( [ untitled ], { registry: {} } );

			expect( mockApiFetch.mock.calls[ 0 ][ 0 ].data.title ).toBe( 'Copy of Untitled' );
		} );

		it( 'duplicates a prebuilt from the in-memory item as a draft, skipping the GET', async () => {
			const prebuiltWithContent: LayoutItem = {
				...prebuiltRow,
				content: { raw: '<!-- wp:paragraph -->Prebuilt source<!-- /wp:paragraph -->', rendered: '' },
			};
			mockApiFetch.mockResolvedValueOnce( { id: 100 } );

			await callbackOf( byId( 'duplicate' ) )( [ prebuiltWithContent ], { registry: {} } );

			expect( mockApiFetch ).toHaveBeenCalledTimes( 1 );
			const postCall = mockApiFetch.mock.calls[ 0 ][ 0 ];
			expect( postCall.path ).toBe( COLLECTION_PATH );
			expect( postCall.method ).toBe( 'POST' );
			expect( postCall.data.status ).toBe( 'draft' );
			expect( postCall.data.title ).toBe( 'Copy of Newsletter Plain' );
			expect( postCall.data.content ).toBe( prebuiltWithContent.content?.raw );
			expect( postCall.data.meta ).toBeUndefined();
			expect( onMutated ).toHaveBeenCalled();
		} );
	} );

	describe( 'renameLayout', () => {
		it( 'POSTs the trimmed title against the collection item', () => {
			mockApiFetch.mockResolvedValueOnce( { id: 42 } );

			renameLayout( 42, 'Renamed' );

			expect( mockApiFetch ).toHaveBeenCalledWith( {
				path: `${ COLLECTION_PATH }/42`,
				method: 'POST',
				data: { title: 'Renamed' },
			} );
		} );

		it( 'rejects on API failure so the caller can leave the inline UI in place', async () => {
			mockApiFetch.mockRejectedValueOnce( new Error( 'nope' ) );

			await expect( renameLayout( 42, 'X' ) ).rejects.toThrow( 'nope' );
		} );
	} );
} );
