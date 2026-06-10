/**
 * Tests for TabStateView — the shared loading / error / empty / refetch chrome
 * used by every data-backed Insights tab.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import TabStateView from './TabStateView';

const Body = () => <div>BODY CONTENT</div>;

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
} );
