/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * Internal dependencies
 */
import Registration from './registration';

jest.mock( '../../../../../../packages/components/src', () => ( {
	ActionCard: () => null,
} ) );
jest.mock( './metering', () => () => null );

describe( 'Registration gate settings', () => {
	it( 'preserves fields it does not manage (gate_layout_id) when a setting changes', () => {
		const onChange = jest.fn();
		const registration = {
			active: true,
			metering: { enabled: false },
			require_verification: false,
			gate_layout_id: 123,
		};

		render( <Registration registration={ registration } onChange={ onChange } isNewsletter /> );

		fireEvent.click( screen.getByRole( 'checkbox' ) );

		expect( onChange ).toHaveBeenCalledWith( expect.objectContaining( { require_verification: true, gate_layout_id: 123 } ) );
	} );
} );
