/**
 * Reproduction: the seat-request notice crashes on the pending -> awaiting-payment
 * transition (single `action` vs `actions` array).
 */
import { render } from '@testing-library/react';
import { NoticesPanel } from './SubscriberNotices';

const pendingNotice = {
	id: 'group_seat_request',
	status: 'warning',
	body: 'The owner requested an increase to <b>25 seats</b>.',
	actions: [
		{ label: 'Decline request', onClick: () => {}, variant: 'tertiary' },
		{ label: 'Adjust seats', onClick: () => {}, variant: 'secondary' },
	],
};

const awaitingNotice = {
	id: 'group_seat_request',
	status: 'warning',
	body: "Awaiting the owner's payment to increase to <b>25 seats</b>.",
	action: { label: 'Mark as paid', onClick: () => {} },
};

describe( 'NoticesPanel seat-request rendering', () => {
	it( 'renders the awaiting-payment notice (single action) without crashing', () => {
		const node = document.createElement( 'div' );
		expect( () => render( <NoticesPanel noticesNode={ node } notices={ [ awaitingNotice ] } /> ) ).not.toThrow();
	} );

	it( 'survives the pending -> awaiting-payment transition', () => {
		const node = document.createElement( 'div' );
		const { rerender } = render( <NoticesPanel noticesNode={ node } notices={ [ pendingNotice ] } /> );
		expect( () => rerender( <NoticesPanel noticesNode={ node } notices={ [ awaitingNotice ] } /> ) ).not.toThrow();
	} );
} );
