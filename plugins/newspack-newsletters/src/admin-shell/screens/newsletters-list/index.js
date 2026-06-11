/**
 * Newsletters list screen — React DataView replacing the classic CPT list.
 */

import { __experimentalHStack as HStack, Spinner } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { DataViews } from '@wordpress/dataviews/wp';
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { envelope } from '@wordpress/icons';

import { getAdminUrl, getCptSlug } from '../../admin-globals';
import EmptyState from '../../components/empty-state';
import { useHeaderActions } from '../../header-actions-context';
import useNewslettersData from './use-newsletters-data';
import useFilterElements from './use-filter-elements';
import { getFields } from './fields';
import { getActions } from './actions';
import { getInitialView } from './initial-filters';
import NewslettersQuickEditPanel from './quick-edit-panel';

// URL-seeded patch last so forwarded-from-legacy values override defaults.
const DEFAULT_VIEW = {
	type: 'table',
	page: 1,
	perPage: 25,
	sort: { field: 'date', direction: 'desc' },
	search: '',
	filters: [],
	titleField: 'title',
	fields: [ 'status', 'date', 'send_date', 'send_list', 'author', 'public_page' ],
	...getInitialView(),
};

const DEFAULT_LAYOUTS = { table: {} };

export default function NewslettersListScreen() {
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const [ quickEditItem, setQuickEditItem ] = useState( null );
	const { data, paginationInfo, isLoading, hasResolved, hasLoadedOnce, trashCount, refresh } = useNewslettersData( view );
	const filterElements = useFilterElements();

	const addNewHref = `${ getAdminUrl() }post-new.php?post_type=${ getCptSlug() }`;

	const fields = useMemo( () => getFields( filterElements ), [ filterElements ] );
	const actions = useMemo( () => getActions( { refresh, openQuickEdit: setQuickEditItem } ), [ refresh ] );

	const isStrictEmpty =
		hasLoadedOnce &&
		! isLoading &&
		paginationInfo.totalItems === 0 &&
		trashCount === 0 &&
		! view.search &&
		( ! view.filters || view.filters.length === 0 );

	useHeaderActions(
		useMemo(
			() =>
				! hasResolved || isStrictEmpty
					? []
					: [
							{
								type: 'primary',
								label: __( 'Add new newsletter', 'newspack-newsletters' ),
								href: addNewHref,
							},
					  ],
			[ hasResolved, isStrictEmpty, addNewHref ]
		)
	);

	if ( ! hasResolved ) {
		return (
			<HStack className="newspack-newsletters-admin__loading" justify="center">
				<Spinner />
			</HStack>
		);
	}

	if ( isStrictEmpty ) {
		return (
			<EmptyState
				icon={ envelope }
				title={ __( 'Get started with newsletters', 'newspack-newsletters' ) }
				description={ __( 'Compose, schedule, and send newsletters to your subscribers via your connected ESP.', 'newspack-newsletters' ) }
				ctaTitle={ __( 'Add new newsletter', 'newspack-newsletters' ) }
				ctaHref={ addNewHref }
			/>
		);
	}

	return (
		<>
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
			/>
			{ quickEditItem && (
				<NewslettersQuickEditPanel
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
