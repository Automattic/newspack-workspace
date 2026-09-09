/**
 * Pricing-path recipes — the intent-first map that turns the advanced rule form
 * into a recipe. Each named path presets the lifecycle matcher + application and
 * hides them; Custom presets nothing.
 */

/**
 * WordPress dependencies
 */
import { __, _x } from '@wordpress/i18n';
import { lifesaver, loop, plus, settings, undo } from '@wordpress/icons';

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

/** Path options for the editor's goal picker (ordered), each with its card icon. */
export function pathOptions(): { label: string; value: PricingPath; icon: JSX.Element }[] {
	return [
		{ label: __( 'New Subscriptions', 'newspack-plugin' ), value: 'new_subscriptions', icon: plus },
		{ label: __( 'Retention', 'newspack-plugin' ), value: 'retention', icon: loop },
		{ label: _x( 'Save', 'pricing-rule goal', 'newspack-plugin' ), value: 'save', icon: lifesaver },
		{ label: __( 'Win-Back', 'newspack-plugin' ), value: 'winback', icon: undo },
		{ label: _x( 'Custom', 'pricing-rule goal', 'newspack-plugin' ), value: 'custom', icon: settings },
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
	return RECIPES[ path ]?.isCustom ? true : 'select' === fieldType;
}

/**
 * Whether a raw value names a real path. Own keys only: `in` would accept inherited
 * members, letting `#/new/toString` past the route guard.
 */
export function isPricingPath( value: string ): value is PricingPath {
	return Object.prototype.hasOwnProperty.call( RECIPES, value );
}

/** Human label for a stored intent value (falls back to the raw value). */
export function intentLabel( value: string ): string {
	return pathOptions().find( o => o.value === value )?.label ?? value;
}

/**
 * Own keys only, for the same reason `isPricingPath` checks them: a stored intent of
 * `toString` or `constructor` would otherwise resolve to an `Object.prototype` member.
 */
function ownValue( map: Record< PricingPath, string >, path: PricingPath ): string {
	return Object.prototype.hasOwnProperty.call( map, path ) ? map[ path ] : '';
}

/** A one-line summary of a path, shown on its card in the goal picker. */
export function pathSummary( path: PricingPath ): string {
	const map: Record< PricingPath, string > = {
		new_subscriptions: __( 'An introduction or stepped offer for first-time subscribers.', 'newspack-plugin' ),
		retention: __( 'A renewal discount to keep existing subscribers.', 'newspack-plugin' ),
		save: __( 'A last-chance offer made at the cancellation moment.', 'newspack-plugin' ),
		winback: __( 'Re-acquisition pricing to bring lapsed subscribers back.', 'newspack-plugin' ),
		custom: __( 'Full manual control, with none of the options preset.', 'newspack-plugin' ),
	};
	return ownValue( map, path );
}
