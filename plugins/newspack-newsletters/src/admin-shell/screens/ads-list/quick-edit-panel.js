/**
 * Quick Edit panel for the ads list — status, advertiser, placement,
 * category, start/expiry dates, price.
 *
 * Insertion strategy / position stay in the full editor; status is
 * editable here.
 */

import apiFetch from '@wordpress/api-fetch';
import { FormTokenField, Notice, RadioControl, TextControl } from '@wordpress/components';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { emailAd } from 'newspack-icons';

import QuickEditPanel from '../../components/quick-edit-panel';
import { notifyError, notifySuccess } from '../../notices';
import { fetchAllTerms, initialSelectionsForTaxonomy, resolveTokens, selectionsFromIds, sortedIdsEqual, unresolvedIds } from '../../utils/terms';

const POSTS_PATH = '/wp/v2/newspack_nl_ads_cpt';

// `hasLoaded` tracks the fetch settling rather than succeeding:
// `fetchAllTerms` swallows request failures and returns whatever it
// collected, so a partial list is indistinguishable from a complete one
// here. What the panel can tell is whether the settled list accounts for
// the ad's stored category IDs.
function useQuickEditCategories() {
	const [ categories, setCategories ] = useState( [] );
	const [ hasLoaded, setHasLoaded ] = useState( false );
	useEffect( () => {
		let cancelled = false;
		fetchAllTerms( '/wp/v2/categories' )
			.then( terms => {
				if ( ! cancelled ) {
					setCategories( Array.isArray( terms ) ? terms : [] );
				}
			} )
			.catch( () => {} )
			.finally( () => {
				if ( ! cancelled ) {
					setHasLoaded( true );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [] );
	return { categories, hasLoaded };
}

// Resolve the post's stored term IDs through the embed first, then the
// options list. The `wp:term` embed caps at 10 terms per taxonomy (the
// REST default `per_page`, and the embed link sets none), so a truncated
// embed must not shadow a fully-paginated options list.
const selectionsForTaxonomy = ( item, ids, taxonomy, options ) => {
	const embedded = initialSelectionsForTaxonomy( item, taxonomy );
	if ( ! Array.isArray( ids ) ) {
		return embedded;
	}
	const byId = new Map( [ ...selectionsFromIds( ids, options ), ...embedded ].map( selection => [ selection.id, selection ] ) );
	return ids.map( id => byId.get( id ) ).filter( Boolean );
};

// A settled options list that still can't account for every stored term
// means the field would misrepresent the ad, so it goes read-only rather
// than showing a quietly wrong value.
function TermsUnavailableNotice( { children } ) {
	return (
		<Notice status="warning" isDismissible={ false } politeness="polite" spokenMessage={ children }>
			{ children }
		</Notice>
	);
}

export default function AdsQuickEditPanel( { item, advertisers, placements, termsLoaded = false, onClose, onSaved } ) {
	const { categories, hasLoaded: categoriesLoaded } = useQuickEditCategories();
	// Terms are only embedded when a taxonomy column is visible, so each
	// field also resolves the post's raw term IDs against the options
	// list. Without this a hidden column would seed an empty field, and
	// saving would strip the terms it never showed.
	const initialAdvertiserSelections = useMemo(
		() => selectionsForTaxonomy( item, item?.newspack_nl_advertiser, 'newspack_nl_advertiser', advertisers ),
		[ item, advertisers ]
	);
	const initialPlacementSelections = useMemo(
		() => selectionsForTaxonomy( item, item?.ad_placement, 'newspack_nl_ad_placement', placements ),
		[ item, placements ]
	);
	const initialCategorySelections = useMemo( () => selectionsForTaxonomy( item, item?.categories, 'category', categories ), [ item, categories ] );
	// Unresolvable terms can't be shown or removed, so they ride along on save.
	const unresolvedAdvertiserIds = useMemo(
		() => unresolvedIds( item?.newspack_nl_advertiser, initialAdvertiserSelections ),
		[ item, initialAdvertiserSelections ]
	);
	const unresolvedPlacementIds = useMemo(
		() => unresolvedIds( item?.ad_placement, initialPlacementSelections ),
		[ item, initialPlacementSelections ]
	);
	const unresolvedCategoryIds = useMemo( () => unresolvedIds( item?.categories, initialCategorySelections ), [ item, initialCategorySelections ] );
	// Slice to `Y-m-d` so legacy datetime meta still fills the date input.
	const initialStartDate = ( item?.meta?.start_date || '' ).slice( 0, 10 );
	const initialExpiryDate = ( item?.meta?.expiry_date || '' ).slice( 0, 10 );
	const initialPrice = ( () => {
		const value = item?.meta?.price;
		return value ? String( value ) : '';
	} )();
	// `private` ads are never served (the serving queries are publish-only), so they are Inactive alongside drafts.
	const initialStatus = [ 'publish', 'future' ].includes( item?.status ) ? 'active' : 'inactive';

	const [ status, setStatus ] = useState( initialStatus );
	const [ advertiserSelections, setAdvertiserSelections ] = useState( initialAdvertiserSelections );
	const [ placementSelections, setPlacementSelections ] = useState( initialPlacementSelections );
	const [ categorySelections, setCategorySelections ] = useState( initialCategorySelections );
	const [ startDate, setStartDate ] = useState( initialStartDate );
	const [ expiryDate, setExpiryDate ] = useState( initialExpiryDate );
	const [ price, setPrice ] = useState( initialPrice );
	const [ isBusy, setIsBusy ] = useState( false );

	// One ref per taxonomy: the three options lists resolve independently,
	// so a shared flag would let an edit to one freeze another's baseline
	// at its pre-resolution (empty) value.
	const hasEditedAdvertiserRef = useRef( false );
	const hasEditedPlacementRef = useRef( false );
	const hasEditedCategoriesRef = useRef( false );

	useEffect( () => {
		if ( ! hasEditedAdvertiserRef.current ) {
			setAdvertiserSelections( initialAdvertiserSelections );
		}
	}, [ initialAdvertiserSelections ] );
	useEffect( () => {
		if ( ! hasEditedPlacementRef.current ) {
			setPlacementSelections( initialPlacementSelections );
		}
	}, [ initialPlacementSelections ] );
	useEffect( () => {
		if ( ! hasEditedCategoriesRef.current ) {
			setCategorySelections( initialCategorySelections );
		}
	}, [ initialCategorySelections ] );

	// Gated on the fetch having settled so a slow load can't flash a
	// warning before the options arrive.
	const advertiserUnavailable = termsLoaded && unresolvedAdvertiserIds.length > 0;
	const placementUnavailable = termsLoaded && unresolvedPlacementIds.length > 0;
	const categoriesUnavailable = categoriesLoaded && unresolvedCategoryIds.length > 0;

	// A field is editable only once its options have settled and account
	// for every stored term. Editing earlier would race the baseline: the
	// embed caps at 10 terms, so an edit made before the full list arrives
	// would be diffed against a baseline that grows underneath it, and the
	// terms resolved late would drop out of the save.
	const advertiserReadOnly = ! termsLoaded || advertiserUnavailable;
	const placementReadOnly = ! termsLoaded || placementUnavailable;
	const categoriesReadOnly = ! categoriesLoaded || categoriesUnavailable;

	const advertiserDirty = ! sortedIdsEqual( advertiserSelections, initialAdvertiserSelections );
	const placementDirty = ! sortedIdsEqual( placementSelections, initialPlacementSelections );
	const categoriesDirty = ! sortedIdsEqual( categorySelections, initialCategorySelections );

	const isDirty =
		status !== initialStatus ||
		startDate !== initialStartDate ||
		expiryDate !== initialExpiryDate ||
		price !== initialPrice ||
		advertiserDirty ||
		placementDirty ||
		categoriesDirty;

	const advertiserSuggestions = useMemo( () => advertisers.map( t => String( t.name ) ), [ advertisers ] );
	const placementSuggestions = useMemo( () => placements.map( t => String( t.name ) ), [ placements ] );
	const categorySuggestions = useMemo( () => categories.map( t => String( t.name ) ), [ categories ] );
	const advertiserTokens = useMemo( () => advertiserSelections.map( s => s.name ), [ advertiserSelections ] );
	const placementTokens = useMemo( () => placementSelections.map( s => s.name ), [ placementSelections ] );
	const categoryTokens = useMemo( () => categorySelections.map( s => s.name ), [ categorySelections ] );

	const validateAgainst = labels => {
		const lower = new Set( labels.map( l => l.toLowerCase() ) );
		return token => lower.has( String( token ).toLowerCase() );
	};

	const validateAdvertiser = useMemo( () => validateAgainst( advertiserSuggestions ), [ advertiserSuggestions ] );
	const validatePlacement = useMemo( () => validateAgainst( placementSuggestions ), [ placementSuggestions ] );
	const validateCategory = useMemo( () => validateAgainst( categorySuggestions ), [ categorySuggestions ] );

	const datesValid = ! startDate || ! expiryDate || startDate <= expiryDate;
	const priceValid = price === '' || ( Number.isFinite( Number( price ) ) && Number( price ) >= 0 );
	const canSave = datesValid && priceValid;

	const handleSave = async () => {
		setIsBusy( true );
		const meta = {
			start_date: startDate,
			expiry_date: expiryDate,
			price: price === '' ? 0 : Number( price ),
		};
		// Only send a taxonomy the user actually touched. The edited ref is
		// the load-bearing half: a dirty diff alone would also be true in
		// the moment a late-resolving baseline overtakes the selection
		// state, which would let a status-only save serialise the stale
		// value. An untouched field must never overwrite what is stored.
		const data = { meta };
		if ( hasEditedAdvertiserRef.current && advertiserDirty ) {
			data.newspack_nl_advertiser = [ ...advertiserSelections.map( s => s.id ), ...unresolvedAdvertiserIds ];
		}
		if ( hasEditedPlacementRef.current && placementDirty ) {
			data.ad_placement = [ ...placementSelections.map( s => s.id ), ...unresolvedPlacementIds ];
		}
		if ( hasEditedCategoriesRef.current && categoriesDirty ) {
			data.categories = [ ...categorySelections.map( s => s.id ), ...unresolvedCategoryIds ];
		}
		if ( status !== initialStatus ) {
			data.status = status === 'active' ? 'publish' : 'draft';
		}
		try {
			await apiFetch( { path: `${ POSTS_PATH }/${ item.id }`, method: 'POST', data } );
			notifySuccess( __( 'Ad updated.', 'newspack-newsletters' ) );
			onSaved();
		} catch ( error ) {
			setIsBusy( false );
			notifyError( error?.message || __( 'Could not update ad. Please try again.', 'newspack-newsletters' ) );
		}
	};

	const subjectTitle = item?.title?.raw ?? item?.title?.rendered ?? __( '(no title)', 'newspack-newsletters' );

	return (
		<QuickEditPanel
			title={ __( 'Quick edit', 'newspack-newsletters' ) }
			icon={ emailAd }
			subjectTitle={ subjectTitle }
			isDirty={ isDirty }
			onClose={ onClose }
			onSave={ handleSave }
			isBusy={ isBusy }
			canSave={ canSave }
			saveLabel={ __( 'Save', 'newspack-newsletters' ) }
		>
			<RadioControl
				label={ __( 'Status', 'newspack-newsletters' ) }
				selected={ status }
				options={ [
					{ label: __( 'Active', 'newspack-newsletters' ), value: 'active' },
					{ label: __( 'Inactive', 'newspack-newsletters' ), value: 'inactive' },
				] }
				onChange={ setStatus }
				help={ __( 'Active ads run according to their start and expiration dates. Inactive ads are never shown.', 'newspack-newsletters' ) }
			/>
			<FormTokenField
				label={ __( 'Advertiser', 'newspack-newsletters' ) }
				value={ advertiserTokens }
				suggestions={ advertiserSuggestions }
				disabled={ advertiserReadOnly }
				onChange={ next => {
					hasEditedAdvertiserRef.current = true;
					setAdvertiserSelections( resolveTokens( next, advertiserSelections, advertisers ) );
				} }
				__experimentalValidateInput={ validateAdvertiser }
				__experimentalShowHowTo={ false }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			{ advertiserUnavailable && (
				<TermsUnavailableNotice>
					{ __( 'Advertisers could not be loaded. Edit this ad to change them.', 'newspack-newsletters' ) }
				</TermsUnavailableNotice>
			) }
			<FormTokenField
				label={ __( 'Ad placement', 'newspack-newsletters' ) }
				value={ placementTokens }
				suggestions={ placementSuggestions }
				disabled={ placementReadOnly }
				onChange={ next => {
					hasEditedPlacementRef.current = true;
					setPlacementSelections( resolveTokens( next, placementSelections, placements ) );
				} }
				__experimentalValidateInput={ validatePlacement }
				__experimentalShowHowTo={ false }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			{ placementUnavailable && (
				<TermsUnavailableNotice>
					{ __( 'Ad placements could not be loaded. Edit this ad to change them.', 'newspack-newsletters' ) }
				</TermsUnavailableNotice>
			) }
			<FormTokenField
				label={ __( 'Categories', 'newspack-newsletters' ) }
				value={ categoryTokens }
				suggestions={ categorySuggestions }
				disabled={ categoriesReadOnly }
				onChange={ next => {
					hasEditedCategoriesRef.current = true;
					setCategorySelections( resolveTokens( next, categorySelections, categories ) );
				} }
				__experimentalValidateInput={ validateCategory }
				__experimentalShowHowTo={ false }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			{ categoriesUnavailable && (
				<TermsUnavailableNotice>
					{ __( 'Categories could not be loaded. Edit this ad to change them.', 'newspack-newsletters' ) }
				</TermsUnavailableNotice>
			) }
			<TextControl
				type="date"
				label={ __( 'Start date', 'newspack-newsletters' ) }
				value={ startDate }
				onChange={ setStartDate }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				type="date"
				label={ __( 'Expiration date', 'newspack-newsletters' ) }
				value={ expiryDate }
				onChange={ setExpiryDate }
				help={ datesValid ? '' : __( 'Expiration date must be on or after the start date.', 'newspack-newsletters' ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				type="number"
				label={ __( 'Price', 'newspack-newsletters' ) }
				value={ price }
				min={ 0 }
				step="0.01"
				onChange={ setPrice }
				help={ priceValid ? '' : __( 'Price must be a non-negative finite number.', 'newspack-newsletters' ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
		</QuickEditPanel>
	);
}
