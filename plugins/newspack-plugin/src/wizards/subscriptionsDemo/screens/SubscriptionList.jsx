/**
 * L0 — Subscription list (DataViews, full-width).
 *
 * Admin-facing list of every subscription plan (digital, print, team). Each
 * row rolls up live counts from the subscriber/group/discount stores via
 * getPlanStats. The list is the whole view; per-row actions handle the rest
 * (view subscribers, add a subscriber discount, open in WooCommerce).
 */

/**
 * WordPress dependencies.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { filterSortAndPaginate } from '@wordpress/dataviews';
import { Snackbar } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { Badge, DataViews } from '../../../../packages/components/src';
import { fmtCurrency } from '../format';
import './style.scss';
import { WIZARD_STORE_NAMESPACE } from '../../../../packages/components/src/wizard/store';
import { getAllPlans, getPlanStats } from '../data/plan-stats';
import { GROUP_LABEL } from '../labels';
import DiscountRuleFlow from '../flows/DiscountRuleFlow';
import RestrictionFlow from '../flows/RestrictionFlow';

// Product status labels; retired plans are no longer sold but keep existing
// subscribers.
const STATUS_LABELS = {
	active: __( 'Active', 'newspack-plugin' ),
	retired: __( 'Retired', 'newspack-plugin' ),
};

const STATUS_BADGE_LEVEL = {
	active: 'success',
	retired: 'error',
};

// A group subscription is still one subscription, held by its owner, so the
// subscriber count is the number of owners (one per group) — seats/members are
// not counted here. The column header already says "Subscribers", so cells show
// just the number.
const subscribersCount = ( plan, stats ) => ( plan.family === 'team' ? stats.groups || 0 : stats.individuals || 0 );

// "3 active" or a dash when the plan has no active discount rules targeting it.
const discountsText = stats =>
	stats.discounts > 0
		? sprintf(
				// translators: %d: number of active discount rules targeting this plan.
				_n( '%d active', '%d active', stats.discounts, 'newspack-plugin' ),
				stats.discounts
		  )
		: '—';

// Deep-link into the Subscribers demo, pre-filtered to this subscription.
function subscribersLink( planName ) {
	const base = ( window.newspack_urls && window.newspack_urls.subscribers_demo ) || '';
	return `${ base }#/?subscription=${ encodeURIComponent( planName ) }`;
}

// Open the WooCommerce products screen, pre-searched for this subscription.
function openWooCommerce( planName ) {
	const site = ( window.newspack_urls && window.newspack_urls.site ) || '';
	window.open( `${ site }/wp-admin/edit.php?post_type=product&s=${ encodeURIComponent( planName ) }`, '_blank', 'noopener,noreferrer' );
}

const DEFAULT_VIEW = {
	type: 'table',
	page: 1,
	perPage: 20,
	sort: { field: 'name', direction: 'asc' },
	search: '',
	// 'type' is intentionally omitted from the columns — it lives as a filter
	// only; group subscriptions are marked inline in the name (see below).
	// 'discounts' is defined as a field (so the Has/No discounts filter and the
	// optional column stay available) but is left out of the default columns.
	fields: [ 'status', 'amount', 'cadence', 'subscribers', 'sales', 'revenue' ],
	filters: [],
	layout: {},
	titleField: 'name',
};

export default function SubscriptionList() {
	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );
	const [ editorRule, setEditorRule ] = useState( null );
	const [ editorRestriction, setEditorRestriction ] = useState( null );
	const [ snackbar, setSnackbar ] = useState( null );
	// Bumped after a discount is saved so statsByPlan (below) re-reads
	// getPlanStats(), which pulls discount rules from localStorage and isn't
	// otherwise reflected in the `plans` dependency.
	const [ statsRevision, setStatsRevision ] = useState( 0 );

	const plans = useMemo( () => getAllPlans(), [] );

	// Status only earns a column/filter when at least one subscription is not
	// active (e.g. a retired plan with legacy subscribers). If everything is
	// active the column carries no information, so it's dropped entirely.
	const showStatus = useMemo( () => plans.some( plan => ( plan.status ?? 'active' ) !== 'active' ), [ plans ] );

	const [ view, setView ] = useState( () => ( {
		...DEFAULT_VIEW,
		fields: showStatus ? DEFAULT_VIEW.fields : DEFAULT_VIEW.fields.filter( field => field !== 'status' ),
	} ) );

	const statsByPlan = useMemo( () => {
		const byName = {};
		plans.forEach( plan => {
			byName[ plan.name ] = getPlanStats( plan.name );
		} );
		return byName;
	}, [ plans, statsRevision ] );

	const fields = useMemo(
		() =>
			[
				{
					id: 'name',
					label: __( 'Subscription', 'newspack-plugin' ),
					enableGlobalSearch: true,
					getValue: ( { item } ) => item.name,
					// Group subscriptions are marked inline (with the publisher's own
					// group label) since Type is a filter-only field.
					render: ( { item } ) => <span>{ item.family === 'team' ? `${ item.name } (${ GROUP_LABEL })` : item.name }</span>,
					enableSorting: true,
				},
				{
					// Filter-only: not in the visible columns (see DEFAULT_VIEW.fields).
					id: 'type',
					label: __( 'Type', 'newspack-plugin' ),
					elements: [
						{ value: 'individual', label: __( 'Individual', 'newspack-plugin' ) },
						{ value: 'group', label: GROUP_LABEL },
					],
					filterBy: { operators: [ 'isAny' ] },
					// Return the stable key (not the label) so the isAny filter matches.
					getValue: ( { item } ) => ( item.family === 'team' ? 'group' : 'individual' ),
					enableSorting: false,
				},
				{
					id: 'status',
					label: __( 'Status', 'newspack-plugin' ),
					elements: Object.entries( STATUS_LABELS ).map( ( [ value, label ] ) => ( { value, label } ) ),
					filterBy: { operators: [ 'isAny' ] },
					getValue: ( { item } ) => item.status,
					render: ( { item } ) => (
						<Badge level={ STATUS_BADGE_LEVEL[ item.status ] || 'default' } text={ STATUS_LABELS[ item.status ] || item.status } />
					),
					enableSorting: true,
				},
				{
					id: 'amount',
					label: __( 'Price', 'newspack-plugin' ),
					getValue: ( { item } ) => item.amount,
					render: ( { item } ) => <span>{ fmtCurrency( item.amount ) }</span>,
					enableSorting: true,
				},
				{
					id: 'cadence',
					label: __( 'Billing', 'newspack-plugin' ),
					elements: [
						{ value: 'Monthly', label: __( 'Monthly', 'newspack-plugin' ) },
						{ value: 'Yearly', label: __( 'Yearly', 'newspack-plugin' ) },
					],
					filterBy: { operators: [ 'isAny' ] },
					getValue: ( { item } ) => item.cadence,
					render: ( { item } ) => <span>{ item.cadence }</span>,
					enableSorting: true,
				},
				{
					id: 'subscribers',
					label: __( 'Subscribers', 'newspack-plugin' ),
					getValue: ( { item } ) => subscribersCount( item, statsByPlan[ item.name ] ),
					render: ( { item } ) => <span>{ subscribersCount( item, statsByPlan[ item.name ] ).toLocaleString() }</span>,
					enableSorting: true,
				},
				{
					id: 'sales',
					label: __( 'Total Sales', 'newspack-plugin' ),
					getValue: ( { item } ) => item.totalSales || 0,
					render: ( { item } ) => <span>{ ( item.totalSales || 0 ).toLocaleString() }</span>,
					enableSorting: true,
				},
				{
					id: 'revenue',
					label: __( 'Total Revenue', 'newspack-plugin' ),
					getValue: ( { item } ) => item.totalRevenue || 0,
					render: ( { item } ) => <span>{ fmtCurrency( item.totalRevenue || 0 ) }</span>,
					enableSorting: true,
				},
				{
					id: 'discounts',
					label: __( 'Discounts', 'newspack-plugin' ),
					elements: [
						{ value: 'has', label: __( 'Has discounts', 'newspack-plugin' ) },
						{ value: 'none', label: __( 'No discounts', 'newspack-plugin' ) },
					],
					filterBy: { operators: [ 'isAny' ] },
					// Filter on presence, not the formatted "N active" string.
					getValue: ( { item } ) => ( statsByPlan[ item.name ]?.discounts > 0 ? 'has' : 'none' ),
					render: ( { item } ) => <span>{ discountsText( statsByPlan[ item.name ] ) }</span>,
					enableSorting: false,
				},
			].filter( field => field.id !== 'status' || showStatus ),
		[ statsByPlan, showStatus ]
	);

	const actions = useMemo(
		() => [
			{
				id: 'view-subscribers',
				label: __( 'View subscribers', 'newspack-plugin' ),
				callback: items => {
					window.location.href = subscribersLink( items[ 0 ].name );
				},
			},
			{
				id: 'add-discount',
				label: __( 'Add subscriber discount', 'newspack-plugin' ),
				callback: items => setEditorRule( { audience: items[ 0 ].name } ),
			},
			{
				id: 'add-restriction',
				label: __( 'Add subscriber-only products', 'newspack-plugin' ),
				callback: items => setEditorRestriction( { subscriptions: [ items[ 0 ].name ] } ),
			},
			{
				id: 'view-in-woocommerce',
				label: __( 'View in WooCommerce', 'newspack-plugin' ),
				callback: items => openWooCommerce( items[ 0 ].name ),
			},
		],
		[]
	);

	const { data: processedData, paginationInfo } = useMemo( () => filterSortAndPaginate( plans, view, fields ), [ plans, view, fields ] );

	const total = paginationInfo?.totalItems ?? 0;

	// Surface the plan count in the header breadcrumb, e.g. "/ Subscriptions (9)".
	useEffect( () => {
		setHeaderData( {
			sectionName: (
				<>
					{ __( 'Subscriptions', 'newspack-plugin' ) }{ ' ' }
					<span
						className="newspack-subscribers-demo__header-count"
						aria-label={ sprintf(
							// translators: %s: number of subscription plans.
							__( '%s subscription plans total', 'newspack-plugin' ),
							total.toLocaleString()
						) }
					>
						{ `(${ total.toLocaleString() })` }
					</span>
				</>
			),
			actions: [
				{
					type: 'more',
					label: __( 'View in WooCommerce', 'newspack-plugin' ),
					action: () => {
						const site = ( window.newspack_urls && window.newspack_urls.site ) || '';
						window.open( `${ site }/wp-admin/edit.php?post_type=product`, '_blank', 'noopener,noreferrer' );
					},
				},
			],
		} );
	}, [ setHeaderData, total ] );

	return (
		<>
			<DataViews
				data={ processedData }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				actions={ actions }
				paginationInfo={ paginationInfo }
				defaultLayouts={ { table: {} } }
				getItemId={ item => item.id }
				search
			/>
			{ editorRule !== null && (
				<DiscountRuleFlow
					rule={ editorRule }
					onClose={ () => setEditorRule( null ) }
					onSaved={ message => {
						setEditorRule( null );
						setSnackbar( { message } );
						setStatsRevision( n => n + 1 );
					} }
				/>
			) }
			{ editorRestriction !== null && (
				<RestrictionFlow
					rule={ editorRestriction }
					onClose={ () => setEditorRestriction( null ) }
					onSaved={ message => {
						setEditorRestriction( null );
						setSnackbar( { message } );
					} }
				/>
			) }
			{ snackbar && (
				<div className="newspack-subscribers-demo__snackbar">
					<Snackbar onRemove={ () => setSnackbar( null ) }>{ snackbar.message }</Snackbar>
				</div>
			) }
		</>
	);
}
