/**
 * Quick Edit panel for the newsletters list.
 *
 * Status / Author stay in the full editor — status because the
 * service-provider base class fires an ESP send on
 * `transition_post_status`.
 */

import apiFetch from '@wordpress/api-fetch';
import { FormTokenField, RadioControl } from '@wordpress/components';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { envelope } from '@wordpress/icons';

import QuickEditPanel from '../../components/quick-edit-panel';
import { getNewsletterVisibilityDescriptions } from '../../../utils/service-provider';
import { notifyError, notifySuccess } from '../../notices';
import { fetchAllTerms, initialSelectionsForTaxonomy, resolveTokens, selectionsFromIds, sortedIdsEqual, unresolvedIds } from '../../utils/terms';

const POSTS_PATH = '/wp/v2/newspack_nl_cpt';

function useQuickEditOptions() {
	const [ options, setOptions ] = useState( { categories: [], tags: [] } );

	useEffect( () => {
		let cancelled = false;
		Promise.all( [ fetchAllTerms( '/wp/v2/categories' ), fetchAllTerms( '/wp/v2/tags' ) ] )
			.then( ( [ categories, tags ] ) => {
				if ( cancelled ) {
					return;
				}
				setOptions( {
					categories: Array.isArray( categories ) ? categories : [],
					tags: Array.isArray( tags ) ? tags : [],
				} );
			} )
			.catch( () => {} );
		return () => {
			cancelled = true;
		};
	}, [] );

	return options;
}

export default function NewslettersQuickEditPanel( { item, onClose, onSaved } ) {
	const { categories, tags } = useQuickEditOptions();

	// Terms are only embedded when a taxonomy column is visible.
	const initialCategorySelections = useMemo( () => {
		const embedded = initialSelectionsForTaxonomy( item, 'category' );
		return embedded.length ? embedded : selectionsFromIds( item?.categories, categories );
	}, [ item, categories ] );
	const initialTagSelections = useMemo( () => {
		const embedded = initialSelectionsForTaxonomy( item, 'post_tag' );
		return embedded.length ? embedded : selectionsFromIds( item?.tags, tags );
	}, [ item, tags ] );
	// Unresolvable terms can't be shown or removed, so they ride along on save.
	const unresolvedCategoryIds = useMemo( () => unresolvedIds( item?.categories, initialCategorySelections ), [ item, initialCategorySelections ] );
	const unresolvedTagIds = useMemo( () => unresolvedIds( item?.tags, initialTagSelections ), [ item, initialTagSelections ] );
	const initialVisibility = item?.meta?.is_public ? 'public' : 'private';

	const [ categorySelections, setCategorySelections ] = useState( initialCategorySelections );
	const [ tagSelections, setTagSelections ] = useState( initialTagSelections );
	const [ visibility, setVisibility ] = useState( initialVisibility );
	const [ isBusy, setIsBusy ] = useState( false );
	// One ref per taxonomy: when a column is hidden the baselines seed
	// asynchronously as each taxonomy's options resolve, so a shared ref
	// would let an edit to one freeze the other at its pre-resolution
	// (empty) value — and a later edit there would then drop real terms.
	const hasEditedCategoriesRef = useRef( false );
	const hasEditedTagsRef = useRef( false );

	useEffect( () => {
		if ( ! hasEditedCategoriesRef.current ) {
			setCategorySelections( initialCategorySelections );
		}
	}, [ initialCategorySelections ] );
	useEffect( () => {
		if ( ! hasEditedTagsRef.current ) {
			setTagSelections( initialTagSelections );
		}
	}, [ initialTagSelections ] );

	const categoriesDirty = ! sortedIdsEqual( categorySelections, initialCategorySelections );
	const tagsDirty = ! sortedIdsEqual( tagSelections, initialTagSelections );
	const isDirty = visibility !== initialVisibility || categoriesDirty || tagsDirty;

	const categoryNames = useMemo( () => categories.map( c => String( c.name ) ), [ categories ] );
	const tagNames = useMemo( () => tags.map( t => String( t.name ) ), [ tags ] );
	const categoryTokens = useMemo( () => categorySelections.map( s => s.name ), [ categorySelections ] );
	const tagTokens = useMemo( () => tagSelections.map( s => s.name ), [ tagSelections ] );

	const validateAgainst = names => {
		const lower = new Set( names.map( n => n.toLowerCase() ) );
		return token => lower.has( String( token ).toLowerCase() );
	};

	const validateCategory = useMemo( () => validateAgainst( categoryNames ), [ categoryNames ] );
	const validateTag = useMemo( () => validateAgainst( tagNames ), [ tagNames ] );

	const handleSave = async () => {
		setIsBusy( true );
		const data = { meta: { is_public: visibility === 'public' } };
		if ( categoriesDirty ) {
			data.categories = [ ...categorySelections.map( s => s.id ), ...unresolvedCategoryIds ];
		}
		if ( tagsDirty ) {
			data.tags = [ ...tagSelections.map( s => s.id ), ...unresolvedTagIds ];
		}
		try {
			await apiFetch( { path: `${ POSTS_PATH }/${ item.id }`, method: 'POST', data } );
			notifySuccess( __( 'Newsletter updated.', 'newspack-newsletters' ) );
			onSaved();
		} catch ( error ) {
			setIsBusy( false );
			notifyError( error?.message || __( 'Could not update newsletter. Please try again.', 'newspack-newsletters' ) );
		}
	};

	const subjectTitle = item?.title?.raw ?? item?.title?.rendered ?? __( '(no subject)', 'newspack-newsletters' );
	const visibilityDescriptions = getNewsletterVisibilityDescriptions();

	return (
		<QuickEditPanel
			title={ __( 'Quick edit', 'newspack-newsletters' ) }
			icon={ envelope }
			subjectTitle={ subjectTitle }
			isDirty={ isDirty }
			onClose={ onClose }
			onSave={ handleSave }
			isBusy={ isBusy }
			saveLabel={ __( 'Save', 'newspack-newsletters' ) }
		>
			<FormTokenField
				label={ __( 'Categories', 'newspack-newsletters' ) }
				value={ categoryTokens }
				suggestions={ categoryNames }
				onChange={ next => {
					hasEditedCategoriesRef.current = true;
					setCategorySelections( resolveTokens( next, categorySelections, categories ) );
				} }
				__experimentalValidateInput={ validateCategory }
				__experimentalShowHowTo={ false }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<FormTokenField
				label={ __( 'Tags', 'newspack-newsletters' ) }
				value={ tagTokens }
				suggestions={ tagNames }
				onChange={ next => {
					hasEditedTagsRef.current = true;
					setTagSelections( resolveTokens( next, tagSelections, tags ) );
				} }
				__experimentalValidateInput={ validateTag }
				__experimentalShowHowTo={ false }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<RadioControl
				label={ __( 'Visibility', 'newspack-newsletters' ) }
				selected={ visibility }
				options={ [
					{
						label: __( 'Email and web', 'newspack-newsletters' ),
						value: 'public',
						description: visibilityDescriptions.public,
					},
					{
						label: __( 'Email only', 'newspack-newsletters' ),
						value: 'private',
						description: visibilityDescriptions.private,
					},
				] }
				onChange={ setVisibility }
			/>
		</QuickEditPanel>
	);
}
