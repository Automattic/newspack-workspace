/**
 * External dependencies.
 */
import { fireEvent, render, screen } from '@testing-library/react';

/**
 * Internal dependencies.
 */
import CardFeature from './';

const primaryButton = () => screen.getByRole( 'button', { name: /Enable|Configure/ } );
const moreMenu = () => screen.queryByRole( 'button', { name: 'More' } );

describe( 'CardFeature', () => {
	describe( 'structure', () => {
		it( 'renders the title as a level-2 heading and the description alongside it', () => {
			render( <CardFeature title="Content gifting" description="Let subscribers share gated articles." /> );
			expect( screen.getByRole( 'heading', { level: 2 } ) ).toHaveTextContent( 'Content gifting' );
			expect( screen.getByText( 'Let subscribers share gated articles.' ) ).toBeInTheDocument();
		} );

		it( 'omits the description paragraph when none is passed', () => {
			const { container } = render( <CardFeature title="Content gifting" /> );
			expect( container.querySelector( '.newspack-card-feature__description' ) ).toBeNull();
		} );

		it( 'keeps the action row a sibling of the header rather than nesting it', () => {
			const { container } = render( <CardFeature title="Content gifting" /> );
			const card = container.querySelector( '.newspack-card-feature' );
			const actions = container.querySelector( '.newspack-card-feature__actions' );
			expect( actions.parentElement ).toBe( card );
			expect( actions ).not.toContainElement( screen.getByRole( 'heading', { level: 2 } ) );
			expect( actions ).toContainElement( primaryButton() );
		} );
	} );

	describe( 'primary button', () => {
		it( 'reads Enable and calls onEnable when the feature is off', () => {
			const onEnable = jest.fn();
			const onConfigure = jest.fn();
			render( <CardFeature title="Content gifting" onEnable={ onEnable } onConfigure={ onConfigure } /> );
			expect( primaryButton() ).toHaveTextContent( 'Enable' );
			fireEvent.click( primaryButton() );
			expect( onEnable ).toHaveBeenCalledTimes( 1 );
			expect( onConfigure ).not.toHaveBeenCalled();
		} );

		it( 'reads Configure and calls onConfigure once enabled', () => {
			const onEnable = jest.fn();
			const onConfigure = jest.fn();
			render( <CardFeature title="Content gifting" enabled onEnable={ onEnable } onConfigure={ onConfigure } /> );
			expect( primaryButton() ).toHaveTextContent( 'Configure' );
			fireEvent.click( primaryButton() );
			expect( onConfigure ).toHaveBeenCalledTimes( 1 );
			expect( onEnable ).not.toHaveBeenCalled();
		} );

		it( 'still routes to onEnable when enabled with an unmet requirement, since the button reads Enable', () => {
			const onEnable = jest.fn();
			const onConfigure = jest.fn();
			render(
				<CardFeature
					title="Content gifting"
					enabled
					requirements="Requires metering"
					requirementsActionable
					onEnable={ onEnable }
					onConfigure={ onConfigure }
				/>
			);
			expect( primaryButton() ).toHaveTextContent( 'Enable' );
			fireEvent.click( primaryButton() );
			expect( onEnable ).toHaveBeenCalledTimes( 1 );
			expect( onConfigure ).not.toHaveBeenCalled();
		} );

		it( 'does not fire when a requirement is not actionable', () => {
			const onEnable = jest.fn();
			render( <CardFeature title="Content gifting" requirements="Managed by site configuration" onEnable={ onEnable } /> );
			expect( primaryButton() ).toBeDisabled();
			fireEvent.click( primaryButton() );
			expect( onEnable ).not.toHaveBeenCalled();
		} );

		it( 'accepts custom labels for both states', () => {
			const { rerender } = render( <CardFeature title="Apple News" enableLabel="Connect" configureLabel="Manage connection" /> );
			expect( screen.getByRole( 'button', { name: 'Connect' } ) ).toBeInTheDocument();
			rerender( <CardFeature title="Apple News" enabled enableLabel="Connect" configureLabel="Manage connection" /> );
			expect( screen.getByRole( 'button', { name: 'Manage connection' } ) ).toBeInTheDocument();
		} );
	} );

	describe( 'badge', () => {
		it( 'shows nothing when off, and the enabled badge when on', () => {
			const { rerender } = render( <CardFeature title="Content gifting" /> );
			expect( screen.queryByText( 'Enabled' ) ).not.toBeInTheDocument();
			rerender( <CardFeature title="Content gifting" enabled /> );
			expect( screen.getByText( 'Enabled' ) ).toBeInTheDocument();
		} );

		it( 'lets the requirements badge win over the enabled badge', () => {
			render( <CardFeature title="Content gifting" enabled requirements="Requires metering" /> );
			expect( screen.getByText( 'Requires metering' ) ).toBeInTheDocument();
			expect( screen.queryByText( 'Enabled' ) ).not.toBeInTheDocument();
		} );

		it( 'accepts custom badge text', () => {
			render( <CardFeature title="Stripe" enabled badgeText="Live mode" badgeLevel="info" /> );
			expect( screen.getByText( 'Live mode' ) ).toBeInTheDocument();
		} );
	} );

	describe( 'More menu', () => {
		const controls = [ { title: 'Disable', onClick: jest.fn() } ];

		it( 'appears only when enabled and controls are supplied', () => {
			const { rerender } = render( <CardFeature title="Content gifting" moreControls={ controls } /> );
			expect( moreMenu() ).not.toBeInTheDocument();
			rerender( <CardFeature title="Content gifting" enabled /> );
			expect( moreMenu() ).not.toBeInTheDocument();
			rerender( <CardFeature title="Content gifting" enabled moreControls={ controls } /> );
			expect( moreMenu() ).toBeInTheDocument();
		} );

		it( 'stays available on an actionable requirement and hides on a locked one', () => {
			const { rerender } = render(
				<CardFeature title="Content gifting" enabled requirements="Requires metering" requirementsActionable moreControls={ controls } />
			);
			expect( moreMenu() ).toBeInTheDocument();
			rerender( <CardFeature title="Content gifting" enabled requirements="Managed by site configuration" moreControls={ controls } /> );
			expect( moreMenu() ).not.toBeInTheDocument();
		} );
	} );

	describe( 'icon', () => {
		it( 'renders a ready element as-is, without the descriptor container', () => {
			const { container } = render( <CardFeature title="Content gifting" icon={ <span data-testid="ready-icon" /> } /> );
			expect( screen.getByTestId( 'ready-icon' ) ).toBeInTheDocument();
			expect( container.querySelector( '.newspack-card-feature__icon' ) ).toBeNull();
		} );

		it( 'applies the descriptor colours inline and rounds fully on request', () => {
			const { container } = render(
				<CardFeature
					title="Content gifting"
					icon={ { node: <span data-testid="descriptor-icon" />, fill: '#003da5', backgroundColor: '#dfe7f4', radius: 'full' } }
				/>
			);
			const iconContainer = container.querySelector( '.newspack-card-feature__icon' );
			expect( screen.getByTestId( 'descriptor-icon' ) ).toBeInTheDocument();
			expect( iconContainer ).toHaveClass( 'newspack-card-feature__icon--radius-full' );
			expect( iconContainer ).toHaveStyle( { backgroundColor: '#dfe7f4', color: '#003da5' } );
		} );

		it( 'falls back to small corners when a background is set without a radius', () => {
			const { container } = render( <CardFeature title="Content gifting" icon={ { node: <span />, backgroundColor: '#dfe7f4' } } /> );
			const iconContainer = container.querySelector( '.newspack-card-feature__icon' );
			expect( iconContainer ).toHaveClass( 'newspack-card-feature__icon--radius-small' );
			expect( iconContainer ).not.toHaveClass( 'newspack-card-feature__icon--radius-full' );
		} );

		it( 'leaves an unbacked descriptor icon without a radius class', () => {
			const { container } = render( <CardFeature title="Content gifting" icon={ { node: <span />, fill: '#003da5' } } /> );
			const iconContainer = container.querySelector( '.newspack-card-feature__icon' );
			expect( iconContainer ).not.toHaveClass( 'newspack-card-feature__icon--radius-small' );
		} );
	} );

	it( 'marks the card as muted only when requirements are set', () => {
		const { container, rerender } = render( <CardFeature title="Content gifting" enabled /> );
		expect( container.querySelector( '.newspack-card-feature--muted' ) ).toBeNull();
		rerender( <CardFeature title="Content gifting" enabled requirements="Requires metering" /> );
		expect( container.querySelector( '.newspack-card-feature--muted' ) ).toBeInTheDocument();
	} );
} );
