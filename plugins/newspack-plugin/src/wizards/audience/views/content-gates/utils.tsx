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
 * Whether a stored access rule value is in a shape the rule can't use: free text
 * on an options-backed rule, or a list on a free-text one. Such a value denies
 * every reader, since `Newspack\Access_Rules::evaluate_rule()` fails closed on
 * it, so the wizard has to label it rather than render it as a live condition.
 *
 * Rules with a composite value shape (one-time purchase) own their formatting and
 * their control, both of which run before this, so only the list/text split is
 * decided here.
 */
export const isMalformedAccessRuleValue = ( config: AccessRule | undefined, value: GateAccessRuleValue ) => {
	if ( ! config || config.is_boolean || 'boolean' === typeof value ) {
		return false;
	}
	return config.has_options ? ! Array.isArray( value ) : Array.isArray( value );
};

export const getGateStatus = ( status: GateStatus ) => {
	return status === 'publish' ? __( 'Active', 'newspack-plugin' ) : __( 'Inactive', 'newspack-plugin' );
};

// An inactive gate is an unpublished draft post, not a settled "off" state.
export const getGateStatusBadgeIntent = ( status: GateStatus ): 'stable' | 'draft' => {
	return status === 'publish' ? 'stable' : 'draft';
};
