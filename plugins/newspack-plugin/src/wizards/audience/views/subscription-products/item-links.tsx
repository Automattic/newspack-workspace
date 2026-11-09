/**
 * Links to the things a plan points at: the gates it unlocks, the plans a bundle
 * holds and the pricing rules applied to it. Shown as plain links, one per line;
 * a badge in these columns would read as a state.
 */

const ACCESS_CONTROL_URL = 'admin.php?page=newspack-audience-access-control';
const PRICING_RULES_URL = 'admin.php?page=newspack-audience-pricing-rules';

export const gateLinks = ( unlocks: SubscriptionProduct[ 'unlocks' ] ) =>
	unlocks.map( gate => (
		<a key={ gate.id } href={ `${ ACCESS_CONTROL_URL }#/edit/${ gate.id }` }>
			{ gate.title }
		</a>
	) );

export const bundledLinks = ( bundled: SubscriptionProductBundled[] ) =>
	bundled.map( plan => (
		<a key={ plan.id } href={ `#/edit/${ plan.id }` }>
			{ plan.name }
		</a>
	) );

export const ruleLinks = ( policy: SubscriptionPolicyResolution ) =>
	( policy?.policies ?? [] ).map( rule => (
		<a key={ rule.id } href={ `${ PRICING_RULES_URL }#/edit/${ rule.id }` } title={ `${ rule.label } — ${ rule.adjustment_label }` }>
			{ rule.label }
		</a>
	) );
