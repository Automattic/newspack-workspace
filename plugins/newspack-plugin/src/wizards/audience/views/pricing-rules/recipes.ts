/**
 * Pricing-path recipes — the intent-first map that turns the advanced rule form
 * into a recipe. Each named path presets the lifecycle matcher + application and
 * hides them; Custom presets nothing. See DP spec 20.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

export type PricingPath = 'new_subscriptions' | 'retention' | 'save' | 'winback' | 'custom';

type ConditionsMap = { [ id: string ]: boolean | number | number[] | null };

/** The mutually-exclusive boolean lifecycle condition matchers a path owns. */
export const LIFECYCLE_CONDITIONS = [ 'first_time_only', 'lapsed_subscriber', 'pending_cancellation' ] as const;

export interface Recipe {
	/** Condition matcher id forced on for this path, or null (retention/custom). */
	lifecycleCondition: string | null;
	/** Application forced for this path, or null when the user picks it (custom). */
	application: 'locked' | 'current' | null;
	/** Default scope applied when the path is chosen — subscription presets target all subscriptions. */
	defaultScope: string;
	/** Default cycle anchor — retention rebases to first apply; others count from subscription start. */
	cycleAnchor: 'subscription_start' | 'rule_application';
	/** Custom = the full advanced form (nothing preset or hidden). */
	isCustom: boolean;
}

export const RECIPES: Record< PricingPath, Recipe > = {
	new_subscriptions: {
		lifecycleCondition: 'first_time_only',
		application: 'locked',
		defaultScope: 'all_subscriptions',
		cycleAnchor: 'subscription_start',
		isCustom: false,
	},
	retention: {
		lifecycleCondition: null,
		application: 'current',
		defaultScope: 'all_subscriptions',
		cycleAnchor: 'rule_application',
		isCustom: false,
	},
	save: {
		lifecycleCondition: 'pending_cancellation',
		application: 'locked',
		defaultScope: 'all_subscriptions',
		cycleAnchor: 'subscription_start',
		isCustom: false,
	},
	winback: {
		lifecycleCondition: 'lapsed_subscriber',
		application: 'locked',
		defaultScope: 'all_subscriptions',
		cycleAnchor: 'subscription_start',
		isCustom: false,
	},
	custom: {
		lifecycleCondition: null,
		application: null,
		defaultScope: 'all_products',
		cycleAnchor: 'subscription_start',
		isCustom: true,
	},
};

/** Path options for the editor's first SelectControl (ordered). */
export function pathOptions(): { label: string; value: PricingPath }[] {
	return [
		{ label: __( 'New subscriptions', 'newspack-plugin' ), value: 'new_subscriptions' },
		{ label: __( 'Subscription retention', 'newspack-plugin' ), value: 'retention' },
		{ label: __( 'Save', 'newspack-plugin' ), value: 'save' },
		{ label: __( 'Win-back', 'newspack-plugin' ), value: 'winback' },
		{ label: __( 'Custom', 'newspack-plugin' ), value: 'custom' },
	];
}

/**
 * Apply a path's recipe to a conditions map: clear every lifecycle matcher, then
 * set the path's one (if any). Non-lifecycle conditions (reader_segment, cohort)
 * are preserved.
 */
export function applyRecipeConditions( path: PricingPath, conditions: ConditionsMap ): ConditionsMap {
	const next: ConditionsMap = { ...conditions };
	LIFECYCLE_CONDITIONS.forEach( id => {
		delete next[ id ];
	} );
	const { lifecycleCondition } = RECIPES[ path ];
	if ( lifecycleCondition ) {
		next[ lifecycleCondition ] = true;
	}
	return next;
}

/**
 * Which condition field_types are editable for a path. Named paths expose only
 * segmentation ('select'); Custom exposes everything.
 */
export function isConditionVisible( path: PricingPath, fieldType: string ): boolean {
	return RECIPES[ path ].isCustom ? true : 'select' === fieldType;
}

/** Human label for a stored intent value (falls back to the raw value). */
export function intentLabel( value: string ): string {
	return pathOptions().find( o => o.value === value )?.label ?? value;
}

/** A plain-language explanation of a path's use and what it presets, shown under the goal picker. */
export function pathDescription( path: PricingPath ): string {
	const map: Record< PricingPath, string > = {
		new_subscriptions: __(
			'Acquisition pricing for first-time subscribers — an intro or stepped offer. Presets new-subscribers-only eligibility, locks the price in at purchase, and targets all subscriptions.',
			'newspack-plugin'
		),
		retention: __(
			'A renewal discount to keep existing subscribers. Stays “always current” so it re-applies at every renewal and targets all subscriptions. Add a reader segment to target a specific at-risk audience.',
			'newspack-plugin'
		),
		save: __(
			'A last-chance offer at the cancellation moment. Applies when a pending-cancel subscriber reactivates, locks the saved price in, and targets all subscriptions.',
			'newspack-plugin'
		),
		winback: __(
			'Re-acquisition pricing to win back lapsed subscribers. Applies to readers with no active subscription when they resubscribe, locks the price in at purchase, and targets all subscriptions.',
			'newspack-plugin'
		),
		custom: __(
			'Full manual control — nothing is preset. Set the eligibility matcher, lock behavior, scope, and pricing yourself.',
			'newspack-plugin'
		),
	};
	return map[ path ];
}
