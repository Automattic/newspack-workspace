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
		a: { readers: 8214, conversions: 156, rate: 1.9, lift: null },
		b: { readers: 8190, conversions: 220, rate: 2.69, lift: 12.3 },
	},
	comparisons: { b: { confidence: 99.12, label: 'reliable' } },
	flags: { young_test: false, high_anonymous: false },
};

const mockRoutes = ( list, extra = {}, statsResponse = stats ) =>
	apiFetch.mockImplementation( ( { path, method } ) => {
		if ( path === '/newspack-popups/v1/ab-tests' && ! method ) {
			return Promise.resolve( list );
		}
		if ( path.endsWith( '/stats' ) || path.includes( '/stats?' ) ) {
			return Promise.resolve( statsResponse );
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

		expect( within( incomplete ).getByRole( 'button', { name: 'Create Version B: Test inc' } ) ).toBeInTheDocument();
		expect( within( draft ).getByRole( 'link', { name: 'Edit Version B: Test drf' } ) ).toHaveAttribute( 'href', prompt( 3 ).edit_link );
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

	it( 'offers Create Version B only while A is the test’s only prompt', async () => {
		mockRoutes( listResponse( [ test( 'two', { a: prompt( 1 ) }, { prompt_count: 2 } ), test( 'noa', { b: prompt( 2 ) } ) ] ) );
		render( <ABTests /> );
		for ( const name of [ 'Test two', 'Test noa' ] ) {
			const card = await screen.findByRole( 'region', { name } );
			expect( within( card ).getByText( 'Incomplete' ) ).toBeInTheDocument();
			expect( within( card ).queryByRole( 'button', { name: /Create Version B/ } ) ).not.toBeInTheDocument();
		}
	} );

	it( 'loads results only for a started test with a stats source', async () => {
		const unstarted = test( 'new', { a: prompt( 1 ), b: prompt( 2 ) } );
		const started = test( 'run', { a: prompt( 3 ), b: prompt( 4 ) }, { started_at: '2026-10-01 00:00:00' } );
		const expectNoStats = async ( list, name ) => {
			mockRoutes( list );
			const { unmount } = render( <ABTests /> );
			const card = await screen.findByRole( 'region', { name } );
			fireEvent.click( within( card ).getByRole( 'button', { name: `Test actions: ${ name }` } ) );
			expect( screen.queryByRole( 'menuitem', { name: 'Refresh Results' } ) ).not.toBeInTheDocument();
			expect( apiFetch.mock.calls.some( ( [ { path } ] ) => path.includes( '/stats' ) ) ).toBe( false );
			unmount();
			apiFetch.mockClear();
		};
		await expectNoStats( listResponse( [ unstarted ] ), 'Test new' );
		await expectNoStats( listResponse( [ started ], { available: false, slug: null, reason: 'No source.' } ), 'Test run' );
	} );

	// The fixture's lift is deliberately one the shown rates can't produce, so the
	// test fails if the table computes lift itself instead of showing the route's.
	it( 'shows the rates, lift and confidence the route returns', async () => {
		mockRoutes( listResponse( [ test( 'run', { a: prompt( 4 ), b: prompt( 5 ) }, { started_at: '2026-10-01 00:00:00' } ) ] ) );
		render( <ABTests /> );
		const card = await screen.findByRole( 'region', { name: 'Test run' } );
		expect( await within( card ).findByText( '2.69%' ) ).toBeInTheDocument();
		expect( within( card ).getByText( '1.90%' ) ).toBeInTheDocument();
		expect( within( card ).getByText( '220 of 8,190' ) ).toBeInTheDocument();
		expect( within( card ).getByText( '+12.3%' ) ).toBeInTheDocument();
		expect( within( card ).getByText( '99.1% Reliable' ) ).toBeInTheDocument();
		expect( within( card ).getByText( /^Updated .*\. Results refresh every hour\.$/ ) ).toBeInTheDocument();
	} );

	it( 'gives the start date, not the fetch time, until a reader is counted', async () => {
		const collecting = {
			...stats,
			source: { ...stats.source, state: 'collecting' },
			variants: {
				a: { readers: 0, conversions: 0, rate: null, lift: null },
				b: { readers: 0, conversions: 0, rate: null, lift: null },
			},
			comparisons: {},
		};
		mockRoutes( listResponse( [ test( 'run', { a: prompt( 4 ), b: prompt( 5 ) }, { started_at: '2026-10-01 00:00:00' } ) ] ), {}, collecting );
		render( <ABTests /> );
		const card = await screen.findByRole( 'region', { name: 'Test run' } );
		expect( await within( card ).findByText( /^Started .*\. Results refresh every hour\.$/ ) ).toBeInTheDocument();
		expect( within( card ).queryByText( /^Updated / ) ).not.toBeInTheDocument();
		expect( within( card ).getAllByText( '0' ) ).toHaveLength( 2 );
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

	it( 'says readers see both duplicates only when the extra prompt is published', async () => {
		const duplicate = { ...prompt( 6, { status: 'draft', status_label: 'Draft' } ), letter: 'b' };
		mockRoutes(
			listResponse( [
				test( 'drf', { a: prompt( 1 ), b: prompt( 2 ) }, { duplicates: [ duplicate ] } ),
				test(
					'pub',
					{ a: prompt( 3 ), b: prompt( 4 ) },
					{ duplicates: [ { ...duplicate, id: 7, status: 'publish', status_label: 'Published' } ] }
				),
			] )
		);
		render( <ABTests /> );
		const draftCard = await screen.findByRole( 'region', { name: 'Test drf' } );
		expect( within( draftCard ).getByText( /isn't published, so readers don't see it\./ ) ).toBeInTheDocument();
		expect( within( draftCard ).queryByText( /Readers still see both/ ) ).not.toBeInTheDocument();
		expect( within( cardFor( 'Test pub' ) ).getByText( /Readers still see both/ ) ).toBeInTheDocument();
	} );

	it( 'names Manual only as a reason a version is ineligible', async () => {
		mockRoutes( listResponse( [ test( 'man', { a: prompt( 1, { eligible: false } ), b: prompt( 2 ) } ) ] ) );
		render( <ABTests /> );
		const card = await screen.findByRole( 'region', { name: 'Test man' } );
		expect( within( card ).getByText( /^Version A uses a manual or custom placement, or is an overlay set to Manual only/ ) ).toBeInTheDocument();
	} );

	it( 'renames a test and updates its card', async () => {
		mockRoutes( listResponse( [ test( 'inc', { a: prompt( 1 ) } ) ] ), {
			'/newspack-popups/v1/ab-tests/inc/name': () => Promise.resolve( { test_id: 'inc', name: 'Year-end appeal' } ),
		} );
		render( <ABTests /> );
		const card = await screen.findByRole( 'region', { name: 'Test inc' } );
		const toggle = within( card ).getByRole( 'button', { name: 'Test actions: Test inc' } );
		fireEvent.click( toggle );
		// In a browser the toggle holds focus as the dialog mounts, so the dialog
		// records it as the place to return focus to; jsdom moves no focus on click.
		toggle.focus();
		fireEvent.click( screen.getByRole( 'menuitem', { name: 'Rename: Test inc' } ) );
		expect( screen.getByRole( 'dialog', { name: 'Rename Test' } ) ).toBeInTheDocument();
		fireEvent.change( screen.getByLabelText( 'Test name' ), { target: { value: ' ' } } );
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toHaveAttribute( 'aria-disabled', 'true' );
		fireEvent.change( screen.getByLabelText( 'Test name' ), { target: { value: 'Year-end appeal' } } );
		const focusedHeadings = [];
		card.addEventListener( 'focusin', event => event.target.matches( 'h2' ) && focusedHeadings.push( event.target.textContent ) );
		screen.getByRole( 'button', { name: 'Save' } ).focus();
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		const renamed = await screen.findByRole( 'region', { name: 'Year-end appeal' } );
		await waitFor( () => expect( within( renamed ).getByRole( 'heading', { name: 'Year-end appeal' } ) ).toHaveFocus() );
		expect( focusedHeadings ).toEqual( [ 'Year-end appeal' ] );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/newspack-popups/v1/ab-tests/inc/name',
			method: 'POST',
			data: { name: 'Year-end appeal' },
		} );
	} );

	it( 'returns focus to the name field when a rename fails', async () => {
		mockRoutes( listResponse( [ test( 'inc', { a: prompt( 1 ) } ) ] ), {
			'/newspack-popups/v1/ab-tests/inc/name': () => Promise.reject( { message: 'Rename failed.' } ),
		} );
		render( <ABTests /> );
		const card = await screen.findByRole( 'region', { name: 'Test inc' } );
		fireEvent.click( within( card ).getByRole( 'button', { name: 'Test actions: Test inc' } ) );
		fireEvent.click( screen.getByRole( 'menuitem', { name: 'Rename: Test inc' } ) );
		const field = screen.getByLabelText( 'Test name' );
		fireEvent.change( field, { target: { value: 'Year-end appeal' } } );
		// Focus starts outside the field, where a browser leaves it once the
		// field is disabled for the save.
		expect( field ).not.toHaveFocus();
		fireEvent.submit( field.closest( 'form' ) );
		expect( await screen.findByText( 'Rename failed.' ) ).toBeInTheDocument();
		await waitFor( () => expect( field ).toHaveFocus() );
	} );
} );
