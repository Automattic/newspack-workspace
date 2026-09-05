/**
 * The card-eligibility rules behind the "Change payment method" action.
 *
 * These are the client's only money decisions — which cards the picker offers
 * and whether the action shows at all — so they are pinned as a spec: same
 * gateway only, never an expired card, and no action without a resolvable
 * current card or another card to switch to.
 */

import { usableCardsFor, canChangePaymentMethod, refundIntent } from './use-payments';

const subscription = {
	id: 1,
	paymentGatewayId: 'stripe',
	paymentTokenId: 11,
	isManual: false,
};

const visa = { id: 11, gatewayId: 'stripe', isExpired: false };
const mastercard = { id: 12, gatewayId: 'stripe', isExpired: false };
const expiredAmex = { id: 13, gatewayId: 'stripe', isExpired: true };
const paypalToken = { id: 14, gatewayId: 'ppcp', isExpired: false };

describe( 'usableCardsFor', () => {
	it( 'offers only non-expired cards on the subscription’s own gateway', () => {
		expect( usableCardsFor( subscription, [ visa, mastercard, expiredAmex, paypalToken ] ) ).toEqual( [ visa, mastercard ] );
	} );
} );

describe( 'canChangePaymentMethod', () => {
	it( 'offers the action when another usable card exists', () => {
		expect( canChangePaymentMethod( subscription, [ visa, mastercard ] ) ).toBe( true );
	} );

	it( 'does not offer it when the only usable card is the current one', () => {
		expect( canChangePaymentMethod( subscription, [ visa, expiredAmex ] ) ).toBe( false );
	} );

	it( 'does not offer it when the subscription has no resolvable current card', () => {
		expect( canChangePaymentMethod( { ...subscription, paymentTokenId: null }, [ visa, mastercard ] ) ).toBe( false );
	} );

	it( 'does not offer it on a manual-renewal subscription', () => {
		expect( canChangePaymentMethod( { ...subscription, isManual: true }, [ visa, mastercard ] ) ).toBe( false );
	} );
} );

describe( 'refundIntent', () => {
	it( 'maps the three-way choice directly when a refund is on the table', () => {
		expect( refundIntent( 'refund-only', false ) ).toEqual( { willRefund: true, willCancel: false } );
		expect( refundIntent( 'cancel-only', false ) ).toEqual( { willRefund: false, willCancel: true } );
		expect( refundIntent( 'refund-cancel', false ) ).toEqual( { willRefund: true, willCancel: true } );
	} );

	it( 'never refunds when the flow collapsed to a plain cancel, whatever the stale choice says', () => {
		expect( refundIntent( 'refund-only', true ) ).toEqual( { willRefund: false, willCancel: true } );
		expect( refundIntent( 'refund-cancel', true ) ).toEqual( { willRefund: false, willCancel: true } );
	} );
} );
