/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

/**
 * Get edit gate layout URL.
 */
export function getEditGateLayoutUrl( gateId: number, gateMode: string ) {
	const audienceGates = ( window as any ).newspackAudienceContentGates;

	if ( ! audienceGates || typeof audienceGates.edit_gate_layout_url !== 'string' || ! audienceGates.edit_gate_layout_url ) {
		// Fallback to avoid runtime errors if the global config is not available.
		// eslint-disable-next-line no-console
		console.error( 'newspackAudienceContentGates.edit_gate_layout_url is not defined on window.' );
		return '';
	}

	let url = audienceGates.edit_gate_layout_url;
	if ( gateId ) {
		url = addQueryArgs( url, { gate_id: gateId } );
	}
	if ( gateMode ) {
		url = addQueryArgs( url, { gate_mode: gateMode } );
	}
	return url;
}

/**
 * Whether a gate actually meters, i.e. it grants at least one free view.
 *
 * Metering switched on with 0 free views gates every reader on their first view, so
 * nothing downstream of metering (the countdown banner, content gifting) has anything
 * to count. This mirrors `Newspack\Metering::is_gate_metered()` on the PHP side, which
 * is what those surfaces are gated on at render time - a section only meters while it
 * is active, has metering on, and allows a positive number of views.
 */
export const isGateMetered = ( gate: Gate ) => {
	const meters = ( section?: Registration | CustomAccess ) =>
		Boolean( section?.active && section?.metering?.enabled && Number( section.metering.count ) > 0 );
	return meters( gate.registration ) || meters( gate.custom_access );
};

/**
 * Whether a rule holds the empty value for its shape: `[]` on an options-backed
 * rule, `''` on a free-text one, or nothing stored at all.
 */
const isEmptyAccessRuleValue = ( value: GateAccessRuleValue ) =>
	null === value || undefined === value || '' === value || ( Array.isArray( value ) && 0 === value.length );

/**
 * Whether a stored access rule value is in a shape the rule can't use: free text
 * on an options-backed rule, or a list on a free-text one. Such a value denies
 * every reader, since `Newspack\Access_Rules::evaluate_rule()` fails closed on
 * it, so the wizard has to label it rather than render it as a live condition.
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
export const isMalformedAccessRuleValue = ( config: AccessRule | undefined, value: GateAccessRuleValue ) => {
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
export const isUnconstrainedAccessRuleValue = ( config: AccessRule | undefined, value: GateAccessRuleValue ) =>
	Boolean( config?.empty_grants_access ) && isEmptyAccessRuleValue( value );

export const getGateStatus = ( status: GateStatus ) => {
	return status === 'publish' ? __( 'Active', 'newspack-plugin' ) : __( 'Inactive', 'newspack-plugin' );
};

// An inactive gate is an unpublished draft post, not a settled "off" state.
export const getGateStatusBadgeIntent = ( status: GateStatus ): 'stable' | 'draft' => {
	return status === 'publish' ? 'stable' : 'draft';
};
