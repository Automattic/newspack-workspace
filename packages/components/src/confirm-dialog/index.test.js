/**
 * External dependencies.
 */
import { act, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter, useHistory } from 'react-router-dom';

/**
 * Internal dependencies.
 */
import ConfirmDialog from './';

const HistoryGrabber = ( { historyRef } ) => {
	historyRef.current = useHistory();
	return null;
};

const renderWithHistory = ( routerProps = {} ) => {
	const historyRef = { current: null };
	render(
		<MemoryRouter { ...routerProps }>
			<HistoryGrabber historyRef={ historyRef } />
			<ConfirmDialog when confirmButtonText="Discard changes" cancelButtonText="Keep editing">
				Unsaved changes
			</ConfirmDialog>
		</MemoryRouter>
	);
	return historyRef.current;
};

// The modal's X close button reuses the cancel label as its aria-label, so
// query by visible text to hit the actual footer buttons.
const confirmNavigation = () => fireEvent.click( screen.getByText( 'Discard changes', { selector: 'button' } ) );
const cancelNavigation = () => fireEvent.click( screen.getByText( 'Keep editing', { selector: 'button' } ) );
const dialog = () => screen.queryByText( 'Unsaved changes' );

describe( 'ConfirmDialog navigation blocking', () => {
	it( 'blocks navigation and stays on the current page on cancel', () => {
		const history = renderWithHistory();
		act( () => history.push( '/next' ) );
		expect( dialog() ).toBeInTheDocument();
		expect( history.location.pathname ).toBe( '/' );

		const replaceSpy = jest.spyOn( history, 'replace' );
		cancelNavigation();
		expect( dialog() ).not.toBeInTheDocument();
		// The URL is re-synced to the page the user chose to stay on.
		expect( replaceSpy ).toHaveBeenCalledTimes( 1 );
		expect( history.location.pathname ).toBe( '/' );
	} );

	it( 'replays a blocked push on confirm: re-sync replace first, then push, and re-arms the blocker', () => {
		const history = renderWithHistory();
		const replaceSpy = jest.spyOn( history, 'replace' );
		const pushSpy = jest.spyOn( history, 'push' );

		act( () => history.push( '/next' ) );
		expect( history.location.pathname ).toBe( '/' );
		confirmNavigation();
		expect( history.location.pathname ).toBe( '/next' );

		// Replay order: re-sync replace fires before the target push.
		const resyncOrder = replaceSpy.mock.invocationCallOrder[ 0 ];
		const replayOrder = pushSpy.mock.invocationCallOrder[ 1 ];
		expect( replaceSpy ).toHaveBeenCalledTimes( 1 );
		expect( pushSpy ).toHaveBeenCalledTimes( 2 );
		expect( resyncOrder ).toBeLessThan( replayOrder );

		// The bypass only lets the confirmed navigation through; the next one is blocked again.
		act( () => history.push( '/another' ) );
		expect( history.location.pathname ).toBe( '/next' );
		expect( dialog() ).toBeInTheDocument();
	} );

	it( 'replays a blocked REPLACE with replace, not push', () => {
		const history = renderWithHistory();
		const pushSpy = jest.spyOn( history, 'push' );

		act( () => history.replace( '/replaced' ) );
		expect( dialog() ).toBeInTheDocument();
		expect( history.location.pathname ).toBe( '/' );

		confirmNavigation();
		expect( history.location.pathname ).toBe( '/replaced' );
		expect( pushSpy ).not.toHaveBeenCalled();
		expect( history.length ).toBe( 1 );
	} );

	it( 'replays a blocked POP (back navigation) as a push after the re-sync', () => {
		const history = renderWithHistory( { initialEntries: [ '/start', '/current' ], initialIndex: 1 } );
		act( () => history.goBack() );
		expect( dialog() ).toBeInTheDocument();
		expect( history.location.pathname ).toBe( '/current' );

		confirmNavigation();
		expect( history.location.pathname ).toBe( '/start' );
		// Pushed rather than popped: the target becomes a new forward entry.
		expect( history.length ).toBe( 3 );
	} );

	// React re-reports errors thrown in event handlers as uncaught window
	// errors; swallow them so the intentional throws don't fail the test.
	const withNavigationError = fn => {
		const consoleError = jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		const onWindowError = event => event.preventDefault();
		window.addEventListener( 'error', onWindowError );
		try {
			fn();
		} finally {
			window.removeEventListener( 'error', onWindowError );
			consoleError.mockRestore();
		}
	};

	it( 'keeps blocking after a confirmed navigation throws', () => {
		const history = renderWithHistory();

		act( () => history.push( '/next' ) );
		jest.spyOn( history, 'push' ).mockImplementationOnce( () => {
			throw new Error( 'nav failed' );
		} );
		withNavigationError( () => confirmNavigation() );

		// bypassBlock was reset by the finally, so navigation is still protected.
		act( () => history.push( '/elsewhere' ) );
		expect( history.location.pathname ).toBe( '/' );
		expect( dialog() ).toBeInTheDocument();
	} );

	it( 'keeps blocking after a cancel URL re-sync throws', () => {
		const history = renderWithHistory();

		act( () => history.push( '/next' ) );
		jest.spyOn( history, 'replace' ).mockImplementationOnce( () => {
			throw new Error( 'replace failed' );
		} );
		withNavigationError( () => cancelNavigation() );

		act( () => history.push( '/elsewhere' ) );
		expect( history.location.pathname ).toBe( '/' );
		expect( dialog() ).toBeInTheDocument();
	} );
} );
