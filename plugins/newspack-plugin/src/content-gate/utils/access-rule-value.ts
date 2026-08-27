/**
 * Verdicts on a stored access rule value, shared by the two surfaces that render
 * one: the Audience wizard's rule control and the block editor's visibility
 * panel. Both read the same registry and evaluate through the same
 * `Newspack\Access_Rules::evaluate_rules()`, so a value that means "denies every
 * reader" on one has to mean it on the other.
 *
 * Typed on the shape these read rather than on either surface's rule type, which
 * differ: the wizard's carries the rule's current value, the editor's does not.
 */
type AccessRuleShape = {
	has_options: boolean;
	is_boolean?: boolean;
	empty_grants_access?: boolean;
};

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
	if ( ! config || config.is_boolean || 'boolean' === typeof value ) {
		return false;
	}
	if ( config.has_options ) {
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
