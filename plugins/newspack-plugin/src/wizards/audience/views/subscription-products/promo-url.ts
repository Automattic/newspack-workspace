/**
 * Pure assembly of modal-checkout promotional URLs (NPPD-1707).
 *
 * Two mechanisms exist:
 * - direct: PHP-side handlers (`?newspack_checkout=1`, `?newspack_donate=1`)
 *   that work on any URL with no block on the page.
 * - page: the JS trigger (`?checkout=1&type=…`) that opens the modal over a
 *   page containing a compatible block.
 */

export type PromoDestination = 'direct' | 'page';
export type PromoKind = 'product' | 'donation';
export type DonateFrequencySlug = 'once' | 'month' | 'year';

export type PromoUrlSelections = {
	destination: PromoDestination;
	pageUrl?: string;
	productId?: number;
	variationId?: number | null;
	frequency?: DonateFrequencySlug;
	amount?: number | 'other';
	otherAmount?: number;
	layoutParam?: 'tiered' | 'untiered' | 'frequency';
	coupon?: string;
	price?: number;
	afterSuccessBehavior?: '' | 'custom';
	afterSuccessUrl?: string;
	afterSuccessButtonLabel?: string;
	utmSource?: string;
	utmMedium?: string;
	utmCampaign?: string;
};

export type PromoUrlInput = {
	kind: PromoKind;
	siteUrl: string;
	selections: PromoUrlSelections;
};

const setIf = ( url: URL, key: string, value: string | number | null | undefined ) => {
	if ( value !== undefined && value !== null && value !== '' ) {
		url.searchParams.set( key, String( value ) );
	}
};

export function buildPromoUrl( input: PromoUrlInput ): string {
	const { kind, siteUrl, selections: s } = input;
	const url = new URL( s.destination === 'page' && s.pageUrl ? s.pageUrl : siteUrl );

	if ( s.destination === 'direct' ) {
		if ( kind === 'product' ) {
			url.searchParams.set( 'newspack_checkout', '1' );
			setIf( url, 'product_id', s.productId );
			setIf( url, 'variation_id', s.variationId );
			setIf( url, 'coupon', s.coupon );
			setIf( url, 'price', s.price );
		} else {
			url.searchParams.set( 'newspack_donate', '1' );
			url.searchParams.set( 'modal_checkout', '1' );
			setIf( url, 'donation_frequency', s.frequency );
			if ( s.frequency && typeof s.amount === 'number' ) {
				url.searchParams.set( `donation_value_${ s.frequency }`, String( s.amount ) );
			}
		}
		if ( s.afterSuccessBehavior === 'custom' ) {
			url.searchParams.set( 'after_success_behavior', 'custom' );
			setIf( url, 'after_success_url', s.afterSuccessUrl );
			setIf( url, 'after_success_button_label', s.afterSuccessButtonLabel );
		}
	} else {
		url.searchParams.set( 'checkout', '1' );
		if ( kind === 'product' ) {
			url.searchParams.set( 'type', 'checkout_button' );
			setIf( url, 'product_id', s.productId );
			setIf( url, 'variation_id', s.variationId );
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
	}
	setIf( url, 'utm_source', s.utmSource );
	setIf( url, 'utm_medium', s.utmMedium );
	setIf( url, 'utm_campaign', s.utmCampaign );
	return url.toString();
}
