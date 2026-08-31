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
import { useMemo, useState } from '@wordpress/element';
import { filterSortAndPaginate } from '@wordpress/dataviews';
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { __experimentalHStack as HStack, CheckboxControl } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { Button, DataViews, Modal, Notice, SectionHeader, StatusIndicator, Waiting } from '../../../../../../../packages/components/src';
import type { Action, Field, View } from '../../../../../../../packages/components/src/dataviews';
import WizardsTab from '../../../../../wizards-tab';
import WizardSection from '../../../../../wizards-section';
import { registerTab } from '../registry';
import { SEARCH_ENDPOINTS } from '../../constants';
import { useRestrictions } from './use-restrictions';
import { useNames } from './use-names';
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
	fields: [ 'availableTo', 'status', 'created_at' ],
	filters: [],
	layout: {},
	titleField: 'products',
};

function SubscriberOnlyProducts() {
	const { restrictions, settings, loading, saving, error, saveRestriction, deleteRestriction, setActive, saveSettings } = useRestrictions();
	const [ editing, setEditing ] = useState< Partial< Restriction > | null >( null );
	const [ deleting, setDeleting ] = useState< Restriction | null >( null );
	const [ settingsOpen, setSettingsOpen ] = useState( false );
	const [ view, setView ] = useState( DEFAULT_VIEW );

	// Resolve the IDs the rules store into names for the list.
	const productIds = useMemo( () => restrictions.flatMap( rule => rule.product_ids || [] ), [ restrictions ] );
	const categoryIds = useMemo( () => restrictions.flatMap( rule => rule.category_ids || [] ), [ restrictions ] );
	const subscriptionIds = useMemo( () => restrictions.flatMap( rule => rule.subscription_product_ids || [] ), [ restrictions ] );
	const productNames = useNames( SEARCH_ENDPOINTS.products, productIds );
	const categoryNames = useNames( SEARCH_ENDPOINTS.productCategories, categoryIds );
	const subscriptionNames = useNames( SEARCH_ENDPOINTS.subscriptions, subscriptionIds );

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
					const { shown, remaining } = leadingProductNames( item, id => productNames[ id ] );
					return (
						<div>
							{ shown.length ? (
								shown.map( name => <div key={ name }>{ name }</div> )
							) : (
								<div className="newspack-subscriber-only__muted">{ __( 'No products', 'newspack-plugin' ) }</div>
							) }
							{ remaining > 0 && <div className="newspack-subscriber-only__muted">{ moreProductsLabel( remaining ) }</div> }
						</div>
					);
				},
			},
			{
				id: 'availableTo',
				label: __( 'Available to', 'newspack-plugin' ),
				enableSorting: false,
				getValue: ( { item }: { item: Restriction } ) =>
					( item.subscription_product_ids || [] ).map( id => subscriptionNames[ id ] || '' ).join( ', ' ),
				render: ( { item }: { item: Restriction } ) => {
					const names = ( item.subscription_product_ids || [] ).map( id => subscriptionNames[ id ] ).filter( Boolean );
					return <span>{ names.length ? names.join( ', ' ) : __( 'No subscriptions', 'newspack-plugin' ) }</span>;
				},
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
					<StatusIndicator status={ item.active ? 'active' : 'draft' }>
						{ item.active ? __( 'Active', 'newspack-plugin' ) : __( 'Inactive', 'newspack-plugin' ) }
					</StatusIndicator>
				),
			},
			{
				id: 'created_at',
				label: __( 'Created', 'newspack-plugin' ),
				getValue: ( { item }: { item: Restriction } ) => item.created_at,
				enableSorting: true,
			},
		],
		[ productNames, categoryNames, subscriptionNames ]
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
		<WizardsTab
			title={
				restrictions.length
					? sprintf(
							/* translators: %d: number of restrictions. */
							_n( 'Subscriber-only products (%d)', 'Subscriber-only products (%d)', total, 'newspack-plugin' ),
							total
					  )
					: __( 'Subscriber-only products', 'newspack-plugin' )
			}
		>
			<WizardSection>
				{ error && <Notice isError noticeText={ error } /> }
				{ loading ? (
					<Waiting />
				) : (
					<>
						{ restrictions.length ? (
							<>
								<HStack justify="flex-end" spacing={ 2 }>
									<Button variant="secondary" onClick={ () => setSettingsOpen( true ) }>
										{ __( 'Settings', 'newspack-plugin' ) }
									</Button>
									<Button variant="primary" onClick={ () => setEditing( {} ) }>
										{ __( 'Add restriction', 'newspack-plugin' ) }
									</Button>
								</HStack>
								{ /* The DataViews wrapper isn't generic — its props resolve to
								     `unknown`, so the typed definitions above are cast at the
								     boundary rather than being written untyped. */ }
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
								/>
							</>
						) : (
							<SectionHeader
								title={ __( 'Get started with subscriber-only products', 'newspack-plugin' ) }
								description={ __(
									'Make selected products purchasable only by subscribers. Readers still see the product and its price — only the purchase is blocked.',
									'newspack-plugin'
								) }
								noMargin
							>
								<Button variant="primary" onClick={ () => setEditing( {} ) }>
									{ __( 'Add restriction', 'newspack-plugin' ) }
								</Button>
							</SectionHeader>
						) }
					</>
				) }
			</WizardSection>

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
		</WizardsTab>
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

registerTab( 'subscriber-only', { render: () => <SubscriberOnlyProducts /> } );
