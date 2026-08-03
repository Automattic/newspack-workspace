import { getVariationChoices, getFrequencyChoices, getAmountChoices, getDefaultFrequency, resolvePageProductParams } from './promo-url-options';
import type { PromoTargetBlockConfig, PromoTargetDonateConfig } from './promo-url-options';

const variableItem = {
	id: 100,
	type: 'variable-subscription',
	variations: [
		{ id: 101, name: 'Monthly', plan_label: 'Monthly' },
		{ id: 102, name: 'Yearly', plan_label: 'Yearly' },
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
