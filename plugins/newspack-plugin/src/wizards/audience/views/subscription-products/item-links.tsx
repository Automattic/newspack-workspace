/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Handoff from '../../../../../packages/components/src/handoff';

/**
 * Links to the things a plan points at: the gates it unlocks, the plans a bundle
 * holds and the pricing rules applied to it. Shown as plain links, one per line;
 * a badge in these columns would read as a state.
 *
 * Gates and rules open another wizard, so they go through a handoff: the
 * destination shows a banner back to Plans. A bundled plan opens in Plans itself,
 * which has its own way back.
 */

const ACCESS_CONTROL_URL = 'admin.php?page=newspack-audience-access-control';
const PRICING_RULES_URL = 'admin.php?page=newspack-audience-pricing-rules';

const BackToPlans = ( { url, children }: { url: string; children: string } ) => (
	<Handoff
		url={ url }
		isLink
		bannerText={ __( 'Return to Plans once you’re done.', 'newspack-plugin' ) }
		bannerButtonText={ __( 'Back to Plans', 'newspack-plugin' ) }
	>
		{ children }
	</Handoff>
);

export const gateLinks = ( unlocks: SubscriptionProduct[ 'unlocks' ] ) =>
	unlocks.map( gate => (
		<BackToPlans key={ gate.id } url={ `${ ACCESS_CONTROL_URL }#/edit/${ gate.id }` }>
			{ gate.title }
		</BackToPlans>
	) );

export const bundledLinks = ( bundled: SubscriptionProductBundled[] ) =>
	bundled.map( plan => (
		<a key={ plan.id } href={ `#/edit/${ plan.id }` }>
			{ plan.name }
		</a>
	) );

export const ruleLinks = ( policy: SubscriptionPolicyResolution ) =>
	( policy?.policies ?? [] ).map( rule => (
		<BackToPlans key={ rule.id } url={ `${ PRICING_RULES_URL }#/edit/${ rule.id }` }>
			{ rule.label }
		</BackToPlans>
	) );
