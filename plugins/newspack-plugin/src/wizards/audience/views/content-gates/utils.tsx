/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
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
 * Resolve the free-view allowance one audience path grants.
 *
 * The count lives on the site meter unless the path opts out, so a stale count
 * left on the gate must not be read while it is sharing.
 *
 * @param metering  The path's metering settings.
 * @param siteCount The site meter count governing this path.
 */
export const getMeteringCount = ( metering?: Metering, siteCount?: number ) => {
	if ( ! metering ) {
		return 0;
	}
	if ( metering.scope === 'gate' ) {
		return Number( metering.count ) || 0;
	}
	// No allowance rather than a guessed one: the summaries print this number and the
	// metered/not-metered helpers read it, so inventing a default states an allowance
	// the site may not grant. A wizard whose config carries no site meter, such as
	// Premium Newsletters, only ever asks about gates keeping their own.
	return Number( siteCount ?? 0 ) || 0;
};

/**
 * Whether a gate actually meters, i.e. it grants at least one free view.
 *
 * Metering switched on with 0 free views gates every reader on their first view, so
 * nothing downstream of metering (the countdown banner, content gifting) has anything
 * to count. This mirrors `Newspack\Metering::is_gate_metered()` on the PHP side, which
 * is what those surfaces are gated on at render time - a section only meters while it
 * is active, has metering on, and allows a positive number of views.
 */
export const isGateMetered = ( gate: Gate, siteMeter?: SiteMeterConfig ) => {
	const meters = ( section?: Registration | CustomAccess, siteCount?: number ) =>
		Boolean( section?.active && section?.metering?.enabled && getMeteringCount( section.metering, siteCount ) > 0 );
	// Signed-out readers fall through to the paywall when there is no registration wall.
	const signedOutPath = gate.registration?.active ? gate.registration : gate.custom_access;
	return meters( signedOutPath, siteMeter?.anonymous_count ) || meters( gate.custom_access, siteMeter?.registered_count );
};

/**
 * Whether any of a gate's audience paths keeps its own allowance.
 *
 * Scope is stored per audience path, and adoption stamps only the paths that disagree
 * with the shared allowance, so a gate can hold one of each. This answers "any path
 * opted out", which is the question for warning that a gate is not wholly governed by
 * the Metering page. It is not the complement of `hasSharedMeteredPath()`: a mixed gate
 * satisfies both.
 *
 * @param gate The gate.
 */
export const hasOwnMeter = ( gate: Gate ) => {
	const optsOut = ( section?: Registration | CustomAccess ) =>
		Boolean( section?.active && section?.metering?.enabled && section.metering.scope === 'gate' );
	return optsOut( gate.registration ) || optsOut( gate.custom_access );
};

/**
 * Whether any of a gate's audience paths still draws on the shared allowance.
 *
 * The question the Metering page needs before warning that changing the allowance
 * leaves gate wording behind: a gate with one path pinned and one path sharing still
 * quotes the shared number somewhere.
 *
 * @param gate      The gate.
 * @param siteMeter The site meter, once the wizard has loaded it.
 */
export const hasSharedMeteredPath = ( gate: Gate, siteMeter?: SiteMeterConfig ) => {
	const shares = ( section: Registration | CustomAccess | undefined, siteCount?: number ) =>
		Boolean(
			section?.active && section?.metering?.enabled && section.metering.scope !== 'gate' && getMeteringCount( section.metering, siteCount ) > 0
		);
	const signedOutPath = gate.registration?.active ? gate.registration : gate.custom_access;
	return shares( signedOutPath, siteMeter?.anonymous_count ) || shares( gate.custom_access, siteMeter?.registered_count );
};

/**
 * Whether a gate draws on the shared allowance at all, whatever that allowance is.
 *
 * Deliberately blind to the count, which is what separates it from
 * `hasSharedMeteredPath()`: the Metering page needs this to warn that an allowance of
 * 0 gates readers immediately, and at 0 the count test that helper applies is false.
 *
 * @param gate The gate.
 */
export const sharesTheSiteMeter = ( gate: Gate ) => {
	const shares = ( section?: Registration | CustomAccess ) =>
		Boolean( section?.active && section?.metering?.enabled && section.metering.scope !== 'gate' );
	return shares( gate.registration ) || shares( gate.custom_access );
};

export const getGateStatus = ( status: GateStatus ) => {
	return status === 'publish' ? __( 'Active', 'newspack-plugin' ) : __( 'Inactive', 'newspack-plugin' );
};

export const getGateStatusBadgeLevel = ( status: GateStatus ) => {
	return status === 'publish' ? 'success' : 'default';
};

/**
 * Describe the shared allowance, for the Metering card and the Metering page header.
 *
 * @param siteMeter The site meter, once the wizard has loaded it.
 */
export const getMeteringDescription = ( siteMeter?: SiteMeterConfig ) => {
	if ( ! siteMeter ) {
		return __( 'Set how many articles readers can view for free before a gate applies.', 'newspack-plugin' );
	}
	return sprintf(
		// translators: 1: free views for signed-out readers, 2: free views for signed-in readers, 3: how often the allowance resets, e.g. "monthly".
		__(
			'Free views reset %3$s: %1$d for signed-out readers, %2$d for signed-in. Every gate shares this allowance unless it keeps its own.',
			'newspack-plugin'
		),
		siteMeter.anonymous_count,
		siteMeter.registered_count,
		siteMeter.period === 'week' ? __( 'weekly', 'newspack-plugin' ) : __( 'monthly', 'newspack-plugin' )
	);
};
