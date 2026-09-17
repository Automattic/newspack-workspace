/**
 * External dependencies.
 */
import { render, screen } from '@testing-library/react';

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
