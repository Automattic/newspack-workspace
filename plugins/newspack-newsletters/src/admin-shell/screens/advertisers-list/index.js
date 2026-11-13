/**
 * Advertisers list screen — React DataView replacing the classic
 * taxonomy term-management screen for `newspack_nl_advertiser`.
 *
 * Two fetches drive the screen: paginated DataView data + a
 * lightweight all-terms fetch for the Modal's parent picker (see
 * `useAllAdvertisers`).
 */

import { Button } from '@wordpress/components';
import { useCallback, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { store } from '@wordpress/icons';
import { EmptyState, Stack } from '@wordpress/ui';

import { DataViews } from 'newspack-components';
import LoadingState from '../../components/loading-state';
import HeaderCount from '../../components/header-count';
import ItemsPerPage from '../../components/items-per-page';
import { EMPTY_STATE_CLASS, getEmptyStateHeading } from '../../constants';
import { useHeaderActions } from '../../header-actions-context';
import usePersistedView from '../../hooks/use-persisted-view';
import isStrictlyEmpty from '../../utils/is-strictly-empty';
import AdvertiserModal from './modal';
import useAdvertisersData from './use-advertisers-data';
import useAllAdvertisers from './use-all-advertisers';
import { getInitialView } from './initial-filters';
import { FIELD_IDS, getFields } from './fields';
import { getActions } from './actions';

const DEFAULT_VIEW = {
	type: 'table',
	page: 1,
	perPage: 20,
	sort: { field: 'name', direction: 'asc' },
	search: '',
	filters: [],
	titleField: 'name',
	descriptionField: 'description',
	fields: [ 'slug', 'count' ],
};

const DEFAULT_LAYOUTS = { table: {} };

const FIT_COLUMNS = [ 'count' ];

const PERSIST_OPTIONS = {
	fieldIds: FIELD_IDS,
	layoutTypes: Object.keys( DEFAULT_LAYOUTS ),
	urlPatch: getInitialView(),
	// A view saved while the description had its own column would show it twice.
	normalize: view => ( { ...view, fields: ( view.fields || [] ).filter( field => field !== 'description' ) } ),
};

// Suppress the built-in ViewConfig per-page control — the custom
// `ItemsPerPage` renders in its place inside the View options popover.
const DATAVIEWS_CONFIG = { perPageSizes: [] };

export default function AdvertisersListScreen() {
	const [ view, setView ] = usePersistedView( 'advertisers-list', DEFAULT_VIEW, PERSIST_OPTIONS );
	const [ modalState, setModalState ] = useState( null ); // null | { mode: 'add' | 'edit', advertiser?: Object }
	// Single mutation trigger shared by every write path (Modal save,
	// per-row Delete, bulk Delete). Bumping it refetches both the
	// paginated DataView and the all-advertisers cache that powers the
	// parent picker — keeps the two datasets in lockstep so a deleted
	// term doesn't linger in the modal's TreeSelect and a freshly-
	// created one appears immediately on the next modal open.
	const [ mutationKey, setMutationKey ] = useState( 0 );

	const { data, paginationInfo, isLoading, hasResolved, hasLoadedOnce } = useAdvertisersData( view, mutationKey );
	const allAdvertisers = useAllAdvertisers( mutationKey );

	// `setModalState` (a `useState` setter) is itself stable, but wrapping
	// the modal handlers in `useCallback` keeps their identities stable
	// across renders so the `useMemo`s below — which capture them — don't
	// have to choose between (a) re-running every render or (b) lying to
	// the hooks-deps lint rule with an empty deps array.
	const openAdd = useCallback( () => setModalState( { mode: 'add' } ), [] );
	const openEdit = useCallback( advertiser => setModalState( { mode: 'edit', advertiser } ), [] );
	const closeModal = useCallback( () => setModalState( null ), [] );
	const onMutated = useCallback( () => setMutationKey( key => key + 1 ), [] );

	const fields = useMemo( () => getFields( { onEdit: openEdit } ), [ openEdit ] );
	const actions = useMemo( () => getActions( { onEdit: openEdit, onMutated } ), [ openEdit, onMutated ] );

	// `trashCount` is omitted, not forgotten: advertisers are terms and have no trash.
	// Passing the `null` `useAdvertisersData` hands back would block this empty state.
	const isStrictEmpty = isStrictlyEmpty( { hasLoadedOnce, isLoading, paginationInfo, view } );
	const HeadingTag = getEmptyStateHeading() === 1 ? 'h1' : 'h2';

	useHeaderActions(
		useMemo(
			() =>
				! hasResolved || isStrictEmpty
					? []
					: [
							{
								type: 'primary',
								label: __( 'Add Advertiser', 'newspack-newsletters' ),
								onClick: openAdd,
							},
					  ],
			[ hasResolved, isStrictEmpty, openAdd ]
		)
	);

	if ( ! hasResolved ) {
		return <LoadingState label={ __( 'Fetching advertisers…', 'newspack-newsletters' ) } />;
	}

	return (
		<>
			<HeaderCount count={ paginationInfo.totalItems } />
			{ isStrictEmpty ? (
				<Stack direction="column" align="center">
					<EmptyState.Root className={ EMPTY_STATE_CLASS }>
						<EmptyState.Icon icon={ store } />
						<EmptyState.Title render={ <HeadingTag /> }>{ __( 'No advertisers yet', 'newspack-newsletters' ) }</EmptyState.Title>
						<EmptyState.Description>
							{ __(
								'Add the businesses that buy ad space, then see and report on each advertiser’s ads in one place.',
								'newspack-newsletters'
							) }
						</EmptyState.Description>
						<EmptyState.Actions>
							<Button variant="primary" onClick={ openAdd }>
								{ __( 'Add Advertiser', 'newspack-newsletters' ) }
							</Button>
						</EmptyState.Actions>
					</EmptyState.Root>
				</Stack>
			) : (
				<DataViews
					countLabel={ ( count, formatted ) =>
						sprintf(
							/* translators: %s: number of advertisers. */
							_n( '%s advertiser', '%s advertisers', count, 'newspack-newsletters' ),
							formatted
						)
					}
					className="newspack-newsletters-list newspack-newsletters-advertisers-list"
					fitColumns={ FIT_COLUMNS }
					data={ data }
					fields={ fields }
					view={ view }
					onChangeView={ setView }
					actions={ actions }
					paginationInfo={ paginationInfo }
					defaultLayouts={ DEFAULT_LAYOUTS }
					isLoading={ isLoading }
					getItemId={ item => String( item.id ) }
					config={ DATAVIEWS_CONFIG }
					inlineFilters
					header={
						<ItemsPerPage value={ view.perPage } onChange={ perPage => setView( current => ( { ...current, perPage, page: 1 } ) ) } />
					}
				/>
			) }

			{ modalState && (
				<AdvertiserModal
					advertiser={ modalState.mode === 'edit' ? modalState.advertiser : null }
					advertisers={ allAdvertisers }
					onClose={ closeModal }
					onSaved={ onMutated }
				/>
			) }
		</>
	);
}
