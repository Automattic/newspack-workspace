import { buildPromoUrl } from './promo-url';

const SITE = 'https://example.test';

describe( 'buildPromoUrl', () => {
	it( 'builds a direct product URL with coupon, price, after-success and utm', () => {
		const url = new URL(
			buildPromoUrl( {
				kind: 'product',
				siteUrl: SITE,
				selections: {
					destination: 'direct',
					productId: 100,
					variationId: 102,
					coupon: 'SPRING 20',
					price: 25,
					afterSuccessBehavior: 'custom',
					afterSuccessUrl: 'https://example.test/thanks',
					afterSuccessButtonLabel: 'Continue',
					utmSource: 'newsletter',
				},
			} )
		);
		expect( url.origin ).toBe( SITE );
		expect( url.searchParams.get( 'newspack_checkout' ) ).toBe( '1' );
		expect( url.searchParams.get( 'product_id' ) ).toBe( '100' );
		expect( url.searchParams.get( 'variation_id' ) ).toBe( '102' );
		expect( url.searchParams.get( 'coupon' ) ).toBe( 'SPRING 20' );
		expect( url.searchParams.get( 'price' ) ).toBe( '25' );
		expect( url.searchParams.get( 'after_success_behavior' ) ).toBe( 'custom' );
		expect( url.searchParams.get( 'after_success_url' ) ).toBe( 'https://example.test/thanks' );
		expect( url.searchParams.get( 'utm_source' ) ).toBe( 'newsletter' );
	} );

	it( 'omits empty optional params', () => {
		const url = new URL(
			buildPromoUrl( {
				kind: 'product',
				siteUrl: SITE,
				selections: { destination: 'direct', productId: 100 },
			} )
		);
		expect( url.searchParams.has( 'variation_id' ) ).toBe( false );
		expect( url.searchParams.has( 'coupon' ) ).toBe( false );
		expect( url.searchParams.has( 'after_success_behavior' ) ).toBe( false );
		expect( url.searchParams.has( 'utm_source' ) ).toBe( false );
	} );

	it( 'builds a direct donation URL', () => {
		const url = new URL(
			buildPromoUrl( {
				kind: 'donation',
				siteUrl: SITE,
				selections: { destination: 'direct', frequency: 'month', amount: 15 },
			} )
		);
		expect( url.searchParams.get( 'newspack_donate' ) ).toBe( '1' );
		expect( url.searchParams.get( 'modal_checkout' ) ).toBe( '1' );
		expect( url.searchParams.get( 'donation_frequency' ) ).toBe( 'month' );
		expect( url.searchParams.get( 'donation_value_month' ) ).toBe( '15' );
	} );

	it( 'builds a page product URL on the page permalink', () => {
		const url = new URL(
			buildPromoUrl( {
				kind: 'product',
				siteUrl: SITE,
				selections: {
					destination: 'page',
					pageUrl: 'https://example.test/support/',
					productId: 100,
					variationId: 102,
					utmCampaign: 'spring',
				},
			} )
		);
		expect( url.pathname ).toBe( '/support/' );
		expect( url.searchParams.get( 'checkout' ) ).toBe( '1' );
		expect( url.searchParams.get( 'type' ) ).toBe( 'checkout_button' );
		expect( url.searchParams.get( 'product_id' ) ).toBe( '100' );
		expect( url.searchParams.get( 'variation_id' ) ).toBe( '102' );
		expect( url.searchParams.get( 'utm_campaign' ) ).toBe( 'spring' );
		expect( url.searchParams.has( 'newspack_checkout' ) ).toBe( false );
	} );

	it( 'builds a page donate URL with layout, preset amount, and other amount', () => {
		const preset = new URL(
			buildPromoUrl( {
				kind: 'donation',
				siteUrl: SITE,
				selections: {
					destination: 'page',
					pageUrl: 'https://example.test/donate/',
					layoutParam: 'tiered',
					frequency: 'year',
					amount: 360,
				},
			} )
		);
		expect( preset.searchParams.get( 'type' ) ).toBe( 'donate' );
		expect( preset.searchParams.get( 'layout' ) ).toBe( 'tiered' );
		expect( preset.searchParams.get( 'frequency' ) ).toBe( 'year' );
		expect( preset.searchParams.get( 'amount' ) ).toBe( '360' );

		const other = new URL(
			buildPromoUrl( {
				kind: 'donation',
				siteUrl: SITE,
				selections: {
					destination: 'page',
					pageUrl: 'https://example.test/donate/',
					layoutParam: 'frequency',
					frequency: 'month',
					amount: 'other',
					otherAmount: 42,
				},
			} )
		);
		expect( other.searchParams.get( 'amount' ) ).toBe( 'other' );
		expect( other.searchParams.get( 'other' ) ).toBe( '42' );
	} );
} );
