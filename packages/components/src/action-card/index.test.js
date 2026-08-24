/**
 * External dependencies.
 */
import { render, screen } from '@testing-library/react';

/**
 * WordPress dependencies.
 */
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import ActionCard from './index';

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
 * Core derives a notice's announcement with `renderToString` in the render body, so a
 * non-string `spokenMessage` is serialised mid-render: any hooks its children hold are
 * recorded on the notice's own fiber, and the next render throws once their composition
 * changes. `notification` is a ReactNode, so the component has to resolve the string
 * itself rather than forward what it was handed.
 */
describe( 'ActionCard notification announcements', () => {
	const announced = () => document.getElementById( 'a11y-speak-assertive' )?.textContent || '';
	const notice = () => document.querySelector( '.components-notice__content' )?.textContent || '';
	const Hooky = () => {
		const [ label ] = useState( 'Connect your account' );
		useEffect( () => {}, [] );
		return <a href="#connect">{ label }</a>;
	};

	// Clear the regions rather than the body: `speak()` resolves them by id and drops
	// the message when they are absent, so wiping the body would silence every case.
	beforeEach( () => {
		[ 'a11y-speak-assertive', 'a11y-speak-polite' ].forEach( id => {
			const region = document.getElementById( id );
			if ( region ) {
				region.textContent = '';
			}
		} );
	} );

	it( 'announces a plain-string notification', () => {
		render( <ActionCard title="GAM" notification="No credentials provided." notificationLevel="error" /> );

		expect( notice() ).toContain( 'No credentials provided.' );
		expect( announced() ).toContain( 'No credentials provided.' );
	} );

	it( 'renders a notification holding a component without announcing it', () => {
		render( <ActionCard title="GAM" notification={ [ 'No credentials provided. ', <Hooky key="connect" /> ] } notificationLevel="error" /> );

		expect( notice() ).toContain( 'Connect your account' );
		// The control's label must not be read out as part of the error.
		expect( announced() ).toBe( '' );
	} );

	it( 'survives a change to the composition of a notification holding a component', () => {
		const Wrapper = ( { withLink } ) => (
			<ActionCard
				title="GAM"
				notification={ withLink ? [ 'No credentials provided. ', <Hooky key="connect" /> ] : [ 'No credentials provided. ' ] }
				notificationLevel="error"
			/>
		);

		const { rerender } = render( <Wrapper withLink /> );
		// Dropping the element used to change the hook count on core's Notice mid-render.
		expect( () => rerender( <Wrapper withLink={ false } /> ) ).not.toThrow();
		expect( notice() ).toContain( 'No credentials provided.' );
	} );

	it( 'announces an explicit spoken message for a notification that is not plain text', () => {
		render(
			<ActionCard
				title="GAM"
				notification={ [ 'Created custom targeting keys: a, b. ', <Hooky key="connect" /> ] }
				notificationLevel="success"
				notificationSpokenMessage="Created custom targeting keys: a, b"
			/>
		);

		expect( notice() ).toContain( 'Created custom targeting keys: a, b.' );
		expect( document.getElementById( 'a11y-speak-polite' )?.textContent ).toContain( 'Created custom targeting keys: a, b' );
	} );

	it( 'keeps an info notification silent', () => {
		render( <ActionCard title="GAM" notification="Currently operating in legacy mode." notificationLevel="info" /> );

		expect( notice() ).toContain( 'Currently operating in legacy mode.' );
		expect( announced() ).toBe( '' );
	} );
} );
