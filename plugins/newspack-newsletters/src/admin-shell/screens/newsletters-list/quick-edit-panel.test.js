// The list query only embeds terms when a taxonomy column is visible, so
// the panel has to resolve the post's own `categories`/`tags` ID arrays —
// and must never send a taxonomy the user didn't touch.
jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

jest.mock( '../../components/quick-edit-panel', () => ( {
	__esModule: true,
	default: ( { children, isDirty, isBusy, onSave } ) => (
		<div>
			<div data-testid="panel-dirty">{ String( isDirty ) }</div>
			{ children }
			<button type="button" data-testid="panel-save" onClick={ onSave } disabled={ isBusy || ! isDirty }>
				Save
			</button>
		</div>
	),
} ) );

import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import NewslettersQuickEditPanel from './quick-edit-panel';

const CATEGORIES = [
	{ id: 5, name: 'News' },
	{ id: 6, name: 'Sport' },
];
const TAGS = [ { id: 7, name: 'weekly' } ];

const makeTermsResponse = terms => ( {
	headers: { get: name => ( name === 'X-WP-TotalPages' ? '1' : String( terms.length ) ) },
	json: async () => terms,
} );

const postCall = () => apiFetch.mock.calls.find( call => call[ 0 ]?.method === 'POST' )?.[ 0 ];

// Scoped to the rendered notices: `Notice` also announces through the
// shared `a11y-speak` live region, which survives between tests in the
// same document and would otherwise satisfy a plain text query.
const visibleNotices = () => [ ...document.querySelectorAll( '.components-notice__content' ) ].map( n => n.textContent.trim() );

const CATEGORIES_UNAVAILABLE = 'Categories could not be loaded. Edit this newsletter to change them.';

const makeItem = ( extra = {} ) => ( { id: 42, title: { raw: 'Friday Five' }, meta: {}, categories: [], tags: [], ...extra } );

const renderPanel = item => render( <NewslettersQuickEditPanel item={ item } onClose={ jest.fn() } onSaved={ jest.fn() } /> );

describe( 'NewslettersQuickEditPanel', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		apiFetch.mockImplementation( ( { path, method } ) => {
			if ( method === 'POST' ) {
				return Promise.resolve( {} );
			}
			if ( path.startsWith( '/wp/v2/categories' ) ) {
				return Promise.resolve( makeTermsResponse( CATEGORIES ) );
			}
			if ( path.startsWith( '/wp/v2/tags' ) ) {
				return Promise.resolve( makeTermsResponse( TAGS ) );
			}
			return Promise.resolve( makeTermsResponse( [] ) );
		} );
	} );

	it( 'does not send categories or tags when only visibility changed', async () => {
		renderPanel( makeItem( { meta: { is_public: false }, categories: [ 5 ], tags: [ 7 ] } ) );

		await waitFor( () => expect( screen.getByText( 'News' ) ).toBeInTheDocument() );

		fireEvent.click( screen.getByRole( 'radio', { name: /email and web/i } ) );
		fireEvent.click( screen.getByTestId( 'panel-save' ) );

		await waitFor( () => expect( postCall() ).toBeTruthy() );
		expect( postCall().data ).toEqual( { meta: { is_public: true } } );
	} );

	it( 'seeds the token fields from the raw term IDs when no embed is present', async () => {
		renderPanel( makeItem( { categories: [ 5, 6 ] } ) );

		await waitFor( () => expect( screen.getByText( 'News' ) ).toBeInTheDocument() );
		expect( screen.getByText( 'Sport' ) ).toBeInTheDocument();
		expect( screen.getByTestId( 'panel-dirty' ) ).toHaveTextContent( 'false' );
	} );

	// The embed caps at 100 terms per taxonomy and is absent entirely when
	// no taxonomy column is visible, so neither source is complete on its
	// own. Term 55 is reachable only through the embed here.
	it( 'merges the embed with the fetched options list', async () => {
		renderPanel(
			makeItem( {
				categories: [ 5, 55 ],
				_embedded: { 'wp:term': [ [ { id: 55, name: 'Opinion', taxonomy: 'category' } ] ] },
			} )
		);

		expect( await screen.findByText( 'Opinion' ) ).toBeInTheDocument();
		await waitFor( () => expect( screen.getByText( 'News' ) ).toBeInTheDocument() );
		await waitFor( () => expect( screen.getByLabelText( 'Categories' ) ).not.toBeDisabled() );
	} );

	// Read-only rather than editable while loading: an edit made against a
	// baseline that is still growing would drop the terms resolved late.
	// This is the guard the ads list already carries.
	it( 'holds the fields read-only, and silent, while the options are still loading', async () => {
		let release;
		apiFetch.mockImplementation( ( { path, method } ) => {
			if ( method === 'POST' ) {
				return Promise.resolve( {} );
			}
			if ( path.startsWith( '/wp/v2/categories' ) ) {
				return new Promise( resolve => {
					release = () => resolve( makeTermsResponse( CATEGORIES ) );
				} );
			}
			return Promise.resolve( makeTermsResponse( TAGS ) );
		} );

		renderPanel( makeItem( { categories: [ 5 ] } ) );

		expect( await screen.findByLabelText( 'Categories' ) ).toBeDisabled();
		expect( screen.getByLabelText( 'Tags' ) ).toBeDisabled();
		expect( visibleNotices() ).not.toContain( CATEGORIES_UNAVAILABLE );

		await act( async () => {
			release();
		} );

		await waitFor( () => expect( screen.getByLabelText( 'Categories' ) ).not.toBeDisabled() );
		expect( screen.getByText( 'News' ) ).toBeInTheDocument();
	} );

	// A settled list that still cannot account for every stored term means
	// the field would misrepresent the newsletter, so it goes read-only and
	// says why rather than showing a quietly wrong value.
	it( 'holds Categories read-only and warns once its options settle without the stored term', async () => {
		renderPanel( makeItem( { categories: [ 5, 99 ] } ) );

		await waitFor( () => expect( visibleNotices() ).toContain( CATEGORIES_UNAVAILABLE ) );
		expect( screen.getByLabelText( 'Categories' ) ).toBeDisabled();
	} );

	// A field with unresolvable stored terms is read-only, so it can never
	// go dirty and is never sent. The ride-along in `handleSave` stays as a
	// backstop should that guard ever be relaxed.
	it( 'never sends a taxonomy whose stored terms could not all be resolved', async () => {
		renderPanel( makeItem( { categories: [ 5, 99 ] } ) );

		await waitFor( () => expect( screen.getByLabelText( 'Categories' ) ).toBeDisabled() );
		fireEvent.click( screen.getByRole( 'radio', { name: /email and web/i } ) );
		fireEvent.click( screen.getByTestId( 'panel-save' ) );

		await waitFor( () => expect( postCall() ).toBeTruthy() );
		expect( postCall().data ).toEqual( { meta: { is_public: true } } );
	} );

	it( 'sends only the taxonomy the user edited', async () => {
		renderPanel( makeItem( { categories: [ 5 ], tags: [ 7 ] } ) );

		const input = await screen.findByLabelText( 'Categories' );
		await waitFor( () => expect( input ).not.toBeDisabled() );
		fireEvent.change( input, { target: { value: 'Sport' } } );
		fireEvent.keyDown( input, { key: 'Enter', keyCode: 13 } );
		await screen.findByText( 'Sport' );

		fireEvent.click( screen.getByTestId( 'panel-save' ) );
		await waitFor( () => expect( postCall() ).toBeTruthy() );

		expect( postCall().data.categories ).toEqual( [ 5, 6 ] );
		expect( postCall().data ).not.toHaveProperty( 'tags' );
	} );
} );
