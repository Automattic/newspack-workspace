/**
 * Subscription Products management screen.
 *
 * A DataViews list of WooCommerce Subscriptions products with the consolidated product
 * model, plus the applied-rule stack + effective price (behind the
 * Subscription_Policy_Resolver seam).
 */

import '../../../../shared/js/public-path';

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { forwardRef } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { Wizard, withWizard } from '../../../../../packages/components/src';
import SubscriptionProductsList from './list';
import ProductEdit from './product-edit';
import './style.scss';

const ROOT = [ { label: __( 'Audience Management', 'newspack-plugin' ) } ];
const PLANS = [ ...ROOT, { label: __( 'Plans', 'newspack-plugin' ) } ];
const PLANS_TRAIL = [ ...ROOT, { label: __( 'Plans', 'newspack-plugin' ), url: '#/' } ];

const AudienceSubscriptionProducts = ( props: object, ref: React.Ref< HTMLDivElement > ) => {
	return (
		<Wizard
			ref={ ref }
			sections={ [
				// Scope tabs. Each renders the same list, filtered to its scope (passed via
				// `props`). The first two are *individual* products by purpose; "Plan bundles"
				// is a separate structural lens for grouped containers, so a bundle never appears
				// inline among the products it bundles. Default (`/`) is non-donation subscriptions.
				{
					path: '/',
					label: __( 'Subscriptions', 'newspack-plugin' ),
					render: SubscriptionProductsList,
					props: { scope: 'subscriptions' },
					exact: true,
					breadcrumbs: PLANS,
					fullWidth: true,
				},
				{
					path: '/donations',
					label: __( 'Donations', 'newspack-plugin' ),
					render: SubscriptionProductsList,
					props: { scope: 'donations' },
					exact: true,
					breadcrumbs: PLANS,
					fullWidth: true,
				},
				{
					path: '/bundles',
					label: __( 'Plan bundles', 'newspack-plugin' ),
					render: SubscriptionProductsList,
					props: { scope: 'groups' },
					exact: true,
					breadcrumbs: PLANS,
					fullWidth: true,
				},
				{
					path: '/new',
					render: ProductEdit,
					isHidden: true,
					exact: true,
					breadcrumbs: PLANS_TRAIL,
					backNav: '#/',
				},
				{
					path: '/edit/:id',
					render: ProductEdit,
					isHidden: true,
					exact: true,
					breadcrumbs: PLANS_TRAIL,
					backNav: '#/',
				},
			] }
		/>
	);
};

export default withWizard( forwardRef( AudienceSubscriptionProducts ) );
