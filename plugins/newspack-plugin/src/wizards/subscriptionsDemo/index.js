import '../../shared/js/public-path';

/**
 * Subscriptions Demo — subscriptions/commerce twin of the Subscribers Demo
 * people-first subscriber management prototype, transplanted from the
 * discounts demo (PR #544).
 *
 * Entry point: mounts a Wizard with three visible tabs — Subscriptions (plan
 * list), Discounts, and Subscriber-only products — plus hidden plan-detail,
 * subscriber-profile, and group-detail routes reached by row click.
 */

/**
 * WordPress dependencies.
 */
import { render } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { Wizard } from '../../../packages/components/src';
import SubscriptionList from './screens/SubscriptionList';
import DiscountList from './screens/DiscountList';
import RestrictionList from './screens/RestrictionList';
import { purgeStaleStorage } from './data/storage';

function SubscribersDemoApp() {
	return (
		<Wizard
			headerText={ __( 'Audience Management', 'newspack-plugin' ) }
			sections={ [
				{
					label: __( 'Subscriptions', 'newspack-plugin' ),
					path: '/',
					exact: true,
					fullWidth: true,
					render: SubscriptionList,
				},
				{
					label: __( 'Subscriber discounts', 'newspack-plugin' ),
					path: '/discounts',
					exact: true,
					fullWidth: true,
					render: DiscountList,
				},
				{
					label: __( 'Subscriber-only products', 'newspack-plugin' ),
					path: '/subscriber-only',
					exact: true,
					fullWidth: true,
					render: RestrictionList,
				},
			] }
		/>
	);
}

purgeStaleStorage();

render( <SubscribersDemoApp />, document.getElementById( 'newspack-subscriptions-demo' ) );
