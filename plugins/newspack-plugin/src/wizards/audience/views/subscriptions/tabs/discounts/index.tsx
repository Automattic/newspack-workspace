/**
 * The Subscriptions wizard's Subscriber discounts tab: rules giving a
 * subscription's subscribers money off store products.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { filterSortAndPaginate } from '@wordpress/dataviews';
import type { Action, Field, View } from '@wordpress/dataviews';
import { percent } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { Badge, Button, DataViews, SectionHeader } from '../../../../../../../packages/components/src';
import WizardsTab from '../../../../../wizards-tab';
import WizardSection from '../../../../../wizards-section';
import { registerTab } from '../registry';
import { DISCOUNTS_ENDPOINT } from './constants';
import { DEFAULT_CURRENCY, discountLabel, targetingLabel } from './discount';
import DiscountEditor from './editor';
import SettingsModal from './settings-modal';
import type { DiscountRule, DiscountsPayload } from './types';

const DEFAULT_VIEW: View = {
	type: 'table',
	page: 1,
	perPage: 25,
	sort: { field: 'created_at', direction: 'desc' },
	search: '',
	fields: [ 'status', 'discount', 'applies_to', 'created_at' ],
	filters: [],
	layout: {},
	titleField: 'subscription',
};

function SubscriberDiscounts() {
	const [ payload, setPayload ] = useState< DiscountsPayload >( {
		rules: [],
		settings: { overlap: 'best', apply_on_sale: false },
		currency: DEFAULT_CURRENCY,
	} );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ view, setView ] = useState< View >( DEFAULT_VIEW );
	const [ editing, setEditing ] = useState< DiscountRule | null | undefined >( undefined );
	const [ showSettings, setShowSettings ] = useState( false );

	useEffect( () => {
		apiFetch< DiscountsPayload >( { path: DISCOUNTS_ENDPOINT } )
			.then( setPayload )
			.finally( () => setIsLoading( false ) );
	}, [] );

	const applyPayload = useCallback( ( next: DiscountsPayload ) => {
		setPayload( next );
		setEditing( undefined );
		setShowSettings( false );
	}, [] );

	const setActive = useCallback( ( rule: DiscountRule, active: boolean ) => {
		apiFetch< DiscountsPayload >( {
			path: DISCOUNTS_ENDPOINT,
			method: 'POST',
			data: { ...rule, active },
		} ).then( setPayload );
	}, [] );

	const deleteRule = useCallback( ( rule: DiscountRule ) => {
		apiFetch< DiscountsPayload >( {
			path: `${ DISCOUNTS_ENDPOINT }/${ rule.id }`,
			method: 'DELETE',
		} ).then( setPayload );
	}, [] );

	const { currency } = payload;

	const fields: Field< DiscountRule >[] = useMemo(
		() => [
			{
				id: 'subscription',
				label: __( 'Subscription', 'newspack-plugin' ),
				enableHiding: false,
				getValue: ( { item } ) =>
					item.subscription_product_ids.length === 1
						? __( '1 subscription', 'newspack-plugin' )
						: `${ item.subscription_product_ids.length } ${ __( 'subscriptions', 'newspack-plugin' ) }`,
			},
			{
				id: 'status',
				label: __( 'Status', 'newspack-plugin' ),
				getValue: ( { item } ) => ( item.active ? __( 'Active', 'newspack-plugin' ) : __( 'Paused', 'newspack-plugin' ) ),
				render: ( { item } ) => (
					<Badge
						level={ item.active ? 'success' : 'default' }
						text={ item.active ? __( 'Active', 'newspack-plugin' ) : __( 'Paused', 'newspack-plugin' ) }
					/>
				),
			},
			{
				id: 'discount',
				label: __( 'Discount', 'newspack-plugin' ),
				getValue: ( { item } ) => discountLabel( item, currency ),
			},
			{
				id: 'applies_to',
				label: __( 'Applies to', 'newspack-plugin' ),
				getValue: ( { item } ) => targetingLabel( item ),
			},
			{
				id: 'created_at',
				label: __( 'Created', 'newspack-plugin' ),
				getValue: ( { item } ) => item.created_at,
			},
		],
		[ currency ]
	);

	const actions: Action< DiscountRule >[] = useMemo(
		() => [
			{
				id: 'edit',
				label: __( 'Edit', 'newspack-plugin' ),
				isPrimary: true,
				callback: ( [ item ]: DiscountRule[] ) => setEditing( item ),
			},
			{
				id: 'toggle-active',
				label: __( 'Pause or resume', 'newspack-plugin' ),
				callback: ( [ item ]: DiscountRule[] ) => setActive( item, ! item.active ),
			},
			{
				id: 'delete',
				label: __( 'Delete', 'newspack-plugin' ),
				isDestructive: true,
				callback: ( [ item ]: DiscountRule[] ) => deleteRule( item ),
			},
		],
		[ setActive, deleteRule ]
	);

	const { data: processedData, paginationInfo } = useMemo(
		() => filterSortAndPaginate( payload.rules, view, fields ),
		[ payload.rules, view, fields ]
	);

	const hasRules = payload.rules.length > 0;

	return (
		<WizardsTab title={ __( 'Subscriber discounts', 'newspack-plugin' ) }>
			<WizardSection>
				{ ! isLoading && ! hasRules ? (
					<SectionHeader
						centered
						icon={ percent }
						title={ __( 'Get started with subscriber discounts', 'newspack-plugin' ) }
						description={ __(
							'Offer subscribers a discount on your products. Create your first rule to choose which subscription gets what off which products.',
							'newspack-plugin'
						) }
					>
						<Button variant="primary" onClick={ () => setEditing( null ) }>
							{ __( 'Add discount', 'newspack-plugin' ) }
						</Button>
					</SectionHeader>
				) : (
					<>
						<div className="newspack-subscriber-discounts__actions">
							<Button variant="secondary" onClick={ () => setShowSettings( true ) }>
								{ __( 'Settings', 'newspack-plugin' ) }
							</Button>
							<Button variant="primary" onClick={ () => setEditing( null ) }>
								{ __( 'Add discount', 'newspack-plugin' ) }
							</Button>
						</div>
						<DataViews
							data={ processedData }
							fields={ fields }
							view={ view }
							onChangeView={ setView }
							actions={ actions }
							paginationInfo={ paginationInfo }
							defaultLayouts={ { table: {} } }
							isLoading={ isLoading }
							getItemId={ ( item: DiscountRule ) => item.id }
							search
						/>
					</>
				) }
			</WizardSection>
			{ undefined !== editing && (
				<DiscountEditor rule={ editing } currency={ currency } onSaved={ applyPayload } onClose={ () => setEditing( undefined ) } />
			) }
			{ showSettings && <SettingsModal settings={ payload.settings } onSaved={ applyPayload } onClose={ () => setShowSettings( false ) } /> }
		</WizardsTab>
	);
}

registerTab( 'discounts', { render: () => <SubscriberDiscounts /> } );
