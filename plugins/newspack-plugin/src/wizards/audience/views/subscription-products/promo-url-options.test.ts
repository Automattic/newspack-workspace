import { getPlanChoices, getFrequencyChoices, getAmountChoices, getDefaultFrequency, getValidationError, resolveProductParams } from './promo-url-options';
import type { PromoTargetDonateConfig, PromoValidationInput } from './promo-url-options';

const variableItem = {
	id: 100,
	type: 'variable-subscription',
	variations: [
		{ id: 101, name: 'Monthly', plan_label: 'Monthly' },
		{ id: 102, name: 'Yearly', plan_label: 'Yearly' },
	],
} as unknown as SubscriptionProduct;

const groupedItem = {
	id: 200,
	type: 'grouped',
	bundled_products: [
		{ id: 201, name: 'Digital', price_label: '$10' },
		{ id: 202, name: 'Tote bag', price_label: '$25' },
	],
} as unknown as SubscriptionProduct;

const simpleItem = { id: 300, type: 'subscription' } as unknown as SubscriptionProduct;

const donateConfig: PromoTargetDonateConfig = {
	layout_param: 'frequency',
	frequencies: {
		once: { enabled: false, amounts: [], supports_custom: false, suggested: null },
		month: { enabled: true, amounts: [ 7, 15, 30 ], supports_custom: true, suggested: 15 },
		year: { enabled: true, amounts: [ 84, 180, 360 ], supports_custom: false, suggested: 180 },
	},
	default_frequency: 'month',
	minimum: 5,
};

describe( 'getPlanChoices', () => {
	it( 'offers every variation plus the reader-chooses option', () => {
		// The variable picker renders over any page, so the reader can always
		// be left to choose.
		expect( getPlanChoices( variableItem, [ 101, 102 ] ).map( c => c.value ) ).toEqual( [ '', 101, 102 ] );
	} );

	it( 'offers nothing for a plan with no children', () => {
		expect( getPlanChoices( simpleItem, [] ) ).toEqual( [] );
	} );

	it( 'offers grouped members, with reader-chooses only when the picker serves one', () => {
		expect( getPlanChoices( groupedItem, [ 201 ] ).map( c => c.value ) ).toEqual( [ '', 201, 202 ] );
		// No child is servable by the tiers form, so "let the reader choose"
		// would open a picker with no options.
		expect( getPlanChoices( groupedItem, [] ).map( c => c.value ) ).toEqual( [ 201, 202 ] );
	} );

	it( 'labels grouped members with their price', () => {
		expect( getPlanChoices( groupedItem, [ 201 ] )[ 1 ].label ).toBe( 'Digital ($10)' );
	} );
} );

describe( 'resolveProductParams', () => {
	it( 'names the parent for the reader-chooses option', () => {
		expect( resolveProductParams( variableItem, '' ) ).toEqual( { productId: 100, variationId: null } );
	} );

	it( 'rides a variation on its parent', () => {
		expect( resolveProductParams( variableItem, 102 ) ).toEqual( { productId: 100, variationId: 102 } );
	} );

	it( 'emits a plain product URL for a grouped member (never product_id === variation_id)', () => {
		expect( resolveProductParams( groupedItem, 201 ) ).toEqual( { productId: 201, variationId: null } );
	} );
} );

describe( 'frequency and amount choices', () => {
	it( 'lists only enabled frequencies', () => {
		expect( getFrequencyChoices( donateConfig ).map( c => c.value ) ).toEqual( [ 'month', 'year' ] );
	} );

	it( 'returns presets and custom support per frequency', () => {
		expect( getAmountChoices( donateConfig, 'month' ) ).toEqual( {
			presets: [ 7, 15, 30 ],
			supportsCustom: true,
			suggested: 15,
		} );
		expect( getAmountChoices( donateConfig, 'year' ).supportsCustom ).toBe( false );
	} );

	it( 'derives the default frequency from the row period, falling back to config', () => {
		const monthlyRow = { period: 'month' } as unknown as SubscriptionProduct;
		expect( getDefaultFrequency( monthlyRow, donateConfig ) ).toBe( 'month' );
		const onceRow = { period: '' } as unknown as SubscriptionProduct;
		// `once` is disabled in config, so fall back to default_frequency.
		expect( getDefaultFrequency( onceRow, donateConfig ) ).toBe( 'month' );
	} );
} );

describe( 'getValidationError', () => {
	const productBase: PromoValidationInput = {
		kind: 'product',
		hasTarget: true,
		requiresChild: false,
		variationId: 101,
		donateConfig: null,
		effectiveAmount: undefined,
		customAmount: '',
		presets: [],
	};
	const donationBase: PromoValidationInput = {
		...productBase,
		kind: 'donation',
		donateConfig,
		effectiveAmount: 15,
		presets: [ 7, 15, 30 ],
	};

	it( 'allows a complete product selection', () => {
		expect( getValidationError( productBase ) ).toBeNull();
	} );

	it( 'requires a target page', () => {
		expect( getValidationError( { ...productBase, hasTarget: false } ) ).toBe( 'Choose a target page.' );
	} );

	it( 'requires a specific plan option when the picker cannot let the reader choose', () => {
		expect( getValidationError( { ...productBase, requiresChild: true, variationId: '' } ) ).toBe(
			'Choose which plan option the link should check out.'
		);
	} );

	it( 'reports a Donate block that cannot take a link', () => {
		expect( getValidationError( { ...donationBase, donateConfig: null } ) ).toBe(
			'The Donate block on this page cannot take a promotional link.'
		);
	} );

	it( 'rejects an unparseable or absent amount', () => {
		expect( getValidationError( { ...donationBase, effectiveAmount: undefined } ) ).toBe( 'Enter a valid amount.' );
	} );

	it( 'enforces the minimum donation, including for a custom amount', () => {
		expect( getValidationError( { ...donationBase, effectiveAmount: 2 } ) ).toBe( 'The amount must be at least 5.' );
		expect( getValidationError( { ...donationBase, effectiveAmount: 'other', customAmount: '1' } ) ).toBe( 'The amount must be at least 5.' );
	} );

	it( 'requires an amount the target block actually renders', () => {
		expect( getValidationError( { ...donationBase, effectiveAmount: 22 } ) ).toBe( 'Choose one of the amounts available on the target page.' );
		expect( getValidationError( { ...donationBase, effectiveAmount: 15 } ) ).toBeNull();
	} );

	it( 'accepts any amount on an untiered target block', () => {
		expect(
			getValidationError( {
				...donationBase,
				effectiveAmount: 22,
				donateConfig: { ...donateConfig, layout_param: 'untiered' },
			} )
		).toBeNull();
	} );
} );
