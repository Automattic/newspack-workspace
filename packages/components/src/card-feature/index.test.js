/**
 * External dependencies.
 */
import { render, screen } from '@testing-library/react';

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

describe( 'CardFeature', () => {
	beforeEach( () => {
		_x.mockClear();
	} );

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
