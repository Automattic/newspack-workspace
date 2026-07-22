/* eslint-disable @wordpress/i18n-translator-comments */
/**
 * L0 — Subscriber-only products list (DataViews, full-width).
 *
 * Admin-facing list of purchase restrictions: which store products are
 * subscriber-only, and which subscriptions unlock them. Mirrors DiscountList's
 * list idioms.
 */

/**
 * WordPress dependencies.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { filterSortAndPaginate } from '@wordpress/dataviews';
import {
	Snackbar,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { Badge, Button, DataViews, Router } from '../../../../packages/components/src';
import EmptyRestrictions from './EmptyRestrictions';
import './style.scss';
import { WIZARD_STORE_NAMESPACE } from '../../../../packages/components/src/wizard/store';
import { getAllRestrictions, deleteRestriction, setRestrictionActive } from '../data/mock-restrictions';
import { targetingLabel, targetingBaseLabel, excludedLabel, productNamesForRule, coveredParentProductIds } from '../data/targeting';
import { PRODUCTS } from '../data/mock-catalog';
import { getAllPlans } from '../data/plan-stats';
import { TEAM_PLANS } from '../data/mock-groups';
import { GROUP_LABEL } from '../labels';
import { fmtDate } from '../format';
import ConfirmFlow from '../flows/ConfirmFlow';
import RestrictionFlow from '../flows/RestrictionFlow';
import RestrictionSettingsFlow from '../flows/RestrictionSettingsFlow';

const DEFAULT_VIEW = {
	type: 'table',
	page: 1,
	perPage: 20,
	sort: { field: 'createdAt', direction: 'desc' },
	search: '',
	fields: [ 'availableTo', 'status', 'createdAt' ],
	filters: [],
	layout: {},
	titleField: 'products',
};

const { useLocation } = Router;

// How many product names a specific-products row lists before collapsing the
// rest into a muted "+N more".
const MAX_PRODUCT_NAMES = 2;

// Group (team) subscriptions are tagged "(Group)" — matching the editor and the
// Subscribers demo — while individual ones show a plain name.
const planLabel = name => ( TEAM_PLANS.some( p => p.name === name ) ? `${ name } (${ GROUP_LABEL })` : name );

export default function RestrictionList() {
	// `?empty=1` forces the onboarding for demos/screenshots without touching
	// stored restrictions (nothing to restore afterwards).
	const previewEmpty = new URLSearchParams( useLocation().search ).get( 'empty' ) !== null;

	const [ view, setView ] = useState( DEFAULT_VIEW );
	// Restrictions mutate in place on this screen (pause/resume/delete are
	// direct storage writes), so re-reading the store is enough to refresh.
	const [ rules, setRules ] = useState( () => getAllRestrictions() );
	const refresh = () => setRules( getAllRestrictions() );

	const [ editorRule, setEditorRule ] = useState( null );
	const [ settingsOpen, setSettingsOpen ] = useState( false );
	const [ confirmAction, setConfirmAction ] = useState( null );
	const [ snackbar, setSnackbar ] = useState( null );

	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );

	const closeConfirm = () => setConfirmAction( null );

	const applyPauseResume = () => {
		const { item, kind } = confirmAction;
		setRestrictionActive( item.id, kind === 'resume' );
		refresh();
		setSnackbar( {
			message: kind === 'resume' ? __( 'Restriction resumed.', 'newspack-plugin' ) : __( 'Restriction paused.', 'newspack-plugin' ),
		} );
		closeConfirm();
	};

	const applyDelete = () => {
		deleteRestriction( confirmAction.item.id );
		refresh();
		setSnackbar( { message: __( 'Restriction deleted.', 'newspack-plugin' ) } );
		closeConfirm();
	};

	const fields = useMemo(
		() => [
			{
				id: 'products',
				label: __( 'Products', 'newspack-plugin' ),
				enableGlobalSearch: true,
				// Product names are included so global search finds a restriction by
				// the products it covers, not just its targeting label.
				getValue: ( { item } ) => [ targetingLabel( item ), ...productNamesForRule( item ) ].join( ' ' ),
				render: ( { item } ) => {
					const names = productNamesForRule( item );
					const shown = names.slice( 0, MAX_PRODUCT_NAMES );
					const extra = names.length - shown.length;
					const excluded = excludedLabel( item );
					return (
						<VStack spacing={ 0 } alignment="flex-start" expanded={ false }>
							{ shown.length > 0 ? (
								shown.map( name => <span key={ name }>{ name }</span> )
							) : (
								<span>{ targetingBaseLabel( item ) }</span>
							) }
							{ extra > 0 && (
								<span className="newspack-subscribers-demo__muted">
									{ sprintf( _n( '+%d more product', '+%d more products', extra, 'newspack-plugin' ), extra ) }
								</span>
							) }
							{ excluded && <span className="newspack-subscribers-demo__muted">{ excluded }</span> }
						</VStack>
					);
				},
				enableSorting: false,
			},
			{
				id: 'availableTo',
				label: __( 'Available to', 'newspack-plugin' ),
				enableGlobalSearch: true,
				elements: getAllPlans().map( plan => ( { value: plan.name, label: planLabel( plan.name ) } ) ),
				filterBy: { operators: [ 'isAny' ] },
				// The raw name array: isAny matches any of the rule's subscriptions.
				getValue: ( { item } ) => item.subscriptions,
				render: ( { item } ) => <span>{ item.subscriptions.map( planLabel ).join( ', ' ) }</span>,
				enableSorting: false,
			},
			{
				// Filter-only: not in the visible columns (see DEFAULT_VIEW.fields).
				// A rule matches when its effective scope covers the product —
				// named directly, via its category, or via an all-products rule,
				// with exclusions applied.
				id: 'product',
				label: __( 'Product', 'newspack-plugin' ),
				elements: PRODUCTS.map( p => ( { value: p.id, label: p.name } ) ),
				filterBy: { operators: [ 'isAny' ] },
				getValue: ( { item } ) => coveredParentProductIds( item ),
				enableSorting: false,
			},
			{
				id: 'status',
				label: __( 'Status', 'newspack-plugin' ),
				elements: [
					{ value: 'active', label: __( 'Active', 'newspack-plugin' ) },
					{ value: 'paused', label: __( 'Paused', 'newspack-plugin' ) },
				],
				filterBy: { operators: [ 'isAny' ] },
				getValue: ( { item } ) => ( item.active ? 'active' : 'paused' ),
				render: ( { item } ) => (
					<Badge
						level={ item.active ? 'success' : 'default' }
						text={ item.active ? __( 'Active', 'newspack-plugin' ) : __( 'Paused', 'newspack-plugin' ) }
					/>
				),
			},
			{
				id: 'createdAt',
				label: __( 'Created', 'newspack-plugin' ),
				getValue: ( { item } ) => item.createdAt,
				render: ( { item } ) => <span>{ fmtDate( item.createdAt ) }</span>,
				enableSorting: true,
			},
		],
		[]
	);

	const actions = useMemo(
		() => [
			{
				id: 'edit',
				label: __( 'Edit', 'newspack-plugin' ),
				callback: items => setEditorRule( items[ 0 ] ),
			},
			{
				id: 'toggle-active',
				label: items => ( items[ 0 ].active ? __( 'Pause', 'newspack-plugin' ) : __( 'Resume', 'newspack-plugin' ) ),
				callback: items => setConfirmAction( { kind: items[ 0 ].active ? 'pause' : 'resume', item: items[ 0 ] } ),
			},
			{
				id: 'delete',
				label: __( 'Delete', 'newspack-plugin' ),
				isDestructive: true,
				callback: items => setConfirmAction( { kind: 'delete', item: items[ 0 ] } ),
			},
		],
		[]
	);

	const { data: processedData, paginationInfo } = useMemo( () => filterSortAndPaginate( rules, view, fields ), [ rules, view, fields ] );

	// Whole-row click → edit (DataViews only wires up the title cell).
	const onRowClick = event => {
		if ( event.target.closest( 'a, button, input, label, [role="button"], [role="checkbox"]' ) ) {
			return;
		}
		const row = event.target.closest( 'tbody tr.dataviews-view-table__row' );
		if ( ! row ) {
			return;
		}
		const index = Array.from( row.parentNode.children ).indexOf( row );
		const item = processedData[ index ];
		if ( item ) {
			setEditorRule( item );
		}
	};

	// Onboarding shows for a genuinely empty store (or the preview param), but a
	// search/filter that returns nothing keeps the normal DataViews "No results".
	const isEmpty = previewEmpty || rules.length === 0;
	const total = previewEmpty ? 0 : paginationInfo?.totalItems ?? 0;

	// Surface the count in the header breadcrumb, e.g. "/ Subscriber-only products (4)".
	useEffect( () => {
		setHeaderData( {
			sectionName: (
				<>
					{ __( 'Subscriber-only products', 'newspack-plugin' ) }{ ' ' }
					<span
						className="newspack-subscribers-demo__header-count"
						aria-label={ sprintf( _n( '%d restriction total', '%d restrictions total', total, 'newspack-plugin' ), total ) }
					>
						{ `(${ total.toLocaleString() })` }
					</span>
				</>
			),
			// When empty, the onboarding owns the CTA — don't duplicate it in the header.
			actions: isEmpty
				? []
				: [
						{
							type: 'primary',
							label: __( 'Add restriction', 'newspack-plugin' ),
							action: () => setEditorRule( {} ),
						},
				  ],
		} );
	}, [ setHeaderData, total, isEmpty ] );

	return (
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions, jsx-a11y/click-events-have-key-events
		<div className="newspack-subscribers-demo__clickable-rows" onClick={ onRowClick }>
			{ isEmpty ? (
				<EmptyRestrictions onAdd={ () => setEditorRule( {} ) } />
			) : (
				<DataViews
					data={ processedData }
					fields={ fields }
					view={ view }
					onChangeView={ setView }
					actions={ actions }
					paginationInfo={ paginationInfo }
					defaultLayouts={ { table: {} } }
					getItemId={ item => item.id }
					onClickItem={ item => setEditorRule( item ) }
					search
					header={
						<Button variant="secondary" size="compact" onClick={ () => setSettingsOpen( true ) }>
							{ __( 'Settings', 'newspack-plugin' ) }
						</Button>
					}
				/>
			) }

			{ editorRule !== null && (
				<RestrictionFlow
					rule={ editorRule }
					onClose={ () => setEditorRule( null ) }
					onSaved={ message => {
						refresh();
						setEditorRule( null );
						setSnackbar( { message } );
					} }
				/>
			) }

			{ settingsOpen && (
				<RestrictionSettingsFlow
					onClose={ () => setSettingsOpen( false ) }
					onSaved={ message => {
						setSettingsOpen( false );
						setSnackbar( { message } );
					} }
				/>
			) }

			{ confirmAction?.kind === 'pause' && (
				<ConfirmFlow
					title={ __( 'Pause restriction', 'newspack-plugin' ) }
					confirmLabel={ __( 'Pause restriction', 'newspack-plugin' ) }
					onCancel={ closeConfirm }
					onConfirm={ applyPauseResume }
				>
					{ sprintf(
						__( 'Pause the restriction on %s? Everyone can purchase these products until you resume it.', 'newspack-plugin' ),
						targetingBaseLabel( confirmAction.item )
					) }
				</ConfirmFlow>
			) }

			{ confirmAction?.kind === 'resume' && (
				<ConfirmFlow
					title={ __( 'Resume restriction', 'newspack-plugin' ) }
					confirmLabel={ __( 'Resume restriction', 'newspack-plugin' ) }
					onCancel={ closeConfirm }
					onConfirm={ applyPauseResume }
				>
					{ sprintf(
						__( 'Resume the restriction on %s? Purchasing becomes subscriber-only again immediately.', 'newspack-plugin' ),
						targetingBaseLabel( confirmAction.item )
					) }
				</ConfirmFlow>
			) }

			{ confirmAction?.kind === 'delete' && (
				<ConfirmFlow
					title={ __( 'Delete restriction', 'newspack-plugin' ) }
					confirmLabel={ __( 'Delete restriction', 'newspack-plugin' ) }
					isDestructive
					onCancel={ closeConfirm }
					onConfirm={ applyDelete }
				>
					{ sprintf(
						__( 'Delete the restriction on %s? Everyone can purchase these products immediately.', 'newspack-plugin' ),
						targetingBaseLabel( confirmAction.item )
					) }
				</ConfirmFlow>
			) }

			{ snackbar && (
				<div className="newspack-subscribers-demo__snackbar">
					<Snackbar onRemove={ () => setSnackbar( null ) }>{ snackbar.message }</Snackbar>
				</div>
			) }
		</div>
	);
}
