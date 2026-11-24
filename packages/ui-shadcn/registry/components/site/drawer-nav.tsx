import type { CSSProperties } from 'react';
import { chevronDown, Icon } from '@wordpress/icons';
import { NAV_LINKS, type NavLink } from '@/lib/site';
import { cn } from '@/lib/utils';

/**
 * Class strings for mobile menu rows and the menu close button. They match Fumadocs' notebook
 * sidebar items with its colors read from newspack-ui tokens, so the site Sheet and the Fumadocs
 * docs drawer look the same.
 */
export const DRAWER_ITEM_BASE =
	'relative flex flex-row items-center gap-2 rounded-lg p-2 text-start text-(color:--newspack-ui-color-text-muted) wrap-anywhere [&_svg]:size-4 [&_svg]:shrink-0';
export const DRAWER_ITEM_HOVER =
	'transition-colors hover:bg-(color:--newspack-ui-color-neutral-fill)/50 hover:text-(color:--newspack-ui-color-text)/80 hover:transition-none';
export const DRAWER_ITEM = `${ DRAWER_ITEM_BASE } ${ DRAWER_ITEM_HOVER } data-[active=true]:bg-(color:--newspack-ui-color-accent)/10 data-[active=true]:text-(color:--newspack-ui-color-accent) data-[active=true]:hover:transition-colors`;
export const DRAWER_CLOSE =
	'inline-flex items-center justify-center rounded-md text-sm font-medium transition-colors duration-100 disabled:pointer-events-none disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-(color:--newspack-ui-color-focus) hover:bg-(color:--newspack-ui-color-neutral-fill) hover:text-(color:--newspack-ui-color-text) p-1.5 [&_svg]:size-4.5 ms-auto text-(color:--newspack-ui-color-text-muted)';

/** Fumadocs' inline-start offset for an item at a folder depth. */
export function drawerOffset( depth: number ): CSSProperties {
	return { paddingInlineStart: `calc(${ 2 + 3 * depth } * var(--spacing))` };
}

export function FolderChevron( { open }: { open: boolean } ) {
	return (
		<Icon
			icon={ chevronDown }
			size={ 20 }
			data-icon=""
			aria-hidden="true"
			style={ { width: 20, height: 20 } }
			className={ cn( 'ms-auto fill-current transition-transform', ! open && '-rotate-90 rtl:rotate-90' ) }
		/>
	);
}

/** The site links shown in the mobile menu. Links marked `drawer: false` are sections of the docs tree. */
export const DRAWER_LINKS = NAV_LINKS.filter( ( link ) => link.drawer !== false );

function withSlash( path: string ) {
	return path.replace( /\/?$/, '/' );
}

/** The link whose `section` is the longest prefix of `pathname`. `'/'` matches only the home page. */
export function currentLink( pathname: string, links: NavLink[] = NAV_LINKS ): NavLink | undefined {
	const path = withSlash( pathname );
	let match: NavLink | undefined;
	for ( const link of links ) {
		if ( link.external || ! link.section ) {
			continue;
		}
		const section = withSlash( link.section );
		const hit = section === '/' ? path === '/' : path.startsWith( section );
		if ( hit && ( ! match || section.length > withSlash( match.section! ).length ) ) {
			match = link;
		}
	}
	return match;
}

export function linkRel( link: NavLink ) {
	return link.external ? 'noopener' : undefined;
}
