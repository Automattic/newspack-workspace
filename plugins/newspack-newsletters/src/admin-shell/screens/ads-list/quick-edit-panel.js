/**
 * Quick Edit panel for the ads list — status, advertiser, placement,
 * category, start/expiry dates, price.
 *
 * Insertion strategy / position stay in the full editor; status is
 * editable here.
 */

import apiFetch from '@wordpress/api-fetch';
import { FormTokenField, RadioControl, TextControl } from '@wordpress/components';
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

export default function AdsQuickEditPanel( { item, advertisers, placements, onClose, onSaved } ) {
	const { categories, hasLoaded: categoriesLoaded } = useQuickEditCategories();
	// Terms are only embedded when a taxonomy column is visible, so each
	// field falls back to resolving the post's raw term IDs against the
	// options list. Without this a hidden column would seed an empty
	// field, and saving would strip the terms it never showed.
	const initialAdvertiserSelections = useMemo( () => {
		const embedded = initialSelectionsForTaxonomy( item, 'newspack_nl_advertiser' );
		return embedded.length ? embedded : selectionsFromIds( item?.newspack_nl_advertiser, advertisers );
	}, [ item, advertisers ] );
	const initialPlacementSelections = useMemo( () => {
		const embedded = initialSelectionsForTaxonomy( item, 'newspack_nl_ad_placement' );
		return embedded.length ? embedded : selectionsFromIds( item?.ad_placement, placements );
	}, [ item, placements ] );
	const initialCategorySelections = useMemo( () => {
		const embedded = initialSelectionsForTaxonomy( item, 'category' );
		return embedded.length ? embedded : selectionsFromIds( item?.categories, categories );
	}, [ item, categories ] );
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

	// Once the embed is skipped the options list is the only source for
	// this field, so a settled list that can't account for the ad's stored
	// categories means the field would show an empty box on an ad that has
	// them. Say so and make it read-only instead of lying quietly.
	const categoriesUnavailable = categoriesLoaded && unresolvedCategoryIds.length > 0;

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
		// Only send a taxonomy the user actually touched: an untouched
		// field must never overwrite what is stored, whatever the options
		// lists managed to resolve.
		const data = { meta };
		if ( advertiserDirty ) {
			data.newspack_nl_advertiser = [ ...advertiserSelections.map( s => s.id ), ...unresolvedAdvertiserIds ];
		}
		if ( placementDirty ) {
			data.ad_placement = [ ...placementSelections.map( s => s.id ), ...unresolvedPlacementIds ];
		}
		if ( categoriesDirty ) {
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
				onChange={ next => {
					hasEditedAdvertiserRef.current = true;
					setAdvertiserSelections( resolveTokens( next, advertiserSelections, advertisers ) );
				} }
				__experimentalValidateInput={ validateAdvertiser }
				__experimentalShowHowTo={ false }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<FormTokenField
				label={ __( 'Ad placement', 'newspack-newsletters' ) }
				value={ placementTokens }
				suggestions={ placementSuggestions }
				onChange={ next => {
					hasEditedPlacementRef.current = true;
					setPlacementSelections( resolveTokens( next, placementSelections, placements ) );
				} }
				__experimentalValidateInput={ validatePlacement }
				__experimentalShowHowTo={ false }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<FormTokenField
				label={ __( 'Categories', 'newspack-newsletters' ) }
				value={ categoryTokens }
				suggestions={ categorySuggestions }
				disabled={ categoriesUnavailable }
				onChange={ next => {
					hasEditedCategoriesRef.current = true;
					setCategorySelections( resolveTokens( next, categorySelections, categories ) );
				} }
				__experimentalValidateInput={ validateCategory }
				__experimentalShowHowTo={ false }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			{ /* `FormTokenField`'s own `help` prop is not rendered by the
			     runtime (WP core) build, so the message stands on its own. */ }
			{ categoriesUnavailable && (
				<p className="components-base-control__help">
					{ __( 'Categories could not be loaded. Edit this ad to change them.', 'newspack-newsletters' ) }
				</p>
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
