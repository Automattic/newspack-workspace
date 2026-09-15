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
import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';
import { decodeEntities } from '@wordpress/html-entities';
import type { Field } from '@wordpress/dataviews';

/**
 * Internal dependencies.
 */
import { StatusIndicator } from '../../../../../../../packages/components/src';
import { discountLabel, excludedLabel, subscriptionNames, subscriptionsSummary, targetingBaseLabel, targetingLabel } from './discount';
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
export function discountFields( currency: DiscountCurrency, subscriptions: Subscription[] ): Field< DiscountRule >[] {
	return [
		{
			id: 'subscription',
			label: __( 'Subscription', 'newspack-plugin' ),
			enableHiding: false,
			elements: subscriptions.map( option => ( { value: option.id, label: decodeEntities( option.name ) } ) ),
			filterBy: { operators: [ 'isAny' ] },
			// Ids rather than names, so two subscriptions sharing a name stay
			// separate options in the filter.
			getValue: ( { item } ) => item.subscription_product_ids,
			render: ( { item } ) => {
				const { named, more } = subscriptionsSummary( item.subscription_product_ids, subscriptions );
				return (
					<span className="newspack-subscriber-discounts__subscriptions">
						{ /* The clipped names stay in the DOM, so a screen reader reads the
						     whole string and `title` shows it on hover. Neither reaches the
						     subscriptions the count stands for — the editor drawer lists those. */ }
						<span className="newspack-subscriber-discounts__subscriptions-named" title={ named }>
							{ named }
						</span>
						{ more && <span className="newspack-subscriber-discounts__subscriptions-more">{ more }</span> }
					</span>
				);
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
			getValue: ( { item } ) => subscriptionNames( item.subscription_product_ids, subscriptions ).join( ' ' ),
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
				<StatusIndicator status={ item.active ? 'active' : 'draft' }>
					{ item.active ? __( 'Active', 'newspack-plugin' ) : __( 'Inactive', 'newspack-plugin' ) }
				</StatusIndicator>
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
			render: ( { item } ) => {
				const excluded = excludedLabel( item );
				if ( ! excluded ) {
					return targetingBaseLabel( item );
				}
				return (
					<span className="newspack-subscriber-discounts__applies-to">
						{ targetingBaseLabel( item ) }
						<span className="newspack-subscriber-discounts__applies-to-excluded">{ excluded }</span>
					</span>
				);
			},
		},
		{
			id: 'created_at',
			label: __( 'Created', 'newspack-plugin' ),
			getValue: ( { item } ) => item.created_at,
			render: ( { item } ) => ( item.created_at ? dateI18n( getDateSettings().formats.date, item.created_at ) : '' ),
		},
	];
}
