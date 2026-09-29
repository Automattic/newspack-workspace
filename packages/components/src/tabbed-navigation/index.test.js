/**
 * External dependencies.
 */
import { act, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter, Redirect, useHistory } from 'react-router-dom';

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

	it( 'renders the content outside the panels when no tab owns the route', () => {
		// A route no tab matches — an edit screen registered as hidden, or a stale
		// URL. The content must still mount, or the page body is blank.
		renderTabs( { initialEntries: [ '/edit/123' ] } );

		// Assert positively: with nothing selected the panels unmount entirely, so
		// a `closest( '[role=tabpanel]' )` check alone would pass even if the
		// content rendered somewhere wrong. Exactly one copy also guards the mutual
		// exclusivity with the in-panel render.
		const matches = screen.getAllByText( 'Routed content' );
		expect( matches ).toHaveLength( 1 );
		expect( matches[ 0 ].closest( '.newspack-tabbed-navigation__root' ) ).not.toBeNull();
		expect( matches[ 0 ].closest( '[role="tabpanel"]' ) ).toBeNull();
		ITEMS.forEach( ( { label } ) => {
			expect( getTab( label ) ).toHaveAttribute( 'aria-selected', 'false' );
		} );
	} );

	it( 'renders the content when the only matching item is hidden from the bar', () => {
		// The wizard routes hidden sections but filters them out of the bar, so the
		// item exists and yet can never own the route.
		render(
			<MemoryRouter initialEntries={ [ '/edit/123' ] }>
				<TabbedNavigation
					items={ [ ...ITEMS, { label: 'Edit', path: '/edit/:id', isHiddenInTabbedNavigation: true } ] }
					content={ <div>Routed content</div> }
				/>
			</MemoryRouter>
		);
		expect( screen.getAllByText( 'Routed content' ) ).toHaveLength( 1 );
		expect( screen.queryByRole( 'tab', { name: 'Edit' } ) ).not.toBeInTheDocument();
	} );

	it( 'renders unowned content even when disableUpcoming disables every tab', () => {
		// The setup wizard's Welcome and Completed screens are hidden from the bar
		// AND use disableUpcoming, so `index > activeIndex` disables every tab. The
		// content still has to render — this is the case that left onboarding blank.
		renderTabs( { initialEntries: [ '/unowned' ], disableUpcoming: true } );
		expect( screen.getAllByText( 'Routed content' ) ).toHaveLength( 1 );
		ITEMS.forEach( ( { label } ) => {
			expect( getTab( label ) ).toHaveAttribute( 'aria-disabled', 'true' );
		} );
	} );

	it( 'mounts unowned content so a router fallback inside it still runs', () => {
		// The wizard's trailing <Redirect> lives inside this content, so dropping
		// it would strand the route instead of correcting it.
		renderTabs( { initialEntries: [ '/unknown' ], content: <Redirect to="/stories" /> } );
		expect( getTab( 'Stories' ) ).toHaveAttribute( 'aria-selected', 'true' );
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
