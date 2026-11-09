/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { decodeEntities } from '@wordpress/html-entities';
import { Button } from '@wordpress/components';

/**
 * Links to the things a plan points at: the gates it unlocks, the plans a bundle
 * holds and the pricing rules applied to it. Shown as plain links, one per line;
 * a badge in these columns would read as a state.
 *
 * Gates and rules open another wizard, so they go through a handoff: the
 * destination shows a banner back to Plans. It is the Handoff component's request,
 * made here so the edit form's unsaved-changes dialog can run first; a failed
 * request still navigates, without the banner. A bundled plan opens in Plans itself,
 * which has its own way back; one Plans doesn't list stays plain text, since its
 * edit view would bounce back to the list.
 */

const ACCESS_CONTROL_URL = 'admin.php?page=newspack-audience-access-control';
const PRICING_RULES_URL = 'admin.php?page=newspack-audience-pricing-rules';
const EDITABLE_BUNDLED_TYPES = [ 'subscription', 'variable-subscription' ];

/** The edit form's unsaved-changes guard, from `useUnsavedChangesDialog`. */
type LeaveGuard = {
	requestConfirm: ( onConfirm: () => void ) => void;
	allowNextUnload: () => void;
};

const BackToPlans = ( { url, guard, children }: { url: string; guard?: LeaveGuard; children: string } ) => {
	const leave = () =>
		apiFetch< { HandoffLink: string } >( {
			path: '/newspack/v1/handoff',
			method: 'POST',
			data: {
				destinationUrl: url,
				handoffReturnUrl: window.location.href,
				bannerText: __( 'Return to Plans once you’re done.', 'newspack-plugin' ),
				bannerButtonText: __( 'Back to Plans', 'newspack-plugin' ),
			},
		} )
			.then(
				response => response.HandoffLink,
				() => url
			)
			.then( destination => {
				guard?.allowNextUnload();
				window.location.href = destination;
			} );
	return (
		<Button variant="link" onClick={ () => ( guard ? guard.requestConfirm( leave ) : leave() ) }>
			{ children }
		</Button>
	);
};

export const gateLinks = ( unlocks: SubscriptionProduct[ 'unlocks' ], guard?: LeaveGuard ) =>
	unlocks.map( gate => (
		<BackToPlans key={ gate.id } url={ `${ ACCESS_CONTROL_URL }#/edit/${ gate.id }` } guard={ guard }>
			{ decodeEntities( gate.title ) || `#${ gate.id }` }
		</BackToPlans>
	) );

export const bundledLinks = ( bundled: SubscriptionProductBundled[] ) =>
	bundled.map( plan =>
		EDITABLE_BUNDLED_TYPES.includes( plan.type ) ? (
			<a key={ plan.id } href={ `#/edit/${ plan.id }` }>
				{ plan.name }
			</a>
		) : (
			<span key={ plan.id }>{ plan.name }</span>
		)
	);

export const ruleLinks = ( policy: SubscriptionPolicyResolution, guard?: LeaveGuard ) =>
	( policy?.policies ?? [] ).map( rule => (
		<BackToPlans key={ rule.id } url={ `${ PRICING_RULES_URL }#/edit/${ rule.id }` } guard={ guard }>
			{ rule.label }
		</BackToPlans>
	) );
