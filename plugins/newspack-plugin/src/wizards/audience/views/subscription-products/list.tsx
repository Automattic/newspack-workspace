/**
 * Subscription Products list view using DataViews.
 *
 * Columns (name, type, price + period, active subscriptions, category, status) are
 * built from live WooCommerce Subscriptions data. The applied-rules and effective-price
 * columns come from the PHP rule-resolution seam, which reads the live pricing-rule
 * engine; see Subscription_Policy_Resolver.
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

const DEFAULT_CURRENCY: SubscriptionProductsCurrency = {
	code: 'USD',
	symbol: '$',
	decimals: 2,
	decimal_separator: '.',
	thousand_separator: ',',
};

type Scope = 'subscriptions' | 'donations' | 'groups';

const inScope = ( item: SubscriptionProduct, scope: Scope ): boolean => {
	if ( scope === 'groups' ) {
		return item.type === 'grouped';
	}
	// Individual products only — plan bundles live in their own scope.
	if ( item.type === 'grouped' ) {
		return false;
	}
	return scope === 'donations' ? item.is_donation : ! item.is_donation;
};

const DEFAULT_VIEW: View = {
	type: 'table',
	page: 1,
	perPage: 25,
	sort: { field: 'name', direction: 'asc' },
	search: '',
	// Default columns = hard facts (price, active subs, status) + the differentiating
	// columns (applied rules, effective price, unlocks). Derived/secondary attributes stay
	// defined below — so they remain filters and toggleable columns — but are off by default:
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

export default function SubscriptionProductsList( { scope = 'subscriptions' }: { scope?: Scope } ) {
	const { setHeaderData, addNotice } = useDispatch( WIZARD_STORE_NAMESPACE );
	const history = useHistory();
	const [ data, setData ] = useState< SubscriptionProduct[] >( [] );
	const [ currency, setCurrency ] = useState< SubscriptionProductsCurrency >( DEFAULT_CURRENCY );
	const [ policyIsMock, setPolicyIsMock ] = useState( false );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ view, setView ] = useState< View >( () => ( {
		...DEFAULT_VIEW,
		fields: DEFAULT_VIEW.fields.filter( field => scope === 'subscriptions' || ( field !== 'policies' && field !== 'effective_price' ) ),
	} ) );

	const globals = window.newspackAudienceSubscriptionProducts;

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

	// Applied-rule + effective-price columns only apply to subscription products —
	// donations and plan bundles are engine-excluded, so they never carry a rule.
	// Hide the two columns (and their column-picker entries) outside that scope.
	const visibleFields = useMemo(
		() => ( scope === 'subscriptions' ? fields : fields.filter( field => field.id !== 'policies' && field.id !== 'effective_price' ) ),
		[ fields, scope ]
	);

	const { data: processedData, paginationInfo } = useMemo(
		() => filterSortAndPaginate( scopedData, view, visibleFields ),
		[ scopedData, view, visibleFields ]
	);

	if ( isLoading ) {
		return (
			<div style={ { display: 'flex', justifyContent: 'center', alignItems: 'center', padding: '48px' } }>
				<Spinner />
			</div>
		);
	}

	return (
		<div className="newspack-subscription-products">
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
				fields={ visibleFields }
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
