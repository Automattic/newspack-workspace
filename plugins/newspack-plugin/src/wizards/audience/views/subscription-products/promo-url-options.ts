/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { DonateFrequencySlug, PromoKind } from './promo-url';

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
	// Children a target page's picker can actually serve — for a grouped plan
	// this excludes children the tiers form skips (non-subscription, private).
	eligible_children?: number[];
};

const FREQUENCY_LABELS: Record< DonateFrequencySlug, string > = {
	once: __( 'One-time', 'newspack-plugin' ),
	month: __( 'Monthly', 'newspack-plugin' ),
	year: __( 'Annually', 'newspack-plugin' ),
};

/**
 * Child-product choices for a plan row, constrained to what the chosen page's
 * blocks accept. Returns nothing until a page is chosen, since the blocks on
 * that page decide which children a link can name. The "reader chooses" option
 * appears only when a block renders a picker.
 *
 * `eligibleChildren` (from the server) narrows the picker's options to children
 * its form will actually render: a grouped plan can bundle children the tiers
 * form skips, and offering one of those would emit a URL matching no radio.
 */
export function getVariationChoices(
	item: SubscriptionProduct,
	targetBlocks: PromoTargetBlockConfig[] | null,
	eligibleChildren?: number[]
): { value: number | ''; label: string }[] {
	if ( ! targetBlocks ) {
		return [];
	}
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
	const pickerServable = eligibleChildren?.length ? children.filter( child => eligibleChildren.includes( child.value as number ) ) : children;
	const allowed = new Set< number >();
	let allowChooser = false;
	for ( const block of targetBlocks ) {
		if ( block.variation_id ) {
			allowed.add( block.variation_id );
		} else if ( block.has_variation_picker ) {
			allowChooser = true;
			pickerServable.forEach( child => allowed.add( child.value as number ) );
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

export type PromoValidationInput = {
	kind: PromoKind;
	hasTarget: boolean;
	requiresChild: boolean;
	variationId: number | '';
	donateConfig: PromoTargetDonateConfig | null;
	effectiveAmount: number | 'other' | undefined;
	customAmount: string;
	presets: number[];
};

/**
 * The gate that decides whether a promotional URL may be emitted at all: the
 * modal shows the returned message and withholds the link. Kept pure so each
 * refusal is directly testable — a gap here is what lets a silently-failing
 * link reach a publisher. Returns null when the selections are valid.
 */
export function getValidationError( input: PromoValidationInput ): string | null {
	const { kind, donateConfig, effectiveAmount, customAmount } = input;
	if ( ! input.hasTarget ) {
		return __( 'Choose a target page.', 'newspack-plugin' );
	}
	if ( kind === 'product' && input.requiresChild && input.variationId === '' ) {
		return __( 'Choose which plan option the link should check out.', 'newspack-plugin' );
	}
	if ( kind === 'donation' ) {
		if ( ! donateConfig ) {
			return __( 'The Donate block on this page cannot take a promotional link.', 'newspack-plugin' );
		}
		if ( effectiveAmount === undefined ) {
			return __( 'Enter a valid amount.', 'newspack-plugin' );
		}
		const numeric = effectiveAmount === 'other' ? parseFloat( customAmount ) : effectiveAmount;
		if ( numeric < donateConfig.minimum ) {
			return sprintf(
				/* translators: %s: minimum donation amount. */
				__( 'The amount must be at least %s.', 'newspack-plugin' ),
				donateConfig.minimum
			);
		}
		if ( typeof effectiveAmount === 'number' && donateConfig.layout_param !== 'untiered' && ! input.presets.includes( effectiveAmount ) ) {
			return __( 'Choose one of the amounts available on the target page.', 'newspack-plugin' );
		}
	}
	return null;
}

/**
 * Resolve the product_id/variation_id a page URL must carry so the block on the
 * page can actually serve the chosen child. A member-only button emits a plain
 * product URL — the JS trigger rejects product_id === variation_id (NPPM-2872
 * residual quirk), so that combination must never be produced.
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
