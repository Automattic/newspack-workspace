"use client"

import { Children, useEffect, useState, type ReactNode } from 'react';
import { Icon, menu } from '@wordpress/icons';
import { Button } from '@/components/ui/button';
import { Sheet, SheetClose, SheetContent, SheetDescription, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { CloseIcon } from '@/lib/newspack-ui-icons';
import { NAV_LINKS, SITE_NAME } from '@/lib/site';
import { useMobileMenu } from '@/lib/theme';
import { cn } from '@/lib/utils';
import { DRAWER_CLOSE, DRAWER_ITEM, DRAWER_LINKS, FolderChevron, currentLink, linkRel } from './drawer-nav';
import { SkipLink } from './skip-link';
import { ThemeToggle, type ThemeToggleHandler } from './theme-toggle';

const NAV_LINK =
	'inline-flex min-h-(--newspack-ui-spacer-7) items-center rounded-(--newspack-ui-radius-control) px-(--newspack-ui-spacer-1) text-(length:--newspack-ui-font-size-small) font-(weight:--newspack-ui-font-weight-medium) text-(color:--newspack-ui-color-text-muted) no-underline transition-colors duration-(--newspack-ui-duration-color) ease-(--newspack-ui-ease-color) hover:bg-(color:--newspack-ui-color-neutral-fill) hover:text-(color:--newspack-ui-color-text) aria-[current=page]:text-(color:--newspack-ui-color-text) aria-[current=page]:underline aria-[current=page]:decoration-(color:--newspack-ui-color-accent) aria-[current=page]:decoration-(length:--site-nav-underline) aria-[current=page]:underline-offset-(--newspack-ui-spacer-1)';

const WORDMARK =
	'inline-flex min-w-0 items-center gap-(--newspack-ui-spacer-1) text-(length:--newspack-ui-font-size-body) font-(weight:--newspack-ui-font-weight-strong) tracking-(--newspack-ui-letter-spacing-title-m) whitespace-nowrap text-(color:--newspack-ui-color-text)';

export function MenuIcon() {
	return (
		<>
			<Icon icon={ menu } />
			<span className="sr-only">Menu</span>
		</>
	);
}

function SiteMenu( { pathname, drawerSearch }: { pathname: string; drawerSearch?: ( close: () => void ) => ReactNode } ) {
	const [ open, setOpen ] = useState( false );
	const mobile = useMobileMenu();
	const current = currentLink( pathname );

	useEffect( () => {
		if ( ! mobile ) {
			setOpen( false );
		}
	}, [ mobile ] );

	return (
		<Sheet open={ open } onOpenChange={ setOpen }>
			<SheetTrigger asChild>
				<Button type="button" variant="ghost" size="icon-sm" className="md:hidden">
					<MenuIcon />
				</Button>
			</SheetTrigger>
			<SheetContent
				showCloseButton={ false }
				className="group/menu gap-0 overflow-hidden text-[0.9375rem]"
				onOpenAutoFocus={ ( event ) => {
					const close = event.currentTarget instanceof HTMLElement ? event.currentTarget.querySelector( 'button' ) : null;
					if ( close ) {
						event.preventDefault();
						close.focus();
					}
				} }
			>
				<SheetTitle className="sr-only">Menu</SheetTitle>
				<SheetDescription className="sr-only">Site navigation</SheetDescription>
				<div className="flex flex-col gap-3 p-4 pb-2 has-data-querying:min-h-0 has-data-querying:flex-1">
					<SheetClose className={ DRAWER_CLOSE }>
						<CloseIcon />
						<span className="sr-only">Close</span>
					</SheetClose>
					{ drawerSearch?.( () => setOpen( false ) ) }
				</div>
				<nav
					className="flex min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto overscroll-contain p-4 group-has-data-querying/menu:hidden"
					aria-label="Site menu"
				>
					{ DRAWER_LINKS.map( ( link ) => {
						const active = link.drawer !== 'tree' && link === current;
						return (
							<a
								key={ link.href }
								href={ link.href }
								rel={ linkRel( link ) }
								className={ link.drawer === 'tree' ? `${ DRAWER_ITEM } w-full` : DRAWER_ITEM }
								data-active={ active }
								aria-current={ active ? 'page' : undefined }
							>
								{ link.text }
								{ link.drawer === 'tree' && <FolderChevron open={ false } /> }
							</a>
						);
					} ) }
				</nav>
			</SheetContent>
		</Sheet>
	);
}

/**
 * The site header: wordmark, main nav, theme toggle and, below `MOBILE_MENU_QUERY`, a menu
 * button that opens a Sheet from the right with the same links.
 *
 * - `wordmark`: replaces the site name in the home link. The link keeps `SITE_NAME` as its name.
 *   It sits in a centered flex row with a spacer-1 gap, so a mark and a name can be passed side
 *   by side. Text passed as a string ends in an ellipsis when it is too long; give a text element
 *   of your own `min-w-0 truncate` for the same.
 * - `wordmarkLink`: `true` by default, so the wordmark is the link home. With `false` it is a
 *   plain row with no link, and the wordmark brings its own, so it can hold an interactive island
 *   (a link inside a link is invalid, and the home link's name would hide it from screen readers).
 *   Without a `wordmark` the site name keeps its home link whatever this is set to.
 * - `search`: a search trigger shown from `md` up, such as the docs search button.
 * - `drawerSearch`: rendered at the top of the Sheet; call `close` when a result is followed.
 * - `menuButton`: replaces the Sheet and its button, such as the Fumadocs docs drawer button.
 * - `onThemeToggle`: passed to the theme toggle as `onToggle`, to apply the theme the site's
 *   own way.
 * - `skipTo`: the target of the "Skip to content" link rendered before the header, `#main` by
 *   default; `false` leaves it out, as the docs layout does, which renders its own.
 */
export function SiteHeader( {
	pathname,
	wordmark,
	wordmarkLink = true,
	search,
	drawerSearch,
	menuButton,
	onThemeToggle,
	skipTo = '#main',
	className,
}: {
	pathname: string;
	wordmark?: ReactNode;
	wordmarkLink?: boolean;
	search?: ReactNode;
	drawerSearch?: ( close: () => void ) => ReactNode;
	menuButton?: ReactNode;
	onThemeToggle?: ThemeToggleHandler;
	skipTo?: string | false;
	className?: string;
} ) {
	const mobile = useMobileMenu();
	const current = currentLink( pathname );
	const wordmarkRow = (
		<span className="flex min-w-0 items-center gap-(--newspack-ui-spacer-1)">
			{ Children.map( wordmark ?? SITE_NAME, ( child ) =>
				typeof child === 'string' || typeof child === 'number' ? <span className="min-w-0 truncate">{ child }</span> : child
			) }
		</span>
	);
	return (
		<>
			{ skipTo !== false && <SkipLink href={ skipTo } /> }
			<header
				className={ cn(
					'sticky top-0 z-40 h-(--site-header-height) bg-(--newspack-ui-color-surface) shadow-[inset_0_-1px_0_var(--newspack-ui-color-border)]',
					className
				) }
			>
				<div className="mx-auto box-content flex h-full max-w-(--site-wide-width) items-center justify-between gap-(--newspack-ui-spacer-2) px-(--newspack-ui-spacer-3) min-[782px]:px-(--newspack-ui-spacer-6)">
					{ wordmarkLink || ! wordmark ? (
						<a href="/" aria-label={ wordmark ? SITE_NAME : undefined } className={ cn( WORDMARK, 'rounded-(--newspack-ui-radius-control) no-underline' ) }>
							{ wordmarkRow }
						</a>
					) : (
						<div className={ WORDMARK }>{ wordmarkRow }</div>
					) }
					<div className="flex shrink-0 items-center gap-(--newspack-ui-spacer-half)">
						<nav aria-label="Main" className="me-(--newspack-ui-spacer-1) max-md:hidden">
							<ul className="m-0 flex list-none gap-(--newspack-ui-spacer-half) p-0">
								{ NAV_LINKS.map( ( link ) => (
									<li key={ link.href }>
										<a
											href={ link.href }
											rel={ linkRel( link ) }
											aria-current={ link === current ? 'page' : undefined }
											className={ NAV_LINK }
										>
											{ link.text }
										</a>
									</li>
								) ) }
							</ul>
						</nav>
						{ search }
						{ ! mobile && <ThemeToggle onToggle={ onThemeToggle } /> }
						{ menuButton ?? <SiteMenu pathname={ pathname } drawerSearch={ drawerSearch } /> }
					</div>
				</div>
			</header>
		</>
	);
}
