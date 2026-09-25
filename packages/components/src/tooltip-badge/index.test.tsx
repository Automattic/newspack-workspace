/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import TooltipBadge from './';

describe( 'TooltipBadge', () => {
	it( 'describes its focusable trigger with the tooltip text, keeping it out of a surrounding heading name', () => {
		render(
			<h3>
				Registration wall
				<TooltipBadge label="Overrides paid access" tooltip="This grants registered readers access." intent="low" />
			</h3>
		);

		expect( screen.getByRole( 'heading', { name: 'Registration wall Overrides paid access' } ) ).toBeInTheDocument();
		const trigger = screen.getByText( 'Overrides paid access' ).closest( '[tabindex="0"]' );
		expect( trigger ).toHaveAccessibleDescription( 'This grants registered readers access.' );
	} );
} );
