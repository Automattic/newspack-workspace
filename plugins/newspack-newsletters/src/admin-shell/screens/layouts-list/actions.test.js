// Spy on the network layer; everything else (`@wordpress/data`,
// `@wordpress/notices`) stays real so jsdom can host the notice store
// the way it does in production.
jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn( () => Promise.resolve() ),
} ) );

import apiFetch from '@wordpress/api-fetch';
import { getActions, renameLayout } from './actions';

const COLLECTION_PATH = '/wp/v2/newspack_nl_layo_cpt';

const savedRow = {
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

const prebuiltRow = {
	id: 'prebuilt-1',
	is_prebuilt: true,
	title: { raw: 'Newsletter Plain', rendered: 'Newsletter Plain' },
	content: { raw: '<!-- wp:paragraph -->Prebuilt<!-- /wp:paragraph -->', rendered: '' },
	meta: {},
};

describe( 'layouts list actions', () => {
	const onRenameStart = jest.fn();
	const onMutated = jest.fn();
	const byId = id => getActions( { onRenameStart, onMutated } ).find( action => action.id === id );

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
			expect( action.isEligible( savedRow ) ).toBe( true );
			expect( action.isEligible( prebuiltRow ) ).toBe( false );
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
		byId( 'rename' ).callback( [ savedRow ] );
		expect( onRenameStart ).toHaveBeenCalledWith( savedRow );
	} );

	describe( 'Duplicate', () => {
		it( 'fetches the saved row via context=edit and POSTs a Copy payload', async () => {
			apiFetch
				.mockResolvedValueOnce( { ...savedRow } ) // GET
				.mockResolvedValueOnce( { id: 99 } ); // POST

			await byId( 'duplicate' ).callback( [ savedRow ] );

			expect( apiFetch ).toHaveBeenNthCalledWith( 1, {
				path: `${ COLLECTION_PATH }/${ savedRow.id }?context=edit`,
			} );
			const postCall = apiFetch.mock.calls[ 1 ][ 0 ];
			expect( postCall.path ).toBe( COLLECTION_PATH );
			expect( postCall.method ).toBe( 'POST' );
			expect( postCall.data.title ).toBe( 'Copy of My Layout' );
			expect( postCall.data.content ).toBe( savedRow.content.raw );
			expect( postCall.data.meta.font_header ).toBe( 'Arial' );
			expect( onMutated ).toHaveBeenCalled();
		} );

		it( 'does not bump mutationKey when the API rejects', async () => {
			apiFetch.mockRejectedValueOnce( new Error( 'boom' ) );

			await byId( 'duplicate' ).callback( [ savedRow ] );

			expect( onMutated ).not.toHaveBeenCalled();
		} );

		it( 'falls back to "Copy of Untitled" when the source has an empty title', async () => {
			const untitled = {
				...prebuiltRow,
				title: { raw: '   ', rendered: '' },
				content: { raw: '<!-- wp:paragraph -->Hi<!-- /wp:paragraph -->', rendered: '' },
			};
			apiFetch.mockResolvedValueOnce( { id: 101 } );

			await byId( 'duplicate' ).callback( [ untitled ] );

			expect( apiFetch.mock.calls[ 0 ][ 0 ].data.title ).toBe( 'Copy of Untitled' );
		} );

		it( 'duplicates a prebuilt from the in-memory item as a draft, skipping the GET', async () => {
			const prebuiltWithContent = {
				...prebuiltRow,
				content: { raw: '<!-- wp:paragraph -->Prebuilt source<!-- /wp:paragraph -->', rendered: '' },
			};
			apiFetch.mockResolvedValueOnce( { id: 100 } );

			await byId( 'duplicate' ).callback( [ prebuiltWithContent ] );

			expect( apiFetch ).toHaveBeenCalledTimes( 1 );
			const postCall = apiFetch.mock.calls[ 0 ][ 0 ];
			expect( postCall.path ).toBe( COLLECTION_PATH );
			expect( postCall.method ).toBe( 'POST' );
			expect( postCall.data.status ).toBe( 'draft' );
			expect( postCall.data.title ).toBe( 'Copy of Newsletter Plain' );
			expect( postCall.data.content ).toBe( prebuiltWithContent.content.raw );
			expect( postCall.data.meta ).toBeUndefined();
			expect( onMutated ).toHaveBeenCalled();
		} );
	} );

	describe( 'renameLayout', () => {
		it( 'POSTs the trimmed title against the collection item', () => {
			apiFetch.mockResolvedValueOnce( { id: 42 } );

			renameLayout( 42, 'Renamed' );

			expect( apiFetch ).toHaveBeenCalledWith( {
				path: `${ COLLECTION_PATH }/42`,
				method: 'POST',
				data: { title: 'Renamed' },
			} );
		} );

		it( 'rejects on API failure so the caller can leave the inline UI in place', async () => {
			apiFetch.mockRejectedValueOnce( new Error( 'nope' ) );

			await expect( renameLayout( 42, 'X' ) ).rejects.toThrow( 'nope' );
		} );
	} );
} );
