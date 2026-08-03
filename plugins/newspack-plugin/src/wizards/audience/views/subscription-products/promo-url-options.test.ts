import {
	getVariationChoices,
	getFrequencyChoices,
	getAmountChoices,
	getDefaultFrequency,
	getValidationError,
	resolvePageProductParams,
} from './promo-url-options';
import type { PromoTargetBlockConfig, PromoTargetDonateConfig, PromoValidationInput } from './promo-url-options';

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

describe( 'getVariationChoices', () => {
	it( 'requires a specific child on the direct path (no chooser option)', () => {
		const choices = getVariationChoices( variableItem, null );
		expect( choices.map( c => c.value ) ).toEqual( [ 101, 102 ] );
	} );

	it( 'constrains to the target blocks and offers chooser only with a picker', () => {
		const locked: PromoTargetBlockConfig[] = [
			{ product_id: 100, variation_id: 102, has_variation_picker: false, coupon: null, after_success: null },
		];
		expect( getVariationChoices( variableItem, locked ).map( c => c.value ) ).toEqual( [ 102 ] );

		const picker: PromoTargetBlockConfig[] = [
			{ product_id: 100, variation_id: null, has_variation_picker: true, coupon: null, after_success: null },
		];
		const choices = getVariationChoices( variableItem, picker );
		expect( choices[ 0 ].value ).toBe( '' );
		expect( choices.map( c => c.value ) ).toContain( 101 );
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

describe( 'resolvePageProductParams', () => {
	const lockedBlock: PromoTargetBlockConfig = {
		product_id: 100,
		variation_id: 102,
		has_variation_picker: false,
		coupon: null,
		after_success: null,
	};
	const pickerBlock: PromoTargetBlockConfig = {
		product_id: 100,
		variation_id: null,
		has_variation_picker: true,
		coupon: null,
		after_success: null,
	};
	const memberBlock: PromoTargetBlockConfig = {
		product_id: 201,
		variation_id: null,
		has_variation_picker: false,
		coupon: null,
		after_success: null,
	};

	it( 'prefers a block locked to the chosen child', () => {
		expect( resolvePageProductParams( [ pickerBlock, lockedBlock ], 100, 102 ) ).toEqual( {
			productId: 100,
			variationId: 102,
		} );
	} );

	it( 'emits a plain product URL for a member-only button (never product_id === variation_id)', () => {
		expect( resolvePageProductParams( [ memberBlock ], 200, 201 ) ).toEqual( {
			productId: 201,
			variationId: null,
		} );
	} );

	it( 'routes any child through a picker block', () => {
		expect( resolvePageProductParams( [ pickerBlock ], 100, 101 ) ).toEqual( {
			productId: 100,
			variationId: 101,
		} );
	} );

	it( 'uses the picker parent for the reader-chooses option', () => {
		expect( resolvePageProductParams( [ pickerBlock ], 100, '' ) ).toEqual( {
			productId: 100,
			variationId: null,
		} );
	} );
} );

describe( 'getVariationChoices with server-side eligible children', () => {
	const pickerBlock: PromoTargetBlockConfig[] = [
		{ product_id: 200, variation_id: null, has_variation_picker: true, coupon: null, after_success: null },
	];

	it( 'omits grouped children the target page picker cannot serve', () => {
		// 202 is bundled but not subscription-typed, so the tiers form renders
		// no radio for it — offering it would emit a link that matches nothing.
		const choices = getVariationChoices( groupedItem, pickerBlock, [ 201 ] );
		expect( choices.map( c => c.value ) ).toEqual( [ '', 201 ] );
	} );

	it( 'falls back to every child when the server sends no eligible list', () => {
		const choices = getVariationChoices( groupedItem, pickerBlock );
		expect( choices.map( c => c.value ) ).toEqual( [ '', 201, 202 ] );
	} );

	it( 'leaves the direct path unconstrained', () => {
		expect( getVariationChoices( groupedItem, null, [ 201 ] ).map( c => c.value ) ).toEqual( [ 201, 202 ] );
	} );
} );

describe( 'getValidationError', () => {
	const productBase: PromoValidationInput = {
		kind: 'product',
		destination: 'direct',
		hasTarget: false,
		requiresChild: false,
		variationId: 101,
		donateConfig: null,
		effectiveAmount: undefined,
		customAmount: '',
		presets: [],
		isCouponActive: false,
		couponState: 'idle',
		afterSuccess: '',
		afterSuccessUrl: '',
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

	it( 'requires a target page on the page path', () => {
		expect( getValidationError( { ...productBase, destination: 'page' } ) ).toBe( 'Choose a target page.' );
	} );

	it( 'requires a specific plan option when the page cannot let the reader choose', () => {
		expect( getValidationError( { ...productBase, requiresChild: true, variationId: '' } ) ).toBe(
			'Choose which plan option the link should check out.'
		);
	} );

	it( 'reports donations that are not configured', () => {
		expect( getValidationError( { ...donationBase, donateConfig: null } ) ).toBe( 'Donations are not configured for WooCommerce on this site.' );
	} );

	it( 'rejects an unparseable or absent amount', () => {
		expect( getValidationError( { ...donationBase, effectiveAmount: undefined } ) ).toBe( 'Enter a valid amount.' );
	} );

	it( 'enforces the minimum donation, including for a custom amount', () => {
		expect( getValidationError( { ...donationBase, effectiveAmount: 2 } ) ).toBe( 'The amount must be at least 5.' );
		expect( getValidationError( { ...donationBase, effectiveAmount: 'other', customAmount: '1' } ) ).toBe( 'The amount must be at least 5.' );
	} );

	it( 'requires a page amount the target block actually renders', () => {
		expect( getValidationError( { ...donationBase, destination: 'page', hasTarget: true, effectiveAmount: 22 } ) ).toBe(
			'Choose one of the amounts available on the target page.'
		);
		expect( getValidationError( { ...donationBase, destination: 'page', hasTarget: true, effectiveAmount: 15 } ) ).toBeNull();
	} );

	it( 'accepts any amount on an untiered target block', () => {
		expect(
			getValidationError( {
				...donationBase,
				destination: 'page',
				hasTarget: true,
				effectiveAmount: 22,
				donateConfig: { ...donateConfig, layout_param: 'untiered' },
			} )
		).toBeNull();
	} );

	it( 'surfaces the coupon reason while the coupon field is in play', () => {
		expect(
			getValidationError( {
				...productBase,
				isCouponActive: true,
				couponState: 'invalid',
				couponReason: 'This coupon does not apply to this plan.',
			} )
		).toBe( 'This coupon does not apply to this plan.' );
		// The same coupon state is ignored where the field does not apply.
		expect( getValidationError( { ...productBase, couponState: 'invalid' } ) ).toBeNull();
	} );

	it( 'requires a destination URL for a custom after-checkout behavior', () => {
		expect( getValidationError( { ...productBase, afterSuccess: 'custom' } ) ).toBe( 'Enter the URL readers should continue to.' );
		expect( getValidationError( { ...productBase, afterSuccess: 'custom', afterSuccessUrl: 'https://x.test' } ) ).toBeNull();
	} );
} );
