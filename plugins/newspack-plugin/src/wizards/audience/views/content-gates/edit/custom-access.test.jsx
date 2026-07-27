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
	<>
		<button data-testid="set-rules" onClick={ () => onChange( [ { name: 'active_subscription' } ] ) } />
		<button data-testid="set-subscription-rule" onClick={ () => onChange( [ { slug: 'subscription', value: [ 50 ] } ] ) } />
	</>
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

	describe( 'payment recovery grace toggle', () => {
		const GRACE_TOGGLE_LABEL = /grace during payment recovery/i;

		it( 'is not rendered without a subscription rule', () => {
			const customAccess = {
				active: true,
				metering: { enabled: false },
				access_rules: [ [ { slug: 'email_domain', value: 'example.com' } ] ],
				gate_layout_id: 456,
			};

			render( <CustomAccess customAccess={ customAccess } onChange={ jest.fn() } isNewsletter /> );

			expect( screen.queryByLabelText( GRACE_TOGGLE_LABEL ) ).toBeNull();
		} );

		it( 'renders default-checked for a legacy gate lacking the setting key when a subscription rule exists', () => {
			const customAccess = {
				active: true,
				metering: { enabled: false },
				access_rules: [ [ { slug: 'subscription', value: [ 50 ] } ] ],
				gate_layout_id: 456,
				// No payment_recovery_grace key: legacy gate saved before the setting existed.
			};

			render( <CustomAccess customAccess={ customAccess } onChange={ jest.fn() } isNewsletter /> );

			expect( screen.getByLabelText( GRACE_TOGGLE_LABEL ) ).toBeChecked();
		} );

		it( 'reflects a stored false and reports true on toggle', () => {
			const onChange = jest.fn();
			const customAccess = {
				active: true,
				metering: { enabled: false },
				access_rules: [ [ { slug: 'subscription', value: [ 50 ] } ] ],
				gate_layout_id: 456,
				payment_recovery_grace: false,
			};

			render( <CustomAccess customAccess={ customAccess } onChange={ onChange } isNewsletter /> );

			const graceToggle = screen.getByLabelText( GRACE_TOGGLE_LABEL );
			expect( graceToggle ).not.toBeChecked();

			fireEvent.click( graceToggle );

			expect( onChange ).toHaveBeenCalledWith( expect.objectContaining( { payment_recovery_grace: true } ) );
		} );

		it( 'renders for a subscription rule that is not first in its group', () => {
			const customAccess = {
				active: true,
				metering: { enabled: false },
				// A gate authored over the REST API can group several rules together;
				// only the first of each group reaches the rules editor.
				access_rules: [
					[
						{ slug: 'email_domain', value: 'example.com' },
						{ slug: 'subscription', value: [ 50 ] },
					],
				],
				gate_layout_id: 456,
			};

			render( <CustomAccess customAccess={ customAccess } onChange={ jest.fn() } isNewsletter /> );

			expect( screen.getByLabelText( GRACE_TOGGLE_LABEL ) ).toBeChecked();
		} );

		it( 'resets a stored false when the last subscription rule is removed', () => {
			const onChange = jest.fn();
			const customAccess = {
				active: true,
				metering: { enabled: false },
				access_rules: [ [ { slug: 'subscription', value: [ 50 ] } ] ],
				gate_layout_id: 456,
				payment_recovery_grace: false,
			};

			render( <CustomAccess customAccess={ customAccess } onChange={ onChange } isNewsletter /> );

			// The replacement rules carry no subscription rule, so the toggle
			// disappears — the stored false must not survive to take effect
			// invisibly if a subscription rule is added back later.
			fireEvent.click( screen.getByTestId( 'set-rules' ) );

			expect( onChange ).toHaveBeenCalledWith( expect.objectContaining( { payment_recovery_grace: true } ) );
		} );

		it( 'keeps a stored false when the rules still include a subscription rule', () => {
			const onChange = jest.fn();
			const customAccess = {
				active: true,
				metering: { enabled: false },
				access_rules: [ [ { slug: 'subscription', value: [ 50 ] } ] ],
				gate_layout_id: 456,
				payment_recovery_grace: false,
			};

			render( <CustomAccess customAccess={ customAccess } onChange={ onChange } isNewsletter /> );

			fireEvent.click( screen.getByTestId( 'set-subscription-rule' ) );

			expect( onChange ).toHaveBeenCalledWith( expect.objectContaining( { payment_recovery_grace: false } ) );
		} );
	} );
} );
