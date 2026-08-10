/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { CardFeature } from '../../../../../packages/components/src';

// packages/components has no test runner and is outside the `--filter newspack`
// suite, so CardFeature's More-menu gating is exercised here against the real
// component. Do NOT mock @wordpress/data — mocking it breaks the real
// @wordpress/components barrel (its @wordpress/rich-text dependency runs
// combineReducers() at module load).

const defaultControls = [ { title: 'Disable', onClick: jest.fn() } ];

const renderCard = props => render( <CardFeature title="Newsletter ESP" moreControls={ defaultControls } { ...props } /> );

const moreMenu = () => screen.queryByRole( 'button', { name: 'More' } );

describe( 'CardFeature More menu gating', () => {
	it( 'shows the More menu when enabled with no requirements', () => {
		renderCard( { enabled: true } );
		expect( moreMenu() ).toBeInTheDocument();
	} );

	it( 'shows the More menu when enabled with an actionable requirement', () => {
		renderCard( { enabled: true, requirements: 'Requires an API-based ESP', requirementsActionable: true } );
		expect( moreMenu() ).toBeInTheDocument();
	} );

	it( 'hides the More menu when the requirement is not actionable (locked)', () => {
		renderCard( { enabled: true, requirements: 'Managed by site configuration', requirementsActionable: false } );
		expect( moreMenu() ).not.toBeInTheDocument();
	} );

	it( 'hides the More menu when not enabled', () => {
		renderCard( { enabled: false } );
		expect( moreMenu() ).not.toBeInTheDocument();
	} );

	it( 'hides the More menu when not enabled even with an actionable requirement', () => {
		renderCard( { enabled: false, requirements: 'Requires an API-based ESP', requirementsActionable: true } );
		expect( moreMenu() ).not.toBeInTheDocument();
	} );

	it( 'hides the More menu when there are no controls', () => {
		renderCard( { enabled: true, moreControls: [] } );
		expect( moreMenu() ).not.toBeInTheDocument();
	} );
} );
