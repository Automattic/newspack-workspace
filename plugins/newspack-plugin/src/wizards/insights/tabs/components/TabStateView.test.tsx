/**
 * Tests for TabStateView — the shared loading / error / empty / refetch chrome
 * used by every data-backed Insights tab.
 */

/**
 * External dependencies
 */
import { act, render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import TabStateView from './TabStateView';

const Body = () => <div>BODY CONTENT</div>;

// The prompt referenced `vi.useFakeTimers()`; this suite runs under Jest
// (@wordpress/scripts), so the Jest fake-timer API is used instead.
const MESSAGES = [
	{ text: 'First message', delay: 0 },
	{ text: 'Second message', delay: 250 },
	{ text: 'Third message', delay: 6000 },
];

describe( 'TabStateView', () => {
	it( 'shows a spinner on initial load and withholds the body', () => {
		render(
			<TabStateView status="loading" hasData={ false } errorLabel="err" className="newspack-insights__audience-tab">
				<Body />
			</TabStateView>
		);
		expect( screen.getByText( 'Loading…' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'BODY CONTENT' ) ).not.toBeInTheDocument();
	} );

	it( 'shows the error label plus detail on error', () => {
		render(
			<TabStateView status="error" hasData={ false } error="boom" errorLabel="Could not load audience data." className="x">
				<Body />
			</TabStateView>
		);
		expect( screen.getByText( 'Could not load audience data.' ) ).toBeInTheDocument();
		expect( screen.getByText( 'boom' ) ).toBeInTheDocument();
	} );

	it( 'renders the body without a refetch overlay on success', () => {
		const { container } = render(
			<TabStateView status="success" hasData errorLabel="err" className="newspack-insights__audience-tab">
				<Body />
			</TabStateView>
		);
		expect( screen.getByText( 'BODY CONTENT' ) ).toBeInTheDocument();
		expect( container.querySelector( '.newspack-insights__tab-refreshing' ) ).toBeNull();
		expect( container.querySelector( '.is-refreshing' ) ).toBeNull();
	} );

	it( 'keeps the body and floats a spinner while refetching (data present + loading)', () => {
		const { container } = render(
			<TabStateView status="loading" hasData errorLabel="err" className="newspack-insights__audience-tab">
				<Body />
			</TabStateView>
		);
		expect( screen.getByText( 'BODY CONTENT' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Updating…' ) ).toBeInTheDocument();
		expect( container.querySelector( '.newspack-insights__tab-refreshing' ) ).not.toBeNull();
		expect( container.querySelector( '.is-refreshing' ) ).not.toBeNull();
	} );

	it( 'renders nothing when idle with no data', () => {
		const { container } = render(
			<TabStateView status="idle" hasData={ false } errorLabel="err" className="x">
				<Body />
			</TabStateView>
		);
		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'shows the first loading message immediately and advances by each delay', () => {
		jest.useFakeTimers();
		try {
			render(
				<TabStateView status="loading" hasData={ false } errorLabel="err" className="x" loadingMessages={ MESSAGES }>
					<Body />
				</TabStateView>
			);
			expect( screen.getByText( 'First message' ) ).toBeInTheDocument();
			act( () => {
				jest.advanceTimersByTime( 250 );
			} );
			expect( screen.getByText( 'Second message' ) ).toBeInTheDocument();
			act( () => {
				jest.advanceTimersByTime( 6000 );
			} );
			expect( screen.getByText( 'Third message' ) ).toBeInTheDocument();
		} finally {
			jest.useRealTimers();
		}
	} );

	it( 'ignores loadingMessages while refetching — spinner only, no message text', () => {
		const { container } = render(
			<TabStateView status="loading" hasData errorLabel="err" className="x" loadingMessages={ MESSAGES }>
				<Body />
			</TabStateView>
		);
		expect( screen.getByText( 'BODY CONTENT' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Updating…' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'First message' ) ).not.toBeInTheDocument();
		expect( container.querySelector( '.newspack-insights__tab-loading-message' ) ).toBeNull();
	} );

	it( 'clears all message timers when unmounted mid-load (no orphaned timers)', () => {
		jest.useFakeTimers();
		try {
			const { unmount } = render(
				<TabStateView status="loading" hasData={ false } errorLabel="err" className="x" loadingMessages={ MESSAGES }>
					<Body />
				</TabStateView>
			);
			// Two future messages (250ms, 6000ms) are scheduled; the first is immediate.
			expect( jest.getTimerCount() ).toBe( 2 );
			unmount();
			expect( jest.getTimerCount() ).toBe( 0 );
			// Firing past every delay into the unmounted tree must not throw.
			expect( () =>
				act( () => {
					jest.advanceTimersByTime( 12000 );
				} )
			).not.toThrow();
		} finally {
			jest.useRealTimers();
		}
	} );

	it( 'falls back to a spinner-only frame when loadingMessages is omitted on initial load', () => {
		const { container } = render(
			<TabStateView status="loading" hasData={ false } errorLabel="err" className="x">
				<Body />
			</TabStateView>
		);
		expect( screen.getByText( 'Loading…' ) ).toBeInTheDocument();
		expect( container.querySelector( '.newspack-insights__tab-loading-message' ) ).toBeNull();
	} );
} );
