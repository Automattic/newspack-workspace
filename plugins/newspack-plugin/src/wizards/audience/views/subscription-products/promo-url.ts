/**
 * Pure assembly of modal-checkout promotional URLs (NPPD-1707).
 *
 * A promotional link always points at a page carrying a compatible block and
 * opens the checkout over it (`?checkout=1&type=…`), because the checkout
 * template is built to render inside the modal.
 */

export type PromoKind = 'product' | 'donation';
export type DonateFrequencySlug = 'once' | 'month' | 'year';

export type PromoUrlSelections = {
	pageUrl: string;
	productId?: number;
	variationId?: number | null;
	frequency?: DonateFrequencySlug;
	amount?: number | 'other';
	otherAmount?: number;
	layoutParam?: 'tiered' | 'untiered' | 'frequency';
	coupon?: string;
	utmSource?: string;
	utmMedium?: string;
	utmCampaign?: string;
};

export type PromoUrlInput = {
	kind: PromoKind;
	selections: PromoUrlSelections;
};

const setIf = ( url: URL, key: string, value: string | number | null | undefined ) => {
	if ( value !== undefined && value !== null && value !== '' ) {
		url.searchParams.set( key, String( value ) );
	}
};

export function buildPromoUrl( input: PromoUrlInput ): string {
	const { kind, selections: s } = input;
	const url = new URL( s.pageUrl );

	url.searchParams.set( 'checkout', '1' );
	if ( kind === 'product' ) {
		url.searchParams.set( 'type', 'checkout_button' );
		setIf( url, 'product_id', s.productId );
		setIf( url, 'variation_id', s.variationId );
		// Applied to the cart at checkout, and carried through the plan picker.
		setIf( url, 'coupon', s.coupon );
	} else {
		url.searchParams.set( 'type', 'donate' );
		setIf( url, 'layout', s.layoutParam );
		setIf( url, 'frequency', s.frequency );
		if ( s.amount === 'other' ) {
			url.searchParams.set( 'amount', 'other' );
			setIf( url, 'other', s.otherAmount );
		} else {
			setIf( url, 'amount', s.amount );
		}
	}
	setIf( url, 'utm_source', s.utmSource );
	setIf( url, 'utm_medium', s.utmMedium );
	setIf( url, 'utm_campaign', s.utmCampaign );
	return url.toString();
}
