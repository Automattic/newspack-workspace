import type { ReactNode } from 'react';
import type { Root } from 'fumadocs-core/page-tree';
import type { AstroProviderProps } from 'fumadocs-core/framework/astro';
import type { DocsPageProps } from 'fumadocs-ui/layouts/notebook/page';
import { RootProvider } from 'fumadocs-ui/provider/astro';
import type { ThemeToggleHandler } from '@/components/site/theme-toggle';
import { DocsSiteHeader } from './docs-header';
import { DocsShell } from './docs-shell';
import SearchDialog from './search-dialog';

const THEME = { enabled: false } as const;
const SEARCH = { SearchDialog } as const;
const NO_PARAMS: NonNullable< AstroProviderProps[ 'params' ] > = {};

type Navigate = NonNullable< AstroProviderProps[ 'navigate' ] >;

/**
 * Client navigation for search results and other links Fumadocs opens itself. On a page with
 * Astro's `<ClientRouter />` it hands the URL to the router's `navigate`, so the page is swapped
 * instead of reloaded; anywhere else it is a plain page load. The router module is only loaded
 * when the page uses it.
 */
const astroNavigate: Navigate = ( url ) => {
	if ( document.querySelector( 'meta[name="astro-view-transitions-enabled"]' ) ) {
		return import( 'astro:transitions/client' ).then( ( router ) => router.navigate( url ) );
	}
	window.location.assign( url );
};

/**
 * The docs page island for Astro: `<AstroDocs tree pathname params page client:load>`.
 *
 * - `navigate`: how Fumadocs opens a URL, such as `navigate` from `astro:transitions/client`
 *   passed by a React wrapper. Without it, the island uses Astro's router when the page has
 *   `<ClientRouter />` and a page load otherwise, so a page in an `.astro` file needs nothing.
 * - `onThemeToggle`: runs in place of the theme toggle's own switch, as on `SiteHeader`. A
 *   function cannot be passed from an `.astro` file, so pass it from a React wrapper.
 */
export function AstroDocs( {
	tree,
	pathname,
	params,
	page,
	wordmark,
	navigate = astroNavigate,
	onThemeToggle,
	children,
}: {
	tree: Root;
	pathname: string;
	params: AstroProviderProps[ 'params' ];
	page?: DocsPageProps;
	wordmark?: ReactNode;
	navigate?: Navigate;
	onThemeToggle?: ThemeToggleHandler;
	children: ReactNode;
} ) {
	return (
		<RootProvider pathname={ pathname } params={ params } navigate={ navigate } theme={ THEME } search={ SEARCH }>
			<DocsShell tree={ tree } pathname={ pathname } page={ page } wordmark={ wordmark } onThemeToggle={ onThemeToggle }>
				{ children }
			</DocsShell>
		</RootProvider>
	);
}

/**
 * The header island for an Astro page outside the docs on a site with docs, so the Search
 * button, Ctrl or Cmd+K and the inline drawer search work there too. `navigate` and
 * `onThemeToggle` work as on `AstroDocs`; `skipTo` as on `SiteHeader`.
 */
export function AstroDocsSiteHeader( {
	pathname,
	params = NO_PARAMS,
	wordmark,
	navigate = astroNavigate,
	onThemeToggle,
	skipTo,
}: {
	pathname: string;
	params?: AstroProviderProps[ 'params' ];
	wordmark?: ReactNode;
	navigate?: Navigate;
	onThemeToggle?: ThemeToggleHandler;
	skipTo?: string | false;
} ) {
	return (
		<RootProvider pathname={ pathname } params={ params } navigate={ navigate } theme={ THEME } search={ SEARCH }>
			<DocsSiteHeader pathname={ pathname } wordmark={ wordmark } onThemeToggle={ onThemeToggle } skipTo={ skipTo } />
		</RootProvider>
	);
}
