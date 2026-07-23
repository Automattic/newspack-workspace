// Spy on the network layer; the real `@wordpress/components` render the
// RadioControl so we exercise the actual radio markup. `QuickEditPanel`
// is a thin modal shell (portal + exit animation), so it's mocked down to
// a plain wrapper that exposes `isDirty` and a Save trigger — the panel's
// own status/save logic is what's under test here.
jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

jest.mock( '../../components/quick-edit-panel', () => ( {
	__esModule: true,
	default: ( { children, isDirty, canSave, isBusy, onSave } ) => (
		<div>
			<div data-testid="panel-dirty">{ String( isDirty ) }</div>
			{ children }
			<button type="button" data-testid="panel-save" onClick={ onSave } disabled={ isBusy || ! canSave }>
				Save
			</button>
		</div>
	),
} ) );

import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import AdsQuickEditPanel from './quick-edit-panel';

const ADVERTISERS = [ { id: 10, name: 'Acme' } ];
const PLACEMENTS = [ { id: 20, name: 'Header' } ];

const renderPanel = ( item, extra = {} ) =>
	render(
		<AdsQuickEditPanel
			item={ item }
			advertisers={ ADVERTISERS }
			placements={ PLACEMENTS }
			termsLoaded
			onClose={ jest.fn() }
			onSaved={ jest.fn() }
			{ ...extra }
		/>
	);

const makeItem = status => ( { id: 42, status, title: { raw: 'Summer sale' }, meta: {} } );

const postCall = () => apiFetch.mock.calls.find( call => call[ 0 ]?.method === 'POST' )?.[ 0 ];

// Scoped to the rendered notices: `Notice` also announces through the
// shared `a11y-speak` live region, which survives between tests in the
// same document and would otherwise satisfy a plain text query.
const visibleNotices = () => [ ...document.querySelectorAll( '.components-notice__content' ) ].map( n => n.textContent.trim() );

const ADVERTISERS_UNAVAILABLE = 'Advertisers could not be loaded. Edit this ad to change them.';
const CATEGORIES_UNAVAILABLE = 'Categories could not be loaded. Edit this ad to change them.';

// `fetchAllTerms` reads `parse: false` responses, so the categories fetch
// needs a Response-alike rather than a plain object.
const mockCategoriesFetch = terms =>
	apiFetch.mockImplementation( options => {
		if ( typeof options?.path === 'string' && options.path.startsWith( '/wp/v2/categories' ) ) {
			return Promise.resolve( { json: () => Promise.resolve( terms ), headers: { get: () => '1' } } );
		}
		return Promise.resolve( {} );
	} );

describe( 'AdsQuickEditPanel status control', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		// Categories fetch (`fetchAllTerms`) and the save POST both go
		// through this mock; an empty object resolves both harmlessly.
		apiFetch.mockResolvedValue( {} );
	} );

	it( 'selects Active when the raw post_status is publish', async () => {
		renderPanel( makeItem( 'publish' ) );
		expect( await screen.findByRole( 'radio', { name: 'Active' } ) ).toBeChecked();
		expect( screen.getByRole( 'radio', { name: 'Inactive' } ) ).not.toBeChecked();
	} );

	it( 'selects Inactive when the raw post_status is draft', async () => {
		renderPanel( makeItem( 'draft' ) );
		expect( await screen.findByRole( 'radio', { name: 'Inactive' } ) ).toBeChecked();
		expect( screen.getByRole( 'radio', { name: 'Active' } ) ).not.toBeChecked();
	} );

	it( 'POSTs status: publish when toggled Inactive → Active', async () => {
		const onSaved = jest.fn();
		renderPanel( makeItem( 'draft' ), { onSaved } );
		fireEvent.click( await screen.findByRole( 'radio', { name: 'Active' } ) );
		fireEvent.click( screen.getByTestId( 'panel-save' ) );
		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );
		expect( postCall().path ).toBe( '/wp/v2/newspack_nl_ads_cpt/42' );
		expect( postCall().method ).toBe( 'POST' );
		expect( postCall().data.status ).toBe( 'publish' );
	} );

	it( 'POSTs status: draft when toggled Active → Inactive', async () => {
		const onSaved = jest.fn();
		renderPanel( makeItem( 'publish' ), { onSaved } );
		fireEvent.click( await screen.findByRole( 'radio', { name: 'Inactive' } ) );
		fireEvent.click( screen.getByTestId( 'panel-save' ) );
		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );
		expect( postCall().data.status ).toBe( 'draft' );
	} );

	it( 'omits status from the payload when it is unchanged', async () => {
		const onSaved = jest.fn();
		renderPanel( makeItem( 'publish' ), { onSaved } );
		await screen.findByRole( 'radio', { name: 'Active' } );
		fireEvent.click( screen.getByTestId( 'panel-save' ) );
		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );
		expect( postCall().data ).not.toHaveProperty( 'status' );
	} );

	it( 'preserves an edge status (future) by omitting status when unchanged', async () => {
		const onSaved = jest.fn();
		renderPanel( makeItem( 'future' ), { onSaved } );
		// `future` maps to the Active radio but must not be rewritten to publish on save.
		expect( await screen.findByRole( 'radio', { name: 'Active' } ) ).toBeChecked();
		fireEvent.click( screen.getByTestId( 'panel-save' ) );
		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );
		expect( postCall().data ).not.toHaveProperty( 'status' );
	} );

	it( 'flattens a scheduled (future) ad to draft when toggled to Inactive', async () => {
		const onSaved = jest.fn();
		renderPanel( makeItem( 'future' ), { onSaved } );
		// Toggling a scheduled ad off is a manual override: it POSTs `draft`, discarding the scheduled post_date.
		fireEvent.click( await screen.findByRole( 'radio', { name: 'Inactive' } ) );
		fireEvent.click( screen.getByTestId( 'panel-save' ) );
		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );
		expect( postCall().data.status ).toBe( 'draft' );
	} );

	it( 'selects Inactive for a private ad and preserves it by omitting status when unchanged', async () => {
		const onSaved = jest.fn();
		renderPanel( makeItem( 'private' ), { onSaved } );
		// `private` ads are never served, so the control reflects Inactive; leaving it untouched must not rewrite the status.
		expect( await screen.findByRole( 'radio', { name: 'Inactive' } ) ).toBeChecked();
		expect( screen.getByRole( 'radio', { name: 'Active' } ) ).not.toBeChecked();
		fireEvent.click( screen.getByTestId( 'panel-save' ) );
		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );
		expect( postCall().data ).not.toHaveProperty( 'status' );
	} );

	it( 'marks the panel dirty after a status-only change', async () => {
		renderPanel( makeItem( 'publish' ) );
		await screen.findByRole( 'radio', { name: 'Active' } );
		expect( screen.getByTestId( 'panel-dirty' ) ).toHaveTextContent( 'false' );
		fireEvent.click( screen.getByRole( 'radio', { name: 'Inactive' } ) );
		expect( screen.getByTestId( 'panel-dirty' ) ).toHaveTextContent( 'true' );
	} );
} );

// The list only embeds terms while a taxonomy column is visible, so the
// panel has to hydrate from the post's raw term IDs — and must never send
// a taxonomy the user did not touch, or a status-only save would wipe the
// terms it never displayed.
describe( 'AdsQuickEditPanel taxonomy handling', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( {} );
	} );

	const withRawTerms = () => ( {
		...makeItem( 'publish' ),
		newspack_nl_advertiser: [ 10 ],
		ad_placement: [ 20 ],
	} );

	const withEmbeddedTerms = () => ( {
		...makeItem( 'publish' ),
		newspack_nl_advertiser: [ 10 ],
		_embedded: { 'wp:term': [ [ { id: 10, name: 'Acme', taxonomy: 'newspack_nl_advertiser' } ] ] },
	} );

	it( 'hydrates the fields from raw term IDs when the embed is absent', async () => {
		renderPanel( withRawTerms() );
		expect( await screen.findByText( 'Acme' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Header' ) ).toBeInTheDocument();
	} );

	it( 'still reads embedded terms when they are present', async () => {
		renderPanel( withEmbeddedTerms() );
		expect( await screen.findByText( 'Acme' ) ).toBeInTheDocument();
	} );

	it( 'omits untouched taxonomies from a status-only save', async () => {
		const onSaved = jest.fn();
		renderPanel( withRawTerms(), { onSaved } );
		fireEvent.click( await screen.findByRole( 'radio', { name: 'Inactive' } ) );
		fireEvent.click( screen.getByTestId( 'panel-save' ) );
		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );

		expect( postCall().data.status ).toBe( 'draft' );
		expect( postCall().data ).not.toHaveProperty( 'newspack_nl_advertiser' );
		expect( postCall().data ).not.toHaveProperty( 'ad_placement' );
		expect( postCall().data ).not.toHaveProperty( 'categories' );
	} );

	it( 'sends only the taxonomy the user edited', async () => {
		const onSaved = jest.fn();
		renderPanel( { ...makeItem( 'publish' ), ad_placement: [ 20 ] }, { onSaved } );

		const input = await screen.findByLabelText( 'Advertiser' );
		fireEvent.change( input, { target: { value: 'Acme' } } );
		fireEvent.keyDown( input, { key: 'Enter', keyCode: 13 } );
		await screen.findByText( 'Acme' );

		fireEvent.click( screen.getByTestId( 'panel-save' ) );
		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );

		expect( postCall().data.newspack_nl_advertiser ).toEqual( [ 10 ] );
		expect( postCall().data ).not.toHaveProperty( 'ad_placement' );
	} );

	// The categories fetch is the only source for that field once the embed
	// is skipped, and `fetchAllTerms` returns a partial list rather than
	// throwing, so a failure would otherwise show an empty field on an ad
	// that has categories.
	it( 'flags the Categories field read-only when its options cannot account for the stored terms', async () => {
		renderPanel( { ...makeItem( 'publish' ), categories: [ 77 ] } );
		await waitFor( () => expect( visibleNotices() ).toContain( CATEGORIES_UNAVAILABLE ) );
		expect( screen.getByLabelText( 'Categories' ) ).toBeDisabled();
	} );

	it( 'leaves the Categories field alone when there are no stored categories', async () => {
		renderPanel( withRawTerms() );
		await screen.findByText( 'Acme' );
		await waitFor( () => expect( screen.getByLabelText( 'Categories' ) ).not.toBeDisabled() );
		expect( visibleNotices() ).not.toContain( CATEGORIES_UNAVAILABLE );
	} );

	// The `wp:term` embed caps at 10 terms per taxonomy, so an ad with more
	// than that arrives with a truncated embed. The fetched options list is
	// complete and must be allowed to fill the gap, rather than the short
	// embed making the field look broken.
	it( 'fills the gap when the embed truncates at 10 terms', async () => {
		const allCategories = Array.from( { length: 11 }, ( _, i ) => ( { id: i + 1, name: `Cat ${ i + 1 }` } ) );
		mockCategoriesFetch( allCategories );
		renderPanel( {
			...makeItem( 'publish' ),
			categories: allCategories.map( c => c.id ),
			_embedded: { 'wp:term': [ allCategories.slice( 0, 10 ).map( c => ( { ...c, taxonomy: 'category' } ) ) ] },
		} );

		expect( await screen.findByText( 'Cat 11' ) ).toBeInTheDocument();
		expect( visibleNotices() ).not.toContain( CATEGORIES_UNAVAILABLE );
		expect( screen.getByLabelText( 'Categories' ) ).not.toBeDisabled();
	} );

	it( 'flags Advertiser read-only once its options have settled without the stored term', async () => {
		renderPanel( { ...makeItem( 'publish' ), newspack_nl_advertiser: [ 99 ] }, { termsLoaded: true } );
		await waitFor( () => expect( visibleNotices() ).toContain( ADVERTISERS_UNAVAILABLE ) );
		expect( screen.getByLabelText( 'Advertiser' ) ).toBeDisabled();
	} );

	// Read-only rather than editable while loading: an edit made against a
	// baseline that is still growing would drop the terms resolved late.
	// No warning yet either — nothing has failed, it just hasn't arrived.
	it( 'holds Advertiser read-only, and silent, while its options are still loading', async () => {
		renderPanel( { ...makeItem( 'publish' ), newspack_nl_advertiser: [ 10 ] }, { termsLoaded: false } );
		await screen.findByLabelText( 'Advertiser' );
		expect( screen.getByLabelText( 'Advertiser' ) ).toBeDisabled();
		expect( visibleNotices() ).not.toContain( ADVERTISERS_UNAVAILABLE );
	} );

	it( 'keeps Categories read-only until its fetch settles, then opens it with every term', async () => {
		const allCategories = Array.from( { length: 11 }, ( _, i ) => ( { id: i + 1, name: `Cat ${ i + 1 }` } ) );
		let release;
		apiFetch.mockImplementation( options => {
			if ( typeof options?.path === 'string' && options.path.startsWith( '/wp/v2/categories' ) ) {
				return new Promise( resolve => {
					release = () => resolve( { json: () => Promise.resolve( allCategories ), headers: { get: () => '1' } } );
				} );
			}
			return Promise.resolve( {} );
		} );

		renderPanel( {
			...makeItem( 'publish' ),
			categories: allCategories.map( c => c.id ),
			// The embed caps at 10, so the 11th is missing until the fetch lands.
			_embedded: { 'wp:term': [ allCategories.slice( 0, 10 ).map( c => ( { ...c, taxonomy: 'category' } ) ) ] },
		} );

		expect( await screen.findByLabelText( 'Categories' ) ).toBeDisabled();
		expect( screen.queryByText( 'Cat 11' ) ).toBeNull();

		await act( async () => {
			release();
		} );

		await waitFor( () => expect( screen.getByLabelText( 'Categories' ) ).not.toBeDisabled() );
		expect( screen.getByText( 'Cat 11' ) ).toBeInTheDocument();
		expect( visibleNotices() ).not.toContain( CATEGORIES_UNAVAILABLE );
	} );

	it( 'still refuses to send the unshowable categories on save', async () => {
		const onSaved = jest.fn();
		renderPanel( { ...makeItem( 'publish' ), categories: [ 77 ] }, { onSaved } );
		fireEvent.click( await screen.findByRole( 'radio', { name: 'Inactive' } ) );
		fireEvent.click( screen.getByTestId( 'panel-save' ) );
		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );
		expect( postCall().data ).not.toHaveProperty( 'categories' );
	} );

	// A field with unresolvable stored terms is read-only, so it can never
	// go dirty and is never sent. The ride-along in `handleSave` stays as a
	// backstop should that guard ever be relaxed.
	it( 'never sends a taxonomy whose stored terms could not all be resolved', async () => {
		const onSaved = jest.fn();
		renderPanel( { ...makeItem( 'publish' ), newspack_nl_advertiser: [ 99 ] }, { onSaved } );

		await waitFor( () => expect( screen.getByLabelText( 'Advertiser' ) ).toBeDisabled() );
		fireEvent.click( screen.getByRole( 'radio', { name: 'Inactive' } ) );
		fireEvent.click( screen.getByTestId( 'panel-save' ) );
		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );

		expect( postCall().data ).not.toHaveProperty( 'newspack_nl_advertiser' );
	} );
} );
