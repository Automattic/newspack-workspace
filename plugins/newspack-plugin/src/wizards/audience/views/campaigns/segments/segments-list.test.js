/**
 * External dependencies
 */
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

/**
 * Internal dependencies
 */
import SegmentsList from './segments-list';

// Below the medium breakpoint the page header folds secondary actions into its menu.
jest.mock( '@wordpress/compose', () => ( {
	...jest.requireActual( '@wordpress/compose' ),
	useViewportMatch: jest.fn( () => true ),
} ) );

const segment = ( id, name, isDisabled = false ) => ( { id, name, configuration: { is_disabled: isDisabled }, criteria: [] } );
// The live region repeats a spoken notice, and keeps it after the notice has gone.
const NOTICE = { selector: '.components-notice__content' };

const SEGMENTS = [ segment( 'a', 'Donors' ), segment( 'b', 'Newsletter readers', true ) ];

const renderList = ( props = {} ) => {
	window.newspackAudienceCampaigns = { api: '/api', criteria: [] };
	const merged = { segments: SEGMENTS, setSegments: jest.fn(), wizardApiFetch: jest.fn(), ...props };
	render(
		<MemoryRouter>
			<SegmentsList { ...merged } />
		</MemoryRouter>
	);
	return merged;
};

const rowTitles = () => screen.queryAllByRole( 'heading', { level: 3 } ).map( heading => heading.textContent );

const THREE_SEGMENTS = [ segment( 'a', 'Alpha' ), segment( 'b', 'Beta' ), segment( 'c', 'Gamma' ) ];

const startReordering = () => fireEvent.click( screen.getByRole( 'button', { name: 'Segment Priority' } ) );

// A move reaches the list once its animation ends.
const moveDown = name => {
	fireEvent.click( screen.getByRole( 'button', { name: `Move ${ name } down` } ) );
	act( () => {
		jest.advanceTimersByTime( 200 );
	} );
};

beforeAll( () => {
	window.scrollTo = jest.fn();
} );

describe( 'SegmentsList', () => {
	it( 'shows the empty state without a toolbar when there are no segments', () => {
		renderList( { segments: [] } );
		expect( screen.getByText( 'No segments yet' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'searchbox' ) ).not.toBeInTheDocument();
	} );

	it( 'names the toggle and the menu after the segment', () => {
		renderList();
		expect( screen.getByRole( 'checkbox', { name: 'Donors' } ) ).toBeChecked();
		expect( screen.getByRole( 'checkbox', { name: 'Newsletter readers' } ) ).not.toBeChecked();
		expect( screen.getByRole( 'button', { name: 'Segment actions: Donors' } ) ).toBeInTheDocument();
	} );

	it( 'decodes the stored segment name for display and accessible names', () => {
		renderList( { segments: [ segment( 'a', 'News &amp; Views' ) ] } );
		expect( rowTitles() ).toEqual( [ 'News & Views' ] );
		expect( screen.getByRole( 'checkbox', { name: 'News & Views' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Segment actions: News & Views' } ) ).toBeInTheDocument();
	} );

	it( 'shows the move buttons disabled outside reorder mode', () => {
		renderList();
		expect( screen.getByRole( 'button', { name: 'Move Donors down' } ) ).toBeDisabled();
		expect( screen.getByRole( 'button', { name: 'Move Newsletter readers up' } ) ).toBeDisabled();
	} );

	it( 'lists disabled segments alongside enabled ones, with no status filter', () => {
		renderList();
		expect( rowTitles() ).toEqual( [ 'Donors', 'Newsletter readers' ] );
		expect( screen.queryByRole( 'button', { name: /^Status/ } ) ).not.toBeInTheDocument();
	} );

	it( 'searches by segment name, with a message when nothing matches', () => {
		renderList();
		const search = screen.getByRole( 'searchbox', { name: 'Search segments' } );

		fireEvent.change( search, { target: { value: 'donor' } } );
		return waitFor( () => expect( rowTitles() ).toEqual( [ 'Donors' ] ) ).then( () => {
			fireEvent.change( search, { target: { value: 'nothing like this' } } );
			return waitFor( () => expect( screen.getByText( 'No segments match your search.' ) ).toBeInTheDocument() );
		} );
	} );

	it( 'offers Segment Priority only with two or more segments', () => {
		renderList( { segments: [ SEGMENTS[ 0 ] ] } );
		const header = document.querySelector( '.newspack-page__header-actions' );
		expect( within( header ).getByRole( 'link', { name: 'Add Segment' } ) ).toHaveAttribute( 'href', '#/segments/new' );
		expect( screen.queryByRole( 'button', { name: 'Segment Priority' } ) ).not.toBeInTheDocument();
	} );

	describe( 'Reorder mode', () => {
		beforeEach( () => jest.useFakeTimers() );
		afterEach( () => jest.useRealTimers() );

		it( 'enables the moves on every segment, swaps search for Cancel and Save, and focuses the first move', () => {
			renderList( { segments: THREE_SEGMENTS } );
			fireEvent.change( screen.getByRole( 'searchbox', { name: 'Search segments' } ), { target: { value: 'beta' } } );
			expect( rowTitles() ).toEqual( [ 'Beta' ] );

			startReordering();

			expect( rowTitles() ).toEqual( [ 'Alpha', 'Beta', 'Gamma' ] );
			expect( screen.queryByRole( 'searchbox' ) ).not.toBeInTheDocument();
			expect( screen.getByRole( 'button', { name: 'Cancel' } ) ).toBeInTheDocument();
			expect( screen.getByRole( 'button', { name: 'Save' } ) ).toHaveAttribute( 'aria-disabled', 'true' );
			expect( screen.getByRole( 'checkbox', { name: 'Alpha' } ) ).toBeDisabled();
			expect( screen.getByRole( 'button', { name: 'Segment actions: Alpha' } ) ).toBeDisabled();
			expect( document.activeElement ).toHaveAccessibleName( 'Move Alpha down' );
		} );

		it( 'restores the saved order on Cancel, without a request, and hands focus back', () => {
			const { wizardApiFetch } = renderList( { segments: THREE_SEGMENTS } );
			startReordering();
			moveDown( 'Alpha' );
			expect( rowTitles() ).toEqual( [ 'Beta', 'Alpha', 'Gamma' ] );

			fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );

			expect( rowTitles() ).toEqual( [ 'Alpha', 'Beta', 'Gamma' ] );
			expect( screen.getByRole( 'button', { name: 'Move Alpha down' } ) ).toBeDisabled();
			expect( screen.getByRole( 'checkbox', { name: 'Alpha' } ) ).toBeEnabled();
			expect( screen.getByRole( 'button', { name: 'Segment actions: Alpha' } ) ).toBeEnabled();
			expect( wizardApiFetch ).not.toHaveBeenCalled();
			expect( document.activeElement ).toHaveAccessibleName( 'Segment Priority' );
		} );

		it( 'posts the new order, hands the reply on and leaves reorder mode', async () => {
			const reply = [ THREE_SEGMENTS[ 1 ], THREE_SEGMENTS[ 0 ], THREE_SEGMENTS[ 2 ] ];
			const wizardApiFetch = jest.fn().mockResolvedValue( reply );
			const { setSegments } = renderList( { segments: THREE_SEGMENTS, wizardApiFetch } );
			startReordering();
			moveDown( 'Alpha' );

			fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

			expect( wizardApiFetch ).toHaveBeenCalledWith( {
				path: '/api/segmentation-sort',
				method: 'POST',
				data: { segmentIds: [ 'b', 'a', 'c' ] },
				quiet: true,
			} );
			await waitFor( () => expect( setSegments ).toHaveBeenCalledWith( reply ) );
			expect( await screen.findByRole( 'button', { name: 'Segment Priority' } ) ).toBeInTheDocument();
			expect( screen.getByRole( 'checkbox', { name: 'Alpha' } ) ).toBeEnabled();
			expect( screen.getByRole( 'button', { name: 'Segment actions: Alpha' } ) ).toBeEnabled();
		} );

		it( 'stays in reorder mode on failure, and clears the error when retrying', async () => {
			let resolveRetry;
			const wizardApiFetch = jest
				.fn()
				.mockRejectedValueOnce( { message: 'Failed to sort.' } )
				.mockImplementationOnce( () => new Promise( resolve => ( resolveRetry = resolve ) ) );
			const { setSegments } = renderList( { segments: THREE_SEGMENTS, wizardApiFetch } );
			startReordering();
			moveDown( 'Alpha' );

			fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
			await screen.findByText( 'Failed to sort.', NOTICE );
			expect( setSegments ).not.toHaveBeenCalled();
			expect( rowTitles() ).toEqual( [ 'Beta', 'Alpha', 'Gamma' ] );

			fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
			expect( screen.queryByText( 'Failed to sort.', NOTICE ) ).not.toBeInTheDocument();
			await act( async () => resolveRetry( THREE_SEGMENTS ) );
			expect( screen.getByRole( 'button', { name: 'Segment Priority' } ) ).toBeInTheDocument();
		} );

		it( 'falls back to a generic message when a save fails', async () => {
			renderList( { segments: THREE_SEGMENTS, wizardApiFetch: jest.fn().mockRejectedValue( {} ) } );
			startReordering();
			moveDown( 'Alpha' );
			fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
			await screen.findByText( 'There was an error sorting segments. Please try again.', NOTICE );
		} );
	} );

	it( 'disables only the row being toggled, and hands on the reply', async () => {
		let resolve;
		const wizardApiFetch = jest.fn( () => new Promise( done => ( resolve = done ) ) );
		const { setSegments } = renderList( { wizardApiFetch } );

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Donors' } ) );

		expect( screen.getByRole( 'checkbox', { name: 'Donors', hidden: true } ).closest( '[inert]' ) ).not.toBeNull();
		expect( screen.getByRole( 'checkbox', { name: 'Newsletter readers' } ).closest( '[inert]' ) ).toBeNull();
		expect( wizardApiFetch.mock.calls[ 0 ][ 0 ].data.configuration.is_disabled ).toBe( true );

		resolve( SEGMENTS );
		await waitFor( () => expect( setSegments ).toHaveBeenCalledWith( SEGMENTS ) );
		await waitFor( () => expect( screen.getByRole( 'checkbox', { name: 'Donors' } ).closest( '[inert]' ) ).toBeNull() );
	} );

	it( 'does not let an older reply undo a newer one', async () => {
		const replies = [];
		const wizardApiFetch = jest.fn( () => new Promise( done => replies.push( done ) ) );
		const { setSegments } = renderList( { wizardApiFetch } );

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Donors' } ) );
		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Newsletter readers' } ) );

		replies[ 1 ]( [ 'newer' ] );
		await waitFor( () => expect( setSegments ).toHaveBeenCalledWith( [ 'newer' ] ) );
		replies[ 0 ]( [ 'older' ] );
		await waitFor( () => expect( screen.getByRole( 'checkbox', { name: 'Donors' } ).closest( '[inert]' ) ).toBeNull() );
		expect( setSegments ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'shows a failed toggle, and clears it at the next attempt', async () => {
		let resolveRetry;
		const wizardApiFetch = jest
			.fn()
			.mockRejectedValueOnce( { message: 'Toggle failed.' } )
			.mockImplementationOnce( () => new Promise( resolve => ( resolveRetry = resolve ) ) );
		renderList( { wizardApiFetch } );

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Donors' } ) );
		await screen.findByText( 'Toggle failed.', NOTICE );
		await waitFor( () => expect( screen.getByRole( 'checkbox', { name: 'Donors' } ).closest( '[inert]' ) ).toBeNull() );

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Donors' } ) );
		expect( screen.queryByText( 'Toggle failed.', NOTICE ) ).not.toBeInTheDocument();
		resolveRetry( SEGMENTS );
	} );

	it( 'shows a failed delete, with a fallback message', async () => {
		renderList( { wizardApiFetch: jest.fn().mockRejectedValue( {} ) } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Segment actions: Donors' } ) );
		fireEvent.click( await screen.findByRole( 'menuitem', { name: 'Delete' } ) );
		await screen.findByText( 'There was an error deleting the segment. Please try again.', NOTICE );
		expect( rowTitles() ).toEqual( [ 'Donors', 'Newsletter readers' ] );
	} );
} );
