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
import { EmptyState, Stack } from '@wordpress/ui';
import { people } from '@wordpress/icons';
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { __experimentalHStack as HStack, CheckboxControl, Notice as CoreNotice, SelectControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { decodeEntities } from '@wordpress/html-entities';

/**
 * Internal dependencies.
 */
import { Button, CellList, DataViews, formatSiteDate, Modal, Notice, StatusBadge, Waiting } from '../../../../../../../packages/components/src';
import type { Action, Field, View } from '../../../../../../../packages/components/src/dataviews';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../../packages/components/src/wizard/store';
import Router from '../../../../../../../packages/components/src/proxied-imports/router';
import { formatCount } from '../../../../../../../packages/components/src/breadcrumbs/format-count';
import { registerTab } from '../registry';
import { ALL_SUBSCRIPTIONS_FILTER_VALUE, allSubscriptionsLabel } from '../../constants';
import { useRestrictions } from './use-restrictions';
import ScopeSummary from '../../../../components/scope-summary';
import { restrictionTypeLabel, scopeLabel } from './labels';
import RestrictionEditor from './restriction-editor';
import type { ProductPageMode, Restriction, RestrictionSettings } from './types';
import './style.scss';

const FIT_COLUMNS = [ 'status' ];

const DEFAULT_VIEW = {
	type: 'table' as const,
	page: 1,
	perPage: 20,
	sort: { field: 'created_at', direction: 'desc' as const },
	search: '',
	fields: [ 'restriction_type', 'status', 'availableTo', 'created_at' ],
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
				render: ( { item }: { item: Restriction } ) => (
					<ScopeSummary
						asTitle
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
				id: 'restriction_type',
				label: __( 'Restrict', 'newspack-plugin' ),
				elements: [
					{ value: 'purchase', label: restrictionTypeLabel( 'purchase' ) },
					{ value: 'view', label: restrictionTypeLabel( 'view' ) },
				],
				filterBy: { operators: [ 'isAny' as const ] },
				enableSorting: false,
				getValue: ( { item }: { item: Restriction } ) => item.restriction_type,
				render: ( { item }: { item: Restriction } ) => <span>{ restrictionTypeLabel( item.restriction_type ) }</span>,
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
					item.created_at ? <span className="newspack-dataviews__date">{ formatSiteDate( item.created_at ) }</span> : '',
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
					fitColumns={ FIT_COLUMNS }
					countLabel={ ( count, formatted ) =>
						sprintf(
							/* translators: %s: number of restrictions. */
							_n( '%s restriction', '%s restrictions', count, 'newspack-plugin' ),
							formatted
						)
					}
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
					<Stack direction="column" align="center">
						<EmptyState.Root>
							<EmptyState.Icon icon={ people } />
							<EmptyState.Title>{ __( 'No restrictions yet', 'newspack-plugin' ) }</EmptyState.Title>
							<EmptyState.Description>
								{ __(
									'Limit selected products to subscribers: block only their purchase, or hide them from everyone else too.',
									'newspack-plugin'
								) }
							</EmptyState.Description>
							<EmptyState.Actions>
								<Button variant="primary" onClick={ () => setEditing( {} ) }>
									{ __( 'Add Restriction', 'newspack-plugin' ) }
								</Button>
							</EmptyState.Actions>
						</EmptyState.Root>
					</Stack>
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
				<SettingsModal
					settings={ settings }
					saving={ saving }
					error={ error }
					onSave={ saveSettings }
					onClose={ () => setSettingsOpen( false ) }
				/>
			) }
		</div>
	);
}

interface SettingsModalProps {
	settings: RestrictionSettings;
	saving: boolean;
	/** The latest request error, shown when a save from this modal fails. */
	error: string;
	onSave: ( settings: RestrictionSettings ) => Promise< boolean >;
	onClose: () => void;
}

function SettingsModal( { settings, saving, error, onSave, onClose }: SettingsModalProps ) {
	const [ hide, setHide ] = useState( settings.hide_from_product_lists );
	const [ productPage, setProductPage ] = useState< ProductPageMode >( settings.product_page );
	const [ redirectPageId, setRedirectPageId ] = useState( settings.redirect_page_id );
	// The page-level notice sits under this modal, so a failed save shows here.
	const [ saveFailed, setSaveFailed ] = useState( false );
	const { pages, failed, retry } = usePublishedPages( 'redirect' === productPage );

	// "Hide completely" leaves nothing to link to, so it hides from lists too.
	const hidesFromLists = hide || 'hide' === productPage;
	// A redirect needs a published page to go to.
	const hasRedirectPage = Boolean( pages?.some( page => page.id === redirectPageId ) );
	const isComplete = 'redirect' !== productPage || hasRedirectPage;

	let redirectHelp: string = __( 'Choose the page readers go to.', 'newspack-plugin' );
	if ( pages && ! pages.length ) {
		redirectHelp = __( 'No published pages yet. Publish a page to redirect readers to.', 'newspack-plugin' );
	} else if ( pages && redirectPageId > 0 && ! hasRedirectPage ) {
		redirectHelp = __( 'The saved page is no longer published. Choose another page.', 'newspack-plugin' );
	}

	return (
		<Modal title={ __( 'Restriction settings', 'newspack-plugin' ) } onRequestClose={ onClose }>
			<Stack direction="column" gap="lg">
				{ saveFailed && error && (
					<CoreNotice status="error" isDismissible={ false }>
						{ error }
					</CoreNotice>
				) }
				{ 'redirect' === productPage && failed && (
					<CoreNotice
						status="error"
						isDismissible={ false }
						politeness="polite"
						actions={ [ { label: __( 'Retry', 'newspack-plugin' ), onClick: retry } ] }
					>
						{ __( "Pages couldn't be loaded.", 'newspack-plugin' ) }
					</CoreNotice>
				) }
				<CheckboxControl
					label={ __( 'Hide from product lists', 'newspack-plugin' ) }
					help={
						'hide' === productPage
							? __( '"Hide completely" also hides the products from product lists.', 'newspack-plugin' )
							: __(
									'Keep products restricted for "Purchase and visibility" out of product lists for readers who can\'t see them.',
									'newspack-plugin'
							  )
					}
					checked={ hidesFromLists }
					onChange={ setHide }
					disabled={ saving || 'hide' === productPage }
					__nextHasNoMarginBottom
				/>
				<SelectControl
					label={ __( 'Product page for hidden products', 'newspack-plugin' ) }
					help={ __(
						'What a reader who can\'t see the product gets on its page. "Hide content only" keeps the page but replaces its details with the subscriber-only notice.',
						'newspack-plugin'
					) }
					value={ productPage }
					options={ [
						{ value: 'hide_content', label: __( 'Hide content only', 'newspack-plugin' ) },
						{ value: 'hide', label: __( 'Hide completely (404)', 'newspack-plugin' ) },
						{ value: 'redirect', label: __( 'Redirect to a page', 'newspack-plugin' ) },
					] }
					onChange={ ( next: string ) => setProductPage( next as ProductPageMode ) }
					disabled={ saving }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				{ 'redirect' === productPage && (
					<SelectControl
						label={ __( 'Redirect to', 'newspack-plugin' ) }
						help={ redirectHelp }
						value={ hasRedirectPage ? String( redirectPageId ) : '0' }
						options={ [
							{
								value: '0',
								label: pages || failed ? __( 'Select a page', 'newspack-plugin' ) : __( 'Loading pages…', 'newspack-plugin' ),
								disabled: true,
							},
							...( pages || [] ).map( page => ( { value: String( page.id ), label: page.title } ) ),
						] }
						onChange={ ( next: string ) => setRedirectPageId( Number( next ) ) }
						disabled={ saving || ! pages?.length }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				) }
				<Stack direction="row" gap="sm" justify="end">
					<Button variant="secondary" onClick={ onClose } disabled={ saving }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button
						variant="primary"
						disabled={ saving || ! isComplete }
						accessibleWhenDisabled
						onClick={ () => {
							// Cleared first, so a repeated failure mounts the notice again and is announced.
							setSaveFailed( false );
							onSave( {
								hide_from_product_lists: hide,
								product_page: productPage,
								redirect_page_id: 'redirect' === productPage ? redirectPageId : 0,
							} ).then( ( ok: boolean ) => {
								setSaveFailed( ! ok );
								if ( ok ) {
									onClose();
								}
							} );
						} }
					>
						{ __( 'Save', 'newspack-plugin' ) }
					</Button>
				</Stack>
			</Stack>
		</Modal>
	);
}

interface PageOption {
	id: number;
	title: string;
}

/**
 * Every published page, by title, for the redirect picker, loaded the first
 * time it is needed. `pages` is null until then and while loading; `failed`
 * is set when the list can't be loaded, and `retry` loads it again.
 *
 * @param needed Whether the picker is showing.
 */
function usePublishedPages( needed: boolean ) {
	const [ pages, setPages ] = useState< PageOption[] | null >( null );
	const [ failed, setFailed ] = useState( false );
	const [ requested, setRequested ] = useState( false );
	const retry = () => {
		setFailed( false );
		setRequested( false );
	};

	useEffect( () => {
		if ( ! needed || requested ) {
			return;
		}
		setRequested( true );
		type PageResponse = { id: number; title: { rendered: string } };
		const toOption = ( page: PageResponse ) => ( {
			id: page.id,
			title:
				decodeEntities( page.title.rendered ) ||
				sprintf( /* translators: %d: page ID. */ __( '(no title) #%d', 'newspack-plugin' ), page.id ),
		} );
		const fetchFrom = async ( pageNumber: number ): Promise< PageOption[] > => {
			const response = ( await apiFetch( {
				path: addQueryArgs( '/wp/v2/pages', {
					status: 'publish',
					_fields: 'id,title',
					orderby: 'title',
					order: 'asc',
					per_page: 100,
					page: pageNumber,
				} ),
				parse: false,
			} ) ) as Response;
			const options = ( ( await response.json() ) as PageResponse[] ).map( toOption );
			const totalPages = Number( response.headers.get( 'X-WP-TotalPages' ) ) || 1;
			return pageNumber < totalPages ? [ ...options, ...( await fetchFrom( pageNumber + 1 ) ) ] : options;
		};
		fetchFrom( 1 )
			.then( setPages )
			.catch( () => setFailed( true ) );
	}, [ needed, requested ] );

	return { pages, failed, retry };
}

registerTab( 'subscriber-only', { render: () => <SubscriberOnlyProducts />, fullWidth: true, rendersLeafCrumb: true } );
