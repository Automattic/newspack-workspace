import { render, screen, waitFor, within } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import confetti from 'canvas-confetti';

import Done from './index';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'canvas-confetti', () => jest.fn() );

const HOMEPAGE_EDIT = 'https://example.test/wp-admin/post.php?post=2&action=edit';

const URLS = {
	dashboard: 'https://example.test/wp-admin/admin.php?page=newspack-dashboard',
	homepage: null,
	site: 'https://example.test',
	support: 'https://help.newspack.com/',
};

const NEXT_STEPS = {
	reader_revenue: 'https://example.test/wp-admin/admin.php?page=newspack-audience',
	newsletters: 'https://example.test/wp-admin/edit.php?post_type=newspack_nl_cpt',
	ads: 'https://example.test/wp-admin/admin.php?page=newspack-ads-display-ads',
};

const respondWith = ( overrides: Record< string, unknown > = {} ) =>
	( apiFetch as unknown as jest.Mock ).mockResolvedValue( {
		homepage_edit_url: HOMEPAGE_EDIT,
		next_steps: NEXT_STEPS,
		...overrides,
	} );

const nextStepLinks = async () => within( await screen.findByRole( 'list', { name: 'Pick where to start' } ) ).getAllByRole( 'link' );

describe( 'Done', () => {
	beforeEach( () => {
		window.newspack_urls = URLS;
		( confetti as unknown as jest.Mock ).mockClear();
		respondWith();
	} );

	it( 'links each next step to its screen, in order', async () => {
		render( <Done /> );
		const hrefs = ( await nextStepLinks() ).map( link => link.getAttribute( 'href' ) );
		expect( hrefs ).toEqual( [ NEXT_STEPS.reader_revenue, NEXT_STEPS.newsletters, NEXT_STEPS.ads, HOMEPAGE_EDIT, URLS.dashboard ] );
		const rows: [ string, string ][] = [
			[ 'Set up reader revenue', NEXT_STEPS.reader_revenue ],
			[ 'Start a newsletter', NEXT_STEPS.newsletters ],
			[ 'Sell ads', NEXT_STEPS.ads ],
			[ 'Edit your homepage', HOMEPAGE_EDIT ],
			[ 'Open the dashboard', URLS.dashboard ],
		];
		rows.forEach( ( [ name, href ] ) => expect( screen.getByRole( 'link', { name } ) ).toHaveAttribute( 'href', href ) );
		expect( screen.getByRole( 'link', { name: 'Set up reader revenue' } ) ).toHaveAccessibleDescription(
			'Donations, subscriptions, and reader accounts.'
		);
	} );

	it( 'leaves out a next step whose plugin is not active', async () => {
		respondWith( { next_steps: { ...NEXT_STEPS, newsletters: null, ads: null } } );
		render( <Done /> );
		const hrefs = ( await nextStepLinks() ).map( link => link.getAttribute( 'href' ) );
		expect( hrefs ).toEqual( [ NEXT_STEPS.reader_revenue, HOMEPAGE_EDIT, URLS.dashboard ] );
		expect( screen.queryByText( 'Start a newsletter' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Sell ads' ) ).not.toBeInTheDocument();
	} );

	it( 'links Edit your homepage to the Pages list when there is no front page', async () => {
		respondWith( { homepage_edit_url: null } );
		render( <Done /> );
		expect( await screen.findByRole( 'link', { name: 'Edit your homepage' } ) ).toHaveAttribute( 'href', 'edit.php?post_type=page' );
	} );

	it( 'keeps the always-available next steps when the request fails', async () => {
		( apiFetch as unknown as jest.Mock ).mockRejectedValue( new Error( 'fail' ) );
		render( <Done /> );
		const hrefs = ( await nextStepLinks() ).map( link => link.getAttribute( 'href' ) );
		expect( hrefs ).toEqual( [ 'admin.php?page=newspack-audience', 'edit.php?post_type=page', URLS.dashboard ] );
	} );

	it( 'links to the site from the heading, in a new tab, instead of a homepage preview', async () => {
		render( <Done /> );
		await nextStepLinks();
		expect( document.querySelector( 'iframe' ) ).toBeNull();
		const viewSite = screen.getByRole( 'link', { name: /^view your site/ } );
		expect( viewSite ).toHaveAttribute( 'href', URLS.site );
		expect( viewSite ).toHaveAttribute( 'target', '_blank' );
		expect( screen.getByText( 'Pick what to set up first, or', { exact: false } ) ).toBeInTheDocument();
	} );

	it( 'fires confetti from the bottom edge of the next steps, unless motion is reduced', async () => {
		const rect = jest.spyOn( Element.prototype, 'getBoundingClientRect' ).mockReturnValue( { left: 100, width: 400, bottom: 600 } as DOMRect );
		Object.defineProperty( window, 'innerWidth', { configurable: true, value: 1000 } );
		Object.defineProperty( window, 'innerHeight', { configurable: true, value: 800 } );
		render( <Done /> );
		await nextStepLinks();
		await waitFor( () => expect( confetti ).toHaveBeenCalledTimes( 1 ) );
		expect( confetti ).toHaveBeenCalledWith( expect.objectContaining( { origin: { x: 0.3, y: 0.75 }, disableForReducedMotion: true } ) );
		rect.mockRestore();
	} );

	it( 'ends on the dashboard row and the documentation link', async () => {
		render( <Done /> );
		await nextStepLinks();
		expect( screen.getByRole( 'heading', { level: 2, name: 'Your site is set up' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'link', { name: 'Open the dashboard' } ) ).toHaveAccessibleDescription( 'Settings, plugins, and everything else.' );
		expect( screen.queryByRole( 'link', { name: 'Open Dashboard' } ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'link', { name: /^View Site/ } ) ).not.toBeInTheDocument();
		expect( screen.getByRole( 'link', { name: /^Read the documentation/ } ) ).toHaveAttribute( 'href', URLS.support );
	} );
} );
