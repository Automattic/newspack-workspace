/**
 * Field definitions for the Layouts list DataView.
 */

import { parse } from '@wordpress/blocks';
import { Icon, TextControl } from '@wordpress/components';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { commentAuthorAvatar, plugins } from '@wordpress/icons';
import { ENTER, ESCAPE } from '@wordpress/keycodes';

import NewsletterPreview from '../../../components/newsletter-preview';
import { setPreventDeduplicationForPostsInserter } from '../../../editor/blocks/posts-inserter/utils';
import { getAdminUrl } from '../../admin-globals';
import { formatPostDate } from '../../utils/format-date';
import LazyPreview from './lazy-preview';

import type { ComponentProps, ReactElement } from 'react';
import type { Field, FieldElement, PostItem } from '../../types';
import type { LayoutItem } from './types';

const editUrl = ( item: LayoutItem ): string => `${ getAdminUrl() }post.php?post=${ item.id }&action=edit`;

// String token can't collide with real (positive integer) WP user IDs.
export const PREBUILT_AUTHOR_VALUE = 'newspack';

function getRawTitle( item: LayoutItem ): string {
	return item?.title?.raw ?? item?.title?.rendered ?? '';
}

function getRawContent( item: LayoutItem ): string {
	return item?.content?.raw ?? '';
}

interface PreviewMeta {
	font_body: string;
	font_header: string;
	background_color: string;
	text_color: string;
	custom_css: string;
}

function getMetaForPreview( item: LayoutItem ): PreviewMeta {
	const meta = item?.meta || {};
	return {
		font_body: meta.font_body || '',
		font_header: meta.font_header || '',
		background_color: meta.background_color || '',
		text_color: meta.text_color || '',
		custom_css: meta.custom_css || '',
	};
}

interface RenamingTitleProps {
	item: LayoutItem;
	onCommit: ( value: string ) => Promise< unknown > | undefined;
	onCancel: () => void;
}

/**
 * Inline-renaming title cell. Commits on blur or Enter, reverts on Escape.
 */
function RenamingTitle( { item, onCommit, onCancel }: RenamingTitleProps ): ReactElement {
	const [ value, setValue ] = useState( getRawTitle( item ) );
	const [ isBusy, setIsBusy ] = useState( false );
	const inputRef = useRef< HTMLDivElement >( null );

	useEffect( () => {
		const input = inputRef.current?.querySelector?.( 'input' );
		input?.focus();
		input?.select();
	}, [] );

	const commit = async () => {
		const trimmed = ( value || '' ).trim();
		const original = getRawTitle( item );
		if ( trimmed === '' || trimmed === original ) {
			onCancel();
			return;
		}
		setIsBusy( true );
		try {
			await onCommit( trimmed );
		} catch {
			// Screen-level handler raises the notice and leaves `renamingId`
			// set; swallow here so blur/keydown don't trip an unhandled rejection.
		} finally {
			setIsBusy( false );
		}
	};

	const onKeyDown: NonNullable< ComponentProps< typeof TextControl >[ 'onKeyDown' ] > = event => {
		if ( event.keyCode === ENTER ) {
			event.preventDefault();
			commit();
		} else if ( event.keyCode === ESCAPE ) {
			event.preventDefault();
			onCancel();
		}
	};

	// `onClickCapture` rather than `onClick` so the wrapper isn't flagged
	// by `jsx-a11y/no-static-element-interactions` (which only checks the
	// classic interactive event names). The capture-phase handler stops
	// the click from bubbling up to the DataView row, which would
	// otherwise toggle the row's selection while the user types.
	return (
		<div ref={ inputRef } onClickCapture={ event => event.stopPropagation() }>
			<TextControl
				value={ value }
				onChange={ setValue }
				onBlur={ commit }
				onKeyDown={ onKeyDown }
				disabled={ isBusy }
				__nextHasNoMarginBottom
			/>
		</div>
	);
}

interface GetFieldsArgs {
	/** Row id currently in inline-rename mode (or `null`). */
	renamingId?: string | number | null;
	/** PATCH and refresh. */
	onRenameCommit?: ( item: LayoutItem, newTitle: string ) => Promise< unknown >;
	/** Clear `renamingId` without saving. */
	onRenameCancel?: () => void;
	/** Filter elements for the author field, derived from the loaded data. */
	authorElements?: FieldElement[];
}

/**
 * Build the field list. See `GetFieldsArgs` for the options shape.
 *
 * @return Field definitions.
 */
export function getFields( { renamingId = null, onRenameCommit, onRenameCancel, authorElements = [] }: GetFieldsArgs = {} ): Field< LayoutItem >[] {
	const renderTitle = ( { item }: { item: LayoutItem } ) => {
		const id = item?.id;
		if ( renamingId !== null && String( renamingId ) === String( id ) ) {
			return <RenamingTitle item={ item } onCommit={ next => onRenameCommit?.( item, next ) } onCancel={ () => onRenameCancel?.() } />;
		}
		const raw = getRawTitle( item );
		// Auto-drafts carry WordPress's "Auto Draft" placeholder; show a friendly title instead.
		const label = ! raw || 'auto-draft' === item?.status ? __( '(no title)', 'newspack-newsletters' ) : raw;
		// Prebuilts aren't editable; only user-owned layouts link to the editor.
		if ( item?.is_prebuilt ) {
			return <strong>{ label }</strong>;
		}
		return (
			<a className="newspack-newsletters-list__title" href={ editUrl( item ) } onClickCapture={ event => event.stopPropagation() }>
				<strong>{ label }</strong>
			</a>
		);
	};

	const renderAuthor = ( { item }: { item: LayoutItem } ) => {
		const author = item?._embedded?.author?.[ 0 ];
		if ( ! author ) {
			return null;
		}
		const isPrebuilt = !! item?.is_prebuilt;
		// Prefer the 48px source so the 16px display stays crisp on hi-DPI screens.
		const avatarUrl = ! isPrebuilt && ( author.avatar_urls?.[ 48 ] || author.avatar_urls?.[ 24 ] );
		return (
			<span className="newspack-newsletters-list__author">
				{ avatarUrl ? (
					<span className="newspack-newsletters-list__author-avatar">
						<img src={ avatarUrl } width={ 16 } height={ 16 } alt="" />
					</span>
				) : (
					<Icon className="newspack-newsletters-list__author-icon" icon={ isPrebuilt ? plugins : commentAuthorAvatar } size={ 24 } />
				) }
				<span>{ author.name || '' }</span>
			</span>
		);
	};

	const authorField: Field< LayoutItem > = {
		id: 'author',
		label: __( 'Author', 'newspack-newsletters' ),
		enableSorting: false,
		getValue: ( { item } ) => ( item?.is_prebuilt ? PREBUILT_AUTHOR_VALUE : String( item?._embedded?.author?.[ 0 ]?.id ?? item?.author ?? '' ) ),
		render: renderAuthor,
	};

	if ( authorElements.length > 0 ) {
		authorField.elements = authorElements;
		// `isNone` would need client-side post-filter after server pagination;
		// that leaves blank slots and miscounts totals. Add once the REST
		// collection accepts author exclusions.
		authorField.filterBy = { operators: [ 'is', 'isAny' ] };
	}

	return [
		{
			id: 'title',
			label: __( 'Title', 'newspack-newsletters' ),
			enableGlobalSearch: true,
			enableSorting: true,
			getValue: ( { item } ) => getRawTitle( item ),
			render: renderTitle,
		},
		authorField,
		{
			id: 'preview',
			label: __( 'Preview', 'newspack-newsletters' ),
			enableSorting: false,
			enableHiding: false,
			getValue: () => '',
			render: PreviewCard,
		},
		{
			id: 'modified',
			label: __( 'Last modified', 'newspack-newsletters' ),
			enableSorting: true,
			getValue: ( { item } ) => item?.modified || '',
			render: ( { item } ) => {
				// `formatPostDate` reads only `item[ fieldName ]` — the shared
				// `PostItem.id: number` is stricter than `LayoutItem.id`
				// (`string | number`, for synthetic prebuilt rows), so the two
				// types only partially overlap; that overlap covers everything
				// this call actually touches.
				const formatted = formatPostDate( item as PostItem, 'modified', { kind: 'date' } );
				return formatted ? <span>{ formatted }</span> : null;
			},
		},
	];
}

/**
 * Grid card preview. Memoises the parsed-block tree so resize /
 * unrelated rerenders don't re-parse the layout markup, and wraps the
 * `<NewsletterPreview>` in `LazyPreview` so the iframe only mounts when
 * the card scrolls into view.
 */
function PreviewCard( { item }: { item: LayoutItem } ): ReactElement {
	const content = getRawContent( item );
	const meta = getMetaForPreview( item );
	const blocks = useMemo( () => {
		if ( ! content ) {
			return [];
		}
		// Match the layout picker's behaviour — posts-inserter blocks
		// inside a layout would otherwise dedupe against the editor's
		// post list when previewed. The picker uses the same helper for
		// the same reason; the Layouts list inherits the consequence.
		return setPreventDeduplicationForPostsInserter( parse( content ) );
	}, [ content ] );

	if ( ! content || ! blocks.length ) {
		// `role="img"` plus a visually-hidden label so screen readers
		// announce the empty state — generic divs with only `aria-label`
		// aren't reliably announced.
		const emptyLabel = __( 'Empty layout', 'newspack-newsletters' );
		return (
			<div
				role="img"
				aria-label={ emptyLabel }
				className="newspack-newsletters-layouts-list__preview newspack-newsletters-layouts-list__preview--empty"
			>
				<span className="screen-reader-text">{ emptyLabel }</span>
			</div>
		);
	}

	// `NewsletterPreview` (`src/components/newsletter-preview`) has no
	// declared prop types (its params are untyped, inferred from
	// destructuring defaults), so its real, wider prop surface has to be
	// asserted here at this opaque boundary.
	const previewProps = {
		layoutId: item?.id,
		meta,
		blocks,
		viewportWidth: 848,
	} as ComponentProps< typeof NewsletterPreview >;

	return (
		<LazyPreview placeholderStyle={ { aspectRatio: '1' } } rootMargin="200px">
			{ () => (
				<div className="newspack-newsletters-layouts-list__preview">
					<NewsletterPreview { ...previewProps } />
				</div>
			) }
		</LazyPreview>
	);
}
