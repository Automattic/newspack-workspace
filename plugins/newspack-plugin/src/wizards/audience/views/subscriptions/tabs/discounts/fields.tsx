/**
 * The Subscriber discounts list's DataViews field definitions.
 *
 * Separate from the tab so the list's search and filter behaviour can be
 * exercised without mounting the wizard.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { decodeEntities } from '@wordpress/html-entities';
import type { Field } from '@wordpress/dataviews';

/**
 * Internal dependencies.
 */
import NamesSummary from '../../../../components/names-summary';
import ScopeSummary from '../../../../components/scope-summary';
import { fmtDate } from '../../../../../subscribers/format';
import { StatusBadge } from '../../../../../../../packages/components/src';
import { ALL_SUBSCRIPTIONS_FILTER_VALUE, allSubscriptionsLabel } from '../../constants';
import { discountLabel, subscriptionNames, subscriptionsSummary, targetingLabel } from './discount';
import type { DiscountCurrency, DiscountRule } from './types';

type Subscription = { id: number; name: string };

/**
 * The list's columns, its filters and what its search matches.
 *
 * @param currency      The store's currency, for the discount amounts.
 * @param subscriptions Every subscription the list can name, which includes the
 *                      ones a rule covers but the picker would not offer, such
 *                      as trashed ones. The filter narrows rules already on
 *                      screen, so it offers that same set.
 */
export function discountFields(
	currency: DiscountCurrency,
	subscriptions: Subscription[],
	productNames: Record< number, string > = {},
	categoryNames: Record< number, string > = {}
): Field< DiscountRule >[] {
	return [
		{
			id: 'subscription',
			label: __( 'Subscription', 'newspack-plugin' ),
			enableHiding: false,
			elements: [
				{ value: ALL_SUBSCRIPTIONS_FILTER_VALUE, label: allSubscriptionsLabel() },
				...subscriptions.map( option => ( { value: option.id, label: decodeEntities( option.name ) } ) ),
			],
			filterBy: { operators: [ 'isAny' ] },
			// Ids rather than names, so two subscriptions sharing a name stay
			// separate options in the filter. A rule reaching every subscriber
			// names none, so it takes the sentinel instead and stays filterable.
			getValue: ( { item } ) => ( 'all' === item.subscription_targeting ? [ ALL_SUBSCRIPTIONS_FILTER_VALUE ] : item.subscription_product_ids ),
			render: ( { item } ) => {
				if ( 'all' === item.subscription_targeting ) {
					return <span>{ allSubscriptionsLabel() }</span>;
				}
				const { shown, more, hidden } = subscriptionsSummary( item.subscription_product_ids, subscriptions );
				return <NamesSummary shown={ shown } more={ more } hidden={ hidden } />;
			},
		},
		{
			id: 'subscription_names',
			// Not user-visible on this version of DataViews — the field is absent
			// from the view's fields and `enableHiding: false` keeps it out of the
			// column-toggle menu — but that guarantee lives in the library, so the
			// label stays translated.
			label: __( 'Subscription names', 'newspack-plugin' ),
			// DataViews matches a search only against fields that opt in, and the
			// Subscription field cannot be the one to opt in because its value has
			// to stay ids for the filter.
			enableHiding: false,
			enableSorting: false,
			enableGlobalSearch: true,
			getValue: ( { item } ) =>
				'all' === item.subscription_targeting
					? allSubscriptionsLabel()
					: subscriptionNames( item.subscription_product_ids, subscriptions ).join( ' ' ),
		},
		{
			id: 'status',
			label: __( 'Status', 'newspack-plugin' ),
			elements: [
				{ value: 'active', label: __( 'Active', 'newspack-plugin' ) },
				{ value: 'inactive', label: __( 'Inactive', 'newspack-plugin' ) },
			],
			filterBy: { operators: [ 'isAny' ] },
			getValue: ( { item } ) => ( item.active ? 'active' : 'inactive' ),
			render: ( { item } ) => (
				<StatusBadge status={ item.active ? 'active' : 'draft' }>
					{ item.active ? __( 'Active', 'newspack-plugin' ) : __( 'Inactive', 'newspack-plugin' ) }
				</StatusBadge>
			),
		},
		{
			id: 'discount',
			label: __( 'Discount', 'newspack-plugin' ),
			enableGlobalSearch: true,
			getValue: ( { item } ) => discountLabel( item, currency ),
		},
		{
			id: 'applies_to',
			label: __( 'Applies to', 'newspack-plugin' ),
			enableGlobalSearch: true,
			getValue: ( { item } ) => targetingLabel( item ),
			render: ( { item } ) => (
				<ScopeSummary
					targeting={ item.targeting }
					productIds={ item.product_ids }
					categoryIds={ item.category_ids }
					excludedProductIds={ item.excluded_product_ids }
					productNames={ productNames }
					categoryNames={ categoryNames }
				/>
			),
		},
		{
			id: 'created_at',
			label: __( 'Created', 'newspack-plugin' ),
			getValue: ( { item } ) => item.created_at,
			render: ( { item } ) => ( item.created_at ? <span className="newspack-dataviews__date">{ fmtDate( item.created_at ) }</span> : '' ),
		},
	];
}
