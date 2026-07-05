/**
 * Layouts list screen — React DataView managing prebuilt + user-saved
 * layouts. Prebuilts surface only Duplicate; other actions filter out
 * via the row-level `isUserOwned` gate in `actions.tsx`.
 */

import { getBlockType, registerBlockType } from '@wordpress/blocks';
import { registerCoreBlocks } from '@wordpress/block-library';
import { Spinner } from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews/wp';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { getAdminUrl } from '../../admin-globals';
import { useHeaderActions } from '../../header-actions-context';
import { notifyError, notifySuccess } from '../../notices';
import { LAYOUT_CPT_SLUG } from '../../../utils/consts';
import useLayoutsData from './use-layouts-data';
import usePrebuiltLayouts from './use-prebuilt-layouts';
import { getFields, PREBUILT_AUTHOR_VALUE } from './fields';
import { getActions, renameLayout } from './actions';
import { getInitialView } from './initial-filters';

import type { ComponentProps, ReactElement } from 'react';
import type { FieldElement, HeaderAction, QueryView, View } from '../../types';
import type { LayoutItem } from './types';

// See `./wordpress-block-library.d.ts` — `@wordpress/block-library` ships
// no types at all, and `../../screens/newsletters-list/wordpress-dataviews-wp.d.ts`
// for why `@wordpress/dataviews/wp` resolves (both ambient shims are
// program-wide, not scoped to the file that declares them).

type RegisterBlockTypeSettings = Parameters< typeof registerBlockType >[ 1 ];

// Admin-shell pages don't auto-register blocks the way `post.php` does,
// so without this `parse()` would drop unknown blocks and every preview
// card would render the empty placeholder. Newspack blocks intentionally
// stay unregistered here — they live in the heavy newsletter-editor
// bundle and render as "block-not-found" in previews, acceptable for a
// recognition-grade thumbnail.
function ensureCoreBlocksRegistered(): void {
	if ( typeof getBlockType === 'function' && getBlockType( 'core/paragraph' ) ) {
		return;
	}
	if ( typeof registerCoreBlocks === 'function' ) {
		registerCoreBlocks();
		return;
	}
	if ( typeof registerBlockType === 'function' ) {
		// Last-resort fallback for an environment without `@wordpress/block-library`
		// loaded at all; `registerBlockType`'s settings type requires `category` +
		// `attributes` that this minimal registration has never actually supplied
		// (WP's real implementation only warns and no-ops on the missing fields,
		// it doesn't throw) — kept as-is so that pre-existing (likely accidental
		// no-op) behaviour doesn't change. `title` is widened to plain `string`
		// first, otherwise it keeps `__()`'s branded per-string-literal return
		// type. Going through `Partial<…>` first (rather than casting the
		// literal straight to the full settings type) avoids the missing
		// `category`/`attributes` fields tripping TS's "insufficient overlap"
		// check on the cast — a concrete settings value is always a valid
		// `Partial` of itself, so that narrower assertion is sound.
		const title: string = __( 'Paragraph', 'newspack-newsletters' );
		const partialSettings: Partial< RegisterBlockTypeSettings > = { title, save: () => null };
		registerBlockType( 'core/paragraph', partialSettings as RegisterBlockTypeSettings );
	}
}

const DEFAULT_VIEW: View = {
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

interface AuthorFilterResolution {
	showPrebuilts: boolean;
	restrictedAuthorIds: number[];
	savedFetchAllAuthors: boolean;
}

export default function LayoutsListScreen(): ReactElement {
	useEffect( () => {
		ensureCoreBlocksRegistered();
	}, [] );

	const [ view, setView ] = useState< View >( DEFAULT_VIEW );
	// DataViews always echoes a full `perPage`/`page` back through
	// `onChangeView` for this grid/table screen; `View.perPage`/`.page` are
	// optional only because the type is shared with DataViews' picker
	// layouts, which paginate differently.
	const perPage = view.perPage as number;
	const page = view.page as number;
	const [ renamingId, setRenamingId ] = useState< string | number | null >( null );
	// Bumping this forces every write path to refetch the saved data.
	const [ mutationKey, setMutationKey ] = useState( 0 );

	const { layouts: prebuiltData, isLoading: isPrebuiltLoading } = usePrebuiltLayouts();

	// Resolve the author filter into:
	//   - showPrebuilts          — include the prebuilt set in the merged view
	//   - restrictedAuthorIds    — REST `author=` include-list for saved rows
	//   - savedFetchAllAuthors   — fetch saved collection without any author param
	const authorFilterResolution = useMemo< AuthorFilterResolution >( () => {
		const filter = ( view.filters || [] ).find( f => f.field === 'author' );
		const noFilter: AuthorFilterResolution = { showPrebuilts: true, restrictedAuthorIds: [], savedFetchAllAuthors: true };
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

	// Prebuilts pin to page 1 only; search hides them (titles aren't
	// indexed against parsed content). Saved rows offset-paginate around
	// the slots prebuilts reserve.
	const showPrebuilts = authorShowPrebuilts && view.page === 1 && ! view.search;
	const prebuiltCount = prebuiltData.length;
	// `couldRideAlong` lets us defer the saved fetch until we know the
	// prebuilt count, avoiding a refetch with a smaller slot count once
	// prebuilts arrive.
	const couldRideAlong = authorShowPrebuilts && ! view.search;
	const ridingAlong = couldRideAlong && prebuiltCount > 0;
	const firstPageSavedSlots = ridingAlong ? Math.max( 1, perPage - prebuiltCount ) : perPage;

	const savedView = useMemo< QueryView | null >( () => {
		if ( ! showSaved ) {
			return null;
		}
		// Hold the saved fetch while prebuilts load — otherwise the first
		// request uses too many slots and refetches once prebuilts arrive.
		if ( couldRideAlong && isPrebuiltLoading ) {
			return null;
		}
		const baseView: QueryView = restrictedAuthorIds.length > 0 ? { ...view, author: restrictedAuthorIds } : { ...view };
		if ( ridingAlong ) {
			if ( view.page === 1 ) {
				return { ...baseView, perPage: firstPageSavedSlots, offset: 0 };
			}
			return { ...baseView, offset: firstPageSavedSlots + ( page - 2 ) * perPage };
		}
		return { ...baseView };
	}, [ view, showSaved, couldRideAlong, isPrebuiltLoading, ridingAlong, firstPageSavedSlots, restrictedAuthorIds ] );

	const { data: savedData, paginationInfo: savedPagination, isLoading, hasResolved: savedHasResolved } = useLayoutsData( savedView, mutationKey );

	const filteredPrebuilts = showPrebuilts ? prebuiltData : [];
	const filteredSaved = showSaved ? savedData : [];

	const data = useMemo( () => [ ...filteredSaved, ...filteredPrebuilts ], [ filteredPrebuilts, filteredSaved ] );

	// Author elements grow as the user pages through; a static list would
	// require a server-side enumeration of every layout author.
	const authorElements = useMemo< FieldElement[] >( () => {
		const elements: FieldElement[] = [ { value: PREBUILT_AUTHOR_VALUE, label: __( 'Newspack', 'newspack-newsletters' ) } ];
		const seen = new Set< number >();
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
		const totalPages = 1 + Math.ceil( remainingSaved / perPage );
		return {
			totalItems: savedPagination.totalItems + ( authorShowPrebuilts ? prebuiltCount : 0 ),
			totalPages: Math.max( 1, totalPages ),
		};
	}, [ savedPagination, prebuiltCount, showSaved, authorShowPrebuilts, ridingAlong, firstPageSavedSlots, perPage ] );

	// `mediaField` is grid-only — in table mode the per-row iframe blows
	// out row heights, so strip it on layout switches.
	const onChangeView = useCallback( ( next: View ) => {
		if ( next.type === 'table' ) {
			setView( { ...next, mediaField: undefined } );
		} else if ( next.type === 'grid' && ! next.mediaField ) {
			setView( { ...next, mediaField: 'preview' } );
		} else {
			setView( next );
		}
	}, [] );

	const onMutated = useCallback( () => setMutationKey( key => key + 1 ), [] );

	const startRenaming = useCallback( ( item: LayoutItem ) => {
		setRenamingId( item?.id ?? null );
	}, [] );
	const cancelRenaming = useCallback( () => setRenamingId( null ), [] );
	const commitRename = useCallback(
		async ( item: LayoutItem, nextTitle: string ) => {
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
		useMemo< HeaderAction[] >(
			() => [
				{
					type: 'primary',
					label: __( 'Add new layout', 'newspack-newsletters' ),
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

	// `DataViews`' declared props don't include `className` (see
	// `../newsletters-list/index.tsx` — its source never destructures or
	// forwards one), so passing it here has never actually applied either
	// class to a rendered DOM node. Kept as-is; cast once so it still
	// type-checks.
	const dataViewsProps = {
		className: 'newspack-newsletters-list newspack-newsletters-layouts-list',
		data,
		fields,
		view,
		onChangeView,
		actions,
		paginationInfo,
		defaultLayouts: DEFAULT_LAYOUTS,
		isLoading: isLoading || isPrebuiltLoading,
		getItemId: ( item: LayoutItem ) => String( item.id ),
		search: true,
	} as ComponentProps< typeof DataViews >;

	return <DataViews { ...dataViewsProps } />;
}
