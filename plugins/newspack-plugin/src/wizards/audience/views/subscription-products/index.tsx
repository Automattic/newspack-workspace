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

const AudienceSubscriptionProducts = ( props: object, ref: React.Ref< HTMLDivElement > ) => {
	return (
		<Wizard
			title={ __( 'Plans', 'newspack-plugin' ) }
			headerText={ __( 'Audience Management / Plans', 'newspack-plugin' ) }
			ref={ ref }
			fixedHeader
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
					fullWidth: true,
				},
				{
					path: '/donations',
					label: __( 'Donations', 'newspack-plugin' ),
					render: SubscriptionProductsList,
					props: { scope: 'donations' },
					exact: true,
					fullWidth: true,
				},
				{
					path: '/bundles',
					label: __( 'Plan bundles', 'newspack-plugin' ),
					render: SubscriptionProductsList,
					props: { scope: 'groups' },
					exact: true,
					fullWidth: true,
				},
				{
					path: '/new',
					render: ProductEdit,
					isHidden: true,
					exact: true,
					backNav: '#/',
					title: __( 'Add plan', 'newspack-plugin' ),
				},
				{
					path: '/edit/:id',
					render: ProductEdit,
					isHidden: true,
					exact: true,
					backNav: '#/',
					title: __( 'Edit plan', 'newspack-plugin' ),
				},
			] }
		/>
	);
};

export default withWizard( forwardRef( AudienceSubscriptionProducts ) );
