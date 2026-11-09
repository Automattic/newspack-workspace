/**
 * The Subscriptions wizard's Subscriber-only products tab.
 *
 * Lists the restrictions that make products purchasable only by subscribers,
 * and hosts the editor, the settings and the empty state.
 */

/**
 * WordPress dependencies.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { filterSortAndPaginate } from '@wordpress/dataviews';
import { Stack } from '@wordpress/ui';
import { people } from '@wordpress/icons';
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { __experimentalHStack as HStack, CheckboxControl } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { Button, CellList, DataViews, Modal, Notice, StatusBadge, Waiting } from '../../../../../../../packages/components/src';
import EmptyState from '../../../../../../../packages/components/src/empty-state';
import type { Action, Field, View } from '../../../../../../../packages/components/src/dataviews';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../../packages/components/src/wizard/store';
import Router from '../../../../../../../packages/components/src/proxied-imports/router';
import { formatCount } from '../../../../../../../packages/components/src/breadcrumbs/format-count';
import { fmtDate } from '../../../../../subscribers/format';
import { registerTab } from '../registry';
import { ALL_SUBSCRIPTIONS_FILTER_VALUE, allSubscriptionsLabel } from '../../constants';
import { useRestrictions } from './use-restrictions';
import NamesTooltip from '../../components/names-tooltip';
import { excludedLabel, leadingProductNames, moreProductsLabel, scopeLabel } from './labels';
import RestrictionEditor from './restriction-editor';
import type { Restriction, RestrictionSettings } from './types';
import './style.scss';

const DEFAULT_VIEW = {
	type: 'table' as const,
	page: 1,
	perPage: 20,
	sort: { field: 'created_at', direction: 'desc' as const },
	search: '',
	fields: [ 'status', 'availableTo', 'created_at' ],
	filters: [],
	layout: {},
	titleField: 'products',
};

const { useLocation } = Router;

const SECTION_LABEL = __( 'Subscriber-only products', 'newspack-plugin' );

function SubscriberOnlyProducts() {
	const {
		restrictions,
		names: ruleNames,
		settings,
		loading,
		saving,
		error,
		saveRestriction,
		deleteRestriction,
		setActive,
		saveSettings,
	} = useRestrictions();
	const [ editing, setEditing ] = useState< Partial< Restriction > | null >( null );
	const [ deleting, setDeleting ] = useState< Restriction | null >( null );
	const [ settingsOpen, setSettingsOpen ] = useState( false );
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );
	const { pathname } = useLocation();

	const productNames = ruleNames.products;
	const categoryNames = ruleNames.categories;
	const subscriptionNames = ruleNames.subscriptions;
	const subscriptionIds = useMemo( () => restrictions.flatMap( rule => rule.subscription_product_ids || [] ), [ restrictions ] );

	const fields: Field< Restriction >[] = useMemo(
		() => [
			{
				id: 'products',
				label: __( 'Products', 'newspack-plugin' ),
				enableGlobalSearch: true,
				enableSorting: false,
				getValue: ( { item }: { item: Restriction } ) =>
					'products' === item.targeting
						? ( item.product_ids || [] ).map( id => productNames[ id ] || '' ).join( ' ' )
						: scopeLabel( item, id => categoryNames[ id ] ),
				render: ( { item }: { item: Restriction } ) => {
					const excluded = excludedLabel( item );
					if ( 'products' !== item.targeting ) {
						return (
							<div>
								<div>{ scopeLabel( item, id => categoryNames[ id ] ) }</div>
								{ excluded && <div className="newspack-subscriber-only__muted">{ excluded }</div> }
							</div>
						);
					}
					const { shown, hidden, remaining } = leadingProductNames( item, id => productNames[ id ] );
					return (
						<div>
							{ shown.length ? (
								shown.map( name => <div key={ name }>{ name }</div> )
							) : (
								<div className="newspack-subscriber-only__muted">{ __( 'No products', 'newspack-plugin' ) }</div>
							) }
							{ remaining > 0 && (
								<div className="newspack-subscriber-only__muted">
									<NamesTooltip muted label={ moreProductsLabel( remaining ) } names={ hidden } />
								</div>
							) }
						</div>
					);
				},
			},
			{
				id: 'availableTo',
				label: __( 'Available to', 'newspack-plugin' ),
				enableSorting: false,
				elements: [
					{ value: ALL_SUBSCRIPTIONS_FILTER_VALUE, label: allSubscriptionsLabel() },
					...[ ...new Set( subscriptionIds ) ].map( id => ( { value: id, label: subscriptionNames[ id ] || `#${ id }` } ) ),
				],
				filterBy: { operators: [ 'isAny' ] },
				// Ids rather than names, so the filter keeps two same-named subscriptions
				// apart. A rule open to every subscriber names none, so it takes the
				// sentinel instead and stays filterable.
				getValue: ( { item }: { item: Restriction } ) =>
					'all' === item.subscription_targeting ? [ ALL_SUBSCRIPTIONS_FILTER_VALUE ] : item.subscription_product_ids || [],
				render: ( { item }: { item: Restriction } ) => {
					if ( 'all' === item.subscription_targeting ) {
						return <span>{ allSubscriptionsLabel() }</span>;
					}
					const names = ( item.subscription_product_ids || [] ).map( id => subscriptionNames[ id ] ).filter( Boolean );
					if ( ! names.length ) {
						return <span>{ __( 'No subscriptions', 'newspack-plugin' ) }</span>;
					}
					return (
						<CellList>
							{ names.map( ( name, index ) => (
								<span key={ `${ name }-${ index }` }>{ name }</span>
							) ) }
						</CellList>
					);
				},
			},
			{
				id: 'available_to_names',
				// What the search matches, since Available to has to hold ids for its filter.
				label: __( 'Available to (names)', 'newspack-plugin' ),
				enableHiding: false,
				enableSorting: false,
				enableGlobalSearch: true,
				getValue: ( { item }: { item: Restriction } ) =>
					'all' === item.subscription_targeting
						? allSubscriptionsLabel()
						: ( item.subscription_product_ids || [] ).map( id => subscriptionNames[ id ] || '' ).join( ', ' ),
			},
			{
				id: 'status',
				label: __( 'Status', 'newspack-plugin' ),
				elements: [
					{ value: 'active', label: __( 'Active', 'newspack-plugin' ) },
					{ value: 'inactive', label: __( 'Inactive', 'newspack-plugin' ) },
				],
				filterBy: { operators: [ 'isAny' as const ] },
				getValue: ( { item }: { item: Restriction } ) => ( item.active ? 'active' : 'inactive' ),
				render: ( { item }: { item: Restriction } ) => (
					<StatusBadge status={ item.active ? 'active' : 'draft' }>
						{ item.active ? __( 'Active', 'newspack-plugin' ) : __( 'Inactive', 'newspack-plugin' ) }
					</StatusBadge>
				),
			},
			{
				id: 'created_at',
				label: __( 'Created', 'newspack-plugin' ),
				getValue: ( { item }: { item: Restriction } ) => item.created_at,
				render: ( { item }: { item: Restriction } ) =>
					item.created_at ? <span className="newspack-dataviews__date">{ fmtDate( item.created_at ) }</span> : '',
				enableSorting: true,
			},
		],
		[ productNames, categoryNames, subscriptionNames, subscriptionIds ]
	);

	const actions: Action< Restriction >[] = useMemo(
		() => [
			{
				id: 'edit',
				label: __( 'Edit', 'newspack-plugin' ),
				isPrimary: true,
				callback: ( items: Restriction[] ) => setEditing( items[ 0 ] ),
			},
			{
				id: 'toggle',
				label: ( items: Restriction[] ) =>
					items[ 0 ]?.active ? __( 'Set to inactive', 'newspack-plugin' ) : __( 'Set to active', 'newspack-plugin' ),
				callback: ( items: Restriction[] ) => setActive( items[ 0 ], ! items[ 0 ].active ),
			},
			{
				id: 'delete',
				label: __( 'Delete', 'newspack-plugin' ),
				isDestructive: true,
				callback: ( items: Restriction[] ) => setDeleting( items[ 0 ] ),
			},
		],
		[ setActive ]
	);

	const { data: processedData, paginationInfo } = useMemo(
		() => filterSortAndPaginate( restrictions, view, fields ),
		[ restrictions, view, fields ]
	);

	const total = paginationInfo?.totalItems ?? 0;
	const hasRestrictions = restrictions.length > 0;

	useEffect( () => {
		if ( loading || ! hasRestrictions ) {
			setHeaderData( { sectionName: SECTION_LABEL, actions: [] } );
			return;
		}
		setHeaderData( {
			sectionName: [
				{
					label: SECTION_LABEL,
					count: total,
					countLabel: sprintf(
						/* translators: %s: number of restrictions. */
						_n( '%s restriction', '%s restrictions', total, 'newspack-plugin' ),
						formatCount( total )
					),
				},
			],
			actions: [
				{ type: 'secondary', label: __( 'Settings', 'newspack-plugin' ), action: () => setSettingsOpen( true ) },
				{ type: 'primary', label: __( 'Add Restriction', 'newspack-plugin' ), mobileIcon: 'plus', action: () => setEditing( {} ) },
			],
		} );
		// `pathname` re-asserts the header after the wizard resets it on a route change.
	}, [ setHeaderData, loading, hasRestrictions, total, pathname ] );

	const handleSave = ( rule: Partial< Restriction > ) => {
		saveRestriction( rule ).then( ( ok: boolean ) => {
			if ( ok ) {
				setEditing( null );
			}
		} );
	};

	const handleDelete = () => {
		if ( ! deleting ) {
			return;
		}
		deleteRestriction( deleting.id ).then( ( ok: boolean ) => {
			if ( ok ) {
				setDeleting( null );
			}
		} );
	};

	return (
		<div className="newspack-subscriber-only">
			{ error && <Notice isError noticeText={ error } /> }
			{ loading && (
				<div className="newspack-wizard__loader">
					<Stack direction="column" align="center" gap="sm">
						<Waiting noMargin />
						<strong>{ __( 'Fetching…', 'newspack-plugin' ) }</strong>
					</Stack>
				</div>
			) }
			{ ! loading && hasRestrictions && (
				// The DataViews wrapper isn't generic — its props resolve to `unknown`,
				// so the typed definitions above are cast at the boundary.
				<DataViews
					data={ processedData }
					fields={ fields as unknown as Field< unknown >[] }
					view={ view as View }
					onChangeView={ nextView => setView( nextView as typeof view ) }
					actions={ actions as unknown as Action< unknown >[] }
					paginationInfo={ paginationInfo }
					getItemId={ ( item: unknown ) => ( item as Restriction ).id }
					defaultLayouts={ { table: {} } }
					isLoading={ saving }
					inlineFilters
				/>
			) }
			{ ! loading && ! hasRestrictions && (
				<div className="newspack-subscriber-only__empty">
					<EmptyState.Root>
						<EmptyState.Header
							icon={ people }
							title={ __( 'Get started with subscriber-only products', 'newspack-plugin' ) }
							description={ __(
								'Make selected products purchasable only by subscribers. Readers still see the product and its price — only the purchase is blocked.',
								'newspack-plugin'
							) }
						/>
						<EmptyState.Actions>
							<Button variant="primary" onClick={ () => setEditing( {} ) }>
								{ __( 'Add Restriction', 'newspack-plugin' ) }
							</Button>
						</EmptyState.Actions>
					</EmptyState.Root>
				</div>
			) }

			{ editing && <RestrictionEditor restriction={ editing } saving={ saving } onSave={ handleSave } onClose={ () => setEditing( null ) } /> }

			{ deleting && (
				<Modal title={ __( 'Delete restriction?', 'newspack-plugin' ) } onRequestClose={ () => setDeleting( null ) }>
					<p>
						{ __(
							'The products it covers become purchasable by everyone. To keep the restriction but stop enforcing it, pause it instead.',
							'newspack-plugin'
						) }
					</p>
					<HStack justify="flex-end" spacing={ 2 }>
						<Button variant="secondary" onClick={ () => setDeleting( null ) } disabled={ saving }>
							{ __( 'Cancel', 'newspack-plugin' ) }
						</Button>
						<Button variant="primary" isDestructive onClick={ handleDelete } disabled={ saving }>
							{ __( 'Delete', 'newspack-plugin' ) }
						</Button>
					</HStack>
				</Modal>
			) }

			{ settingsOpen && (
				<SettingsModal settings={ settings } saving={ saving } onSave={ saveSettings } onClose={ () => setSettingsOpen( false ) } />
			) }
		</div>
	);
}

interface SettingsModalProps {
	settings: RestrictionSettings;
	saving: boolean;
	onSave: ( settings: RestrictionSettings ) => Promise< boolean >;
	onClose: () => void;
}

function SettingsModal( { settings, saving, onSave, onClose }: SettingsModalProps ) {
	const [ hide, setHide ] = useState( settings.hide_from_product_lists );

	return (
		<Modal title={ __( 'Restriction settings', 'newspack-plugin' ) } onRequestClose={ onClose }>
			<CheckboxControl
				label={ __( 'Hide from product lists', 'newspack-plugin' ) }
				help={ __(
					'Keep subscriber-only products out of product lists for readers who cannot purchase them. Direct links still work.',
					'newspack-plugin'
				) }
				checked={ hide }
				onChange={ setHide }
				__nextHasNoMarginBottom
			/>
			<HStack justify="flex-end" spacing={ 2 }>
				<Button variant="secondary" onClick={ onClose } disabled={ saving }>
					{ __( 'Cancel', 'newspack-plugin' ) }
				</Button>
				<Button
					variant="primary"
					disabled={ saving }
					onClick={ () => {
						onSave( { hide_from_product_lists: hide } ).then( ( ok: boolean ) => {
							if ( ok ) {
								onClose();
							}
						} );
					} }
				>
					{ __( 'Save', 'newspack-plugin' ) }
				</Button>
			</HStack>
		</Modal>
	);
}

registerTab( 'subscriber-only', { render: () => <SubscriberOnlyProducts />, fullWidth: true, rendersLeafCrumb: true } );
