/**
 * Ads list screen — React DataView replacing the classic ads CPT list.
 */

import { Button, __experimentalHStack as HStack, Spinner } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { DataViews } from '@wordpress/dataviews/wp';
import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { emailAd } from 'newspack-icons';

import { EmptyState } from 'newspack-components';
import { getAdminUrl } from '../../admin-globals';
import HeaderCount from '../../components/header-count';
import ItemsPerPage from '../../components/items-per-page';
import { EMPTY_STATE_CLASS, getEmptyStateHeading } from '../../constants';
import { useHeaderActions } from '../../header-actions-context';
import usePersistedView from '../../hooks/use-persisted-view';
import isStrictlyEmpty from '../../utils/is-strictly-empty';
import { fetchAllTerms, idsMissingFromOptions, mergeTerms } from '../../utils/terms';
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
function useFilterTerms() {
	const [ terms, setTerms ] = useState( { advertisers: [], placements: [], hasLoaded: false } );
	const [ attempt, setAttempt ] = useState( 0 );
	// Quick Edit gates editability on these lists, so a single failed request
	// would otherwise leave every ad opened afterwards read-only until the page
	// is reloaded. `hasLoaded` goes back to false for the duration: a
	// re-attempt is a load, and without this the panel would paint "could not
	// be loaded" — announcing it — for the round trip that is about to fix it.
	const reload = useCallback( () => {
		setTerms( current => ( { ...current, hasLoaded: false } ) );
		setAttempt( current => current + 1 );
	}, [] );

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

	// Read at click time, so the callback below can stay stable for `getActions`.
	const filterTermsRef = useRef( filterTerms );
	filterTermsRef.current = filterTerms;

	// Retry the shared lists when Quick Edit opens on a row they cannot explain
	// — that is, only when the panel would otherwise render the field read-only.
	// A healthy list is never refetched, and an unsettled one is left alone, so
	// opening mid-load cannot cancel the fetch and restart its pagination.
	//
	// This runs in the click handler rather than an effect so the reload batches
	// with setting the item: the panel's first render then already shows the
	// loading state, instead of painting a "could not be loaded" notice — and
	// announcing it through `speak()` — for the frame before an effect could
	// clear it. One click is one attempt, so it cannot spin on a site whose
	// taxonomy is legitimately empty.
	const openQuickEdit = useCallback(
		item => {
			const { advertisers, placements, hasLoaded } = filterTermsRef.current;
			const incomplete =
				hasLoaded &&
				( idsMissingFromOptions( item?.newspack_nl_advertiser, advertisers ).length > 0 ||
					idsMissingFromOptions( item?.ad_placement, placements ).length > 0 );
			if ( incomplete ) {
				reloadFilterTerms();
			}
			setQuickEditItem( item );
		},
		[ reloadFilterTerms ]
	);

	const actions = useMemo( () => getActions( { refresh, openQuickEdit } ), [ refresh, openQuickEdit ] );

	const isStrictEmpty = isStrictlyEmpty( { hasLoadedOnce, isLoading, paginationInfo, trashCount, view } );

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
			<EmptyState.Root className={ EMPTY_STATE_CLASS }>
				<EmptyState.Header
					icon={ emailAd }
					heading={ getEmptyStateHeading() }
					title={ __( 'Get started with newsletter ads', 'newspack-newsletters' ) }
					description={ __(
						'Monetise newsletters with sponsored or house ads. Schedule by date, target by placement or category.',
						'newspack-newsletters'
					) }
				/>
				<EmptyState.Actions>
					<Button variant="primary" href={ addNewHref }>
						{ __( 'Add Newsletter Ad', 'newspack-newsletters' ) }
					</Button>
				</EmptyState.Actions>
			</EmptyState.Root>
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
