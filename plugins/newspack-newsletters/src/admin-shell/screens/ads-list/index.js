/**
 * Ads list screen — React DataView replacing the classic ads CPT list.
 */

import { __experimentalHStack as HStack, Spinner } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { DataViews } from '@wordpress/dataviews/wp';
import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { emailAd } from 'newspack-icons';

import { getAdminUrl } from '../../admin-globals';
import EmptyState from '../../components/empty-state';
import HeaderCount from '../../components/header-count';
import ItemsPerPage from '../../components/items-per-page';
import { useHeaderActions } from '../../header-actions-context';
import usePersistedView from '../../hooks/use-persisted-view';
import { fetchAllTerms, idsMissingFromOptions } from '../../utils/terms';
import useAdsData from './use-ads-data';
import { getFields } from './fields';
import { getActions } from './actions';
import { getInitialView } from './initial-filters';
import AdsQuickEditPanel from './quick-edit-panel';

const DEFAULT_VIEW = {
	type: 'table',
	page: 1,
	perPage: 20,
	sort: { field: 'date', direction: 'desc' },
	search: '',
	filters: [],
	titleField: 'title',
	fields: [ 'advertiser', 'ad_placement', 'status', 'start_date', 'expiry_date', 'impressions', 'clicks', 'price' ],
	...getInitialView(),
};

const DEFAULT_LAYOUTS = { table: {} };

// Suppress the built-in ViewConfig per-page control — the custom
// `ItemsPerPage` renders in its place inside the View options popover.
const DATAVIEWS_CONFIG = { perPageSizes: [] };

const ADS_CPT = 'newspack_nl_ads_cpt';

// Filter-dropdown taxonomy terms (advertisers + placements). Paginated
// so sites with many terms still get a complete list. Categories are
// fetched lazily inside the Quick Edit panel.
// `hasLoaded` reports the fetch settling, not succeeding: `fetchAllTerms`
// swallows request failures and returns what it collected, so Quick Edit
// judges completeness by whether the settled lists account for an ad's
// stored term IDs.
// Union rather than replacement. `fetchAllTerms` breaks out of its pagination
// loop on the first failed request and returns what it collected, so a
// re-attempt that blips comes back short — and since Quick Edit now gates
// editability on these lists, replacing a good list with a truncated one would
// disable a field for an ad that is fine, and empty the filter dropdowns with
// it. Growing only means a blip can never cost ground.
const mergeTerms = ( current, next ) => {
	const byId = new Map( current.map( term => [ term.id, term ] ) );
	( Array.isArray( next ) ? next : [] ).forEach( term => byId.set( term.id, term ) );
	return [ ...byId.values() ];
};

function useFilterTerms() {
	const [ terms, setTerms ] = useState( { advertisers: [], placements: [], hasLoaded: false } );
	const [ attempt, setAttempt ] = useState( 0 );
	// Quick Edit gates editability on these lists, so a single failed request
	// would otherwise leave every ad opened afterwards read-only until the page
	// is reloaded.
	const reload = useCallback( () => setAttempt( current => current + 1 ), [] );

	useEffect( () => {
		let cancelled = false;
		Promise.all( [ fetchAllTerms( '/wp/v2/newspack_nl_advertiser' ), fetchAllTerms( '/wp/v2/ad_placement' ) ] )
			.then( ( [ advertisers, placements ] ) => {
				if ( ! cancelled ) {
					setTerms( current => ( {
						...current,
						advertisers: mergeTerms( current.advertisers, advertisers ),
						placements: mergeTerms( current.placements, placements ),
					} ) );
				}
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setTerms( current => ( { ...current, hasLoaded: true } ) );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ attempt ] );

	return [ terms, reload ];
}

export default function AdsListScreen() {
	const [ view, setView ] = usePersistedView( 'ads-list', DEFAULT_VIEW );
	const [ quickEditItem, setQuickEditItem ] = useState( null );
	const { data, paginationInfo, isLoading, hasResolved, hasLoadedOnce, trashCount, progress, refresh } = useAdsData( view );
	const [ filterTerms, reloadFilterTerms ] = useFilterTerms();

	const addNewHref = `${ getAdminUrl() }post-new.php?post_type=${ ADS_CPT }`;

	const fields = useMemo( () => getFields( filterTerms ), [ filterTerms ] );

	// Retry the shared lists when Quick Edit opens, but only when this ad has
	// terms they cannot explain — that is, only when the panel would otherwise
	// render the field read-only. A healthy list is never refetched, and the ref
	// caps it at one attempt per row so a site whose taxonomy really is empty
	// cannot spin.
	const retriedForRef = useRef( null );
	useEffect( () => {
		if ( ! quickEditItem ) {
			retriedForRef.current = null;
			return;
		}
		if ( retriedForRef.current === quickEditItem.id ) {
			return;
		}
		const incomplete =
			idsMissingFromOptions( quickEditItem.newspack_nl_advertiser, filterTerms.advertisers ).length > 0 ||
			idsMissingFromOptions( quickEditItem.ad_placement, filterTerms.placements ).length > 0;
		if ( incomplete ) {
			retriedForRef.current = quickEditItem.id;
			reloadFilterTerms();
		}
	}, [ quickEditItem, filterTerms, reloadFilterTerms ] );
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
								label: __( 'Add Newsletter Ad', 'newspack-newsletters' ),
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
				ctaTitle={ __( 'Add Newsletter Ad', 'newspack-newsletters' ) }
				ctaHref={ addNewHref }
			/>
		);
	}

	return (
		<>
			<HeaderCount count={ paginationInfo.totalItems } />
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
				config={ DATAVIEWS_CONFIG }
				header={
					<ItemsPerPage
						value={ view.perPage }
						progress={ progress }
						onChange={ perPage => setView( current => ( { ...current, perPage, page: 1 } ) ) }
					/>
				}
			/>
			{ quickEditItem && (
				<AdsQuickEditPanel
					key={ quickEditItem.id }
					item={ quickEditItem }
					advertisers={ filterTerms.advertisers }
					placements={ filterTerms.placements }
					termsLoaded={ filterTerms.hasLoaded }
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
