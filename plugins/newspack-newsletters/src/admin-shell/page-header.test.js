/**
 * Tests for the chassis PageHeader + useHeaderActions hook.
 *
 * Action shape mirrors `newspack-plugin`'s wizard `setHeaderData` so a future
 * consolidation against the shared component package is mechanical:
 * `{ type: 'primary' | 'secondary', label, icon?, href?, onClick? }`.
 *
 * `useHeaderActions` requires a memoised array reference (see its JSDoc).
 * Tests stabilise the array via the outer test scope (`const actions = …`)
 * or `useMemo([…], [])` inside the component.
 */

import { render, screen, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
import { useMemo } from '@wordpress/element';

import PageHeader from './page-header';
import { HeaderActionsProvider, useHeaderActions } from './header-actions-context';

const Harness = ( { actions } ) => {
	useHeaderActions( actions );
	return null;
};

const withProvider = ui => (
	<HeaderActionsProvider>
		<PageHeader />
		{ ui }
	</HeaderActionsProvider>
);

describe( 'PageHeader', () => {
	beforeEach( () => {
		// Each test starts without a Newspack admin-header in the DOM, so the
		// portal-fallback path renders inline. Tests that need the portal
		// target inject it explicitly (see "portals into newspack-plugin's
		// admin-header strip when present").
		document.getElementById( 'newspack-wizards-admin-header' )?.remove();
	} );

	it( 'renders nothing when no actions are registered', () => {
		const { container } = render( withProvider( null ) );
		expect( container.querySelector( '.newspack-newsletters-admin__header-actions' ) ).toBeNull();
		expect( screen.queryAllByRole( 'button' ) ).toHaveLength( 0 );
		expect( screen.queryAllByRole( 'link' ) ).toHaveLength( 0 );
	} );

	it( 'renders registered actions with their label', () => {
		const actions = [
			{ type: 'primary', label: 'Add new', onClick: jest.fn() },
			{ type: 'secondary', label: 'Help', href: 'https://example.test/help' },
		];
		render( withProvider( <Harness actions={ actions } /> ) );

		expect( screen.getByRole( 'button', { name: 'Add new' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'link', { name: 'Help' } ) ).toHaveAttribute( 'href', 'https://example.test/help' );
	} );

	it( 'fires onClick handlers when a primary action is activated', () => {
		const onClick = jest.fn();
		const actions = [ { type: 'primary', label: 'Add new', onClick } ];
		render( withProvider( <Harness actions={ actions } /> ) );

		fireEvent.click( screen.getByRole( 'button', { name: 'Add new' } ) );
		expect( onClick ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'clears the actions when the registering component unmounts', () => {
		const StableHarness = () => {
			const actions = useMemo( () => [ { type: 'primary', label: 'Add new' } ], [] );
			return <Harness actions={ actions } />;
		};
		const ConditionalHarness = ( { mounted } ) => ( mounted ? <StableHarness /> : null );

		const { rerender } = render( withProvider( <ConditionalHarness mounted /> ) );
		expect( screen.getByRole( 'button', { name: 'Add new' } ) ).toBeInTheDocument();

		rerender( withProvider( <ConditionalHarness mounted={ false } /> ) );
		expect( screen.queryByRole( 'button', { name: 'Add new' } ) ).not.toBeInTheDocument();
	} );

	it( 'restores a still-mounted earlier registration when an overlapping later one unmounts', () => {
		// Regression for the previous "single action slot" implementation: a
		// nested/transitioning second screen used to wipe the active actions
		// on unmount because cleanup unconditionally cleared the context.
		// The owner-keyed registry should now restore ScreenA's actions when
		// ScreenB unmounts.
		const ScreenA = () => {
			const actions = useMemo( () => [ { type: 'primary', label: 'Action A' } ], [] );
			useHeaderActions( actions );
			return null;
		};
		const ScreenB = () => {
			const actions = useMemo( () => [ { type: 'primary', label: 'Action B' } ], [] );
			useHeaderActions( actions );
			return null;
		};

		const Switcher = ( { showB } ) => (
			<>
				<ScreenA />
				{ showB && <ScreenB /> }
			</>
		);

		const { rerender } = render( withProvider( <Switcher showB /> ) );
		expect( screen.getByRole( 'button', { name: 'Action B' } ) ).toBeInTheDocument();

		// B unmounts; A is still mounted and should be visible again.
		rerender( withProvider( <Switcher showB={ false } /> ) );
		expect( screen.getByRole( 'button', { name: 'Action A' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Action B' } ) ).not.toBeInTheDocument();
	} );

	it( 'lets the latest registering component own the action set', () => {
		const ScreenA = () => {
			const actions = useMemo( () => [ { type: 'primary', label: 'Action A' } ], [] );
			useHeaderActions( actions );
			return null;
		};
		const ScreenB = () => {
			const actions = useMemo( () => [ { type: 'primary', label: 'Action B' } ], [] );
			useHeaderActions( actions );
			return null;
		};

		const Switcher = ( { showB } ) => (
			<>
				<ScreenA />
				{ showB && <ScreenB /> }
			</>
		);

		const { rerender } = render( withProvider( <Switcher showB={ false } /> ) );
		expect( screen.getByRole( 'button', { name: 'Action A' } ) ).toBeInTheDocument();

		rerender( withProvider( <Switcher showB /> ) );
		// Last writer wins — matches newspack-plugin's setHeaderData semantics.
		expect( screen.getByRole( 'button', { name: 'Action B' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Action A' } ) ).not.toBeInTheDocument();
	} );

	it( 'is a no-op when used outside a provider', () => {
		// Screens may be rendered outside the chassis (e.g. unit tests).
		// The hook should not throw; no actions should render.
		const actions = [ { type: 'primary', label: 'Add new' } ];
		expect( () => {
			render( <Harness actions={ actions } /> );
		} ).not.toThrow();
	} );

	it( 'propagates the latest onClick closure when only the handler reference changes', () => {
		// Regression for the previous serialise-actions strategy that ignored
		// function identity and could leave stale closures wired up.
		const callOrder = [];
		const StableHarness = ( { tag } ) => {
			// `actions` deliberately depends on `tag` — caller must memoise
			// with the closure-captured value listed in deps for updates to
			// reach the chassis.
			const actions = useMemo(
				() => [
					{
						type: 'primary',
						label: 'Add new',
						onClick: () => callOrder.push( tag ),
					},
				],
				[ tag ]
			);
			return <Harness actions={ actions } />;
		};

		const { rerender } = render( withProvider( <StableHarness tag="first" /> ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Add new' } ) );

		rerender( withProvider( <StableHarness tag="second" /> ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Add new' } ) );

		expect( callOrder ).toEqual( [ 'first', 'second' ] );
	} );

	it( "portals into newspack-plugin's admin-header strip when present", () => {
		// Stand up the same DOM newspack-plugin renders so the portal slot exists.
		const wrapper = document.createElement( 'div' );
		wrapper.id = 'newspack-wizards-admin-header';
		wrapper.innerHTML =
			'<div class="newspack-wizard__header"><div class="newspack-wizard__header__inner"><div class="newspack-wizard__title"><h2>Test</h2></div></div></div>';
		document.body.appendChild( wrapper );

		const actions = [ { type: 'primary', label: 'Add new newsletter' } ];
		render( withProvider( <Harness actions={ actions } /> ) );

		const portalled = wrapper.querySelector( '.newspack-newsletters-admin__header-actions--in-newspack-header' );
		expect( portalled ).not.toBeNull();
		expect( portalled.querySelector( 'button' ) ).toHaveTextContent( 'Add new newsletter' );
	} );
} );
