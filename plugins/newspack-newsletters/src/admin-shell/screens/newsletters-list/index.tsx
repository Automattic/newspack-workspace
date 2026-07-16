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

import type { ComponentProps, ReactElement } from 'react';
import type { HeaderAction, PostItem, QueryView, View } from '../../types';

// See `./wordpress-dataviews-wp.d.ts` for why `@wordpress/dataviews/wp`
// resolves at all under this workspace's `moduleResolution: "node"`.

// URL-seeded patch last so forwarded-from-legacy values override defaults.
const DEFAULT_VIEW: View = {
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

export default function NewslettersListScreen(): ReactElement {
	const [ view, setView ] = useState< View >( DEFAULT_VIEW );
	const [ quickEditItem, setQuickEditItem ] = useState< PostItem | null >( null );
	// `QueryView` is the deliberately loose shape the query-building layer
	// reads by dynamic key (see its definition in `../../types`); the
	// concrete DataViews `View` union satisfies it structurally but lacks
	// an index signature, so it can't be passed (or cast) directly — spread
	// it into a fresh object literal typed as `QueryView` instead.
	const queryView: QueryView = { ...view };
	const { data, paginationInfo, isLoading, hasResolved, hasLoadedOnce, trashCount, refresh } = useNewslettersData( queryView );
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
		useMemo< HeaderAction[] >(
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

	// `DataViews`' declared props don't include `className` (its source
	// never destructures or forwards one — see `wordpress-dataviews-wp.d.ts`
	// for why the module resolves at all), so passing it here has never
	// actually applied `newspack-newsletters-list` to any rendered DOM node.
	// Kept as-is; cast once at this call so the dead prop still type-checks.
	const dataViewsProps = {
		className: 'newspack-newsletters-list',
		data,
		fields,
		view,
		onChangeView: setView,
		actions,
		paginationInfo,
		defaultLayouts: DEFAULT_LAYOUTS,
		isLoading,
		getItemId: ( item: PostItem ) => String( item.id ),
		search: true,
	} as ComponentProps< typeof DataViews >;

	return (
		<>
			<DataViews { ...dataViewsProps } />
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
