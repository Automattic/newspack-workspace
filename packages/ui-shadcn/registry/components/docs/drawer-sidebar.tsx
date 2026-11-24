"use client"

import { useMemo, useRef, type ReactNode } from 'react';
import Link from 'fumadocs-core/link';
import { usePathname } from 'fumadocs-core/framework';
import type * as PageTree from 'fumadocs-core/page-tree';
import { TreeContextProvider, useTreeContext, useTreePath } from 'fumadocs-ui/contexts/tree';
import { CollapsibleTrigger } from 'fumadocs-ui/components/ui/collapsible';
import {
	SidebarFolder,
	SidebarFolderContent,
	SidebarSeparator,
	useAutoScroll,
	useFolder,
	useFolderDepth,
	useSidebar as useSidebarBase,
} from 'fumadocs-ui/components/sidebar/base';
import {
	Sidebar,
	SidebarCollapseTrigger,
	SidebarProvider,
	SidebarTrigger,
	useSidebar,
	type SidebarProps,
} from 'fumadocs-ui/layouts/notebook/slots/sidebar';
import { cn } from '@/lib/utils';
import { DOCS_SIDEBAR } from '@/lib/site';
import { CloseIcon } from '@/lib/newspack-ui-icons';
import { DRAWER_CLOSE, DRAWER_ITEM, DRAWER_ITEM_BASE, DRAWER_ITEM_HOVER, DRAWER_LINKS, FolderChevron, drawerOffset } from '@/components/site/drawer-nav';

function normalize( url: string ) {
	return url.length > 1 && url.endsWith( '/' ) ? url.slice( 0, -1 ) : url;
}

const INDEX_HIGHLIGHT =
	"data-[active=true]:before:content-[''] data-[active=true]:before:absolute data-[active=true]:before:w-px data-[active=true]:before:inset-y-2.5 data-[active=true]:before:inset-s-2.5 data-[active=true]:before:bg-(--newspack-ui-color-accent)";

function hasPage( nodes: PageTree.Node[], url: string ): boolean {
	return nodes.some( ( node ) =>
		node.type === 'page'
			? normalize( node.url ) === url
			: node.type === 'folder' && ( ( !! node.index && normalize( node.index.url ) === url ) || hasPage( node.children, url ) )
	);
}

function DrawerFolderLink( { item, index }: { item: PageTree.Folder; index: PageTree.Item } ) {
	const { open, setOpen, depth, collapsible } = useFolder()!;
	const { prefetch } = useSidebarBase();
	const pathname = usePathname();
	const ref = useRef< HTMLAnchorElement >( null );
	const url = normalize( index.url );
	const active = url === normalize( pathname ) && ! hasPage( item.children, url );
	useAutoScroll( active, ref );

	return (
		<Link
			ref={ ref }
			href={ index.url }
			external={ index.external }
			prefetch={ prefetch }
			data-active={ active }
			className={ cn( DRAWER_ITEM, depth > 1 && INDEX_HIGHLIGHT, 'w-full' ) }
			style={ drawerOffset( depth - 1 ) }
			onClick={ ( event ) => {
				if ( ! collapsible ) {
					return;
				}
				if ( event.target instanceof Element && event.target.matches( '[data-icon], [data-icon] *' ) ) {
					setOpen( ! open );
					event.preventDefault();
				} else {
					setOpen( active ? ! open : true );
				}
			} }
		>
			{ item.icon }
			{ item.name }
			{ collapsible && <FolderChevron open={ open } /> }
		</Link>
	);
}

function DrawerSeparator( { item }: { item: PageTree.Separator } ) {
	const depth = useFolderDepth();
	return (
		<SidebarSeparator
			className="inline-flex items-center gap-2 mb-1.5 px-2 mt-6 first:mt-0 empty:mb-0 [&_svg]:size-4 [&_svg]:shrink-0"
			style={ drawerOffset( depth ) }
		>
			{ item.icon }
			{ item.name }
		</SidebarSeparator>
	);
}

function DrawerFolderHead( { item }: { item: PageTree.Folder } ) {
	const { open, depth, collapsible } = useFolder()!;
	const style = drawerOffset( depth - 1 );

	if ( item.index ) {
		return <DrawerFolderLink item={ item } index={ item.index } />;
	}

	if ( ! collapsible ) {
		return (
			<div className={ cn( DRAWER_ITEM_BASE, 'w-full' ) } style={ style }>
				{ item.icon }
				{ item.name }
			</div>
		);
	}

	return (
		<CollapsibleTrigger className={ cn( DRAWER_ITEM_BASE, DRAWER_ITEM_HOVER, 'w-full' ) } style={ style }>
			{ item.icon }
			{ item.name }
			<FolderChevron open={ open } />
		</CollapsibleTrigger>
	);
}

function DrawerFolderBody( { children }: { children: ReactNode } ) {
	const depth = useFolderDepth();
	return (
		<SidebarFolderContent
			className={ cn(
				'relative',
				depth === 1 && "before:content-[''] before:absolute before:w-px before:inset-y-1 before:bg-(--newspack-ui-color-border) before:inset-s-2.5"
			) }
		>
			<div className="flex flex-col gap-0.5 pt-0.5">{ children }</div>
		</SidebarFolderContent>
	);
}

function DrawerFolder( { item, children }: { item: PageTree.Folder; children: ReactNode } ) {
	const path = useTreePath();
	return (
		<SidebarFolder collapsible={ item.collapsible } active={ path.includes( item ) } defaultOpen={ item.defaultOpen }>
			<DrawerFolderHead item={ item } />
			<DrawerFolderBody>{ children }</DrawerFolderBody>
		</SidebarFolder>
	);
}

const DRAWER_COMPONENTS = { Folder: DrawerFolder, Separator: DrawerSeparator };

/**
 * The page tree the docs drawer renders: the site links at the top level as plain rows, with the
 * docs tree nested as an open folder under the link marked `drawer: 'tree'`. That folder's row
 * links to the link's `href` and keeps its own toggle. It is active only on that page, and only
 * when the docs tree has no page of its own there (such as an Introduction at `/docs/`), so one
 * row is ever active.
 */
export function drawerTree( root: PageTree.Root ): PageTree.Root {
	return {
		...root,
		$id: `drawer:${ root.$id ?? 'root' }`,
		fallback: undefined,
		children: DRAWER_LINKS.map( ( link ): PageTree.Node => {
			const page: PageTree.Item = { type: 'page', $id: `drawer:${ link.href }`, name: link.text, url: link.href, external: false };
			return link.drawer === 'tree'
				? { type: 'folder', $id: `drawer:folder:${ link.href }`, name: link.text, defaultOpen: true, index: page, children: root.children }
				: page;
		} ),
	};
}

/**
 * The notebook sidebar. Both modes render folders and separators with the components above, so
 * no lucide chevron is drawn. In drawer mode it renders the site links with the docs tree nested
 * under the `drawer: 'tree'` link, from `drawerTree()`. With `DOCS_SIDEBAR` off, the desktop
 * sidebar is not rendered, so the layout gives its column to the page.
 */
function DocsSidebar( props: SidebarProps ) {
	const { mode } = useSidebar();
	const { full } = useTreeContext();
	const tree = useMemo( () => drawerTree( full ), [ full ] );

	if ( mode !== 'drawer' ) {
		return DOCS_SIDEBAR ? <Sidebar { ...props } components={ DRAWER_COMPONENTS } /> : null;
	}
	return (
		<TreeContextProvider tree={ tree }>
			<Sidebar { ...props } components={ DRAWER_COMPONENTS } />
		</TreeContextProvider>
	);
}

/** Replaces Fumadocs' lucide close icon with the newspack-ui one, keeping its trigger and label. */
export function DrawerClose() {
	return (
		<SidebarTrigger className={ DRAWER_CLOSE }>
			<CloseIcon />
		</SidebarTrigger>
	);
}

export const DRAWER_SIDEBAR_SLOT = {
	provider: SidebarProvider,
	root: DocsSidebar,
	trigger: SidebarTrigger,
	collapseTrigger: SidebarCollapseTrigger,
	useSidebar,
};
