"use client"

import { Fragment, useEffect, useId, useMemo, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import * as JsxRuntime from 'react/jsx-runtime';
import { chevronRightSmall, Icon, search as searchIcon } from '@wordpress/icons';
import { useHighlightQuery, useStaticSearch, type SearchResultRecord, type SearchResultTable } from 'fumadocs-core/search/client';
import { toJsxRuntime, type Components, type Options } from 'hast-util-to-jsx-runtime';
import { Input } from '@/components/ui/input';
import { CloseIcon } from '@/lib/newspack-ui-icons';
import { SEARCH_INDEX } from '@/lib/site';
import { cn } from '@/lib/utils';

export type SearchResult = { record: SearchResultRecord; table?: SearchResultTable };

/** One result per record, with each table row as its own result, in the order they are listed. */
export function flattenResults( items: ( SearchResultRecord | SearchResultTable )[] | undefined ): SearchResult[] {
	return (
		items?.flatMap( ( item ): SearchResult[] =>
			item.type === 'table' ? item.rows.map( ( record ) => ( { record, table: item } ) ) : [ { record: item } ]
		) ?? []
	);
}

/** The row box shared by the drawer search and the search overlay. */
export const SEARCH_RESULT_ROW =
	'flex min-h-(--newspack-ui-touch-target) flex-col justify-center rounded-(--newspack-ui-radius-control) px-(--newspack-ui-spacer-2) py-(--newspack-ui-spacer-1) text-(color:--newspack-ui-color-text) no-underline transition-colors duration-(--newspack-ui-duration-color) ease-(--newspack-ui-ease-color)';

/** Matches of the query: the accent color, underlined. */
export const SEARCH_HIGHLIGHT = '[&_::highlight(fd-search)]:text-(color:--newspack-ui-color-accent) [&_::highlight(fd-search)]:underline';

const Pass = ( { children }: { children?: ReactNode } ) => <>{ children }</>;
const Cell = ( { children }: { children?: ReactNode } ) => <span className="me-(--newspack-ui-spacer-2)">{ children }</span>;

const inline = {
	a: 'span',
	p: 'span',
	pre: 'span',
	custom: Pass,
	table: Pass,
	thead: Pass,
	tbody: Pass,
	tr: Pass,
	th: Cell,
	td: Cell,
} as Partial< Components >;
const rowWithHeader: Partial< Components > = { ...inline, thead: () => null };

function render( tree: SearchResultRecord[ 'hastContent' ], components: Partial< Components > ) {
	return toJsxRuntime( tree, { ...JsxRuntime, development: false, components } as Options );
}

/** A result's breadcrumbs and its text, with the matches of `query` highlighted. */
export function SearchResultBody( { result, query }: { result: SearchResult; query: string } ) {
	const { record, table } = result;
	const highlight = useHighlightQuery( query );
	const content = useMemo( () => render( record.hastContent, table?.header ? rowWithHeader : inline ), [ record, table ] );
	const nested = record.type !== 'page';

	return (
		<>
			{ !! record.breadcrumbs?.length && (
				<span className="flex flex-wrap items-center text-(length:--newspack-ui-font-size-small) leading-(--newspack-ui-line-height-small) text-(color:--newspack-ui-color-text-muted)">
					{ record.breadcrumbs.map( ( crumb, index ) => (
						<Fragment key={ index }>
							{ index > 0 && (
								<>
									<Icon icon={ chevronRightSmall } size={ 16 } aria-hidden="true" className="fill-current rtl:rotate-180" />
									<span className="sr-only">, </span>
								</>
							) }
							{ crumb }
						</Fragment>
					) ) }
				</span>
			) }
			<span
				ref={ highlight }
				className={
					nested
						? cn(
								'line-clamp-2 border-s border-(--newspack-ui-color-border) ps-(--newspack-ui-spacer-3) text-(length:--newspack-ui-font-size-small) leading-(--newspack-ui-line-height-small)',
								record.type === 'heading' ? 'font-(weight:--newspack-ui-font-weight-medium)' : 'text-(color:--newspack-ui-color-text-muted)'
						  )
						: 'font-(weight:--newspack-ui-font-weight-medium)'
				}
			>
				{ content }
			</span>
		</>
	);
}

function ResultLink( { result, query, onNavigate }: { result: SearchResult; query: string; onNavigate: () => void } ) {
	return (
		<li>
			<a href={ result.record.url } onClick={ onNavigate } className={ cn( SEARCH_RESULT_ROW, 'hover:bg-(--newspack-ui-color-neutral-fill)' ) }>
				<SearchResultBody result={ result } query={ query } />
			</a>
		</li>
	);
}

/**
 * Docs search for a mobile drawer: the field, then the results in place of
 * whatever follows it. While there is a query the root carries
 * `data-querying`, which the host's CSS uses to hide its nav.
 */
export function DrawerSearch( {
	onNavigate,
	open = true,
	from = SEARCH_INDEX,
	className,
}: {
	onNavigate: () => void;
	open?: boolean;
	from?: string;
	className?: string;
} ) {
	const id = useId();
	const { search, onSearchChange, isLoading, data, error } = useStaticSearch( { from } );
	const root = useRef< HTMLDivElement >( null );
	const field = useRef< HTMLInputElement >( null );
	const list = useRef< HTMLUListElement >( null );
	const [ wasOpen, setWasOpen ] = useState( open );
	const querying = search.trim() !== '';

	if ( open !== wasOpen ) {
		setWasOpen( open );
		if ( open ) {
			onSearchChange( '' );
		}
	}

	const results = useMemo( () => flattenResults( data?.items ), [ data ] );

	useEffect( () => {
		const onEscape = ( event: globalThis.KeyboardEvent ) => {
			if (
				event.key !== 'Escape' ||
				! search ||
				! ( event.target instanceof Node ) ||
				! root.current?.contains( event.target )
			) {
				return;
			}
			event.preventDefault();
			onSearchChange( '' );
			field.current?.focus();
		};
		window.addEventListener( 'keydown', onEscape, true );
		return () => window.removeEventListener( 'keydown', onEscape, true );
	}, [ search, onSearchChange ] );

	const onKeyDown = ( event: KeyboardEvent< HTMLDivElement > ) => {
		const links = Array.from( list.current?.querySelectorAll( 'a' ) ?? [] );
		const index = links.indexOf( document.activeElement as HTMLAnchorElement );

		if ( event.key === 'Enter' && event.target === field.current ) {
			event.preventDefault();
			if ( ! isLoading && ! error && data?.query === search ) {
				links[ 0 ]?.click();
			}
			return;
		}
		if ( event.key === 'ArrowDown' && links.length ) {
			event.preventDefault();
			links[ Math.min( index + 1, links.length - 1 ) ].focus();
		} else if ( event.key === 'ArrowUp' && index !== -1 ) {
			event.preventDefault();
			( index === 0 ? field.current : links[ index - 1 ] )?.focus();
		}
	};

	let status = '';
	if ( querying ) {
		if ( error ) {
			status = "Search couldn't load. Reload the page and try again.";
		} else if ( ! data ) {
			status = 'Searching…';
		} else if ( results.length === 0 ) {
			status = 'No results';
		} else {
			status = results.length === 1 ? '1 result' : `${ results.length } results`;
		}
	}
	const showResults = querying && !! data && ! error && results.length > 0;

	return (
		<div
			ref={ root }
			role="search"
			data-drawer-search=""
			data-querying={ querying ? '' : undefined }
			className={ cn( 'flex flex-col data-querying:min-h-0 data-querying:flex-1', className ) }
			onKeyDown={ onKeyDown }
		>
			<label htmlFor={ id } className="sr-only">
				Search the docs
			</label>
			<div className="relative">
				<Icon
					icon={ searchIcon }
					size={ 20 }
					aria-hidden="true"
					className="pointer-events-none absolute start-(--newspack-ui-spacer-2) top-1/2 -translate-y-1/2 fill-current text-(color:--newspack-ui-color-text-subtle)"
				/>
				<Input
					ref={ field }
					id={ id }
					type="search"
					placeholder="Search"
					autoComplete="off"
					autoCapitalize="off"
					spellCheck={ false }
					enterKeyHint="search"
					value={ search }
					className="ps-[calc(var(--newspack-ui-spacer-2)+var(--newspack-ui-spacer-4)+var(--newspack-ui-spacer-1))] pe-[calc(var(--newspack-ui-spacer-1)+var(--newspack-ui-spacer-6))] [&::-webkit-search-cancel-button]:appearance-none"
					onChange={ ( event ) => onSearchChange( event.target.value ) }
				/>
				{ search && (
					<button
						type="button"
						aria-label="Clear search"
						className="absolute end-(--newspack-ui-spacer-1) top-1/2 inline-flex -translate-y-1/2 items-center justify-center rounded-md p-1.5 text-(color:--newspack-ui-color-text-muted) transition-colors duration-100 hover:bg-(color:--newspack-ui-color-neutral-fill) hover:text-(color:--newspack-ui-color-text) focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-(color:--newspack-ui-color-focus) [&_svg]:size-4.5"
						onClick={ () => {
							onSearchChange( '' );
							field.current?.focus();
						} }
					>
						<CloseIcon />
					</button>
				) }
			</div>
			<p
				role="status"
				className={
					showResults
						? 'sr-only'
						: 'm-0 px-(--newspack-ui-spacer-2) pt-(--newspack-ui-spacer-3) text-(length:--newspack-ui-font-size-small) leading-(--newspack-ui-line-height-small) text-(color:--newspack-ui-color-text-muted) empty:hidden'
				}
			>
				{ status }
			</p>
			{ showResults && (
				<ul
					ref={ list }
					aria-label="Search results"
					className={ cn( "-mx-(--newspack-ui-spacer-2) mt-(--newspack-ui-spacer-1) flex min-h-0 flex-1 flex-col gap-(--newspack-ui-spacer-half) overflow-y-auto overscroll-contain p-(--newspack-ui-spacer-2)", SEARCH_HIGHLIGHT ) }
				>
					{ results.map( ( result ) => (
						<ResultLink key={ result.record.id } result={ result } query={ data.query } onNavigate={ onNavigate } />
					) ) }
				</ul>
			) }
		</div>
	);
}
