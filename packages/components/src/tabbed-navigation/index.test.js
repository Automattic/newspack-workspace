/**
 * External dependencies.
 */
import { act, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter, useHistory } from 'react-router-dom';

/**
 * Internal dependencies.
 */
import TabbedNavigation, { isItemActive } from './index';

const HistoryGrabber = ( { historyRef } ) => {
	historyRef.current = useHistory();
	return null;
};

const ITEMS = [
	{ label: 'Stories', path: '/stories' },
	{ label: 'Budgets', path: '/budgets' },
	{ label: 'Sites', path: '/sites' },
];

const renderTabs = ( { initialEntries = [ '/stories' ], ...props } = {} ) => {
	const historyRef = { current: null };
	render(
		<MemoryRouter initialEntries={ initialEntries }>
			<HistoryGrabber historyRef={ historyRef } />
			<TabbedNavigation items={ ITEMS } content={ <div>Routed content</div> } { ...props } />
		</MemoryRouter>
	);
	return historyRef.current;
};

const getTab = name => screen.getByRole( 'tab', { name } );

describe( 'isItemActive', () => {
	it( 'treats an explicitly selected item as active regardless of pathname', () => {
		expect( isItemActive( { selected: true, path: '/other' }, '/current' ) ).toBe( true );
		expect( isItemActive( { selected: true }, null ) ).toBe( true );
	} );

	describe( 'outside a router (pathname is null)', () => {
		afterEach( () => {
			delete window.location;
			window.location = new URL( 'http://localhost/' );
		} );

		it( 'is active when the href matches the current URL', () => {
			delete window.location;
			window.location = new URL( 'http://example.com/wp-admin/admin.php?page=ads' );
			expect( isItemActive( { href: 'http://example.com/wp-admin/admin.php?page=ads' }, null ) ).toBe( true );
		} );

		it( 'is inactive when the href does not match', () => {
			delete window.location;
			window.location = new URL( 'http://example.com/wp-admin/admin.php?page=ads' );
			expect( isItemActive( { href: 'http://example.com/wp-admin/admin.php?page=other' }, null ) ).toBe( false );
		} );

		it( 'is inactive when the item has no href', () => {
			expect( isItemActive( { path: '/ads' }, null ) ).toBe( false );
		} );
	} );

	describe( 'inside a router (delegates to the shared route matcher)', () => {
		it( 'matches a path as a prefix by default', () => {
			expect( isItemActive( { path: '/stories' }, '/stories' ) ).toBe( true );
			expect( isItemActive( { path: '/stories' }, '/stories/new' ) ).toBe( true );
			expect( isItemActive( { path: '/stories' }, '/budgets' ) ).toBe( false );
		} );

		it( 'matches exactly when the item opts in via exact', () => {
			expect( isItemActive( { path: '/stories', exact: true }, '/stories/new' ) ).toBe( false );
		} );

		it( 'keeps the parent tab active on a hidden subpage via wildcard', () => {
			const item = { path: '/additional-brands', activeTabPaths: [ '/additional-brands/*' ] };
			expect( isItemActive( item, '/additional-brands/new' ) ).toBe( true );
		} );
	} );
} );

describe( 'TabbedNavigation with routed items', () => {
	it( 'renders the routed content inside the active tab panel', () => {
		renderTabs( { initialEntries: [ '/budgets' ] } );
		expect( getTab( 'Budgets' ) ).toHaveAttribute( 'aria-selected', 'true' );

		const content = screen.getByText( 'Routed content' );
		const panel = content.closest( '[role="tabpanel"]' );
		expect( panel ).not.toBeNull();
		expect( getTab( 'Budgets' ) ).toHaveAttribute( 'aria-controls', panel.id );
	} );

	it( 'keeps the tab active and the content in its panel on a nested route', () => {
		renderTabs( { initialEntries: [ '/stories/new' ] } );
		expect( getTab( 'Stories' ) ).toHaveAttribute( 'aria-selected', 'true' );
		expect( screen.getByText( 'Routed content' ).closest( '[role="tabpanel"]' ) ).not.toBeNull();
	} );

	it( 'prefers the most specific tab when paths nest', () => {
		render(
			<MemoryRouter initialEntries={ [ '/stories/new' ] }>
				<TabbedNavigation
					items={ [
						{ label: 'Stories', path: '/stories' },
						{ label: 'New story', path: '/stories/new' },
					] }
				/>
			</MemoryRouter>
		);
		expect( getTab( 'New story' ) ).toHaveAttribute( 'aria-selected', 'true' );
		expect( getTab( 'Stories' ) ).toHaveAttribute( 'aria-selected', 'false' );
	} );

	it( 'navigates through history.push on click', () => {
		const history = renderTabs();
		fireEvent.click( getTab( 'Sites' ) );
		expect( history.location.pathname ).toBe( '/sites' );
		expect( getTab( 'Sites' ) ).toHaveAttribute( 'aria-selected', 'true' );
	} );

	it( 'leaves modified clicks to the browser', () => {
		const history = renderTabs();
		fireEvent.click( getTab( 'Sites' ), { metaKey: true } );
		expect( history.location.pathname ).toBe( '/stories' );
	} );

	it( 'consults history.block guards before navigating', () => {
		const history = renderTabs();
		const unblock = history.block( () => false );
		fireEvent.click( getTab( 'Sites' ) );
		expect( history.location.pathname ).toBe( '/stories' );
		expect( getTab( 'Stories' ) ).toHaveAttribute( 'aria-selected', 'true' );
		unblock();
	} );

	it( 'disables tabs after the active one with disableUpcoming', () => {
		renderTabs( { initialEntries: [ '/budgets' ], disableUpcoming: true } );
		expect( getTab( 'Stories' ) ).toHaveAttribute( 'href' );
		expect( getTab( 'Sites' ) ).not.toHaveAttribute( 'href' );
		expect( getTab( 'Sites' ) ).toHaveAttribute( 'aria-disabled', 'true' );

		fireEvent.click( getTab( 'Sites' ) );
		expect( getTab( 'Budgets' ) ).toHaveAttribute( 'aria-selected', 'true' );
	} );

	it( 'disables every tab with disableUpcoming when no route matches', () => {
		renderTabs( { initialEntries: [ '/unknown' ], disableUpcoming: true } );
		ITEMS.forEach( ( { label } ) => {
			expect( getTab( label ) ).toHaveAttribute( 'aria-disabled', 'true' );
		} );
	} );

	it( 'navigates when the active tab changes so panels follow the route', () => {
		const history = renderTabs();
		act( () => history.push( '/budgets' ) );
		expect( getTab( 'Budgets' ) ).toHaveAttribute( 'aria-selected', 'true' );
		expect( screen.getByText( 'Routed content' ).closest( '[role="tabpanel"]' ).id ).toBe( getTab( 'Budgets' ).getAttribute( 'aria-controls' ) );
	} );
} );

describe( 'TabbedNavigation with href-only items', () => {
	const LINK_ITEMS = [
		{ label: 'Newsletters', href: 'http://example.com/wp-admin/admin.php?page=newsletters', selected: true },
		{ label: 'Ads', href: 'http://example.com/wp-admin/admin.php?page=ads' },
	];

	it( 'renders plain navigation links instead of a tabs widget', () => {
		render( <TabbedNavigation items={ LINK_ITEMS } /> );
		expect( screen.queryByRole( 'tab' ) ).not.toBeInTheDocument();
		expect( screen.getByRole( 'navigation' ) ).toBeInTheDocument();

		const active = screen.getByRole( 'link', { name: 'Newsletters' } );
		expect( active ).toHaveAttribute( 'aria-current', 'page' );
		expect( screen.getByRole( 'link', { name: 'Ads' } ) ).not.toHaveAttribute( 'aria-current' );
	} );
} );
