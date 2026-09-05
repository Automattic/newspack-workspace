import { buildPromoUrl } from './promo-url';

const PAGE = 'https://example.test/support/';

describe( 'buildPromoUrl', () => {
	it( 'builds a product URL on the target page permalink', () => {
		const url = new URL(
			buildPromoUrl( {
				kind: 'product',
				selections: {
					pageUrl: PAGE,
					productId: 100,
					variationId: 102,
					utmSource: 'newsletter',
					utmCampaign: 'spring',
				},
			} )
		);
		expect( url.origin + url.pathname ).toBe( PAGE );
		expect( url.searchParams.get( 'checkout' ) ).toBe( '1' );
		expect( url.searchParams.get( 'type' ) ).toBe( 'checkout_button' );
		expect( url.searchParams.get( 'product_id' ) ).toBe( '100' );
		expect( url.searchParams.get( 'variation_id' ) ).toBe( '102' );
		expect( url.searchParams.get( 'utm_source' ) ).toBe( 'newsletter' );
		expect( url.searchParams.get( 'utm_campaign' ) ).toBe( 'spring' );
		// The standalone-checkout handler is never used: the template is built
		// to render inside the modal.
		expect( url.searchParams.has( 'newspack_checkout' ) ).toBe( false );
	} );

	it( 'omits empty optional params', () => {
		const url = new URL(
			buildPromoUrl( {
				kind: 'product',
				selections: { pageUrl: PAGE, productId: 100 },
			} )
		);
		expect( url.searchParams.has( 'variation_id' ) ).toBe( false );
		expect( url.searchParams.has( 'coupon' ) ).toBe( false );
		expect( url.searchParams.has( 'utm_source' ) ).toBe( false );
		expect( url.searchParams.has( 'utm_medium' ) ).toBe( false );
		expect( url.searchParams.has( 'utm_campaign' ) ).toBe( false );
	} );

	it( 'carries a coupon on a product link', () => {
		const url = new URL(
			buildPromoUrl( {
				kind: 'product',
				selections: { pageUrl: PAGE, productId: 100, coupon: 'SPRING 20' },
			} )
		);
		expect( url.searchParams.get( 'coupon' ) ).toBe( 'SPRING 20' );
	} );

	it( 'carries a custom after-checkout destination', () => {
		const url = new URL(
			buildPromoUrl( {
				kind: 'product',
				selections: {
					pageUrl: PAGE,
					productId: 100,
					afterSuccessBehavior: 'custom',
					afterSuccessUrl: 'https://example.test/welcome',
					afterSuccessButtonLabel: 'Get started',
				},
			} )
		);
		expect( url.searchParams.get( 'after_success_behavior' ) ).toBe( 'custom' );
		expect( url.searchParams.get( 'after_success_url' ) ).toBe( 'https://example.test/welcome' );
		expect( url.searchParams.get( 'after_success_button_label' ) ).toBe( 'Get started' );
	} );

	it( 'omits after-checkout params when the thank-you screen is kept', () => {
		const url = new URL(
			buildPromoUrl( {
				kind: 'product',
				selections: { pageUrl: PAGE, productId: 100, afterSuccessBehavior: '', afterSuccessUrl: 'https://example.test/welcome' },
			} )
		);
		expect( url.searchParams.has( 'after_success_behavior' ) ).toBe( false );
		expect( url.searchParams.has( 'after_success_url' ) ).toBe( false );
	} );

	it( 'never puts a coupon on a donation link', () => {
		const url = new URL(
			buildPromoUrl( {
				kind: 'donation',
				selections: {
					pageUrl: 'https://example.test/donate/',
					layoutParam: 'frequency',
					frequency: 'month',
					amount: 15,
					coupon: 'SPRING20',
				},
			} )
		);
		expect( url.searchParams.has( 'coupon' ) ).toBe( false );
	} );

	it( 'preserves an existing query string on the page permalink', () => {
		const url = new URL(
			buildPromoUrl( {
				kind: 'product',
				selections: { pageUrl: 'https://example.test/?page_id=12', productId: 100 },
			} )
		);
		expect( url.searchParams.get( 'page_id' ) ).toBe( '12' );
		expect( url.searchParams.get( 'checkout' ) ).toBe( '1' );
	} );

	it( 'builds a donate URL with layout and a preset amount', () => {
		const url = new URL(
			buildPromoUrl( {
				kind: 'donation',
				selections: {
					pageUrl: 'https://example.test/donate/',
					layoutParam: 'tiered',
					frequency: 'year',
					amount: 360,
				},
			} )
		);
		expect( url.searchParams.get( 'type' ) ).toBe( 'donate' );
		expect( url.searchParams.get( 'layout' ) ).toBe( 'tiered' );
		expect( url.searchParams.get( 'frequency' ) ).toBe( 'year' );
		expect( url.searchParams.get( 'amount' ) ).toBe( '360' );
		expect( url.searchParams.has( 'newspack_donate' ) ).toBe( false );
	} );

	it( 'builds a donate URL with a custom amount on the frequency-based layout', () => {
		const url = new URL(
			buildPromoUrl( {
				kind: 'donation',
				selections: {
					pageUrl: 'https://example.test/donate/',
					layoutParam: 'frequency',
					frequency: 'month',
					amount: 'other',
					otherAmount: 42,
				},
			} )
		);
		expect( url.searchParams.get( 'amount' ) ).toBe( 'other' );
		expect( url.searchParams.get( 'other' ) ).toBe( '42' );
	} );

	it( 'builds a donate URL with a custom amount on the untiered layout', () => {
		const url = new URL(
			buildPromoUrl( {
				kind: 'donation',
				selections: {
					pageUrl: 'https://example.test/donate/',
					layoutParam: 'untiered',
					frequency: 'month',
					amount: 25,
				},
			} )
		);
		expect( url.searchParams.get( 'layout' ) ).toBe( 'untiered' );
		expect( url.searchParams.get( 'amount' ) ).toBe( '25' );
		expect( url.searchParams.has( 'other' ) ).toBe( false );
	} );
} );
