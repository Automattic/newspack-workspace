/**
 * Verdicts on a stored access rule value, shared by the two surfaces that render
 * one: the Audience wizard's rule control and the block editor's visibility
 * panel. Both read the same registry and evaluate through the same
 * `Newspack\Access_Rules::evaluate_rules()`, so a value that means "denies every
 * reader" on one has to mean it on the other.
 *
 * The caution each surface shows is decided here too, not just the verdicts behind
 * it. Deciding it twice is how the two pickers came to disagree about a rule whose
 * options had all been deleted: one called it open, the other called it closed, and
 * only one of them was right.
 *
 * Typed on the shape these read rather than on either surface's rule type, which
 * differ: the wizard's carries the rule's current value, the editor's does not.
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';

export type AccessRuleShape = {
	has_options?: boolean;
	is_boolean?: boolean;
	is_range?: boolean;
	empty_grants_access?: boolean;
	requires_value?: boolean;
	options?: unknown[];
};

/**
 * Whether a rule's value is a list of option values rather than free text.
 *
 * `has_options` is the rule's own answer, from `Access_Rules::register_rule()`, and it
 * settles the question: it is drawn from the rule's value type rather than from whatever
 * its options callback returned. Only where a caller has no registry entry to read — a
 * rule config assembled by hand — does the loaded list stand in for it, and then a
 * populated one is the only evidence available.
 */
const takesOptionValues = ( config: AccessRuleShape ) => config.has_options ?? ( Array.isArray( config.options ) && config.options.length > 0 );

/**
 * Whether a rule holds the empty value for its shape: `[]` on an options-backed
 * rule, `''` on a free-text one, or nothing stored at all.
 */
export const isEmptyAccessRuleValue = ( value: unknown ) =>
	null === value || undefined === value || '' === value || ( Array.isArray( value ) && 0 === value.length );

/**
 * A range rule's value: the bounds a reader's number has to fall between. Either
 * side may be absent, which leaves it open.
 */
export type RangeValue = { min?: number | string; max?: number | string };

const isPlainObject = ( value: unknown ): value is Record< string, unknown > =>
	null !== value && 'object' === typeof value && ! Array.isArray( value );

/**
 * Whether a value has the shape of range bounds: an object keyed by nothing but `min`
 * and `max`, as `Promoted_Fields::is_range_shape()` reads it. Anything else on a range
 * rule denies every reader there.
 */
const isRangeShape = ( value: unknown ): value is Record< string, unknown > =>
	isPlainObject( value ) && Object.keys( value ).every( key => 'min' === key || 'max' === key );

const isRangeBoundSet = ( bound: unknown ): bound is number | string => ( 'number' === typeof bound || 'string' === typeof bound ) && '' !== bound;

const isNumericBound = ( bound: number | string ) => '' !== String( bound ).trim() && Number.isFinite( Number( bound ) );

/**
 * The bounds a stored range value sets, without anything else it holds. A value that
 * isn't a set of bounds sets none.
 *
 * @param value The stored rule value.
 */
export const normalizeRangeValue = ( value: unknown ): RangeValue => {
	const range: RangeValue = {};
	if ( ! isPlainObject( value ) ) {
		return range;
	}
	if ( isRangeBoundSet( value.min ) ) {
		range.min = value.min;
	}
	if ( isRangeBoundSet( value.max ) ) {
		range.max = value.max;
	}
	return range;
};

/**
 * Whether a rule holds the empty value for its shape. A range rule is also empty
 * when it holds bounds with neither side set, which is what clearing both inputs
 * leaves behind and what `Promoted_Fields::sanitize_range_value()` saves as `[]`.
 */
const isEmptyValueForRule = ( config: AccessRuleShape | undefined, value: unknown ) =>
	isEmptyAccessRuleValue( value ) ||
	( Boolean( config?.is_range ) && isRangeShape( value ) && 0 === Object.keys( normalizeRangeValue( value ) ).length );

/**
 * Whether a stored access rule value is in a shape the rule can't use: free text
 * on an options-backed rule, or a list on a free-text one. Such a value denies
 * every reader, since `Newspack\Access_Rules::evaluate_rule()` fails closed on
 * it, so a control has to label it rather than render it as a live condition.
 *
 * An unset value is not one of those. `Newspack\Access_Rules::is_malformed_options_backed_value()`
 * reads `''` and `null` on an options-backed rule as "not configured", and the
 * rule then grants access to every reader — the opposite verdict, which
 * `isUnconstrainedAccessRuleValue()` covers.
 *
 * One-time purchase owns its formatting and its control, both of which run before
 * this. Range bounds are the one composite shape judged here, so that the summary,
 * both editors, and `Promoted_Fields::is_in_range()` agree on which values deny.
 */
export const isMalformedAccessRuleValue = ( config: AccessRuleShape | undefined, value: unknown ) => {
	// Only the rule's own declaration exempts a value from the shape test. A boolean
	// stored against a rule that is not boolean is malformed, which is what PHP says
	// too: `is_malformed_options_backed_value( false )` is true and the save is refused.
	// Such a value predates this change, and reading it as well-formed would render it
	// as an ordinary empty picker with no caution, then fail the save unannounced.
	if ( ! config || config.is_boolean ) {
		return false;
	}
	// Text saved before range rules had a min/max control, or a list left from an
	// options-backed field. The rule's callback fails closed on either.
	if ( config.is_range ) {
		return ! isEmptyAccessRuleValue( value ) && ! isRangeShape( value );
	}
	if ( takesOptionValues( config ) ) {
		return ! Array.isArray( value ) && ! isEmptyAccessRuleValue( value );
	}
	// A list, or bounds left on a field whose operator moved from Number to Text.
	return null !== value && 'object' === typeof value;
};

/**
 * Whether a rule imposes no constraint as stored, and so grants access to every
 * reader. Only rules that declare `empty_grants_access` read their empty value
 * that way — `subscription` naming no product still requires an active one, and
 * `institution` naming none matches nobody.
 */
export const isUnconstrainedAccessRuleValue = ( config: AccessRuleShape | undefined, value: unknown ) =>
	Boolean( config?.empty_grants_access ) && isEmptyValueForRule( config, value );

/**
 * Whether a rule that needs a value has none, so it states no condition at all.
 *
 * The superset of the state above, and the one the editor and the save-time
 * refusal both act on. Every rule granting access on an empty value also
 * declares `requires_value`; `institution` declares only `requires_value`,
 * because it matches nobody instead — a different symptom, equally far from
 * what the operator configured. Only the wording of the caution depends on
 * which of the two it is.
 */
export const isUnconfiguredAccessRuleValue = ( config: AccessRuleShape | undefined, value: unknown ) =>
	Boolean( config?.requires_value ) && isEmptyValueForRule( config, value );

/**
 * The caution to show under a rule's picker, or undefined where the stored value
 * needs none.
 *
 * Both states it reports are about the value, never about the option list: a rule
 * naming institutions that have since been deleted holds a populated value, denies
 * every reader, and gets no caution here — the stale IDs are named by
 * `UnlistedValuesNotice` instead.
 *
 * @param config     The rule's registry entry.
 * @param value      The rule's stored value.
 * @param hasOptions Whether the picker has anything to offer.
 */
export const getAccessRuleValueNotice = ( config: AccessRuleShape | undefined, value: unknown, hasOptions: boolean ): string | undefined => {
	if ( isMalformedAccessRuleValue( config, value ) ) {
		// A value of the wrong shape holds no token, so the picker alone would read as
		// an empty selection — the opposite of what the value does. Name it, or an
		// editor who ends the edit here writes an empty list over a rule that was
		// denying, and opens the gate.
		return 'string' === typeof value
			? sprintf(
					// translators: %s: the stored value.
					__(
						'The saved value “%s” is not one of this rule’s options, so the rule grants no access. Pick from the list to replace it.',
						'newspack-plugin'
					),
					value
			  )
			: __(
					'The saved value is not one of this rule’s options, so the rule grants no access. Pick from the list to replace it.',
					'newspack-plugin'
			  );
	}
	if ( ! isUnconfiguredAccessRuleValue( config, value ) ) {
		return undefined;
	}
	// What an empty value does is the rule's own business, and the two answers are
	// opposites. Naming the wrong one sends an editor looking for the wrong symptom
	// on the front end: an open paywall reads nothing like a walled-off one.
	if ( isUnconstrainedAccessRuleValue( config, value ) ) {
		// Nothing to pick and nothing picked: every answer the picker can express is
		// the one that lets every reader through, so say where the items come from.
		return hasOptions
			? __(
					'Nothing is selected, so this rule grants access to everyone. Select at least one option, or turn the rule off.',
					'newspack-plugin'
			  )
			: __(
					'This rule has nothing to select yet, so it grants access to everyone. Add the items it selects, or turn the rule off.',
					'newspack-plugin'
			  );
	}
	return hasOptions
		? __( 'Nothing is selected, so this rule matches no reader. Select at least one option, or turn the rule off.', 'newspack-plugin' )
		: __( 'This rule has nothing to select yet, so it matches no reader. Add the items it selects, or turn the rule off.', 'newspack-plugin' );
};

/**
 * Whether the picker can express nothing but the value that grants access to
 * everyone, and so has nothing to offer an editor.
 *
 * Not the same question as "is the option list empty": a rule holding IDs no option
 * describes still has tokens to remove, and taking the field out of play would leave
 * deleting the whole rule as the only way to change it.
 *
 * @param config     The rule's registry entry.
 * @param value      The rule's stored value.
 * @param hasOptions Whether the picker has anything to offer.
 */
export const isAccessRulePickerInert = ( config: AccessRuleShape | undefined, value: unknown, hasOptions: boolean ) =>
	! hasOptions && isUnconfiguredAccessRuleValue( config, value );

/**
 * The caution to show under a range rule's inputs, or undefined where the stored
 * value needs none. Each state names what the rule does with the value, which the
 * inputs alone would not show.
 *
 * @param config The rule's registry entry.
 * @param value  The rule's stored value.
 */
export const getRangeRuleValueNotice = ( config: AccessRuleShape | undefined, value: unknown ): string | undefined => {
	if ( isMalformedAccessRuleValue( config, value ) ) {
		return 'string' === typeof value
			? sprintf(
					// translators: %s: the stored value.
					__(
						'The saved value “%s” is not a minimum or maximum, so this rule grants no access. Enter a minimum, a maximum, or both to replace it.',
						'newspack-plugin'
					),
					value
			  )
			: __(
					'The saved value is not a minimum or maximum, so this rule grants no access. Enter a minimum, a maximum, or both to replace it.',
					'newspack-plugin'
			  );
	}
	if ( isEmptyValueForRule( config, value ) ) {
		return isUnconstrainedAccessRuleValue( config, value )
			? __(
					'No minimum or maximum is set, so this rule grants access to every reader with a number in this field. Enter a minimum, a maximum, or both, or turn the rule off.',
					'newspack-plugin'
			  )
			: __(
					'No minimum or maximum is set, so this rule matches no reader. Enter a minimum, a maximum, or both, or turn the rule off.',
					'newspack-plugin'
			  );
	}
	const { min, max } = normalizeRangeValue( value );
	if ( ( undefined !== min && ! isNumericBound( min ) ) || ( undefined !== max && ! isNumericBound( max ) ) ) {
		return __( 'The minimum and maximum must be numbers. Until they are, this rule grants no access.', 'newspack-plugin' );
	}
	if ( undefined !== min && undefined !== max && Number( min ) > Number( max ) ) {
		return __( 'The minimum is above the maximum, so this rule matches no reader.', 'newspack-plugin' );
	}
	return undefined;
};
