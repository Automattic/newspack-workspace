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

const DEFAULT_VIEW = {
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

// Filter-dropdown taxonomy terms (advertisers + placements). Paginated
// so sites with many terms still get a complete list. Categories are
// fetched lazily inside the Quick Edit panel.
function useFilterTerms() {
	const [ terms, setTerms ] = useState( { advertisers: [], placements: [] } );

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

export default function AdsListScreen() {
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const [ quickEditItem, setQuickEditItem ] = useState( null );
	const { data, paginationInfo, isLoading, hasResolved, hasLoadedOnce, trashCount, refresh } = useAdsData( view );
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
		useMemo(
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

	return (
		<>
			<DataViews
				className="newspack-newsletters-list newspack-newsletters-ads-list"
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
				<AdsQuickEditPanel
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
