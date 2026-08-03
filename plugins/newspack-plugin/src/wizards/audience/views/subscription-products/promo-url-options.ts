/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { DonateFrequencySlug } from './promo-url';

export type PromoTargetBlockConfig = {
	product_id: number;
	variation_id: number | null;
	has_variation_picker: boolean;
	coupon: string | null;
	after_success: { behavior: string; url: string; button_label: string } | null;
};

export type DonateFrequencyConfig = {
	enabled: boolean;
	amounts: number[];
	supports_custom: boolean;
	suggested: number | null;
};

export type PromoTargetDonateConfig = {
	layout_param: 'tiered' | 'untiered' | 'frequency';
	frequencies: Record< DonateFrequencySlug, DonateFrequencyConfig >;
	default_frequency: string;
	minimum: number;
};

export type PromoTarget = {
	id: number;
	title: string;
	url: string;
	blocks: PromoTargetBlockConfig[] | PromoTargetDonateConfig[];
};

export type PromoTargetsResponse = {
	targets: PromoTarget[];
	truncated: boolean;
	donation_config?: PromoTargetDonateConfig | null;
	nyp?: Record< number, boolean >;
};

export type PromoCouponResponse = { valid: boolean; reason?: string };

const FREQUENCY_LABELS: Record< DonateFrequencySlug, string > = {
	once: __( 'One-time', 'newspack-plugin' ),
	month: __( 'Monthly', 'newspack-plugin' ),
	year: __( 'Annually', 'newspack-plugin' ),
};

/**
 * Child-product choices for a plan row. On the direct path (null targetBlocks)
 * a specific child is required — parents cannot be added to the cart. On the
 * page path, choices are constrained to what the target page's blocks accept;
 * the "reader chooses" option appears only when a block renders a picker.
 */
export function getVariationChoices(
	item: SubscriptionProduct,
	targetBlocks: PromoTargetBlockConfig[] | null
): { value: number | ''; label: string }[] {
	const children =
		item.type === 'grouped'
			? ( item.bundled_products || [] ).map( product => ( {
					value: product.id,
					label: product.price_label ? `${ product.name } (${ product.price_label })` : product.name,
			  } ) )
			: ( item.variations || [] ).map( variation => ( {
					value: variation.id,
					label: variation.plan_label || variation.name,
			  } ) );
	if ( ! children.length ) {
		return [];
	}
	if ( ! targetBlocks ) {
		return children;
	}
	const allowed = new Set< number >();
	let allowChooser = false;
	for ( const block of targetBlocks ) {
		if ( block.variation_id ) {
			allowed.add( block.variation_id );
		} else if ( block.has_variation_picker ) {
			allowChooser = true;
			children.forEach( child => allowed.add( child.value as number ) );
		} else if ( block.product_id !== item.id ) {
			// A button pointing directly at a grouped member.
			allowed.add( block.product_id );
		}
	}
	const filtered = children.filter( child => allowed.has( child.value as number ) );
	return allowChooser ? [ { value: '' as const, label: __( 'Let the reader choose', 'newspack-plugin' ) }, ...filtered ] : filtered;
}

export function getFrequencyChoices( config: PromoTargetDonateConfig | null ): { value: DonateFrequencySlug; label: string }[] {
	if ( ! config ) {
		return [];
	}
	return ( Object.keys( FREQUENCY_LABELS ) as DonateFrequencySlug[] )
		.filter( slug => config.frequencies[ slug ]?.enabled )
		.map( slug => ( { value: slug, label: FREQUENCY_LABELS[ slug ] } ) );
}

export function getAmountChoices(
	config: PromoTargetDonateConfig | null,
	frequency: DonateFrequencySlug
): { presets: number[]; supportsCustom: boolean; suggested: number | null } {
	const frequencyConfig = config?.frequencies[ frequency ];
	if ( ! frequencyConfig ) {
		return { presets: [], supportsCustom: false, suggested: null };
	}
	return {
		presets: frequencyConfig.amounts,
		supportsCustom: frequencyConfig.supports_custom,
		suggested: frequencyConfig.suggested,
	};
}

export function getDefaultFrequency( item: SubscriptionProduct, config: PromoTargetDonateConfig | null ): DonateFrequencySlug {
	const fromPeriod: DonateFrequencySlug = item.period === 'month' || item.period === 'year' ? item.period : 'once';
	if ( config?.frequencies[ fromPeriod ]?.enabled ) {
		return fromPeriod;
	}
	const fallback = config?.default_frequency as DonateFrequencySlug | undefined;
	if ( fallback && config?.frequencies[ fallback ]?.enabled ) {
		return fallback;
	}
	return getFrequencyChoices( config )[ 0 ]?.value || 'month';
}

/**
 * Resolve the product_id/variation_id a page-path URL must carry so the block
 * on the page can actually serve the chosen child. A member-only button emits
 * a plain product URL — the JS trigger rejects product_id === variation_id
 * (NPPM-2872 residual quirk), so that combination must never be produced.
 */
export function resolvePageProductParams(
	blocks: PromoTargetBlockConfig[],
	itemId: number,
	chosenChild: number | ''
): { productId: number; variationId: number | null } {
	if ( chosenChild === '' ) {
		const parentBlock = blocks.find( block => block.has_variation_picker );
		return { productId: parentBlock ? parentBlock.product_id : itemId, variationId: null };
	}
	const locked = blocks.find( block => block.variation_id === chosenChild );
	if ( locked ) {
		return { productId: locked.product_id, variationId: chosenChild };
	}
	const member = blocks.find( block => block.variation_id === null && ! block.has_variation_picker && block.product_id === chosenChild );
	if ( member ) {
		return { productId: chosenChild, variationId: null };
	}
	const picker = blocks.find( block => block.has_variation_picker );
	if ( picker ) {
		return { productId: picker.product_id, variationId: chosenChild };
	}
	return { productId: itemId, variationId: chosenChild };
}
