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
import TermsUnavailableNotice from '../../components/terms-unavailable-notice';
import { getNewsletterVisibilityDescriptions } from '../../../utils/service-provider';
import { notifyError, notifySuccess } from '../../notices';
import { fetchAllTerms, resolveTokens, selectionsForTaxonomy, sortedIdsEqual, unresolvedIds } from '../../utils/terms';

const POSTS_PATH = '/wp/v2/newspack_nl_cpt';

// `hasLoaded` tracks the fetch settling rather than succeeding:
// `fetchAllTerms` swallows request failures and returns whatever it
// collected, so a partial list is indistinguishable from a complete one
// here. What the panel can tell is whether the settled lists account for
// the newsletter's stored term IDs.
function useQuickEditOptions() {
	const [ options, setOptions ] = useState( { categories: [], tags: [], hasLoaded: false } );

	useEffect( () => {
		let cancelled = false;
		Promise.all( [ fetchAllTerms( '/wp/v2/categories' ), fetchAllTerms( '/wp/v2/tags' ) ] )
			.then( ( [ categories, tags ] ) => {
				if ( ! cancelled ) {
					setOptions( current => ( {
						...current,
						categories: Array.isArray( categories ) ? categories : [],
						tags: Array.isArray( tags ) ? tags : [],
					} ) );
				}
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setOptions( current => ( { ...current, hasLoaded: true } ) );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [] );

	return options;
}

export default function NewslettersQuickEditPanel( { item, onClose, onSaved } ) {
	const { categories, tags, hasLoaded: optionsLoaded } = useQuickEditOptions();

	// Terms are only embedded when a taxonomy column is visible, so each
	// field also resolves the post's raw term IDs against the options list.
	// Without this a hidden column would seed an empty field, and saving
	// would strip the terms it never showed.
	const initialCategorySelections = useMemo( () => selectionsForTaxonomy( item, item?.categories, 'category', categories ), [ item, categories ] );
	const initialTagSelections = useMemo( () => selectionsForTaxonomy( item, item?.tags, 'post_tag', tags ), [ item, tags ] );
	// Stored IDs the options list cannot explain. They gate the read-only
	// state below, and ride along on save as a backstop should that gate
	// ever be relaxed — a term the user could not see must not be dropped.
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

	// Gated on the fetch having settled so a slow load can't flash a
	// warning before the options arrive.
	const categoriesUnavailable = optionsLoaded && unresolvedCategoryIds.length > 0;
	const tagsUnavailable = optionsLoaded && unresolvedTagIds.length > 0;

	// A field is editable only once its options have settled and account for
	// every stored term. Editing earlier would race the baseline: with no
	// embed, or one capped at 100 terms, an edit made before the full list
	// arrives would be diffed against a baseline that grows underneath it,
	// and the terms resolved late would drop out of the save.
	const categoriesReadOnly = ! optionsLoaded || categoriesUnavailable;
	const tagsReadOnly = ! optionsLoaded || tagsUnavailable;

	// A disabled field drops out of the tab order, so the wait needs its own
	// explanation. `help` lands in the field's `aria-describedby`; an empty
	// string suppresses the default how-to text, which these fields have
	// never shown.
	const categoriesHelp = optionsLoaded ? '' : __( 'Loading categories…', 'newspack-newsletters' );
	const tagsHelp = optionsLoaded ? '' : __( 'Loading tags…', 'newspack-newsletters' );

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
		// Only send a taxonomy the user actually touched. The edited ref is
		// the load-bearing half: a dirty diff alone would also be true in the
		// moment a late-resolving baseline overtakes the selection state,
		// which would let a visibility-only save serialise the stale value. An
		// untouched field must never overwrite what is stored.
		const data = { meta: { is_public: visibility === 'public' } };
		if ( hasEditedCategoriesRef.current && categoriesDirty ) {
			data.categories = [ ...categorySelections.map( s => s.id ), ...unresolvedCategoryIds ];
		}
		if ( hasEditedTagsRef.current && tagsDirty ) {
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
				help={ categoriesHelp }
				disabled={ categoriesReadOnly }
				onChange={ next => {
					hasEditedCategoriesRef.current = true;
					setCategorySelections( resolveTokens( next, categorySelections, categories ) );
				} }
				__experimentalValidateInput={ validateCategory }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			{ categoriesUnavailable && (
				<TermsUnavailableNotice
					message={ __( 'Categories could not be loaded. Edit this newsletter to change them.', 'newspack-newsletters' ) }
				/>
			) }
			<FormTokenField
				label={ __( 'Tags', 'newspack-newsletters' ) }
				value={ tagTokens }
				suggestions={ tagNames }
				help={ tagsHelp }
				disabled={ tagsReadOnly }
				onChange={ next => {
					hasEditedTagsRef.current = true;
					setTagSelections( resolveTokens( next, tagSelections, tags ) );
				} }
				__experimentalValidateInput={ validateTag }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			{ tagsUnavailable && (
				<TermsUnavailableNotice message={ __( 'Tags could not be loaded. Edit this newsletter to change them.', 'newspack-newsletters' ) } />
			) }
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
