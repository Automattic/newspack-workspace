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

type AccessRuleShape = {
	has_options?: boolean;
	is_boolean?: boolean;
	empty_grants_access?: boolean;
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
 * Rules with a composite value shape (one-time purchase) own their formatting and
 * their control, both of which run before this, so only the list/text split is
 * decided here.
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
	if ( takesOptionValues( config ) ) {
		return ! Array.isArray( value ) && ! isEmptyAccessRuleValue( value );
	}
	return Array.isArray( value );
};

/**
 * Whether a rule imposes no constraint as stored, and so grants access to every
 * reader. Only rules that declare `empty_grants_access` read their empty value
 * that way — `subscription` naming no product still requires an active one.
 */
export const isUnconstrainedAccessRuleValue = ( config: AccessRuleShape | undefined, value: unknown ) =>
	Boolean( config?.empty_grants_access ) && isEmptyAccessRuleValue( value );

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
	if ( ! isUnconstrainedAccessRuleValue( config, value ) ) {
		return undefined;
	}
	// Nothing to pick and nothing picked: every answer the picker can express is the
	// one that lets every reader through, so say where the items come from.
	return hasOptions
		? __( 'Nothing is selected, so this rule grants access to everyone. Select at least one option, or turn the rule off.', 'newspack-plugin' )
		: __(
				'This rule has nothing to select yet, so it grants access to everyone. Add the items it selects, or turn the rule off.',
				'newspack-plugin'
		  );
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
	! hasOptions && isUnconstrainedAccessRuleValue( config, value );
