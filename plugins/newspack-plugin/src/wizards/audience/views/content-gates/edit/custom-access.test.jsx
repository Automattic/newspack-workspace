/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * Internal dependencies
 */
import CustomAccess from './custom-access';

jest.mock( './metering', () => () => null );
jest.mock( './access-rules', () => ( { onChange } ) => (
	<button data-testid="set-rules" onClick={ () => onChange( [ { name: 'active_subscription' } ] ) } />
) );

describe( 'CustomAccess gate settings', () => {
	it( 'preserves fields it does not manage (gate_layout_id) when rules change', () => {
		const onChange = jest.fn();
		const customAccess = {
			active: true,
			metering: { enabled: false },
			access_rules: [],
			gate_layout_id: 456,
		};

		render( <CustomAccess customAccess={ customAccess } onChange={ onChange } isNewsletter /> );

		fireEvent.click( screen.getByTestId( 'set-rules' ) );

		expect( onChange ).toHaveBeenCalledWith(
			expect.objectContaining( { access_rules: [ [ { name: 'active_subscription' } ] ], gate_layout_id: 456 } )
		);
	} );
} );
