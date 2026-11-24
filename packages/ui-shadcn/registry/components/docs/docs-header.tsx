"use client"

import type { ReactNode } from 'react';
import { Icon, search } from '@wordpress/icons';
import { useSearchContext } from 'fumadocs-ui/contexts/search';
import { useSidebar } from 'fumadocs-ui/layouts/notebook/slots/sidebar';
import { Button } from '@/components/ui/button';
import { MenuIcon, SiteHeader } from '@/components/site/site-header';
import type { ThemeToggleHandler } from '@/components/site/theme-toggle';
import { DrawerSearch } from './drawer-search';

export function SearchButton() {
	const { setOpenSearch } = useSearchContext();
	return (
		<Button
			type="button"
			variant="outline"
			size="sm"
			className="px-[calc(var(--newspack-ui-spacer-1)*1.75)] max-md:hidden"
			onClick={ () => setOpenSearch( true ) }
		>
			<Icon icon={ search } />
			Search
		</Button>
	);
}

function DocsMenuButton() {
	const { open, setOpen } = useSidebar();
	return (
		<Button
			type="button"
			variant="ghost"
			size="icon-sm"
			className="md:hidden"
			aria-expanded={ open }
			aria-controls="nd-sidebar-mobile"
			onClick={ () => setOpen( ! open ) }
		>
			<MenuIcon />
		</Button>
	);
}

/**
 * The site header with the docs search: the Search button opens the Fumadocs search dialog,
 * and the mobile Sheet searches inline. With `docs`, the menu button opens the Fumadocs docs
 * drawer instead of the Sheet, and the header leaves the skip link to the docs layout. Render it
 * inside a Fumadocs `RootProvider`, and with `docs` inside the notebook `DocsLayout`.
 * `onThemeToggle` and `skipTo` work as on `SiteHeader`.
 */
export function DocsSiteHeader( {
	pathname,
	docs = false,
	wordmark,
	onThemeToggle,
	skipTo,
}: {
	pathname: string;
	docs?: boolean;
	wordmark?: ReactNode;
	onThemeToggle?: ThemeToggleHandler;
	skipTo?: string | false;
} ) {
	return (
		<SiteHeader
			pathname={ pathname }
			wordmark={ wordmark }
			onThemeToggle={ onThemeToggle }
			skipTo={ docs ? false : skipTo }
			search={ <SearchButton /> }
			drawerSearch={ ( close ) => <DrawerSearch onNavigate={ close } /> }
			menuButton={ docs ? <DocsMenuButton /> : undefined }
			className={ docs ? '[grid-area:1/1/2/-1] [contain:inline-size]' : undefined }
		/>
	);
}
