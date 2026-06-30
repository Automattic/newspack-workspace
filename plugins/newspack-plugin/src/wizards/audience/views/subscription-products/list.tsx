/**
 * Subscription Products list view using DataViews.
 *
 * Layer 1 columns (name, type, price + period, active subscriptions, category, status)
 * are built from live WooCommerce Subscriptions data. Layer 2 columns (applied rules
 * + effective price) come from the PHP policy-resolution seam, which reads the live
 * pricing-rule engine; see Subscription_Policy_Resolver.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect, useCallback, useMemo } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { filterSortAndPaginate } from '@wordpress/dataviews';
import type { Action, Field, View } from '@wordpress/dataviews';
import { Spinner, Notice, Button } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { DataViews, Badge, Router } from '../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import { PolicyChips, EffectivePrice } from './policy-cells';

const { useHistory } = Router;

const API_PATH = '/newspack/v1/wizard/newspack-audience-subscription-products/products';

const DEFAULT_CURRENCY: SubscriptionProductsCurrency = { code: 'USD', symbol: '$', decimals: 2 };

type Scope = 'subscriptions' | 'donations' | 'all' | 'groups';

// Top-level scope chips. The first three are *individual* products (by purpose); "Plan
// bundles" is a separate structural lens for grouped containers, so a bundle never appears
// inline among the products it bundles. Defaults to non-donation subscriptions.
const SCOPES: { value: Scope; label: string; separated?: boolean }[] = [
	{ value: 'subscriptions', label: __( 'Subscriptions', 'newspack-plugin' ) },
	{ value: 'donations', label: __( 'Donations', 'newspack-plugin' ) },
	{ value: 'all', label: __( 'All', 'newspack-plugin' ) },
	{ value: 'groups', label: __( 'Plan bundles', 'newspack-plugin' ), separated: true },
];

const inScope = ( item: SubscriptionProduct, scope: Scope ): boolean => {
	if ( scope === 'groups' ) {
		return item.type === 'grouped';
	}
	// Individual products only — plan bundles live in their own scope.
	if ( item.type === 'grouped' ) {
		return false;
	}
	if ( scope === 'all' ) {
		return true;
	}
	return scope === 'donations' ? item.is_donation : ! item.is_donation;
};

const DEFAULT_VIEW: View = {
	type: 'table',
	page: 1,
	perPage: 25,
	sort: { field: 'name', direction: 'asc' },
	search: '',
	// Default columns = hard facts (price, active subs, status) + the RSM differentiators
	// (policies, effective price, unlocks). Derived/secondary attributes stay defined below
	// — so they remain filters and toggleable columns — but are off by default:
	//  - `type`: a raw Woo mechanic; the Price column already signals simple vs variable.
	//  - `category`: 4 of 6 sampled publishers leave subscription products uncategorized.
	//  - `availability`: derived heuristic (placeholder for a real entitlement field), and
	//    mostly "Public" for most publishers — low signal density for a default slot.
	fields: [ 'price', 'active_subscriptions', 'unlocks', 'status', 'policies', 'effective_price' ],
	// Default to published only. The REST query returns every non-trashed status, so
	// draft/private/pending products remain reachable behind the Status filter without
	// cluttering the default view with "(TEST COPY)" drafts and hidden strategy products.
	filters: [ { field: 'status', operator: 'is', value: 'publish' } ],
	layout: {},
	titleField: 'name',
};

export default function SubscriptionProductsList() {
	const { setHeaderData, addNotice } = useDispatch( WIZARD_STORE_NAMESPACE );
	const history = useHistory();
	const [ data, setData ] = useState< SubscriptionProduct[] >( [] );
	const [ currency, setCurrency ] = useState< SubscriptionProductsCurrency >( DEFAULT_CURRENCY );
	const [ policyIsMock, setPolicyIsMock ] = useState( false );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ view, setView ] = useState< View >( DEFAULT_VIEW );
	const [ scope, setScope ] = useState< Scope >( 'subscriptions' );

	const globals = window.newspackAudienceSubscriptionProducts;

	// Row counts per scope, for the chip labels. The purpose scopes count individual
	// products only; "Plan bundles" counts grouped containers.
	const scopeCounts = useMemo( () => {
		const individual = data.filter( item => item.type !== 'grouped' );
		return {
			subscriptions: individual.filter( item => ! item.is_donation ).length,
			donations: individual.filter( item => item.is_donation ).length,
			all: individual.length,
			groups: data.filter( item => item.type === 'grouped' ).length,
		};
	}, [ data ] );

	// Switching scope resets pagination so we never land on an out-of-range page.
	const selectScope = useCallback( ( next: Scope ) => {
		setScope( next );
		setView( current => ( { ...current, page: 1 } ) );
	}, [] );

	// Rows in the active scope, before the DataViews filters/sort/pagination run.
	const scopedData = useMemo( () => data.filter( item => inScope( item, scope ) ), [ data, scope ] );

	useEffect( () => {
		setHeaderData( {
			actions: [
				{
					type: 'secondary',
					label: __( 'Manage in WooCommerce', 'newspack-plugin' ),
					href: globals?.manage_products_url,
				},
				{
					type: 'primary',
					label: __( 'Add plan', 'newspack-plugin' ),
					href: '#/new',
				},
			],
		} );
	}, [ setHeaderData, globals ] );

	const fetchData = useCallback( () => {
		setIsLoading( true );
		apiFetch< SubscriptionProductsResponse >( { path: API_PATH } )
			.then( response => {
				setData( response.products || [] );
				if ( response.currency ) {
					setCurrency( response.currency );
				}
				setPolicyIsMock( Boolean( response.policy_source_is_mock ) );
			} )
			.catch( () => {
				addNotice( {
					message: __( 'Failed to load subscription products. Please refresh the page.', 'newspack-plugin' ),
					type: 'error',
					id: 'subscription-products-fetch-error',
				} );
			} )
			.finally( () => setIsLoading( false ) );
	}, [ addNotice ] );

	useEffect( () => {
		fetchData();
	}, [ fetchData ] );

	// Filter elements derived from the loaded data.
	const statusElements = useMemo( () => {
		const seen = new Map< string, string >();
		data.forEach( item => seen.set( item.status, item.status_label ) );
		return Array.from( seen, ( [ value, label ] ) => ( { value, label } ) );
	}, [ data ] );

	const categoryElements = useMemo( () => {
		const seen = new Map< number, string >();
		data.forEach( item => item.categories.forEach( cat => seen.set( cat.id, cat.name ) ) );
		return Array.from( seen, ( [ value, label ] ) => ( { value, label } ) );
	}, [ data ] );

	const fields: Field< SubscriptionProduct >[] = useMemo(
		() => [
			{
				id: 'name',
				label: __( 'Product', 'newspack-plugin' ),
				enableGlobalSearch: true,
				getValue: ( { item } ) => item.name,
				render: ( { item } ) => (
					<Button
						variant="link"
						className="newspack-subscription-products__name-link"
						onClick={ () => history.push( `/edit/${ item.id }` ) }
					>
						<strong>{ item.name }</strong>
					</Button>
				),
			},
			{
				id: 'type',
				label: __( 'Type', 'newspack-plugin' ),
				getValue: ( { item } ) => item.type,
				render: ( { item } ) => <span>{ item.type_label }</span>,
				elements: [
					{ value: 'subscription', label: __( 'Simple subscription', 'newspack-plugin' ) },
					{ value: 'variable-subscription', label: __( 'Variable subscription', 'newspack-plugin' ) },
					{ value: 'grouped', label: __( 'Plan bundle', 'newspack-plugin' ) },
					{ value: 'simple', label: __( 'One-time', 'newspack-plugin' ) },
				],
				filterBy: { operators: [ 'is' ] },
			},
			{
				id: 'price',
				label: __( 'Price', 'newspack-plugin' ),
				enableGlobalSearch: true,
				// Sort by the numeric base price; render the human label.
				getValue: ( { item } ) => ( item.base_price === null ? -1 : item.base_price ),
				render: ( { item } ) => {
					// Grouped products aren't priced themselves — show what they bundle instead.
					if ( item.type === 'grouped' ) {
						return item.bundled_products.length ? (
							<div className="newspack-subscription-products__bundled">
								{ item.bundled_products.map( bundled => (
									<Badge key={ bundled.id } level="default" text={ bundled.name } />
								) ) }
							</div>
						) : (
							<span className="newspack-subscription-products__muted">&mdash;</span>
						);
					}
					const label = item.type === 'variable-subscription' && item.price_range_label ? item.price_range_label : item.price_label;
					return label ? <span>{ label }</span> : <span className="newspack-subscription-products__muted">&mdash;</span>;
				},
			},
			{
				id: 'members',
				label: __( 'Members', 'newspack-plugin' ),
				// Group-subscription (multi-seat) summary. Off by default; sparse like Availability.
				getValue: ( { item } ) => ( item.is_group_subscription ? item.group_member_label : '' ),
				enableSorting: false,
				render: ( { item } ) =>
					item.is_group_subscription ? (
						<Badge level="info" text={ item.group_member_label } />
					) : (
						<span className="newspack-subscription-products__muted">&mdash;</span>
					),
			},
			{
				id: 'bundled',
				label: __( 'Bundled plans', 'newspack-plugin' ),
				getValue: ( { item } ) => item.bundled_products.map( bundled => bundled.name ).join( ', ' ),
				enableSorting: false,
				render: ( { item } ) =>
					item.bundled_products.length ? (
						<div className="newspack-subscription-products__bundled">
							{ item.bundled_products.map( bundled => (
								<Badge key={ bundled.id } level="default" text={ bundled.name } />
							) ) }
						</div>
					) : (
						<span className="newspack-subscription-products__muted">&mdash;</span>
					),
			},
			{
				id: 'active_subscriptions',
				label: __( 'Active subs', 'newspack-plugin' ),
				getValue: ( { item } ) => ( item.active_subscriptions === null ? -1 : item.active_subscriptions ),
				render: ( { item } ) =>
					item.active_subscriptions === null || item.active_subscriptions === undefined ? (
						<span className="newspack-subscription-products__muted" title={ __( 'Subscription counts unavailable', 'newspack-plugin' ) }>
							&mdash;
						</span>
					) : (
						<span>{ item.active_subscriptions }</span>
					),
			},
			{
				id: 'category',
				label: __( 'Category', 'newspack-plugin' ),
				getValue: ( { item } ) => item.category_ids,
				render: ( { item } ) =>
					item.categories.length ? (
						<span>{ item.category_label }</span>
					) : (
						<span className="newspack-subscription-products__muted">{ __( 'Uncategorized', 'newspack-plugin' ) }</span>
					),
				elements: categoryElements,
				filterBy: { operators: [ 'isAny' ] },
				enableSorting: false,
			},
			{
				id: 'availability',
				label: __( 'Availability', 'newspack-plugin' ),
				getValue: ( { item } ) => item.availability,
				render: ( { item } ) => {
					const levels = { free: 'info', private: 'warning', public: 'default' } as const;
					return <Badge level={ levels[ item.availability ] } text={ item.availability_label } />;
				},
				elements: [
					{ value: 'public', label: __( 'Public', 'newspack-plugin' ) },
					{ value: 'private', label: __( 'Private', 'newspack-plugin' ) },
					{ value: 'free', label: __( 'Free', 'newspack-plugin' ) },
				],
				filterBy: { operators: [ 'is' ] },
			},
			{
				id: 'unlocks',
				label: __( 'Unlocks', 'newspack-plugin' ),
				// Content gates this product unlocks (Access control). Sortable/searchable by
				// gate titles; rendered as chips linking to the gate editor.
				getValue: ( { item } ) => item.unlocks_label,
				enableGlobalSearch: true,
				enableSorting: false,
				render: ( { item } ) =>
					item.unlocks.length ? (
						<div className="newspack-subscription-products__unlocks">
							{ item.unlocks.map( gate => (
								<Badge key={ gate.id } level="default" text={ gate.title } />
							) ) }
						</div>
					) : (
						<span className="newspack-subscription-products__muted">{ __( 'Nothing gated', 'newspack-plugin' ) }</span>
					),
			},
			{
				id: 'status',
				label: __( 'Status', 'newspack-plugin' ),
				getValue: ( { item } ) => item.status,
				render: ( { item } ) => <Badge level={ item.status === 'publish' ? 'success' : 'default' } text={ item.status_label } />,
				elements: statusElements,
				filterBy: { operators: [ 'is' ] },
			},
			{
				id: 'policies',
				label: __( 'Applied rules', 'newspack-plugin' ),
				getValue: ( { item } ) => item.policy?.policies?.map( p => p.label ).join( ', ' ) || '',
				render: ( { item } ) => <PolicyChips policy={ item.policy } />,
				enableSorting: false,
			},
			{
				id: 'effective_price',
				label: __( 'Effective price', 'newspack-plugin' ),
				getValue: ( { item } ) => item.policy?.effective_price ?? -1,
				render: ( { item } ) => <EffectivePrice policy={ item.policy } currency={ currency } />,
			},
		],
		[ statusElements, categoryElements, currency, history ]
	);

	const actions: Action< SubscriptionProduct >[] = useMemo(
		() => [
			{
				id: 'edit',
				label: __( 'Edit', 'newspack-plugin' ),
				isPrimary: true,
				callback: ( items: SubscriptionProduct[] ) => history.push( `/edit/${ items[ 0 ].id }` ),
			},
		],
		[ history ]
	);

	const { data: processedData, paginationInfo } = useMemo( () => filterSortAndPaginate( scopedData, view, fields ), [ scopedData, view, fields ] );

	if ( isLoading ) {
		return (
			<div style={ { display: 'flex', justifyContent: 'center', alignItems: 'center', padding: '48px' } }>
				<Spinner />
			</div>
		);
	}

	return (
		<div className="newspack-subscription-products">
			<div
				className="newspack-subscription-products__scope-chips"
				role="group"
				aria-label={ __( 'Filter products by group', 'newspack-plugin' ) }
			>
				{ SCOPES.map( ( { value, label, separated } ) => {
					const isActive = scope === value;
					return (
						<Button
							key={ value }
							variant={ isActive ? 'primary' : 'secondary' }
							aria-pressed={ isActive }
							onClick={ () => selectScope( value ) }
							className={ `newspack-subscription-products__scope-chip${ separated ? ' is-separated' : '' }` }
						>
							{ label } ({ scopeCounts[ value ] })
						</Button>
					);
				} ) }
			</div>
			{ policyIsMock && (
				<Notice status="info" isDismissible={ false } className="newspack-subscription-products__mock-notice">
					{ __(
						'Applied policies and effective price use mock data. They swap to the live policy engine through a single read API with no UI change.',
						'newspack-plugin'
					) }
				</Notice>
			) }
			<DataViews
				className="newspack-subscription-products__dataviews"
				data={ processedData }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				actions={ actions }
				paginationInfo={ paginationInfo }
				defaultLayouts={ { table: {}, grid: {} } }
				isLoading={ isLoading }
				getItemId={ ( item: SubscriptionProduct ) => String( item.id ) }
				search
			/>
		</div>
	);
}
