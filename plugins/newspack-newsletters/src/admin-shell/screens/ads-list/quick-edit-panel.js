/**
 * Quick Edit panel for the ads list — advertiser, placement,
 * category, start/expiry dates, price.
 *
 * Status / insertion strategy / position stay in the full editor.
 */

import apiFetch from '@wordpress/api-fetch';
import { FormTokenField, TextControl } from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { emailAd } from 'newspack-icons';

import QuickEditPanel from '../../components/quick-edit-panel';
import { notifyError, notifySuccess } from '../../notices';
import { fetchAllTerms, initialSelectionsForTaxonomy, resolveTokens, sortedIdsEqual } from '../../utils/terms';

const POSTS_PATH = '/wp/v2/newspack_nl_ads_cpt';

function useQuickEditCategories() {
	const [ categories, setCategories ] = useState( [] );
	useEffect( () => {
		let cancelled = false;
		fetchAllTerms( '/wp/v2/categories' )
			.then( terms => {
				if ( ! cancelled ) {
					setCategories( Array.isArray( terms ) ? terms : [] );
				}
			} )
			.catch( () => {} );
		return () => {
			cancelled = true;
		};
	}, [] );
	return categories;
}

export default function AdsQuickEditPanel( { item, advertisers, placements, onClose, onSaved } ) {
	const categories = useQuickEditCategories();
	const initialAdvertiserSelections = useMemo( () => initialSelectionsForTaxonomy( item, 'newspack_nl_advertiser' ), [ item ] );
	const initialPlacementSelections = useMemo( () => initialSelectionsForTaxonomy( item, 'newspack_nl_ad_placement' ), [ item ] );
	const initialCategorySelections = useMemo( () => initialSelectionsForTaxonomy( item, 'category' ), [ item ] );
	const initialStartDate = item?.meta?.start_date || '';
	const initialExpiryDate = item?.meta?.expiry_date || '';
	const initialPrice = ( () => {
		const value = item?.meta?.price;
		return value ? String( value ) : '';
	} )();

	const [ advertiserSelections, setAdvertiserSelections ] = useState( initialAdvertiserSelections );
	const [ placementSelections, setPlacementSelections ] = useState( initialPlacementSelections );
	const [ categorySelections, setCategorySelections ] = useState( initialCategorySelections );
	const [ startDate, setStartDate ] = useState( initialStartDate );
	const [ expiryDate, setExpiryDate ] = useState( initialExpiryDate );
	const [ price, setPrice ] = useState( initialPrice );
	const [ isBusy, setIsBusy ] = useState( false );

	const isDirty =
		startDate !== initialStartDate ||
		expiryDate !== initialExpiryDate ||
		price !== initialPrice ||
		! sortedIdsEqual( advertiserSelections, initialAdvertiserSelections ) ||
		! sortedIdsEqual( placementSelections, initialPlacementSelections ) ||
		! sortedIdsEqual( categorySelections, initialCategorySelections );

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
		const data = {
			newspack_nl_advertiser: advertiserSelections.map( s => s.id ),
			ad_placement: placementSelections.map( s => s.id ),
			categories: categorySelections.map( s => s.id ),
			meta,
		};
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
			<FormTokenField
				label={ __( 'Advertiser', 'newspack-newsletters' ) }
				value={ advertiserTokens }
				suggestions={ advertiserSuggestions }
				onChange={ next => setAdvertiserSelections( resolveTokens( next, advertiserSelections, advertisers ) ) }
				__experimentalValidateInput={ validateAdvertiser }
				__experimentalShowHowTo={ false }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<FormTokenField
				label={ __( 'Ad placement', 'newspack-newsletters' ) }
				value={ placementTokens }
				suggestions={ placementSuggestions }
				onChange={ next => setPlacementSelections( resolveTokens( next, placementSelections, placements ) ) }
				__experimentalValidateInput={ validatePlacement }
				__experimentalShowHowTo={ false }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<FormTokenField
				label={ __( 'Categories', 'newspack-newsletters' ) }
				value={ categoryTokens }
				suggestions={ categorySuggestions }
				onChange={ next => setCategorySelections( resolveTokens( next, categorySelections, categories ) ) }
				__experimentalValidateInput={ validateCategory }
				__experimentalShowHowTo={ false }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
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
