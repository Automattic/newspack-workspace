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

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
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
		const item = { id: 42, title: { raw: 'Friday Five' }, meta: { is_public: false }, categories: [ 5 ], tags: [ 7 ] };
		render( <NewslettersQuickEditPanel item={ item } onClose={ jest.fn() } onSaved={ jest.fn() } /> );

		await waitFor( () => expect( screen.getByText( 'News' ) ).toBeInTheDocument() );

		fireEvent.click( screen.getByRole( 'radio', { name: /email and web/i } ) );
		fireEvent.click( screen.getByTestId( 'panel-save' ) );

		await waitFor( () => expect( postCall() ).toBeTruthy() );
		expect( postCall().data ).toEqual( { meta: { is_public: true } } );
	} );

	it( 'keeps assigned term IDs that the options fetch never resolved', async () => {
		const item = { id: 42, title: { raw: 'Friday Five' }, meta: {}, categories: [ 5, 99 ], tags: [] };
		render( <NewslettersQuickEditPanel item={ item } onClose={ jest.fn() } onSaved={ jest.fn() } /> );

		await waitFor( () => expect( screen.getByText( 'News' ) ).toBeInTheDocument() );

		fireEvent.click( screen.getByRole( 'button', { name: /remove item/i } ) );
		fireEvent.click( screen.getByTestId( 'panel-save' ) );

		await waitFor( () => expect( postCall() ).toBeTruthy() );
		expect( postCall().data.categories ).toEqual( [ 99 ] );
	} );

	it( 'seeds the token fields from the raw term IDs when no embed is present', async () => {
		const item = { id: 42, title: { raw: 'Friday Five' }, meta: {}, categories: [ 5, 6 ], tags: [] };
		render( <NewslettersQuickEditPanel item={ item } onClose={ jest.fn() } onSaved={ jest.fn() } /> );

		await waitFor( () => expect( screen.getByText( 'News' ) ).toBeInTheDocument() );
		expect( screen.getByText( 'Sport' ) ).toBeInTheDocument();
		expect( screen.getByTestId( 'panel-dirty' ) ).toHaveTextContent( 'false' );
	} );
} );
