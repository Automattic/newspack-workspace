/**
 * Subscription Products management screen.
 *
 * Exploratory RSM prototype: a DataViews list of WooCommerce Subscriptions products
 * with the consolidated product model (Layer 1) and the applied-policy stack +
 * effective price (Layer 2, behind the Subscription_Policy_Resolver seam).
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
				{
					path: '/',
					render: SubscriptionProductsList,
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
