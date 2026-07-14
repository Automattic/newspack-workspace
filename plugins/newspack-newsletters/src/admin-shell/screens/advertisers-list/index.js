/**
 * Advertisers list screen — React DataView replacing the classic
 * taxonomy term-management screen for `newspack_nl_advertiser`.
 *
 * Two fetches drive the screen: paginated DataView data + a
 * lightweight all-terms fetch for the Modal's parent picker (see
 * `useAllAdvertisers`).
 */

import { __experimentalHStack as HStack, Spinner } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { DataViews } from '@wordpress/dataviews/wp';
import { useCallback, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store } from '@wordpress/icons';

import EmptyState from '../../components/empty-state';
import { useHeaderActions } from '../../header-actions-context';
import AdvertiserModal from './modal';
import useAdvertisersData from './use-advertisers-data';
import useAllAdvertisers from './use-all-advertisers';
import { getInitialView } from './initial-filters';
import { getFields } from './fields';
import { getActions } from './actions';

const DEFAULT_VIEW = {
	type: 'table',
	page: 1,
	perPage: 25,
	sort: { field: 'name', direction: 'asc' },
	search: '',
	filters: [],
	titleField: 'name',
	fields: [ 'description', 'slug', 'count' ],
	...getInitialView(),
};

const DEFAULT_LAYOUTS = { table: {} };

export default function AdvertisersListScreen() {
	const [ view, setView ] = useState( DEFAULT_VIEW );
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

	const isStrictEmpty =
		hasLoadedOnce && ! isLoading && paginationInfo.totalItems === 0 && ! view.search && ( ! view.filters || view.filters.length === 0 );

	useHeaderActions(
		useMemo(
			() =>
				! hasResolved || isStrictEmpty
					? []
					: [
							{
								type: 'primary',
								label: __( 'Add new advertiser', 'newspack-newsletters' ),
								onClick: openAdd,
							},
					  ],
			[ hasResolved, isStrictEmpty, openAdd ]
		)
	);

	if ( ! hasResolved ) {
		return (
			<HStack className="newspack-newsletters-admin__loading" justify="center">
				<Spinner />
			</HStack>
		);
	}

	return (
		<>
			{ isStrictEmpty ? (
				<EmptyState
					icon={ store }
					title={ __( 'Get started with advertisers', 'newspack-newsletters' ) }
					description={ __(
						'Group ads by the advertiser they belong to so you can track and report on each one separately.',
						'newspack-newsletters'
					) }
					ctaTitle={ __( 'Add new advertiser', 'newspack-newsletters' ) }
					ctaOnClick={ openAdd }
				/>
			) : (
				<DataViews
					className="newspack-newsletters-list newspack-newsletters-advertisers-list"
					data={ data }
					fields={ fields }
					view={ view }
					onChangeView={ setView }
					actions={ actions }
					paginationInfo={ paginationInfo }
					defaultLayouts={ DEFAULT_LAYOUTS }
					isLoading={ isLoading }
					getItemId={ item => String( item.id ) }
					search
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
