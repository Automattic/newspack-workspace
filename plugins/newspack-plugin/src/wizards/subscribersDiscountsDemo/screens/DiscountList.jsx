/* eslint-disable @wordpress/i18n-translator-comments */
/**
 * L0 — Discounts list (DataViews, full-width).
 *
 * Admin-facing list of member product-discount rules: which plan gets what
 * off which products. Mirrors GroupList.
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
	__experimentalHStack as HStack,
} from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { Badge, Button, DataViews } from '../../../../packages/components/src';
import './style.scss';
import { WIZARD_STORE_NAMESPACE } from '../../../../packages/components/src/wizard/store';
import { getAllDiscounts, deleteDiscount, setDiscountActive, discountLabel, targetingLabel } from '../data/mock-discounts';
import { fmtDate } from '../format';
import ConfirmFlow from '../flows/ConfirmFlow';
import DiscountRuleFlow from '../flows/DiscountRuleFlow';

// Task 5 replaces this with the real settings-drawer flow.
const DiscountSettingsFlow = null;

const DEFAULT_VIEW = {
	type: 'table',
	page: 1,
	perPage: 20,
	sort: { field: 'createdAt', direction: 'desc' },
	search: '',
	fields: [ 'discount', 'appliesTo', 'status', 'createdAt' ],
	filters: [],
	layout: {},
	titleField: 'audience',
};

export default function DiscountList() {
	const [ view, setView ] = useState( DEFAULT_VIEW );
	// Unlike groups, rules mutate in place on this screen (pause/resume/delete
	// are direct storage writes), so re-reading the store is enough to refresh.
	const [ rules, setRules ] = useState( () => getAllDiscounts() );
	const refresh = () => setRules( getAllDiscounts() );

	const [ editorRule, setEditorRule ] = useState( null );
	const [ settingsOpen, setSettingsOpen ] = useState( false );
	const [ confirmAction, setConfirmAction ] = useState( null );
	const [ snackbar, setSnackbar ] = useState( null );

	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );

	const closeConfirm = () => setConfirmAction( null );

	const applyPauseResume = () => {
		const { item, kind } = confirmAction;
		setDiscountActive( item.id, kind === 'resume' );
		refresh();
		setSnackbar( { message: kind === 'resume' ? __( 'Discount resumed.', 'newspack-plugin' ) : __( 'Discount paused.', 'newspack-plugin' ) } );
		closeConfirm();
	};

	const applyDelete = () => {
		deleteDiscount( confirmAction.item.id );
		refresh();
		setSnackbar( { message: __( 'Discount deleted.', 'newspack-plugin' ) } );
		closeConfirm();
	};

	const fields = useMemo(
		() => [
			{
				id: 'audience',
				label: __( 'Audience', 'newspack-plugin' ),
				enableGlobalSearch: true,
				getValue: ( { item } ) => item.audience,
				render: ( { item } ) => <span>{ item.audience }</span>,
				enableSorting: true,
			},
			{
				id: 'discount',
				label: __( 'Discount', 'newspack-plugin' ),
				getValue: ( { item } ) => discountLabel( item ),
				render: ( { item } ) => <span>{ discountLabel( item ) }</span>,
				enableSorting: false,
			},
			{
				id: 'appliesTo',
				label: __( 'Applies to', 'newspack-plugin' ),
				getValue: ( { item } ) => targetingLabel( item ),
				render: ( { item } ) => <span>{ targetingLabel( item ) }</span>,
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
				isPrimary: true,
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

	const total = paginationInfo?.totalItems ?? 0;

	// Surface the rule count in the header breadcrumb, e.g. "/ Discounts (5)".
	useEffect( () => {
		setHeaderData( {
			sectionName: (
				<>
					{ __( 'Discounts', 'newspack-plugin' ) }{ ' ' }
					<span
						className="newspack-subscribers-demo__header-count"
						aria-label={ sprintf( _n( '%d discount total', '%d discounts total', total, 'newspack-plugin' ), total ) }
					>
						{ `(${ total.toLocaleString() })` }
					</span>
				</>
			),
		} );
	}, [ setHeaderData, total ] );

	return (
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions, jsx-a11y/click-events-have-key-events
		<div className="newspack-subscribers-demo__clickable-rows" onClick={ onRowClick }>
			<HStack className="newspack-subscribers-demo__section-head" justify="flex-end" spacing={ 2 }>
				<Button variant="secondary" size="compact" onClick={ () => setSettingsOpen( true ) }>
					{ __( 'Settings', 'newspack-plugin' ) }
				</Button>
				<Button variant="primary" size="compact" onClick={ () => setEditorRule( {} ) }>
					{ __( 'Add discount', 'newspack-plugin' ) }
				</Button>
			</HStack>

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
			/>

			{ editorRule !== null && (
				<DiscountRuleFlow
					rule={ editorRule }
					onClose={ () => setEditorRule( null ) }
					onSaved={ message => {
						refresh();
						setEditorRule( null );
						setSnackbar( { message } );
					} }
				/>
			) }

			{ settingsOpen && DiscountSettingsFlow && (
				<DiscountSettingsFlow
					onClose={ () => setSettingsOpen( false ) }
					onSaved={ message => {
						setSettingsOpen( false );
						setSnackbar( { message } );
					} }
				/>
			) }

			{ confirmAction?.kind === 'pause' && (
				<ConfirmFlow
					title={ __( 'Pause discount', 'newspack-plugin' ) }
					confirmLabel={ __( 'Pause discount', 'newspack-plugin' ) }
					onCancel={ closeConfirm }
					onConfirm={ applyPauseResume }
				>
					{ sprintf(
						__( 'Pause the %1$s discount for %2$s? Members stop getting the discount until you resume it.', 'newspack-plugin' ),
						discountLabel( confirmAction.item ),
						confirmAction.item.audience
					) }
				</ConfirmFlow>
			) }

			{ confirmAction?.kind === 'resume' && (
				<ConfirmFlow
					title={ __( 'Resume discount', 'newspack-plugin' ) }
					confirmLabel={ __( 'Resume discount', 'newspack-plugin' ) }
					onCancel={ closeConfirm }
					onConfirm={ applyPauseResume }
				>
					{ sprintf(
						__( 'Resume the %1$s discount for %2$s? Members start getting the discount again immediately.', 'newspack-plugin' ),
						discountLabel( confirmAction.item ),
						confirmAction.item.audience
					) }
				</ConfirmFlow>
			) }

			{ confirmAction?.kind === 'delete' && (
				<ConfirmFlow
					title={ __( 'Delete discount', 'newspack-plugin' ) }
					confirmLabel={ __( 'Delete discount', 'newspack-plugin' ) }
					isDestructive
					onCancel={ closeConfirm }
					onConfirm={ applyDelete }
				>
					{ sprintf(
						__( 'Delete the %1$s discount for %2$s? Members stop seeing member prices immediately.', 'newspack-plugin' ),
						discountLabel( confirmAction.item ),
						confirmAction.item.audience
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
