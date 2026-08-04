/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { DonateFrequencySlug, PromoKind } from './promo-url';

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

export type PromoDonateTarget = {
	id: number;
	title: string;
	url: string;
	blocks: PromoTargetDonateConfig[];
};

// Donation links must target a page carrying a Donate block.
export type DonateTargetsResponse = {
	targets: PromoDonateTarget[];
	truncated: boolean;
};

// Product links work over any URL; the server supplies the homepage default
// and which plan children a link may name.
export type ProductPromoContext = {
	homepage: { id: number; title: string; url: string };
	// Children a picker can actually serve — for a grouped plan this excludes
	// children the tiers form skips (non-subscription, private).
	eligible_children: number[];
};

export type PromoPageChoice = { value: string; label: string; url: string };

export type PromoCouponResponse = { valid: boolean; reason?: string };

const FREQUENCY_LABELS: Record< DonateFrequencySlug, string > = {
	once: __( 'One-time', 'newspack-plugin' ),
	month: __( 'Monthly', 'newspack-plugin' ),
	year: __( 'Annually', 'newspack-plugin' ),
};

/**
 * Child-product choices for a plan row. The "reader chooses" option opens the
 * plan's picker over the page, so it is offered whenever that picker can render
 * at least one option: always for a variable plan, and for a grouped plan only
 * when the tiers form serves a child (`eligibleChildren`, from the server).
 */
export function getPlanChoices( item: SubscriptionProduct, eligibleChildren: number[] ): { value: number | ''; label: string }[] {
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
	const allowChooser = item.type === 'grouped' ? eligibleChildren.length > 0 : true;
	return allowChooser ? [ { value: '' as const, label: __( 'Let the reader choose', 'newspack-plugin' ) }, ...children ] : children;
}

/**
 * Resolve the product_id/variation_id a URL must carry for the chosen child.
 * The reader-chooses option names the parent (the picker opens over the page);
 * a grouped member is a plain product (the trigger rejects
 * product_id === variation_id — NPPM-2872 residual quirk); a variation rides
 * on its parent.
 */
export function resolveProductParams( item: SubscriptionProduct, chosenChild: number | '' ): { productId: number; variationId: number | null } {
	if ( chosenChild === '' ) {
		return { productId: item.id, variationId: null };
	}
	if ( item.type === 'grouped' ) {
		return { productId: chosenChild, variationId: null };
	}
	return { productId: item.id, variationId: chosenChild };
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
	couponState?: 'idle' | 'checking' | 'valid' | 'invalid';
	couponReason?: string;
	afterSuccess?: '' | 'custom';
	afterSuccessUrl?: string;
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
	if ( 'invalid' === input.couponState ) {
		return input.couponReason || __( 'The coupon code is not valid.', 'newspack-plugin' );
	}
	if ( 'custom' === input.afterSuccess && ! input.afterSuccessUrl ) {
		return __( 'Enter the URL readers should continue to after checkout.', 'newspack-plugin' );
	}
	return null;
}
