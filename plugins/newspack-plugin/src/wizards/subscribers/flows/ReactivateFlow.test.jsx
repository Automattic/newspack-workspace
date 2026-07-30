/**
 * ReactivateFlow — the load-bearing client rules.
 *
 * What these pin: the charge option only exists when the server said a charge
 * could be attempted; the flow is honest about the payment-link outcome
 * (a "sent" claim only when an email went out, the URL itself when it did
 * not); a charge left in flight is reported as pending, never as reactivated;
 * and a server refusal is surfaced verbatim, not replaced with a generic
 * failure.
 */

import { render, screen, fireEvent, waitFor } from '@testing-library/react';

import ReactivateFlow from './ReactivateFlow';

const SUBSCRIPTION = {
	id: 42,
	plan: 'Gold Membership',
	amount: 10,
	currency: 'USD',
	status: 'on-hold',
};

const renderFlow = ( { canCharge = false, actions = {}, onDone = jest.fn(), onClose = jest.fn() } = {} ) => {
	render(
		<ReactivateFlow
			subscription={ { ...SUBSCRIPTION, canCharge } }
			email="reader@example.com"
			actions={ actions }
			onClose={ onClose }
			onDone={ onDone }
		/>
	);
	return { onDone, onClose };
};

const toDetails = () => fireEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
const confirm = () => fireEvent.click( screen.getByRole( 'button', { name: 'Confirm' } ) );

describe( 'ReactivateFlow', () => {
	it( 'offers the charge option only when the server says a charge could be attempted', () => {
		renderFlow( { canCharge: false } );
		expect( screen.queryByRole( 'radio', { name: 'Charge the customer now' } ) ).toBeNull();
		expect( screen.getByRole( 'radio', { name: 'Send a payment link' } ) ).toBeChecked();
	} );

	it( 'preselects the charge option when charging is possible', () => {
		renderFlow( { canCharge: true } );
		expect( screen.getByRole( 'radio', { name: 'Charge the customer now' } ) ).toBeChecked();
	} );

	it( 'confirms a sent payment link with the subscriber address', async () => {
		const sendPaymentLink = jest.fn().mockResolvedValue( { emailSent: true, paymentUrl: 'https://example.test/pay/1' } );
		const { onDone } = renderFlow( { actions: { sendPaymentLink } } );

		toDetails();
		confirm();

		await waitFor( () => expect( onDone ).toHaveBeenCalledWith( 'Payment link sent to reader@example.com.' ) );
		expect( sendPaymentLink ).toHaveBeenCalledWith( 42 );
	} );

	it( 'hands the admin the link itself when no email went out', async () => {
		const sendPaymentLink = jest.fn().mockResolvedValue( { emailSent: false, paymentUrl: 'https://example.test/pay/1' } );
		const { onDone } = renderFlow( { actions: { sendPaymentLink } } );

		toDetails();
		confirm();

		await waitFor( () => expect( screen.getByRole( 'alert' ) ).toHaveTextContent( 'https://example.test/pay/1' ) );
		expect( onDone ).not.toHaveBeenCalled();
	} );

	it( 'reports an in-flight charge as pending, not as reactivated', async () => {
		const reactivate = jest.fn().mockResolvedValue( { status: 'on-hold', pendingConfirmation: true } );
		const { onDone } = renderFlow( { canCharge: true, actions: { reactivate } } );

		toDetails();
		confirm();

		await waitFor( () =>
			expect( onDone ).toHaveBeenCalledWith( "Payment submitted — check the subscription's renewal order to confirm the outcome." )
		);
		expect( reactivate ).toHaveBeenCalledWith( 42, 'charge' );
	} );

	it( 'surfaces a server refusal verbatim', async () => {
		const reactivate = jest.fn().mockRejectedValue( { message: 'The payment did not complete.' } );
		const { onDone } = renderFlow( { canCharge: true, actions: { reactivate } } );

		toDetails();
		confirm();

		await waitFor( () => expect( screen.getByRole( 'alert' ) ).toHaveTextContent( 'The payment did not complete.' ) );
		expect( onDone ).not.toHaveBeenCalled();
	} );
} );
