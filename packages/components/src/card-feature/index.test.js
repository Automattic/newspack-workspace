/**
 * External dependencies.
 */
import { fireEvent, render, screen } from '@testing-library/react';

/**
 * WordPress dependencies.
 */
import { _x } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import CardFeature from './index';

// The spy lives in the factory: @wordpress/components calls _x while it loads,
// before a module-scope const would be initialized.
jest.mock( '@wordpress/i18n', () => {
	const actual = jest.requireActual( '@wordpress/i18n' );
	return { ...actual, _x: jest.fn( text => text ) };
} );

const primaryButton = () => screen.getByRole( 'button', { name: /Enable|Configure/ } );
const moreMenu = () => screen.queryByRole( 'button', { name: /^More options for/ } );

describe( 'CardFeature', () => {
	beforeEach( () => {
		_x.mockClear();
	} );

	describe( 'structure', () => {
		it( 'renders the title as a level-3 heading and the description alongside it', () => {
			render( <CardFeature title="Content gifting" description="Let subscribers share gated articles." /> );
			expect( screen.getByRole( 'heading', { level: 3 } ) ).toHaveTextContent( 'Content gifting' );
			expect( screen.getByText( 'Let subscribers share gated articles.' ) ).toBeInTheDocument();
		} );

		it( 'renders the title at the requested heading level', () => {
			render( <CardFeature title="Content gifting" headingLevel={ 4 } /> );
			expect( screen.getByRole( 'heading', { level: 4 } ) ).toHaveTextContent( 'Content gifting' );
			expect( screen.queryByRole( 'heading', { level: 3 } ) ).not.toBeInTheDocument();
		} );

		it( 'omits the description paragraph when none is passed', () => {
			const { container } = render( <CardFeature title="Content gifting" /> );
			expect( container.querySelector( '.newspack-card-feature__description' ) ).toBeNull();
		} );
	} );

	describe( 'accessible names', () => {
		it( 'names the feature in the primary button, keeping the visible label first', () => {
			render( <CardFeature title="Metered Countdown" /> );
			const button = screen.getByRole( 'button', { name: 'Enable Metered Countdown' } );
			expect( button ).toHaveTextContent( 'Enable' );
			expect( button.getAttribute( 'aria-label' ).startsWith( button.textContent ) ).toBe( true );
		} );

		it( 'carries a custom label into the accessible name', () => {
			render( <CardFeature title="Subscription retention" enableLabel="Change" /> );
			expect( screen.getByRole( 'button', { name: 'Change Subscription retention' } ) ).toHaveTextContent( 'Change' );
		} );

		it( 'names the feature in the configure state too', () => {
			render( <CardFeature title="Content Gifting" enabled /> );
			expect( screen.getByRole( 'button', { name: 'Configure Content Gifting' } ) ).toBeInTheDocument();
		} );

		it( 'names the feature in the More menu', () => {
			render( <CardFeature title="Content Gifting" enabled moreControls={ [ { title: 'Disable', onClick: () => {} } ] } /> );
			expect( screen.getByRole( 'button', { name: 'More options for Content Gifting' } ) ).toBeInTheDocument();
		} );

		it( 'gives the accessible-name order its own catalogue entry', () => {
			render( <CardFeature title="Metered Countdown" /> );
			expect( _x ).toHaveBeenCalledWith( '%1$s %2$s', 'accessible button name: visible action label, then feature name', 'newspack-plugin' );
		} );

		it( 'distinguishes two cards that share a button label', () => {
			render(
				<>
					<CardFeature title="Metered Countdown" />
					<CardFeature title="Content Gifting" />
				</>
			);
			expect( screen.getByRole( 'button', { name: 'Enable Metered Countdown' } ) ).toBeInTheDocument();
			expect( screen.getByRole( 'button', { name: 'Enable Content Gifting' } ) ).toBeInTheDocument();
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

		it( 'keeps the button live on an actionable requirement before the feature is on, which is how Activate reaches the user', () => {
			const onEnable = jest.fn();
			render(
				<CardFeature
					title="Mailchimp"
					requirements="Requires WooCommerce"
					requirementsActionable
					enableLabel="Activate"
					onEnable={ onEnable }
				/>
			);
			const button = screen.getByRole( 'button', { name: 'Activate Mailchimp' } );
			expect( button ).not.toHaveAttribute( 'aria-disabled' );
			fireEvent.click( button );
			expect( onEnable ).toHaveBeenCalledTimes( 1 );
			expect( screen.getByText( 'Requires WooCommerce' ) ).toBeInTheDocument();
		} );

		it( 'does not fire when a requirement is not actionable, but stays reachable', () => {
			const onEnable = jest.fn();
			render( <CardFeature title="Content gifting" requirements="Managed by site configuration" onEnable={ onEnable } /> );
			expect( primaryButton() ).toHaveAttribute( 'aria-disabled', 'true' );
			expect( primaryButton() ).not.toHaveAttribute( 'disabled' );
			fireEvent.click( primaryButton() );
			expect( onEnable ).not.toHaveBeenCalled();
		} );

		it( 'points the blocked button at the badge that explains why', () => {
			render( <CardFeature title="Content gifting" requirements="Managed by site configuration" /> );
			const describedBy = primaryButton().getAttribute( 'aria-describedby' );
			expect( describedBy ).toBeTruthy();
			expect( document.getElementById( describedBy ) ).toHaveTextContent( 'Managed by site configuration' );
		} );

		it( 'leaves the enabled badge unlinked, since it explains nothing about the button', () => {
			render( <CardFeature title="Content gifting" enabled /> );
			expect( primaryButton() ).not.toHaveAttribute( 'aria-describedby' );
		} );

		it( 'disables the button while an action is in flight, even with an actionable requirement', () => {
			const onEnable = jest.fn();
			const { rerender } = render( <CardFeature title="Content gifting" busy onEnable={ onEnable } /> );
			expect( primaryButton() ).toHaveAttribute( 'aria-disabled', 'true' );
			fireEvent.click( primaryButton() );
			expect( onEnable ).not.toHaveBeenCalled();

			rerender( <CardFeature title="Content gifting" busy requirements="Requires metering" requirementsActionable onEnable={ onEnable } /> );
			expect( primaryButton() ).toHaveAttribute( 'aria-disabled', 'true' );
		} );

		it( 'accepts custom labels for both states', () => {
			const { rerender } = render( <CardFeature title="Apple News" enableLabel="Connect" configureLabel="Manage connection" /> );
			expect( screen.getByRole( 'button', { name: 'Connect Apple News' } ) ).toBeInTheDocument();
			rerender( <CardFeature title="Apple News" enabled enableLabel="Connect" configureLabel="Manage connection" /> );
			expect( screen.getByRole( 'button', { name: 'Manage connection Apple News' } ) ).toBeInTheDocument();
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
			render( <CardFeature title="Stripe" enabled badge={ { label: 'Live mode', intent: 'informational' } } /> );
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

		it( 'hides the descriptor container from assistive tech, since the title already names the feature', () => {
			const { container } = render( <CardFeature title="Content gifting" icon={ { node: <svg /> } } /> );
			expect( container.querySelector( '.newspack-card-feature__icon' ) ).toHaveAttribute( 'aria-hidden', 'true' );
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

	it( 'passes className through to the card element', () => {
		const { container } = render( <CardFeature title="Content gifting" className="newspack-subscribers__card" /> );
		expect( container.querySelector( '.newspack-card-feature' ) ).toHaveClass( 'newspack-subscribers__card' );
	} );

	it( 'marks the card as muted only when requirements are set', () => {
		const { container, rerender } = render( <CardFeature title="Content gifting" enabled /> );
		expect( container.querySelector( '.newspack-card-feature--muted' ) ).toBeNull();
		rerender( <CardFeature title="Content gifting" enabled requirements="Requires metering" /> );
		expect( container.querySelector( '.newspack-card-feature--muted' ) ).toBeInTheDocument();
	} );
} );
