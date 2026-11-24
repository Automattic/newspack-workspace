"use client"

import { Fragment, createContext, use, useEffect, useEffectEvent, useMemo, useRef, useState, type ReactNode } from 'react';
import Link from 'fumadocs-core/link';
import { usePathname } from 'fumadocs-core/framework';
import { getBreadcrumbItemsFromPath } from 'fumadocs-core/breadcrumb';
import { Icon, chevronDown, chevronLeft, chevronRight, tableOfContents } from '@wordpress/icons';
import { useNotebookLayout } from 'fumadocs-ui/layouts/notebook';
import { TOCProvider, TOCScrollArea, useItems, useTOCItems } from 'fumadocs-ui/components/toc';
import { TOCEmpty, TOCItem, TOCItems } from 'fumadocs-ui/components/toc/default';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from 'fumadocs-ui/components/ui/collapsible';
import { useTreeContext, useTreePath } from 'fumadocs-ui/contexts/tree';
import { useFooterItems } from 'fumadocs-ui/utils/use-footer-items';
import { DOCS_SIDEBAR } from '@/lib/site';
import { cn } from '@/lib/utils';

/*
 * Copies of the notebook page slots (breadcrumb, footer, table of contents) that render
 * @wordpress/icons in place of Fumadocs' hard-coded lucide icons. Markup and classes otherwise
 * match fumadocs-ui 16.17 and 16.18; each WordPress icon is sized so its box takes the same space.
 */

function normalize( url: string ) {
	return url.length > 1 && url.endsWith( '/' ) ? url.slice( 0, -1 ) : url;
}

function Breadcrumb() {
	const path = useTreePath();
	const { root } = useTreeContext();
	const items = useMemo( () => getBreadcrumbItemsFromPath( root, path, {} ), [ path, root ] );
	if ( items.length === 0 ) {
		return null;
	}
	return (
		<div className="flex items-center gap-1.5 text-sm text-fd-muted-foreground">
			{ items.map( ( item, i ) => {
				const className = cn( 'truncate', i === items.length - 1 && 'text-fd-primary font-medium' );
				return (
					<Fragment key={ i }>
						{ i !== 0 && <Icon icon={ chevronRight } size={ 18 } className="-mx-0.5 shrink-0 fill-current rtl:rotate-180" /> }
						{ item.url ? (
							<Link href={ item.url } className={ cn( className, 'transition-opacity hover:opacity-80' ) }>
								{ item.name }
							</Link>
						) : (
							<span className={ className }>{ item.name }</span>
						) }
					</Fragment>
				);
			} ) }
		</div>
	);
}

type FooterItemType = { name: ReactNode; description?: ReactNode; url: string };

function FooterItem( { item, index }: { item: FooterItemType; index: 0 | 1 } ) {
	return (
		<Link
			href={ item.url }
			className={ cn(
				'flex flex-col gap-2 rounded-lg border p-4 text-sm transition-colors hover:bg-fd-accent/80 hover:text-fd-accent-foreground @max-lg:col-span-full',
				index === 1 && 'text-end'
			) }
		>
			<div className={ cn( 'inline-flex items-center gap-1.5 font-medium', index === 1 && 'flex-row-reverse' ) }>
				<Icon icon={ index === 0 ? chevronLeft : chevronRight } size={ 20 } className="-mx-1.5 shrink-0 fill-current rtl:rotate-180" />
				<p>{ item.name }</p>
			</div>
			<p className="truncate text-fd-muted-foreground">{ item.description ?? ( index === 0 ? 'Previous page' : 'Next page' ) }</p>
		</Link>
	);
}

function Footer() {
	const list = useFooterItems();
	const pathname = usePathname();
	const index = list.findIndex( ( item ) => normalize( item.url ) === normalize( pathname ) );
	const previous = index > 0 ? list[ index - 1 ] : undefined;
	const next = index !== -1 ? list[ index + 1 ] : undefined;
	return (
		<div className={ cn( '@container grid gap-4', previous && next ? 'grid-cols-2' : 'grid-cols-1' ) }>
			{ previous && <FooterItem item={ previous } index={ 0 } /> }
			{ next && <FooterItem item={ next } index={ 1 } /> }
		</div>
	);
}

function TOC() {
	const items = useTOCItems();
	const {
		props: { aiChat },
	} = useNotebookLayout();
	if ( items.length === 0 ) {
		return DOCS_SIDEBAR ? <div id="nd-toc-placeholder" className="hidden xl:layout:[--fd-toc-width:268px]" /> : null;
	}
	return (
		<div
			id="nd-toc"
			className={ cn(
				'sticky top-(--fd-docs-row-3) [grid-area:toc] h-[calc(var(--fd-docs-height)-var(--fd-docs-row-3))] flex flex-col w-(--fd-toc-width) pt-12 pe-4 pb-2 xl:layout:[--fd-toc-width:268px] max-xl:hidden',
				'transition-[opacity,visibility] duration-300',
				aiChat?.open && aiChat.panel && 'invisible opacity-0'
			) }
		>
			<h3 id="toc-title" className="inline-flex items-center gap-1.5 text-sm text-fd-muted-foreground">
				<Icon icon={ tableOfContents } size={ 20 } className="-mx-0.5 shrink-0 fill-current" />
				On this page
			</h3>
			<TOCScrollArea>
				<TOCItems>
					{ items.length === 0 && <TOCEmpty /> }
					{ items.map( ( item ) => (
						<TOCItem key={ item.url } item={ item } />
					) ) }
				</TOCItems>
			</TOCScrollArea>
		</div>
	);
}

const PopoverContext = createContext< { open: boolean } >( { open: false } );

function ProgressCircle( { value, className }: { value: number; className?: string } ) {
	const size = 18;
	const strokeWidth = 1.5;
	const radius = size / 2 - strokeWidth;
	const circumference = 2 * Math.PI * radius;
	const clamped = Math.min( Math.max( value, 0 ), 1 );
	const circle = { cx: size / 2, cy: size / 2, r: radius, fill: 'none', strokeWidth };
	return (
		<svg
			role="progressbar"
			viewBox={ `0 0 ${ size } ${ size }` }
			aria-valuenow={ clamped }
			aria-valuemin={ 0 }
			aria-valuemax={ 1 }
			style={ { width: size, height: size } }
			className={ className }
		>
			<circle { ...circle } className="stroke-current/25" />
			<circle
				{ ...circle }
				stroke="currentColor"
				strokeDasharray={ circumference }
				strokeDashoffset={ circumference - clamped * circumference }
				strokeLinecap="round"
				transform={ `rotate(-90 ${ size / 2 } ${ size / 2 })` }
				className="transition-all"
			/>
		</svg>
	);
}

function TOCPopoverTrigger() {
	const { open } = use( PopoverContext );
	const items = useItems();
	const selected = items.findIndex( ( item ) => item.active );
	const path = useTreePath().at( -1 );
	const showItem = selected !== -1 && ! open;
	return (
		<CollapsibleTrigger
			data-toc-popover-trigger=""
			className={ cn(
				'flex w-full h-10 items-center text-sm text-fd-muted-foreground gap-2.5 px-4 py-2.5 text-start focus-visible:outline-none md:px-6',
				! DOCS_SIDEBAR && 'px-0 md:px-0'
			) }
		>
			<ProgressCircle
				value={ ( items.findLastIndex( ( item ) => item.active ) + 1 ) / Math.max( 1, items.length ) }
				className={ cn( 'shrink-0', open && 'text-fd-primary' ) }
			/>
			<span className="grid flex-1 *:my-auto *:row-start-1 *:col-start-1">
				<span
					className={ cn(
						'truncate transition-[opacity,translate,color]',
						open && 'text-fd-foreground',
						showItem && 'opacity-0 -translate-y-full pointer-events-none'
					) }
				>
					{ path?.name ?? 'On this page' }
				</span>
				<span className={ cn( 'truncate transition-[opacity,translate]', ! showItem && 'opacity-0 translate-y-full pointer-events-none' ) }>
					{ items[ selected ]?.original.title }
				</span>
			</span>
			<Icon icon={ chevronDown } size={ 20 } className={ cn( 'shrink-0 fill-current transition-transform', open && 'rotate-180' ) } />
		</CollapsibleTrigger>
	);
}

function TOCPopover() {
	const items = useTOCItems();
	const ref = useRef< HTMLElement >( null );
	const [ open, setOpen ] = useState( false );
	const { isNavTransparent } = useNotebookLayout();

	const onClickOutside = useEffectEvent( ( event: MouseEvent ) => {
		if ( ! open || ! ( event.target instanceof HTMLElement ) ) {
			return;
		}
		if ( ref.current && ! ref.current.contains( event.target ) ) {
			setOpen( false );
		}
	} );

	useEffect( () => {
		window.addEventListener( 'click', onClickOutside );
		return () => window.removeEventListener( 'click', onClickOutside );
	}, [] );

	return (
		<PopoverContext value={ useMemo( () => ( { open } ), [ open ] ) }>
			<Collapsible
				open={ open }
				onOpenChange={ setOpen }
				data-toc-popover=""
				className="sticky top-(--fd-docs-row-2) z-10 [grid-area:toc-popover] h-(--fd-toc-popover-height) xl:hidden max-xl:layout:[--fd-toc-popover-height:--spacing(10)]"
			>
				<header
					ref={ ref }
					className={ cn( 'border-b backdrop-blur-sm transition-colors', ( ! isNavTransparent || open ) && 'bg-fd-background/80', open && 'shadow-lg' ) }
				>
					<TOCPopoverTrigger />
					<CollapsibleContent data-toc-popover-content="">
						<div className={ cn( 'flex flex-col px-4 max-h-[50vh] md:px-6', ! DOCS_SIDEBAR && 'px-0 md:px-0' ) }>
							<TOCScrollArea>
								<TOCItems>
									{ items.length === 0 && <TOCEmpty /> }
									{ items.map( ( item ) => (
										<TOCItem key={ item.url } item={ item } onClick={ () => setOpen( false ) } />
									) ) }
								</TOCItems>
							</TOCScrollArea>
						</div>
					</CollapsibleContent>
				</header>
			</Collapsible>
		</PopoverContext>
	);
}

export const PAGE_SLOTS = {
	breadcrumb: Breadcrumb,
	footer: Footer,
	toc: { provider: TOCProvider, main: TOC, popover: TOCPopover },
};
