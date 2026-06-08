import { ExternalLink, Icon } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	DataViews,
	filterSortAndPaginate,
	type Action,
	type Field,
	type View,
} from '@wordpress/dataviews/wp';
import { SourceTypeIcon } from './SourceTypeIcon';
import { DeleteCollectionModal } from './DeleteCollectionModal';
import { DisconnectDataSourceModal } from './DisconnectDataSourceModal';
import { store as profileCollectionsStore } from '../stores/profile-collections';
import { store as onboardingStore } from '../stores/onboarding';
import { store as noticesStore } from '@wordpress/notices';
import { useViewContext } from '../context/ViewContext';
import { isSameDataSource } from '../utils';
import type { ProfileCollection } from '../types/profile-collection';
import type { DataSourceConfig } from '../types/data-source';
import { StatusBadge } from './StatusBadge';

export const REMOTE_DATA_SOURCE_TYPES = [ 'google-sheet', 'airtable' ];

/**
 * Component for displaying and managing profile collections.
 *
 * @return JSX.Element The ProfileCollections component.
 */
export const ProfileCollections = () => {
	const { setViewId } = useViewContext();

	const { profileCollections, isLoading } = useSelect(
		( select ) => ( {
			profileCollections: select(
				profileCollectionsStore
			).getCollections(),
			isLoading: select( profileCollectionsStore ).isResolving(
				'getCollections'
			),
		} ),
		[]
	);

	const { invalidateResolution } = useDispatch( profileCollectionsStore );
	const { resetOnboarding } = useDispatch( onboardingStore );
	const { createInfoNotice, createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );

	const [ view, setView ] = useState< View >( {
		type: 'table',
		perPage: 10,
		page: 1,
		search: '',
		fields: [ 'name', 'status', 'dataSource' ],
	} );

	const fields: Field< ProfileCollection >[] = [
		{
			id: 'name',
			label: __( 'Name', 'newspack-profiles' ),
			enableGlobalSearch: true,
			render: ( { item }: { item: ProfileCollection } ) => {
				return (
					<ExternalLink
						href={ sprintf(
							'/%1$s/%2$s',
							window.NewspackProfilesSettingsConfig.basePath,
							item.slug
						) }
					>
						{ item.name }
					</ExternalLink>
				);
			},
		},
		{
			id: 'status',
			label: __( 'Status', 'newspack-profiles' ),
			render: ( { item }: { item: ProfileCollection } ) => {
				return (
					<div className="flex gap-2">
						<StatusBadge status={ item.status } />
						{ item.dataSource.type === 'wpdb' && (
							<StatusBadge status="disconnected" />
						) }
					</div>
				);
			},
		},
		{
			id: 'dataSource',
			label: __( 'Data Source', 'newspack-profiles' ),
			render: ( { item }: { item: ProfileCollection } ) => {
				if ( item?.isImporting ) {
					return (
						<>
							<Icon
								className="me-2 animate-spin"
								icon="update"
								size={ 24 }
							/>
							{ __( 'Importing', 'newspack-profiles' ) }
							&nbsp;
							<strong className="bg-gray-100 px-1 py-0.5 rounded text-xs">
								{ [
									item.dataSource.name,
									item.dataSource.spreadsheet ??
										item.dataSource.base,
									item.dataSource.sheet ??
										item.dataSource.table,
								]
									.filter( Boolean )
									.join( ' / ' ) }
							</strong>
						</>
					);
				}

				return (
					<>
						<SourceTypeIcon
							className="me-2"
							sourceType={ item.dataSource.type }
						/>
						{ [
							item.dataSource.name,
							item.dataSource.spreadsheet ?? item.dataSource.base,
							item.dataSource.sheet ?? item.dataSource.table,
						]
							.filter( Boolean )
							.join( ' / ' ) }
					</>
				);
			},
			enableSorting: false,
		},
	];

	const actions: Action< ProfileCollection >[] = [
		{
			id: 'change-status',
			label: ( items: ProfileCollection[] ) =>
				items?.[ 0 ]?.status === 'publish'
					? __( 'Convert to Draft', 'newspack-profiles' )
					: __( 'Publish', 'newspack-profiles' ),
			isEligible: ( item: ProfileCollection ) => ! item.isImporting,
			callback: async ( items: ProfileCollection[] ) => {
				if ( ! items?.[ 0 ] ) {
					return;
				}

				const item = items[ 0 ];

				const newStatus =
					item.status === 'publish' ? 'draft' : 'publish';

				const newStatusLabel =
					newStatus === 'publish'
						? __( 'Publish', 'newspack-profiles' )
						: __( 'Draft', 'newspack-profiles' );

				const statusUpdateNoticeId = 'status-update-' + item.slug;

				createInfoNotice(
					sprintf(
						/* translators: 1: Profile collection name. 2: New status. */
						__(
							'Changing status of "%1$s" to "%2$s". This may take a few moments.',
							'newspack-profiles'
						),
						item.name,
						newStatus
					),
					{ type: 'snackbar', id: statusUpdateNoticeId }
				);

				try {
					await apiFetch( {
						path: `/newspack-profiles/v1/profile-collections/update-status`,
						method: 'POST',
						data: {
							slug: item.slug,
							status: newStatus,
						},
					} );

					invalidateResolution( 'getCollections', [] );

					createSuccessNotice(
						sprintf(
							/* translators: 1: Profile collection name. 2: New status. */
							__(
								'Status of "%1$s" changed to "%2$s".',
								'newspack-profiles'
							),
							item.name,
							newStatusLabel
						),
						{
							type: 'snackbar',
							id: statusUpdateNoticeId,
						}
					);
				} catch ( error ) {
					createErrorNotice(
						sprintf(
							/* translators: 1: Profile collection name. 2: New status. */
							__(
								'Failed to change status of "%1$s" to "%2$s". Please try again.',
								'newspack-profiles'
							),
							item.name,
							newStatusLabel
						),
						{
							type: 'snackbar',
							id: statusUpdateNoticeId,
						}
					);

					// eslint-disable-next-line no-console
					console.error( 'Error updating status:', error );
				}
			},
		},
		{
			id: 'edit-collection',
			label: __( 'Edit Collection', 'newspack-profiles' ),
			isEligible: ( item: ProfileCollection ) => ! item.isImporting,
			callback: ( items: ProfileCollection[] ) => {
				if ( ! items?.[ 0 ] ) {
					return;
				}

				const item = items[ 0 ];

				const dataSourceConfig =
					window.NewspackProfilesSettingsConfig.availableDataSources.find(
						( ds ) => isSameDataSource( ds, item.dataSource )
					) as DataSourceConfig;

				resetOnboarding( {
					status: item.status || 'draft',
					currentStep: 1,
					profileName: item.name,
					profileSlug: item.slug,
					slugFields: item.slugFields,
					titleFields: item.titleFields,
					dataSource: dataSourceConfig,
					seoFields: item.seoFields,
					mappings: item.mappings,
					blockPattern: item.pattern,
					pageTemplates: item.pages,
				} );

				setViewId( 'profile-collection/edit' );
			},
		},
		{
			id: 'edit-single-page',
			label: __( 'Edit Single Page', 'newspack-profiles' ),
			isEligible: ( item: ProfileCollection ) => !! item?.pages?.single,
			callback: ( items: ProfileCollection[] ) => {
				items.forEach( ( item ) => {
					if ( ! item.pages?.single ) {
						return;
					}

					window.open(
						window.NewspackProfilesSettingsConfig.editPageURL +
							item.pages.single,
						'_blank'
					);
				} );
			},
		},
		{
			id: 'edit-list-page',
			label: __( 'Edit List Page', 'newspack-profiles' ),
			isEligible: ( item: ProfileCollection ) => !! item?.pages?.list,
			callback: ( items: ProfileCollection[] ) => {
				items.forEach( ( item ) => {
					if ( ! item.pages?.list ) {
						return;
					}

					window.open(
						window.NewspackProfilesSettingsConfig.editPageURL +
							item.pages.list,
						'_blank'
					);
				} );
			},
		},
		{
			id: 'open-sitemap',
			label: __( 'Open Sitemap', 'newspack-profiles' ),
			callback: ( items: ProfileCollection[] ) => {
				items.forEach( ( item ) => {
					window.open(
						sprintf(
							'/%1$s/%2$s/sitemaps.xml',
							window.NewspackProfilesSettingsConfig.basePath,
							item.slug
						),
						'_blank'
					);
				} );
			},
		},
		{
			id: 'delete-collection',
			label: __( 'Delete Collection', 'newspack-profiles' ),
			RenderModal: ( { items, closeModal, onActionPerformed } ) => (
				<DeleteCollectionModal
					item={ items?.[ 0 ]! }
					onClose={ () => {
						closeModal?.();
					} }
					onSuccess={ () => {
						invalidateResolution( 'getCollections', [] );
						onActionPerformed?.( items );
					} }
				/>
			),
		},
		{
			id: 'disconnect-data-source',
			label: __( 'Disconnect Data Source', 'newspack-profiles' ),
			isEligible: ( item: ProfileCollection ) =>
				REMOTE_DATA_SOURCE_TYPES.includes( item.dataSource.type ) &&
				! profileCollections.some(
					( collection: ProfileCollection ) => collection.isImporting
				),
			RenderModal: ( { items, closeModal, onActionPerformed } ) => (
				<DisconnectDataSourceModal
					item={ items?.[ 0 ]! }
					onClose={ () => {
						closeModal?.();
					} }
					onSuccess={ () => {
						invalidateResolution( 'getCollections', [] );
						onActionPerformed?.( items );
					} }
				/>
			),
		},
	];

	const { data, paginationInfo } = filterSortAndPaginate(
		profileCollections,
		view,
		fields
	);

	return (
		<DataViews
			actions={ actions }
			data={ data }
			fields={ fields }
			view={ view }
			isLoading={ isLoading }
			onChangeView={ setView }
			paginationInfo={ paginationInfo }
			defaultLayouts={ {
				table: {},
			} }
			config={ { perPageSizes: [ 10, 20 ] } }
			getItemId={ ( item: ProfileCollection ) => item.slug }
			empty={ <EmptyState /> }
		/>
	);
};

const EmptyState = () => {
	return (
		<div className="p-6 text-center">
			<h3 className="mb-4 text-lg font-semibold">
				{ __( 'No Profile Collections Found', 'newspack-profiles' ) }
			</h3>
			<p className="mb-6 text-gray-600">
				{ __(
					'You have not created any profile collections yet. Click the "Add Profile Collection" button to create your first profile collection.',
					'newspack-profiles'
				) }
			</p>
		</div>
	);
};
