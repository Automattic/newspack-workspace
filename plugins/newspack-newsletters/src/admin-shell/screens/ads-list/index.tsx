/**
 * Ads list screen — React DataView replacing the classic ads CPT list.
 */

import { __experimentalHStack as HStack, Spinner } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { DataViews } from '@wordpress/dataviews/wp';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { emailAd } from 'newspack-icons';

import { getAdminUrl } from '../../admin-globals';
import EmptyState from '../../components/empty-state';
import { useHeaderActions } from '../../header-actions-context';
import { fetchAllTerms } from '../../utils/terms';
import useAdsData from './use-ads-data';
import { getFields } from './fields';
import { getActions } from './actions';
import { getInitialView } from './initial-filters';
import AdsQuickEditPanel from './quick-edit-panel';

import type { ComponentProps, ReactElement } from 'react';
import type { HeaderAction, PostItem, QueryView, Term, View } from '../../types';

const DEFAULT_VIEW: View = {
	type: 'table',
	page: 1,
	perPage: 25,
	sort: { field: 'date', direction: 'desc' },
	search: '',
	filters: [],
	titleField: 'title',
	fields: [ 'advertiser', 'ad_placement', 'status', 'start_date', 'expiry_date', 'impressions', 'clicks', 'price' ],
	...getInitialView(),
};

const DEFAULT_LAYOUTS = { table: {} };

const ADS_CPT = 'newspack_nl_ads_cpt';

interface FilterTerms {
	advertisers: Term[];
	placements: Term[];
}

// Filter-dropdown taxonomy terms (advertisers + placements). Paginated
// so sites with many terms still get a complete list. Categories are
// fetched lazily inside the Quick Edit panel.
function useFilterTerms(): FilterTerms {
	const [ terms, setTerms ] = useState< FilterTerms >( { advertisers: [], placements: [] } );

	useEffect( () => {
		let cancelled = false;
		Promise.all( [ fetchAllTerms( '/wp/v2/newspack_nl_advertiser' ), fetchAllTerms( '/wp/v2/ad_placement' ) ] )
			.then( ( [ advertisers, placements ] ) => {
				if ( cancelled ) {
					return;
				}
				setTerms( {
					advertisers: Array.isArray( advertisers ) ? advertisers : [],
					placements: Array.isArray( placements ) ? placements : [],
				} );
			} )
			.catch( () => {} );
		return () => {
			cancelled = true;
		};
	}, [] );

	return terms;
}

export default function AdsListScreen(): ReactElement {
	const [ view, setView ] = useState< View >( DEFAULT_VIEW );
	const [ quickEditItem, setQuickEditItem ] = useState< PostItem | null >( null );
	// `QueryView` is the deliberately loose shape the query-building layer
	// consumes (an index signature over arbitrary filter/sort keys); the
	// concrete DataViews `View` union satisfies it structurally but lacks
	// an index signature of its own, so TS won't assign it directly. Spread
	// it into a fresh object literal typed as `QueryView` instead.
	const queryView: QueryView = { ...view };
	const { data, paginationInfo, isLoading, hasResolved, hasLoadedOnce, trashCount, refresh } = useAdsData( queryView );
	const filterTerms = useFilterTerms();

	const addNewHref = `${ getAdminUrl() }post-new.php?post_type=${ ADS_CPT }`;

	const fields = useMemo( () => getFields( filterTerms ), [ filterTerms ] );
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
								label: __( 'Add new newsletter ad', 'newspack-newsletters' ),
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
				icon={ emailAd }
				title={ __( 'Get started with newsletter ads', 'newspack-newsletters' ) }
				description={ __(
					'Monetise newsletters with sponsored or house ads. Schedule by date, target by placement or category.',
					'newspack-newsletters'
				) }
				ctaTitle={ __( 'Add new newsletter ad', 'newspack-newsletters' ) }
				ctaHref={ addNewHref }
			/>
		);
	}

	// `DataViews`' declared props don't include `className` (its source
	// never destructures or forwards one — see `wordpress-dataviews-wp.d.ts`
	// for why the module resolves at all), so passing it here has never
	// actually applied either class to any rendered DOM node. Kept as-is;
	// cast once at this call so the dead prop still type-checks.
	const dataViewsProps = {
		className: 'newspack-newsletters-list newspack-newsletters-ads-list',
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
				<AdsQuickEditPanel
					key={ quickEditItem.id }
					item={ quickEditItem }
					advertisers={ filterTerms.advertisers }
					placements={ filterTerms.placements }
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
