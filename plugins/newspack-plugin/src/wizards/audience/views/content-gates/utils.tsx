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
 * Whether Audience Management is set up, i.e. whether the gate-editing screens
 * may be used at all.
 *
 * Audience Management is a hard prerequisite for content gating - see the
 * `Audience_Management_Dependency` PHP trait for why. The `requireAudienceManagement`
 * router wrapper is the caller; the rule lives here so the wire format below is
 * encoded in exactly one place.
 *
 * `wp_localize_script()` stringifies the PHP boolean, so the value arrives as
 * '1' when on and '' when off - which is why this is a truthiness check and not
 * a comparison against `true`.
 */
export const hasAudienceManagement = () => Boolean( window.newspackAudienceContentGates?.audience_management_enabled );

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

export const getGateStatus = ( status: GateStatus ) => {
	return status === 'publish' ? __( 'Active', 'newspack-plugin' ) : __( 'Inactive', 'newspack-plugin' );
};

export const getGateStatusBadgeLevel = ( status: GateStatus ) => {
	return status === 'publish' ? 'success' : 'default';
};
