/**
 * Newsletters list screen — React DataView replacing the classic CPT list.
 */

import { Button } from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews/wp';
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { envelope } from '@wordpress/icons';

import { EmptyState } from 'newspack-components';
import { getAdminUrl, getCptSlug } from '../../admin-globals';
import LoadingState from '../../components/loading-state';
import HeaderCount from '../../components/header-count';
import ItemsPerPage from '../../components/items-per-page';
import { EMPTY_STATE_CLASS, getEmptyStateHeading } from '../../constants';
import { useHeaderActions } from '../../header-actions-context';
import useLockedPosts, { LockedPostsContext, MAX_CHECKED } from '../../hooks/use-locked-posts';
import usePersistedView from '../../hooks/use-persisted-view';
import isStrictlyEmpty from '../../utils/is-strictly-empty';
import useNewslettersData from './use-newsletters-data';
import useFilterElements from './use-filter-elements';
import { FIELD_IDS, getFields } from './fields';
import { getActions } from './actions';
import { getInitialView } from './initial-filters';
import NewslettersQuickEditPanel from './quick-edit-panel';

const DEFAULT_VIEW = {
	type: 'table',
	page: 1,
	perPage: 20,
	sort: { field: 'date', direction: 'desc' },
	search: '',
	filters: [],
	titleField: 'title',
	fields: [ 'status', 'date', 'send_date', 'send_list', 'author', 'public_page' ],
};

const DEFAULT_LAYOUTS = { table: {} };

// Built at module scope so a forwarded legacy link is parsed out of
// `window.location.search` once rather than on every render.
const PERSIST_OPTIONS = {
	fieldIds: FIELD_IDS,
	layoutTypes: Object.keys( DEFAULT_LAYOUTS ),
	urlPatch: getInitialView(),
};

// Suppress the built-in ViewConfig per-page control — the custom
// `ItemsPerPage` renders in its place inside the View options popover.
const DATAVIEWS_CONFIG = { perPageSizes: [] };

export default function NewslettersListScreen() {
	const [ view, setView ] = usePersistedView( 'newsletters-list', DEFAULT_VIEW, PERSIST_OPTIONS );
	const [ quickEditItem, setQuickEditItem ] = useState( null );
	const { data, paginationInfo, isLoading, hasResolved, hasLoadedOnce, trashCount, refresh } = useNewslettersData( view );
	const filterElements = useFilterElements();
	const locks = useLockedPosts( useMemo( () => data.slice( 0, MAX_CHECKED ).map( item => item.id ), [ data ] ) );

	const addNewHref = `${ getAdminUrl() }post-new.php?post_type=${ getCptSlug() }`;

	const fields = useMemo( () => getFields( filterElements ), [ filterElements ] );
	const actions = useMemo( () => getActions( { refresh, openQuickEdit: setQuickEditItem } ), [ refresh ] );

	const isStrictEmpty = isStrictlyEmpty( { hasLoadedOnce, isLoading, paginationInfo, trashCount, view } );

	useHeaderActions(
		useMemo(
			() =>
				! hasResolved || isStrictEmpty
					? []
					: [
							{
								type: 'primary',
								label: __( 'Add Newsletter', 'newspack-newsletters' ),
								href: addNewHref,
							},
					  ],
			[ hasResolved, isStrictEmpty, addNewHref ]
		)
	);

	if ( ! hasResolved ) {
		return <LoadingState label={ __( 'Fetching newsletters…', 'newspack-newsletters' ) } />;
	}

	if ( isStrictEmpty ) {
		return (
			<EmptyState.Root className={ EMPTY_STATE_CLASS }>
				<EmptyState.Header
					icon={ envelope }
					heading={ getEmptyStateHeading() }
					title={ __( 'Get started with newsletters', 'newspack-newsletters' ) }
					description={ __(
						'Compose, schedule, and send newsletters to your subscribers via your connected ESP.',
						'newspack-newsletters'
					) }
				/>
				<EmptyState.Actions>
					<Button variant="primary" href={ addNewHref }>
						{ __( 'Add Newsletter', 'newspack-newsletters' ) }
					</Button>
				</EmptyState.Actions>
			</EmptyState.Root>
		);
	}

	return (
		<>
			<HeaderCount count={ paginationInfo.totalItems } />
			<LockedPostsContext.Provider value={ locks }>
				<DataViews
					className="newspack-newsletters-list"
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
					config={ DATAVIEWS_CONFIG }
					header={
						<ItemsPerPage value={ view.perPage } onChange={ perPage => setView( current => ( { ...current, perPage, page: 1 } ) ) } />
					}
				/>
			</LockedPostsContext.Provider>
			{ quickEditItem && (
				<NewslettersQuickEditPanel
					key={ quickEditItem.id }
					item={ quickEditItem }
					onClose={ () => setQuickEditItem( null ) }
					onSaved={ () => {
						refresh();
						setQuickEditItem( null );
					} }
				/>
			) }
		</>
	);
}
