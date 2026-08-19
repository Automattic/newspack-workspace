/**
 * The Audience Management / Pricing Rules page: rules giving a subscription's
 * subscribers money off store products.
 *
 * This page occupies the same admin slug, menu label and routes as the Pricing
 * Rules manager for the standalone dynamic-pricing engine, and PHP registers
 * exactly one of the two — the engine's when its plugin is active, this one
 * otherwise. Publishers therefore see a single Pricing Rules screen whose URL
 * survives installing the engine later; the stored rules are ported by
 * `wp newspack migrate-discounts` at that point.
 */

import '../../../../shared/js/public-path';

/**
 * WordPress dependencies.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { forwardRef, useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { filterSortAndPaginate } from '@wordpress/dataviews';
import type { Action, Field, View } from '@wordpress/dataviews';
import { percent } from '@wordpress/icons';
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { Badge, Button, DataViews, Notice, SectionHeader, Wizard, withWizard } from '../../../../../packages/components/src';
import { DISCOUNTS_ENDPOINT } from './constants';
import { DEFAULT_CURRENCY, discountLabel, targetingLabel } from './discount';
import DiscountEditor from './editor';
import SettingsModal from './settings-modal';
import type { DiscountRule, DiscountsPayload } from './types';

import './style.scss';

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
		settings: { apply_on_sale: false, apply_at_checkout: false },
		currency: DEFAULT_CURRENCY,
	} );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ view, setView ] = useState< View >( DEFAULT_VIEW );
	const [ editing, setEditing ] = useState< DiscountRule | null | undefined >( undefined );
	const [ showSettings, setShowSettings ] = useState( false );
	const [ error, setError ] = useState( '' );

	const reportFailure = ( apiError: { message?: string } ) =>
		setError( apiError?.message || __( 'That change could not be saved.', 'newspack-plugin' ) );

	useEffect( () => {
		apiFetch< DiscountsPayload >( { path: DISCOUNTS_ENDPOINT } )
			.then( setPayload )
			.catch( reportFailure )
			.finally( () => setIsLoading( false ) );
	}, [] );

	const applyPayload = useCallback( ( next: DiscountsPayload ) => {
		setPayload( next );
		setError( '' );
		setEditing( undefined );
		setShowSettings( false );
	}, [] );

	const setActive = useCallback( ( rule: DiscountRule, active: boolean ) => {
		apiFetch< DiscountsPayload >( {
			path: DISCOUNTS_ENDPOINT,
			method: 'POST',
			data: { ...rule, active },
		} )
			.then( next => {
				setPayload( next );
				setError( '' );
			} )
			.catch( reportFailure );
	}, [] );

	const deleteRule = useCallback( ( rule: DiscountRule ) => {
		apiFetch< DiscountsPayload >( {
			path: `${ DISCOUNTS_ENDPOINT }/${ rule.id }`,
			method: 'DELETE',
		} )
			.then( next => {
				setPayload( next );
				setError( '' );
			} )
			.catch( reportFailure );
	}, [] );

	const { currency } = payload;

	const fields: Field< DiscountRule >[] = useMemo(
		() => [
			{
				id: 'subscription',
				label: __( 'Subscription', 'newspack-plugin' ),
				enableHiding: false,
				getValue: ( { item } ) =>
					sprintf(
						/* translators: %d: number of subscriptions whose subscribers get the discount. */
						_n( '%d subscription', '%d subscriptions', item.subscription_product_ids.length, 'newspack-plugin' ),
						item.subscription_product_ids.length
					),
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
				// Deleting a rule cannot be undone and immediately changes what
				// readers are charged, so it is confirmed rather than one-click.
				RenderModal: ( { items, closeModal }: { items: DiscountRule[]; closeModal?: () => void } ) => (
					<VStack spacing={ 4 }>
						<p>{ __( 'This rule will stop applying immediately. This cannot be undone.', 'newspack-plugin' ) }</p>
						<HStack spacing={ 2 } justify="flex-end">
							<Button variant="secondary" onClick={ closeModal }>
								{ __( 'Cancel', 'newspack-plugin' ) }
							</Button>
							<Button
								variant="primary"
								isDestructive
								onClick={ () => {
									items.forEach( deleteRule );
									closeModal?.();
								} }
							>
								{ __( 'Delete', 'newspack-plugin' ) }
							</Button>
						</HStack>
					</VStack>
				),
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
		<>
			{ error && <Notice isError noticeText={ error } /> }
			{ ! isLoading && ! hasRules ? (
				<SectionHeader
					centered
					icon={ percent }
					title={ __( 'Get started with pricing rules', 'newspack-plugin' ) }
					description={ __(
						'Give subscribers a lower price on your products. Create your first rule to choose which subscription gets what off which products.',
						'newspack-plugin'
					) }
				>
					<Button variant="primary" onClick={ () => setEditing( null ) }>
						{ __( 'Add rule', 'newspack-plugin' ) }
					</Button>
				</SectionHeader>
			) : (
				<>
					<div className="newspack-subscriber-discounts__actions">
						<Button variant="secondary" onClick={ () => setShowSettings( true ) }>
							{ __( 'Settings', 'newspack-plugin' ) }
						</Button>
						<Button variant="primary" onClick={ () => setEditing( null ) }>
							{ __( 'Add rule', 'newspack-plugin' ) }
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
			{ undefined !== editing && (
				<DiscountEditor rule={ editing } currency={ currency } onSaved={ applyPayload } onClose={ () => setEditing( undefined ) } />
			) }
			{ showSettings && <SettingsModal settings={ payload.settings } onSaved={ applyPayload } onClose={ () => setShowSettings( false ) } /> }
		</>
	);
}

const AudiencePricingRules = ( _props: Record< string, unknown >, ref: React.ForwardedRef< HTMLDivElement > ) => (
	<Wizard
		title={ __( 'Pricing Rules', 'newspack-plugin' ) }
		headerText={ __( 'Audience Management / Pricing Rules', 'newspack-plugin' ) }
		ref={ ref }
		fixedHeader
		sections={ [ { path: '/', render: SubscriberDiscounts, exact: true, fullWidth: true } ] }
	/>
);

export default withWizard( forwardRef( AudiencePricingRules ) );
