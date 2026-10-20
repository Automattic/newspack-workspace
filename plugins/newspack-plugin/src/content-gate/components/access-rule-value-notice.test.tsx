/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies.
 */
import AccessRuleValueNotice from './access-rule-value-notice';

const renderNotice = ( notice?: string ) => (
	<AccessRuleValueNotice label="Reader data" notice={ notice }>
		<input aria-label="Value" />
	</AccessRuleValueNotice>
);

describe( 'AccessRuleValueNotice', () => {
	it( 'keeps the wrapped field mounted and focused when a notice appears and goes', () => {
		const { rerender } = render( renderNotice() );
		const input = screen.getByRole( 'textbox' );
		input.focus();

		rerender( renderNotice( 'This rule matches no reader.' ) );
		expect( screen.getByRole( 'textbox' ) ).toBe( input );
		expect( input ).toHaveFocus();
		expect( screen.getByRole( 'group' ) ).toHaveAttribute( 'aria-describedby', screen.getByRole( 'note' ).id );

		rerender( renderNotice() );
		expect( screen.getByRole( 'textbox' ) ).toBe( input );
		expect( input ).toHaveFocus();
		expect( screen.getByRole( 'group' ) ).not.toHaveAttribute( 'aria-describedby' );
		expect( screen.queryByRole( 'note' ) ).not.toBeInTheDocument();
	} );
} );
