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
// eslint-disable-next-line import/order -- After the mock it reads.
import { speak } from '@wordpress/a11y';

jest.mock( '../../../../../../packages/components/src/with-wizard-screen', () => ( {
	__esModule: true,
	default: Component => props => (
		<>
			<header>{ props.headerActions }</header>
			<Component { ...props } />
		</>
	),
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

const listResponse = ( tests, provider = { available: true, slug: 'stub', reason: null }, prompts = [] ) => ( {
	tests,
	prompts,
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
		// Only the empty state's button: the header's waits for a first test.
		expect( screen.getAllByRole( 'button', { name: 'Start A/B Test' } ) ).toHaveLength( 1 );
	} );

	it( 'puts Start A/B Test in the header once there are tests', async () => {
		mockRoutes( listResponse( [ test( 'inc', { a: prompt( 1 ) } ) ] ) );
		render( <ABTests /> );
		await screen.findByRole( 'region', { name: 'Test inc' } );
		expect( within( screen.getByRole( 'banner' ) ).getByRole( 'button', { name: 'Start A/B Test' } ) ).toBeInTheDocument();
	} );

	it( 'offers only published prompts in no test and on a testable placement, and says why others are missing', async () => {
		const startState = ( id, extra = {} ) => ( {
			id,
			title: `Prompt ${ id }`,
			status: 'publish',
			test_id: '',
			ineligible_reason: '',
			suggested_goal: '',
			...extra,
		} );
		mockRoutes(
			listResponse( [], undefined, [
				startState( 1 ),
				startState( 2, { status: 'draft' } ),
				startState( 3, { test_id: 'spring-1a2b' } ),
				startState( 4, { ineligible_reason: 'Prompts on manual or custom placements can’t be A/B tested.' } ),
			] )
		);
		render( <ABTests /> );
		fireEvent.click( await screen.findByRole( 'button', { name: 'Start A/B Test' } ) );

		const picker = await screen.findByLabelText( 'Prompt to test' );
		const offered = within( picker )
			.getAllByRole( 'option' )
			.filter( option => ! option.disabled )
			.map( option => option.textContent );
		expect( offered ).toEqual( [ 'Prompt 1' ] );
		expect( screen.getByText( /Prompts on manual or custom placements can’t be A\/B tested\./ ) ).toBeInTheDocument();
	} );

	it( 'brings the test a prompt menu linked to into view and focuses its heading', async () => {
		const scrollIntoView = jest.fn();
		window.HTMLElement.prototype.scrollIntoView = scrollIntoView;
		mockRoutes( listResponse( [ test( 'one', { a: prompt( 1 ) } ), test( 'two', { a: prompt( 2 ) } ) ] ) );
		render( <ABTests location={ { search: '?test=two' } } /> );

		const heading = await within( await screen.findByRole( 'region', { name: 'Test two' } ) ).findByRole( 'heading', { name: 'Test two' } );
		await waitFor( () => expect( heading ).toHaveFocus() );
		expect( scrollIntoView ).toHaveBeenCalledTimes( 1 );
		// Centered, so the sticky page header doesn't cover it.
		expect( scrollIntoView ).toHaveBeenCalledWith( { block: 'center' } );
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
		expect( within( running ).getByRole( 'button', { name: 'End Test: Test run' } ) ).toBeInTheDocument();
		for ( const card of [ draft, incomplete ] ) {
			expect( within( card ).queryByRole( 'button', { name: /End Test/ } ) ).not.toBeInTheDocument();
		}

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
		expect(
			within( card ).getByText( /^Version A is on a manual or custom placement, or is an overlay set to Manual only/ )
		).toBeInTheDocument();
		// A notice instead of the answer, never both.
		expect( within( card ).queryByText( 'Result' ) ).not.toBeInTheDocument();
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

describe( 'Ended tests', () => {
	beforeEach( () => jest.clearAllMocks() );

	const running = () => test( 'run', { a: prompt( 4 ), b: prompt( 5 ) }, { started_at: '2026-10-01T00:00:00Z', status: 'active' } );
	const ended = ( id = 'old', extra = {} ) =>
		test(
			id,
			{ a: prompt( 8, { status: 'draft', status_label: 'Draft' } ), b: prompt( 9 ) },
			{ started_at: '2026-09-30T00:00:00Z', status: 'ended', ended_at: '2026-10-29T12:00:00Z', kept: 'b', ...extra }
		);

	it( 'hides ended tests until the Status filter asks for them', async () => {
		mockRoutes( listResponse( [ running(), ended() ] ) );
		render( <ABTests /> );
		await screen.findByRole( 'region', { name: 'Test run' } );
		expect( screen.queryByRole( 'region', { name: 'Test old' } ) ).not.toBeInTheDocument();
		expect( screen.getByRole( 'searchbox', { name: 'Search tests' } ) ).toBeInTheDocument();
	} );

	it( "says an ended test's results are not final until they are", async () => {
		mockRoutes( listResponse( [ ended( 'old', { ended_at: new Date().toISOString() } ) ] ) );
		render( <ABTests location={ { search: '?test=old' } } /> );
		const card = await screen.findByRole( 'region', { name: 'Test old' } );
		expect( await within( card ).findByText( /^Results from .+ to .+\. They become final two days after the test ends\.$/ ) ).toBeInTheDocument();
		expect( within( card ).queryByText( /^Final results/ ) ).not.toBeInTheDocument();
	} );

	it( 'stops promising final results once the two days have passed', async () => {
		mockRoutes( listResponse( [ ended() ] ) );
		render( <ABTests location={ { search: '?test=old' } } /> );
		const card = await screen.findByRole( 'region', { name: 'Test old' } );
		expect( await within( card ).findByText( /^Results from .+ to .+\.$/ ) ).toBeInTheDocument();
		expect( within( card ).queryByText( /become final/ ) ).not.toBeInTheDocument();
	} );

	it( 'offers no End Test on a test whose versions are not all published', async () => {
		const unready = test(
			'una',
			{ a: prompt( 10 ), b: prompt( 11, { status: 'draft', eligible: false } ) },
			{ started_at: '2026-10-01T00:00:00Z', status: 'active' }
		);
		mockRoutes( listResponse( [ unready ] ) );
		render( <ABTests /> );
		const card = await screen.findByRole( 'region', { name: 'Test una' } );
		expect( within( card ).queryByRole( 'button', { name: /End Test/ } ) ).not.toBeInTheDocument();
	} );

	it( 'says there are no active tests when every test has ended', async () => {
		mockRoutes( listResponse( [ ended() ] ) );
		render( <ABTests /> );
		expect( await screen.findByText( 'No active tests.' ) ).toBeInTheDocument();
	} );

	it( 'leads a running card with the answer', async () => {
		mockRoutes( listResponse( [ running() ] ) );
		render( <ABTests /> );
		const card = await screen.findByRole( 'region', { name: 'Test run' } );
		expect( await within( card ).findByText( 'B wins. 2.69% of readers who saw B converted, against 1.90% for A.' ) ).toBeInTheDocument();
		expect( within( card ).getByText( 'Result' ) ).toBeInTheDocument();
		expect( card.querySelector( '.newspack-ab-tests__bars' ) ).toHaveAttribute( 'aria-hidden', 'true' );
	} );

	it( 'folds the logged-out caution into the answer instead of a notice', async () => {
		mockRoutes(
			listResponse( [ running() ] ),
			{},
			{
				...stats,
				comparisons: { b: { confidence: 85, label: 'directional' } },
				flags: { young_test: false, high_anonymous: true, days_running: 20 },
			}
		);
		render( <ABTests /> );
		const card = await screen.findByRole( 'region', { name: 'Test run' } );
		expect( await within( card ).findByText( 'No winner yet. B is ahead, 2.69% against 1.90%.' ) ).toBeInTheDocument();
		expect( within( card ).getByText( 'More readers are needed. Some may have seen both versions on different devices.' ) ).toBeInTheDocument();
		expect( within( card ).queryByText( 'Most readers are logged out.' ) ).not.toBeInTheDocument();
	} );

	it( 'says nothing matched a search', async () => {
		mockRoutes( listResponse( [ running() ] ) );
		render( <ABTests /> );
		await screen.findByRole( 'region', { name: 'Test run' } );
		fireEvent.change( screen.getByRole( 'searchbox', { name: 'Search tests' } ), { target: { value: 'nothing like it' } } );
		expect( await screen.findByText( 'No tests match your search or filter.' ) ).toBeInTheDocument();
	} );

	it( 'shows an ended test as ended, with its verdict, final dates and kept row, and no main action', async () => {
		mockRoutes( listResponse( [ ended() ] ), {}, { ...stats, source: { ...stats.source, final: true } } );
		render( <ABTests location={ { search: '?test=old' } } /> );
		const card = await screen.findByRole( 'region', { name: 'Test old' } );
		expect( within( card ).getByText( 'Ended' ) ).toBeInTheDocument();
		expect( within( card ).getByText( /^Ended .+\. Kept B\.$/ ) ).toBeInTheDocument();
		expect( await within( card ).findByText( /^Final results from .+ to .+\.$/ ) ).toBeInTheDocument();
		expect( within( card ).queryByText( 'Split:' ) ).not.toBeInTheDocument();
		expect( within( card ).queryByRole( 'button', { name: /End Test|Edit Version|Create Version/ } ) ).not.toBeInTheDocument();
		// The row is tinted by the stylesheet from this marker; screen readers hear "(kept)".
		const kept = card.querySelector( '.newspack-ab-tests__version.is-kept' );
		expect( kept ).toHaveTextContent( /^B \(kept\)$/ );
		expect( card.querySelectorAll( '.is-kept' ) ).toHaveLength( 1 );

		fireEvent.click( within( card ).getByRole( 'button', { name: 'Test actions: Test old' } ) );
		expect( screen.queryByRole( 'menuitem', { name: 'Refresh Results' } ) ).not.toBeInTheDocument();
		expect( screen.getByRole( 'menuitem', { name: 'Edit Version A' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'menuitem', { name: 'Rename: Test old' } ) ).toBeInTheDocument();
	} );

	it( 'loads an ended test’s kept results even with no stats source connected', async () => {
		mockRoutes( listResponse( [ ended() ], { available: false, slug: null, reason: 'No source.' } ) );
		render( <ABTests location={ { search: '?test=old' } } /> );
		const card = await screen.findByRole( 'region', { name: 'Test old' } );
		expect( await within( card ).findByText( '2.69%' ) ).toBeInTheDocument();
	} );

	it( 'ends a test, then shows it first among ended tests, focused and announced', async () => {
		const refetch = jest.fn();
		// The earlier-ended test is listed first, so only the move puts this one ahead.
		mockRoutes( listResponse( [ ended( 'aaa' ), running() ] ), {
			'/newspack-popups/v1/ab-tests/run/end': () =>
				Promise.resolve( { ...running(), status: 'ended', ended_at: '2026-11-08T10:00:00Z', kept: 'b' } ),
		} );
		render( <ABTests refetch={ refetch } /> );
		const card = await screen.findByRole( 'region', { name: 'Test run' } );
		await within( card ).findByText( '2.69%' );
		fireEvent.click( within( card ).getByRole( 'button', { name: 'End Test: Test run' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'End Test' } ) );

		const endedCard = await screen.findByRole( 'region', { name: 'Test run' } );
		await waitFor( () => expect( within( endedCard ).getByRole( 'heading', { name: 'Test run' } ) ).toHaveFocus() );
		expect( within( endedCard ).getByText( /Kept B\.$/ ) ).toBeInTheDocument();
		const cards = screen.getAllByRole( 'region' );
		expect( cards[ 0 ] ).toBe( endedCard );
		expect( cards[ 1 ] ).toBe( screen.getByRole( 'region', { name: 'Test aaa' } ) );
		expect( speak ).toHaveBeenCalledWith( 'Test ended. Kept B.' );
		expect( refetch ).toHaveBeenCalledTimes( 1 );
		expect( refetch ).toHaveBeenCalledWith( { quiet: true } );
		expect( apiFetch ).toHaveBeenCalledWith( { path: '/newspack-popups/v1/ab-tests/run/end', method: 'POST', data: { keep: 'b' } } );
		// The whole end-to-end flow; Jest's 5s default trips on a loaded machine.
	}, 15000 );
} );
