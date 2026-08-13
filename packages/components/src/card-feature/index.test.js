/**
 * External dependencies.
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies.
 */
import CardFeature from './';

describe( 'CardFeature', () => {
	it( 'renders the title as a level-2 heading and the description alongside it', () => {
		render( <CardFeature title="Content gifting" description="Let subscribers share gated articles." /> );
		const heading = screen.getByRole( 'heading', { level: 2 } );
		expect( heading ).toHaveTextContent( 'Content gifting' );
		expect( screen.getByText( 'Let subscribers share gated articles.' ) ).toBeInTheDocument();
	} );

	it( 'omits the description paragraph when none is passed', () => {
		const { container } = render( <CardFeature title="Content gifting" /> );
		expect( container.querySelector( '.newspack-card-feature__description' ) ).toBeNull();
	} );

	it( 'labels the primary button Enable when disabled and Configure when enabled', () => {
		const { rerender } = render( <CardFeature title="Content gifting" /> );
		expect( screen.getByRole( 'button', { name: 'Enable' } ) ).toBeInTheDocument();
		rerender( <CardFeature title="Content gifting" enabled /> );
		expect( screen.getByRole( 'button', { name: 'Configure' } ) ).toBeInTheDocument();
	} );

	it( 'shows the enabled badge only once enabled', () => {
		const { rerender } = render( <CardFeature title="Content gifting" /> );
		expect( screen.queryByText( 'Enabled' ) ).not.toBeInTheDocument();
		rerender( <CardFeature title="Content gifting" enabled /> );
		expect( screen.getByText( 'Enabled' ) ).toBeInTheDocument();
	} );

	it( 'shows the requirements text as the badge and disables the button', () => {
		render( <CardFeature title="Metered countdown" requirements="Requires metering" /> );
		expect( screen.getByText( 'Requires metering' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Enable' } ) ).toBeDisabled();
	} );

	it( 'keeps the button clickable when the requirement is actionable', () => {
		render( <CardFeature title="Metered countdown" requirements="Requires metering" requirementsActionable /> );
		expect( screen.getByRole( 'button', { name: 'Enable' } ) ).toBeEnabled();
	} );

	it( 'renders a ready icon element as-is and an icon descriptor in its own container', () => {
		const { container, rerender } = render( <CardFeature title="Content gifting" icon={ <span data-testid="ready-icon" /> } /> );
		expect( screen.getByTestId( 'ready-icon' ) ).toBeInTheDocument();
		expect( container.querySelector( '.newspack-card-feature__icon' ) ).toBeNull();

		rerender(
			<CardFeature
				title="Content gifting"
				icon={ { node: <span data-testid="descriptor-icon" />, backgroundColor: '#dfe7f4', radius: 'full' } }
			/>
		);
		const iconContainer = container.querySelector( '.newspack-card-feature__icon' );
		expect( iconContainer ).toHaveClass( 'newspack-card-feature__icon--radius-full' );
		expect( screen.getByTestId( 'descriptor-icon' ) ).toBeInTheDocument();
	} );

	it( 'marks the card as muted only when requirements are set', () => {
		const { container, rerender } = render( <CardFeature title="Content gifting" enabled /> );
		expect( container.querySelector( '.newspack-card-feature--muted' ) ).toBeNull();
		rerender( <CardFeature title="Content gifting" enabled requirements="Requires metering" /> );
		expect( container.querySelector( '.newspack-card-feature--muted' ) ).toBeInTheDocument();
	} );
} );
