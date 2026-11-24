"use client"

import { useCallback, useEffect, useEffectEvent, useLayoutEffect, useMemo, useRef, useState, useSyncExternalStore } from 'react';
import { Dialog as DialogPrimitive } from 'radix-ui';
import { Icon, search as searchIcon } from '@wordpress/icons';
import { SearchDialog, useSearch, type SharedProps } from 'fumadocs-ui/components/dialog/search';
import { useStaticSearch } from 'fumadocs-core/search/client';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { CloseIcon } from '@/lib/newspack-ui-icons';
import { SEARCH_EMPTY_HINT, SEARCH_INDEX } from '@/lib/site';
import { cn } from '@/lib/utils';
import { SEARCH_HIGHLIGHT, SEARCH_RESULT_ROW, SearchResultBody, flattenResults, type SearchResult } from './drawer-search';

const STATUS =
	'm-0 px-(--newspack-ui-spacer-2) py-(--newspack-ui-spacer-2) text-(length:--newspack-ui-font-size-small) leading-(--newspack-ui-line-height-small) text-(color:--newspack-ui-color-text-muted)';

function SearchField() {
	const { search, onSearchChange, getActive, subscribeActive } = useSearch();
	const active = useSyncExternalStore( subscribeActive, getActive, getActive );

	return (
		<div className="relative">
			<Icon
				icon={ searchIcon }
				size={ 24 }
				aria-hidden="true"
				className="pointer-events-none absolute start-(--newspack-ui-spacer-3) top-1/2 -translate-y-1/2 fill-current text-(color:--newspack-ui-color-text-subtle)"
			/>
			<Input
				role="combobox"
				type="search"
				aria-expanded={ active !== null }
				aria-activedescendant={ active !== null ? `fd-search-option-${ active }` : undefined }
				aria-label="Search the docs"
				aria-autocomplete="list"
				aria-controls={ active !== null ? 'fd-search-list' : undefined }
				placeholder="Search the docs"
				autoComplete="off"
				autoCapitalize="off"
				spellCheck={ false }
				enterKeyHint="search"
				value={ search }
				onChange={ ( event ) => onSearchChange( event.target.value ) }
				className="min-h-(--newspack-ui-spacer-10) ps-[calc(var(--newspack-ui-spacer-3)+var(--newspack-ui-spacer-5)+var(--newspack-ui-spacer-1))] text-(length:--newspack-ui-font-size-m) leading-(--newspack-ui-line-height-m) [&::-webkit-search-cancel-button]:appearance-none"
			/>
		</div>
	);
}

function ResultOption( { result, query }: { result: SearchResult; query: string } ) {
	const { onSelect, getActive, setActive, subscribeActive } = useSearch();
	const id = result.record.id;
	const isActive = () => getActive() === id;
	const active = useSyncExternalStore( subscribeActive, isActive, isActive );
	const ref = useCallback(
		( element: HTMLDivElement | null ) => {
			if ( active && element ) {
				element.scrollIntoView( { block: 'nearest', inline: 'nearest' } );
			}
		},
		[ active ]
	);

	return (
		<div
			ref={ ref }
			id={ `fd-search-option-${ id }` }
			role="option"
			aria-selected={ active }
			className={ cn( SEARCH_RESULT_ROW, 'cursor-default aria-selected:bg-(--newspack-ui-color-neutral-fill)' ) }
			onPointerMove={ () => setActive( id ) }
			onClick={ () => onSelect( result.record ) }
		>
			<SearchResultBody result={ result } query={ query } />
		</div>
	);
}

/**
 * The results under the field: the kit's result rows, the first one active, Arrow Up and Down
 * moving through them (wrapping at the ends) and Enter opening the active one, as Fumadocs'
 * own list does.
 */
function SearchResults() {
	const { data, error, query, getActive, setActive, onSelect } = useSearch();
	const results = useMemo( () => ( data ? flattenResults( data.items ) : null ), [ data ] );

	useLayoutEffect( () => setActive( error ? null : results?.[ 0 ]?.record.id ?? null ), [ setActive, results, error ] );

	const onKey = useEffectEvent( ( event: KeyboardEvent ) => {
		const target = event.target;
		if ( ! results || event.isComposing || event.keyCode === 229 || ! ( target instanceof HTMLInputElement ) || target.getAttribute( 'role' ) !== 'combobox' ) {
			return;
		}
		if ( event.key === 'ArrowDown' || event.key === 'ArrowUp' ) {
			let index = results.findIndex( ( result ) => result.record.id === getActive() );
			if ( index === -1 ) {
				index = 0;
			} else {
				index += event.key === 'ArrowDown' ? 1 : -1;
			}
			setActive( results.at( index % results.length )?.record.id ?? null );
			event.preventDefault();
		}
		if ( event.key === 'Enter' ) {
			const selected = results.find( ( result ) => result.record.id === getActive() );
			if ( selected ) {
				onSelect( selected.record );
			}
			event.preventDefault();
		}
	} );

	useEffect( () => {
		const listener = ( event: KeyboardEvent ) => onKey( event );
		window.addEventListener( 'keydown', listener );
		return () => window.removeEventListener( 'keydown', listener );
	}, [] );

	const empty = ! error && results?.length === 0;
	let status = '';
	if ( error ) {
		status = "Search couldn't load. Reload the page and try again.";
	} else if ( empty ) {
		status = SEARCH_EMPTY_HINT;
	} else if ( results?.length ) {
		status = results.length === 1 ? '1 result' : `${ results.length } results`;
	}

	return (
		<>
			<p role="status" className="sr-only">
				{ status }
			</p>
			{ ( error || results !== null ) && (
				<Card
					data-search-results=""
					className="mt-(--newspack-ui-spacer-3) gap-0 bg-(--newspack-ui-color-surface) p-(--newspack-ui-spacer-2) [box-shadow:var(--nui-elevation-2)]"
				>
					{ error && (
						<p className={ STATUS }>Search couldn&apos;t load. Reload the page and try again.</p>
					) }
					{ empty && (
						<p className={ STATUS }>
							{ SEARCH_EMPTY_HINT }
						</p>
					) }
					<div
						id="fd-search-list"
						role={ results?.length ? 'listbox' : undefined }
						aria-label={ results?.length ? 'Search results' : undefined }
						className={ cn( 'flex flex-col gap-(--newspack-ui-spacer-half)', SEARCH_HIGHLIGHT, ! results?.length && 'hidden' ) }
					>
						{ results?.map( ( result ) => (
							<ResultOption key={ result.record.id } result={ result } query={ query } />
						) ) }
					</div>
				</Card>
			) }
		</>
	);
}

/**
 * The docs search, opened by the header's Search button or Ctrl or Cmd+K: a full-screen overlay
 * like the Newspack block theme's Overlay Search, with the field near the top of a centered
 * column and the results in a card under it. Radix Dialog keeps focus inside, locks the page
 * scroll, hides the page from assistive technology and closes on Escape; the page behind is also
 * made inert, and focus goes back to what opened it.
 */
export default function DocsSearchDialog( props: SharedProps ) {
	const search = useStaticSearch( { from: SEARCH_INDEX } );
	const returnFocus = useRef< HTMLElement | null >( null );
	const [ overlay, setOverlay ] = useState< HTMLDivElement | null >( null );

	useLayoutEffect( () => {
		if ( props.open ) {
			returnFocus.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
		}
	}, [ props.open ] );

	useEffect( () => {
		if ( ! props.open || ! overlay ) {
			return;
		}
		let portal: Element = overlay;
		while ( portal.parentElement && portal.parentElement !== document.body ) {
			portal = portal.parentElement;
		}
		const behind = Array.from( document.body.children ).filter(
			( el ) =>
				el !== portal &&
				! el.hasAttribute( 'inert' ) &&
				! el.hasAttribute( 'data-radix-focus-guard' ) &&
				! [ 'SCRIPT', 'STYLE', 'TEMPLATE' ].includes( el.tagName )
		);
		behind.forEach( ( el ) => el.setAttribute( 'inert', '' ) );
		return () => behind.forEach( ( el ) => el.removeAttribute( 'inert' ) );
	}, [ props.open, overlay ] );

	return (
		<SearchDialog { ...search } { ...props }>
			<DialogPrimitive.Portal>
				<DialogPrimitive.Overlay
					ref={ setOverlay }
					data-search-overlay=""
					className="fixed inset-0 z-50 overflow-y-auto overscroll-contain bg-(--newspack-ui-color-overlay-70) text-(color:--search-overlay-ink) duration-(--newspack-ui-duration-modal) ease-(--newspack-ui-ease-out) data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=closed]:animate-out data-[state=closed]:fade-out-0"
				>
					<DialogPrimitive.Content
						aria-describedby={ undefined }
						onOpenAutoFocus={ ( event ) => {
							const input = event.currentTarget instanceof HTMLElement ? event.currentTarget.querySelector( 'input' ) : null;
							if ( input ) {
								event.preventDefault();
								input.focus();
								input.setSelectionRange( input.value.length, input.value.length );
							}
						} }
						onCloseAutoFocus={ ( event ) => {
							if ( returnFocus.current?.isConnected ) {
								event.preventDefault();
								returnFocus.current.focus();
							}
						} }
						onClick={ ( event ) => {
							if ( event.target === event.currentTarget ) {
								props.onOpenChange( false );
							}
						} }
						className="group/search flex min-h-full flex-col px-(--newspack-ui-spacer-3) pt-[calc(10vh+var(--newspack-ui-spacer-11))] pb-(--newspack-ui-spacer-3) font-(family-name:--newspack-ui-font-family-body) text-(length:--newspack-ui-font-size-body) leading-(--newspack-ui-line-height-body) outline-none min-[600px]:px-(--newspack-ui-spacer-5)"
					>
						<DialogPrimitive.Title className="sr-only">Search the docs</DialogPrimitive.Title>
						<DialogPrimitive.Close className="absolute end-(--newspack-ui-spacer-3) top-(--newspack-ui-spacer-3) grid size-(--newspack-ui-spacer-8) place-items-center rounded-(--newspack-ui-radius-control) text-inherit transition-colors duration-(--newspack-ui-duration-color) ease-(--newspack-ui-ease-color) hover:bg-[color-mix(in_oklab,var(--search-overlay-ink)_14%,transparent)] focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current [&_svg]:size-(--newspack-ui-spacer-5)">
							<CloseIcon />
							<span className="sr-only">Close search</span>
						</DialogPrimitive.Close>
						<div className="mx-auto w-full max-w-(--newspack-ui-width-m) duration-(--newspack-ui-duration-modal) ease-(--newspack-ui-ease-out) group-data-[state=open]/search:animate-in group-data-[state=open]/search:slide-in-from-top-[length:var(--newspack-ui-spacer-3)] group-data-[state=closed]/search:animate-out group-data-[state=closed]/search:slide-out-to-top-[length:var(--newspack-ui-spacer-3)] motion-reduce:animate-none">
							<SearchField />
							<SearchResults />
						</div>
					</DialogPrimitive.Content>
				</DialogPrimitive.Overlay>
			</DialogPrimitive.Portal>
		</SearchDialog>
	);
}
