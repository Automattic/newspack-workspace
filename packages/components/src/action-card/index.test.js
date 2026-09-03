/**
 * External dependencies.
 */
import { render, screen } from '@testing-library/react';

/**
 * WordPress dependencies.
 */
import { speak } from '@wordpress/a11y';
import { useState } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import ActionCard from './index';

jest.mock( '@wordpress/a11y', () => ( {
	...jest.requireActual( '@wordpress/a11y' ),
	speak: jest.fn(),
} ) );

/**
 * The library Badge styles its wrapper rather than its text, so a badge with no label
 * paints a bare coloured pill. Callers build the `badges` array from data that can be
 * absent — a payment gateway reports no connection status until it is enabled, a prompt
 * can sit on a placement with no entry in the placement map — and an array literal is
 * always non-empty, so the component has to drop those itself.
 */
describe( 'ActionCard badges', () => {
	const badgeText = () => screen.queryAllByText( ( _, node ) => /__badge/.test( node?.className || '' ) );

	it( 'renders a badge that has a label', () => {
		render( <ActionCard title="Stripe" badges={ [ { label: 'Connected', intent: 'stable' } ] } /> );

		expect( screen.getByText( 'Connected' ) ).toBeInTheDocument();
	} );

	it( 'renders no badge when the label is null', () => {
		const { container } = render( <ActionCard title="Stripe" badges={ [ { label: null, intent: 'informational' } ] } /> );

		expect( container.querySelectorAll( '[class*="__badge"]' ) ).toHaveLength( 0 );
	} );

	it( 'renders no badge when the label is undefined or empty', () => {
		const { container } = render( <ActionCard title="Stripe" badges={ [ { label: undefined }, { label: '' } ] } /> );

		expect( container.querySelectorAll( '[class*="__badge"]' ) ).toHaveLength( 0 );
	} );

	it( 'keeps the labelled badges and drops only the empty ones', () => {
		render( <ActionCard title="Plans" badges={ [ { label: 'Premium' }, { label: null }, { label: 'Archived', intent: 'draft' } ] } /> );

		expect( screen.getByText( 'Premium' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Archived' ) ).toBeInTheDocument();
		expect( badgeText() ).toHaveLength( 2 );
	} );

	it( 'renders nothing for an absent or empty badges prop', () => {
		const { container: withoutProp } = render( <ActionCard title="Stripe" /> );
		expect( withoutProp.querySelectorAll( '[class*="__badge"]' ) ).toHaveLength( 0 );

		const { container: withEmptyArray } = render( <ActionCard title="Stripe" badges={ [] } /> );
		expect( withEmptyArray.querySelectorAll( '[class*="__badge"]' ) ).toHaveLength( 0 );
		// An empty array used to fall through as a literal `0` in the heading.
		expect( withEmptyArray.textContent ).not.toContain( '0' );
	} );
} );

/**
 * ActionCard derives the notice's spoken message rather than letting core default it to
 * the children. Core runs `renderToString` over that default during render, which corrupts
 * the hook dispatcher when the children are components — so the derivation is what keeps a
 * notification carrying a `<Button>` from crashing the card on a later re-render.
 */
describe( 'ActionCard notifications', () => {
	beforeEach( () => {
		speak.mockClear();
	} );

	const spoken = () => speak.mock.calls.map( call => call[ 0 ] );

	it( 'renders a notice for a recognised level', () => {
		const { container } = render( <ActionCard title="Ad Manager" notification="Plugin cannot be installed" notificationLevel="error" /> );

		expect( container.querySelector( '.components-notice.is-error' ) ).toBeInTheDocument();
	} );

	it( 'renders no notice for an unrecognised or absent level', () => {
		const { container: unknown } = render( <ActionCard title="Ad Manager" notification="Something happened" notificationLevel="critical" /> );
		expect( unknown.querySelector( '.components-notice' ) ).not.toBeInTheDocument();

		const { container: absent } = render( <ActionCard title="Ad Manager" notification="Something happened" /> );
		expect( absent.querySelector( '.components-notice' ) ).not.toBeInTheDocument();
	} );

	it( 'announces the text inside an element-only notification', () => {
		render(
			<ActionCard
				title="Ad Manager"
				notificationLevel="error"
				notification={ [ <button key="connect">Click here to connect your account.</button> ] }
			/>
		);

		expect( spoken() ).toContain( 'Click here to connect your account.' );
	} );

	it( 'announces strings and element text together', () => {
		render(
			<ActionCard
				title="Ad Manager"
				notificationLevel="success"
				notification={ [
					'Created custom targeting keys: ',
					// eslint-disable-next-line react/jsx-indent
					<a key="dashboard" href="https://example.org">
						Visit your GAM dashboard
					</a>,
				] }
			/>
		);

		expect( spoken() ).toContain( 'Created custom targeting keys: Visit your GAM dashboard' );
	} );

	it( 'announces an Error and renders its message rather than throwing', () => {
		render( <ActionCard title="Ad Manager" notificationLevel="error" notification={ new Error( 'Network unreachable' ) } /> );

		expect( screen.getByText( 'Network unreachable' ) ).toBeInTheDocument();
		expect( spoken() ).toContain( 'Network unreachable' );
	} );

	it( 'hands HTML notifications to speak() with their tags intact, for speak() to strip', () => {
		const { container } = render(
			<ActionCard title="Ad Manager" notificationLevel="error" notification="<p>Install failed.</p><p>Try again.</p>" notificationHTML />
		);

		expect( container.querySelectorAll( '.newspack-action-card__notification-html > p' ) ).toHaveLength( 2 );
		expect( spoken()[ 0 ] ).toContain( '<p>Install failed.</p>' );
	} );

	it( 'decodes entities so the announcement matches the visible text', () => {
		render(
			<ActionCard
				title="Ad Manager"
				notificationLevel="error"
				notification="Plugin &#8220;Foo&#8221; could not be activated"
				notificationHTML
			/>
		);

		expect( spoken()[ 0 ] ).toContain( '“Foo”' );
	} );

	it( 'renders a non-string notification as children even when notificationHTML is set', () => {
		const { container } = render(
			<ActionCard title="Ad Manager" notificationLevel="error" notification={ <span>Network unreachable</span> } notificationHTML />
		);

		expect( screen.getByText( 'Network unreachable' ) ).toBeInTheDocument();
		expect( container.querySelector( '.newspack-action-card__notification-html' ) ).not.toBeInTheDocument();
	} );

	it( 'keeps adjacent element texts apart in the announcement', () => {
		render(
			<ActionCard
				title="Ad Manager"
				notificationLevel="error"
				notification={ [
					// eslint-disable-next-line react/jsx-indent
					<a key="dashboard" href="https://example.org">
						Visit your GAM dashboard
					</a>,
					<button key="connect" type="button">
						Click here to connect your account.
					</button>,
				] }
			/>
		);

		expect( spoken()[ 0 ] ).toBe( 'Visit your GAM dashboard Click here to connect your account.' );
	} );

	it( 'survives a re-render that changes how many hooked components the notification holds', () => {
		const HookedAction = ( { children } ) => {
			const [ label ] = useState( children );
			return <button type="button">{ label }</button>;
		};
		const card = notification => <ActionCard title="Ad Manager" notificationLevel="error" notification={ notification } />;

		const { rerender, container } = render(
			card( [ <HookedAction key="retry">Retry</HookedAction>, <HookedAction key="docs">Documentation</HookedAction> ] )
		);

		expect( () => rerender( card( [ <HookedAction key="retry">Retry</HookedAction> ] ) ) ).not.toThrow();
		expect( container.querySelector( '.components-notice.is-error' ) ).toBeInTheDocument();
	} );
} );
