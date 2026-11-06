/**
 * A/B Tests tab: loads tests from newspack-popups, renders one card per test
 * with a single status badge, the action for its state and a six-column
 * results table, and renames a test in place.
 */

import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import '@testing-library/jest-dom';
import apiFetch from '@wordpress/api-fetch';
import ABTests from './index';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

jest.mock( '../../../../../../packages/components/src/with-wizard-screen', () => ( {
	__esModule: true,
	default: Component => props => <Component { ...props } />,
} ) );

const prompt = ( id, extra = {} ) => ( {
	id,
	title: `Prompt ${ id }`,
	status: 'publish',
	status_label: 'Published',
	edit_link: `/wp-admin/post.php?post=${ id }&action=edit`,
	eligible: true,
	...extra,
} );

const test = ( id, variants, extra = {} ) => ( {
	test_id: id,
	name: `Test ${ id }`,
	goal: 'donation',
	goal_label: 'Donation',
	control_share: 60,
	started_at: null,
	variants,
	duplicates: [],
	prompt_count: Object.keys( variants ).length,
	...extra,
} );

const listResponse = ( tests, provider = { available: true, slug: 'stub', reason: null } ) => ( {
	tests,
	provider,
	thresholds: { directional: 80, reliable: 95, min_readers: 30 },
	copy: { young_test: 'Too early to call a winner.', high_anonymous: 'Most readers are logged out.' },
	goals: { donation: 'Donation', subscription: 'Subscription' },
	labels: { collecting: 'Too early', directional: 'Early signal', reliable: 'Reliable' },
} );

const stats = {
	test_id: 'run',
	source: { state: 'ok', unit: 'readers', provider: 'stub', reason: null, fetched_at: '2026-10-29T09:00:00Z' },
	variants: {
		a: { readers: 8214, conversions: 156, rate: 1.8992, lift: null },
		b: { readers: 8190, conversions: 220, rate: 2.6862, lift: 41.4 },
	},
	comparisons: { b: { confidence: 99.12, label: 'reliable' } },
	flags: { young_test: false, high_anonymous: false },
};

const mockRoutes = ( list, extra = {} ) =>
	apiFetch.mockImplementation( ( { path, method } ) => {
		if ( path === '/newspack-popups/v1/ab-tests' && ! method ) {
			return Promise.resolve( list );
		}
		if ( path.endsWith( '/stats' ) || path.includes( '/stats?' ) ) {
			return Promise.resolve( stats );
		}
		if ( extra[ path ] ) {
			return extra[ path ]();
		}
		return Promise.reject( new Error( `Unexpected ${ path }` ) );
	} );

const cardFor = name => screen.getByRole( 'region', { name } );

describe( 'A/B Tests tab', () => {
	beforeEach( () => jest.clearAllMocks() );

	it( 'shows the empty state when there are no tests', async () => {
		mockRoutes( listResponse( [] ) );
		render( <ABTests /> );
		expect( await screen.findByText( 'Find out which prompt works best' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Start A/B Test' } ) ).not.toBeInTheDocument();
	} );

	it( 'says why results are missing when no provider is connected', async () => {
		mockRoutes( listResponse( [], { available: false, slug: null, reason: 'Results will show here once a stats source is connected.' } ) );
		render( <ABTests /> );
		expect( await screen.findByText( 'Results will show here once a stats source is connected.' ) ).toBeInTheDocument();
	} );

	it( 'shows an error when the list cannot load', async () => {
		apiFetch.mockRejectedValue( new Error( 'Sorry, you are not allowed to do that.' ) );
		render( <ABTests /> );
		expect( await screen.findByText( 'Sorry, you are not allowed to do that.' ) ).toBeInTheDocument();
	} );

	it( 'gives each card one badge, the action for its state, and the same six columns', async () => {
		mockRoutes(
			listResponse( [
				test( 'inc', { a: prompt( 1 ) } ),
				test( 'drf', { a: prompt( 2 ), b: prompt( 3, { status: 'draft', status_label: 'Draft' } ) } ),
				test( 'run', { a: prompt( 4 ), b: prompt( 5 ) }, { started_at: '2026-10-01 00:00:00' } ),
			] )
		);
		render( <ABTests /> );

		const running = await screen.findByRole( 'region', { name: 'Test run' } );
		const draft = cardFor( 'Test drf' );
		const incomplete = cardFor( 'Test inc' );

		expect( within( running ).getByText( 'Running' ) ).toBeInTheDocument();
		expect( within( draft ).getAllByText( 'Draft' ) ).toHaveLength( 2 ); // The badge, and B's status line.
		expect( within( incomplete ).getByText( 'Incomplete' ) ).toBeInTheDocument();

		expect( within( incomplete ).getByRole( 'button', { name: 'Create Version B' } ) ).toBeInTheDocument();
		expect( within( draft ).getByRole( 'link', { name: 'Edit Version B' } ) ).toHaveAttribute( 'href', prompt( 3 ).edit_link );
		// End Test comes later; a running card has only its menu.
		expect( within( running ).queryByRole( 'button', { name: /End Test|Create Version B/ } ) ).not.toBeInTheDocument();

		expect( within( running ).getByText( 'Split:' ) ).toBeInTheDocument();
		expect( within( incomplete ).queryByText( 'Split:' ) ).not.toBeInTheDocument();
		expect( within( incomplete ).getByText( 'Not started. Create version B, then publish it to start the test.' ) ).toBeInTheDocument();

		for ( const card of [ running, draft, incomplete ] ) {
			const headers = within( card )
				.getAllByRole( 'columnheader' )
				.map( header => header.textContent.trim() );
			expect( headers ).toEqual( [ 'Version', 'Prompt', 'Readers', 'Conversion rate', 'Lift vs. A', 'Confidence' ] );
		}

		expect( document.body.textContent ).not.toMatch( /control|challenger/ );
	} );

	it( 'shows results with lift from the rates as shown and confidence as text', async () => {
		mockRoutes( listResponse( [ test( 'run', { a: prompt( 4 ), b: prompt( 5 ) }, { started_at: '2026-10-01 00:00:00' } ) ] ) );
		render( <ABTests /> );
		const card = await screen.findByRole( 'region', { name: 'Test run' } );
		expect( await within( card ).findByText( '2.69%' ) ).toBeInTheDocument();
		expect( within( card ).getByText( '1.90%' ) ).toBeInTheDocument();
		expect( within( card ).getByText( '220 of 8,190' ) ).toBeInTheDocument();
		expect( within( card ).getByText( '+41.6%' ) ).toBeInTheDocument();
		expect( within( card ).getByText( '99.1% Reliable' ) ).toBeInTheDocument();
		expect( within( card ).getByText( /^Updated .*\. Results refresh every hour\.$/ ) ).toBeInTheDocument();
	} );

	it( 'lists the test actions in the menu and refreshes results', async () => {
		mockRoutes( listResponse( [ test( 'run', { a: prompt( 4 ), b: prompt( 5 ) }, { started_at: '2026-10-01 00:00:00' } ) ] ) );
		render( <ABTests /> );
		const card = await screen.findByRole( 'region', { name: 'Test run' } );
		fireEvent.click( within( card ).getByRole( 'button', { name: 'Test actions: Test run' } ) );
		expect( screen.getByRole( 'menuitem', { name: 'Edit Version A' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'menuitem', { name: 'Edit Version B' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'menuitem', { name: 'Rename: Test run' } ) ).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'menuitem', { name: 'Refresh Results' } ) );
		await waitFor( () => expect( apiFetch ).toHaveBeenCalledWith( { path: '/newspack-popups/v1/ab-tests/run/stats?refresh=1' } ) );
	} );

	it( 'renames a test and updates its card', async () => {
		mockRoutes( listResponse( [ test( 'inc', { a: prompt( 1 ) } ) ] ), {
			'/newspack-popups/v1/ab-tests/inc/name': () => Promise.resolve( { test_id: 'inc', name: 'Year-end appeal' } ),
		} );
		render( <ABTests /> );
		const card = await screen.findByRole( 'region', { name: 'Test inc' } );
		fireEvent.click( within( card ).getByRole( 'button', { name: 'Test actions: Test inc' } ) );
		fireEvent.click( screen.getByRole( 'menuitem', { name: 'Rename: Test inc' } ) );
		fireEvent.change( screen.getByLabelText( 'Test name' ), { target: { value: 'Year-end appeal' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Rename' } ) );
		expect( await screen.findByRole( 'region', { name: 'Year-end appeal' } ) ).toBeInTheDocument();
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/newspack-popups/v1/ab-tests/inc/name',
			method: 'POST',
			data: { name: 'Year-end appeal' },
		} );
	} );
} );
