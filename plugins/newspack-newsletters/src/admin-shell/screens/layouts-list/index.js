/**
 * Layouts list screen — React DataView managing prebuilt + user-saved
 * layouts. Prebuilts surface only Duplicate; other actions filter out
 * via the row-level `isUserOwned` gate in `actions.js`.
 */

import { getBlockType, registerBlockType } from '@wordpress/blocks';
import { registerCoreBlocks } from '@wordpress/block-library';
import { Spinner } from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews/wp';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { getAdminUrl } from '../../admin-globals';
import HeaderCount from '../../components/header-count';
import ItemsPerPage from '../../components/items-per-page';
import { useHeaderActions } from '../../header-actions-context';
import usePersistedView from '../../hooks/use-persisted-view';
import { notifyError, notifySuccess } from '../../notices';
import { isFetchAllPerPage, PER_PAGE_ALL } from '../../utils/per-page';
import { LAYOUT_CPT_SLUG } from '../../../utils/consts';
import useLayoutsData from './use-layouts-data';
import usePrebuiltLayouts from './use-prebuilt-layouts';
import { getFields, PREBUILT_AUTHOR_VALUE } from './fields';
import { getActions, renameLayout } from './actions';
import { getInitialView } from './initial-filters';

// Admin-shell pages don't auto-register blocks the way `post.php` does,
// so without this `parse()` would drop unknown blocks and every preview
// card would render the empty placeholder. Newspack blocks intentionally
// stay unregistered here — they live in the heavy newsletter-editor
// bundle and render as "block-not-found" in previews, acceptable for a
// recognition-grade thumbnail.
function ensureCoreBlocksRegistered() {
	if ( typeof getBlockType === 'function' && getBlockType( 'core/paragraph' ) ) {
		return;
	}
	if ( typeof registerCoreBlocks === 'function' ) {
		registerCoreBlocks();
		return;
	}
	if ( typeof registerBlockType === 'function' ) {
		registerBlockType( 'core/paragraph', {
			title: __( 'Paragraph', 'newspack-newsletters' ),
			save: () => null,
		} );
	}
}

const DEFAULT_VIEW = {
	type: 'grid',
	page: 1,
	// Each card mounts a BlockPreview iframe; 24 sits just under the
	// ~25 threshold where first paint stutters even with `LazyPreview`.
	perPage: 24,
	sort: { field: 'modified', direction: 'desc' },
	search: '',
	filters: [],
	titleField: 'title',
	mediaField: 'preview',
	fields: [ 'author' ],
	...getInitialView(),
};

const DEFAULT_LAYOUTS = {
	grid: {},
	table: {},
};

// Suppress the built-in ViewConfig per-page control — the custom
// `ItemsPerPage` renders in its place inside the View options popover.
// Options are multiples of the grid default rather than the shared
// 10/20/50/100.
const DATAVIEWS_CONFIG = { perPageSizes: [] };
const PER_PAGE_OPTIONS = [ 12, 24, 48, 96, PER_PAGE_ALL ];

export default function LayoutsListScreen() {
	useEffect( () => {
		ensureCoreBlocksRegistered();
	}, [] );

	const [ view, setView ] = usePersistedView( 'layouts-list', DEFAULT_VIEW, PER_PAGE_OPTIONS );
	const [ renamingId, setRenamingId ] = useState( null );
	// Bumping this forces every write path to refetch the saved data.
	const [ mutationKey, setMutationKey ] = useState( 0 );

	const { layouts: prebuiltData, isLoading: isPrebuiltLoading } = usePrebuiltLayouts();

	// Resolve the author filter into:
	//   - showPrebuilts          — include the prebuilt set in the merged view
	//   - restrictedAuthorIds    — REST `author=` include-list for saved rows
	//   - savedFetchAllAuthors   — fetch saved collection without any author param
	const authorFilterResolution = useMemo( () => {
		const filter = ( view.filters || [] ).find( f => f.field === 'author' );
		const noFilter = { showPrebuilts: true, restrictedAuthorIds: [], savedFetchAllAuthors: true };
		if ( ! filter || filter.operator === 'isNone' ) {
			return noFilter;
		}
		const raw = filter.value;
		const values = ( Array.isArray( raw ) ? raw : [ raw ] ).filter( v => v !== undefined && v !== null && v !== '' );
		if ( values.length === 0 ) {
			return noFilter;
		}
		const includesNewspack = values.includes( PREBUILT_AUTHOR_VALUE );
		const userIds = values
			.filter( v => v !== PREBUILT_AUTHOR_VALUE )
			.map( v => Number( v ) )
			.filter( n => Number.isFinite( n ) && n > 0 );

		return {
			showPrebuilts: includesNewspack,
			restrictedAuthorIds: userIds,
			savedFetchAllAuthors: false,
		};
	}, [ view.filters ] );

	const { showPrebuilts: authorShowPrebuilts, restrictedAuthorIds, savedFetchAllAuthors } = authorFilterResolution;
	const showSaved = savedFetchAllAuthors || restrictedAuthorIds.length > 0;

	// "All" collapses everything onto a single page, so the slot
	// arithmetic that rations page 1 between prebuilts and saved rows
	// doesn't apply — both sets simply concatenate.
	const fetchingAll = isFetchAllPerPage( view.perPage );

	// Prebuilts pin to page 1 only; search hides them (titles aren't
	// indexed against parsed content). Saved rows offset-paginate around
	// the slots prebuilts reserve.
	const showPrebuilts = authorShowPrebuilts && view.page === 1 && ! view.search;
	const prebuiltCount = prebuiltData.length;
	// `couldRideAlong` lets us defer the saved fetch until we know the
	// prebuilt count, avoiding a refetch with a smaller slot count once
	// prebuilts arrive.
	const couldRideAlong = authorShowPrebuilts && ! view.search;
	const ridingAlong = couldRideAlong && prebuiltCount > 0 && ! fetchingAll;
	const firstPageSavedSlots = ridingAlong ? Math.max( 1, view.perPage - prebuiltCount ) : view.perPage;

	const savedView = useMemo( () => {
		if ( ! showSaved ) {
			return null;
		}
		// Hold the saved fetch while prebuilts load — otherwise the first
		// request uses too many slots and refetches once prebuilts arrive.
		if ( couldRideAlong && isPrebuiltLoading ) {
			return null;
		}
		const baseView = restrictedAuthorIds.length > 0 ? { ...view, author: restrictedAuthorIds } : view;
		if ( ridingAlong ) {
			if ( view.page === 1 ) {
				return { ...baseView, perPage: firstPageSavedSlots, offset: 0 };
			}
			return { ...baseView, offset: firstPageSavedSlots + ( view.page - 2 ) * view.perPage };
		}
		return baseView;
	}, [ view, showSaved, couldRideAlong, isPrebuiltLoading, ridingAlong, firstPageSavedSlots, restrictedAuthorIds ] );

	const {
		data: savedData,
		paginationInfo: savedPagination,
		isLoading,
		hasResolved: savedHasResolved,
		progress,
	} = useLayoutsData( savedView, mutationKey );

	const filteredPrebuilts = showPrebuilts ? prebuiltData : [];
	const filteredSaved = showSaved ? savedData : [];

	const data = useMemo( () => [ ...filteredSaved, ...filteredPrebuilts ], [ filteredPrebuilts, filteredSaved ] );

	// Author elements grow as the user pages through; a static list would
	// require a server-side enumeration of every layout author.
	const authorElements = useMemo( () => {
		const elements = [ { value: PREBUILT_AUTHOR_VALUE, label: __( 'Newspack', 'newspack-newsletters' ) } ];
		const seen = new Set();
		savedData.forEach( item => {
			const author = item?._embedded?.author?.[ 0 ];
			const id = author?.id;
			const name = author?.name;
			if ( id && name && ! seen.has( id ) ) {
				seen.add( id );
				elements.push( { value: String( id ), label: name } );
			}
		} );
		return elements;
	}, [ savedData ] );

	const paginationInfo = useMemo( () => {
		if ( ! showSaved ) {
			return { totalItems: prebuiltCount, totalPages: 1 };
		}
		if ( fetchingAll ) {
			return {
				totalItems: savedPagination.totalItems + ( authorShowPrebuilts && ! view.search ? prebuiltCount : 0 ),
				totalPages: 1,
			};
		}
		if ( ! ridingAlong ) {
			return {
				totalItems: savedPagination.totalItems,
				totalPages: Math.max( 1, savedPagination.totalPages ),
			};
		}
		// Mixed view: page 1 holds prebuilts + `firstPageSavedSlots` saved,
		// later pages hold `perPage` saved each. Key the prebuilt count on
		// `authorShowPrebuilts` (filter decision) not `showPrebuilts`
		// (page-1-only) so the total stays stable across pages.
		const remainingSaved = Math.max( 0, savedPagination.totalItems - firstPageSavedSlots );
		const totalPages = 1 + Math.ceil( remainingSaved / view.perPage );
		return {
			totalItems: savedPagination.totalItems + ( authorShowPrebuilts ? prebuiltCount : 0 ),
			totalPages: Math.max( 1, totalPages ),
		};
	}, [ savedPagination, prebuiltCount, showSaved, authorShowPrebuilts, ridingAlong, firstPageSavedSlots, view.perPage, view.search, fetchingAll ] );

	// `mediaField` is grid-only — in table mode the per-row iframe blows
	// out row heights, so strip it on layout switches.
	const onChangeView = useCallback( next => {
		if ( next.type === 'table' ) {
			setView( { ...next, mediaField: undefined } );
		} else if ( next.type === 'grid' && ! next.mediaField ) {
			setView( { ...next, mediaField: 'preview' } );
		} else {
			setView( next );
		}
	}, [] );

	const onMutated = useCallback( () => setMutationKey( key => key + 1 ), [] );

	const startRenaming = useCallback( item => {
		setRenamingId( item?.id ?? null );
	}, [] );
	const cancelRenaming = useCallback( () => setRenamingId( null ), [] );
	const commitRename = useCallback(
		async ( item, nextTitle ) => {
			try {
				await renameLayout( item.id, nextTitle );
				setRenamingId( null );
				onMutated();
				notifySuccess( __( 'Layout renamed.', 'newspack-newsletters' ) );
			} catch ( error ) {
				notifyError( __( 'Failed to rename layout.', 'newspack-newsletters' ) );
				throw error;
			}
		},
		[ onMutated ]
	);

	const fields = useMemo(
		() => getFields( { renamingId, onRenameCommit: commitRename, onRenameCancel: cancelRenaming, authorElements } ),
		[ renamingId, commitRename, cancelRenaming, authorElements ]
	);
	const actions = useMemo( () => getActions( { onRenameStart: startRenaming, onMutated } ), [ startRenaming, onMutated ] );

	useHeaderActions(
		useMemo(
			() => [
				{
					type: 'primary',
					label: __( 'Add Layout', 'newspack-newsletters' ),
					href: `${ getAdminUrl() }post-new.php?post_type=${ LAYOUT_CPT_SLUG }`,
				},
			],
			[]
		)
	);

	// Gate on `savedHasResolved`, not `! isLoading` — the latter is
	// momentarily false between prebuilts resolving and the saved fetch
	// starting (would flash the grid early), and `hasLoadedOnce` only
	// flips on success so a failed first fetch would strand the spinner.
	const [ hasResolvedOnce, setHasResolvedOnce ] = useState( false );
	useEffect( () => {
		if ( hasResolvedOnce || isPrebuiltLoading ) {
			return;
		}
		if ( showSaved && ! savedHasResolved ) {
			return;
		}
		setHasResolvedOnce( true );
	}, [ hasResolvedOnce, isPrebuiltLoading, showSaved, savedHasResolved ] );

	if ( ! hasResolvedOnce ) {
		return (
			<div className="newspack-newsletters-admin__loading">
				<Spinner />
			</div>
		);
	}

	return (
		<>
			<HeaderCount count={ paginationInfo.totalItems } />
			<DataViews
				className="newspack-newsletters-list newspack-newsletters-layouts-list"
				data={ data }
				fields={ fields }
				view={ view }
				onChangeView={ onChangeView }
				actions={ actions }
				paginationInfo={ paginationInfo }
				defaultLayouts={ DEFAULT_LAYOUTS }
				isLoading={ isLoading || isPrebuiltLoading }
				getItemId={ item => String( item.id ) }
				search
				config={ DATAVIEWS_CONFIG }
				header={
					<ItemsPerPage
						value={ view.perPage }
						options={ PER_PAGE_OPTIONS }
						progress={ progress }
						onChange={ perPage => setView( current => ( { ...current, perPage, page: 1 } ) ) }
					/>
				}
			/>
		</>
	);
}
