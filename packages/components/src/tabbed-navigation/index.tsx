/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { Tabs } from '@wordpress/ui';

/**
 * External dependencies.
 */
import classnames from 'classnames';
import type { MouseEvent, ReactNode } from 'react';
import Router from '../proxied-imports/router';

/**
 * Internal dependencies.
 */
import { matchesRoute } from '../route-match';
import type { RouteMatchItem } from '../route-match';
import type { BreadcrumbItem } from '../breadcrumbs';
import './style.scss';

const { useHistory, useLocation } = Router;

/**
 * A tabbed-navigation item: a router tab (`path`), a plain link (`href`), or both.
 */
export interface TabbedNavigationItem extends RouteMatchItem {
	/** The tab label. */
	label?: ReactNode;
	/** Full URL for link-style items (no in-page panel). */
	href?: string;
	/** Force the item active regardless of the route. */
	selected?: boolean;
	/** Hide the item from the tabs widget while keeping its route/panel. */
	isHiddenInTabbedNavigation?: boolean;
	/** The item's explicit breadcrumb trail, used by wizard header selection. */
	breadcrumbs?: BreadcrumbItem[];
}

export interface TabbedNavigationProps {
	/** Navigation items. */
	items: TabbedNavigationItem[];
	/** Disable tabs after the active one (setup flows). */
	disableUpcoming?: boolean;
	/** Page content, rendered inside the active tab's panel — or as a sibling when no visible tab owns the route. */
	content?: ReactNode;
	/** Layout callback `( bar, content ) => element`, injected by `Page`. */
	renderShell?: ( bar: ReactNode, content: ReactNode ) => ReactNode;
	/** Additional CSS class name. */
	className?: string;
	/** Extra elements rendered inside the bar (e.g. error notices). */
	children?: ReactNode;
}

const getItemValue = ( item: TabbedNavigationItem, index: number ): string => item.path || item.href || `item-${ index }`;

export const isItemActive = ( item: TabbedNavigationItem, pathname: string | null ): boolean => {
	if ( item.selected ) {
		return true;
	}
	if ( null === pathname ) {
		return Boolean( item.href ) && window.location.href === item.href;
	}
	return matchesRoute( item, pathname );
};

/**
 * Default layout when `Page` doesn't inject one: bar above content.
 */
const defaultRenderShell = ( bar: ReactNode, content: ReactNode ) => (
	<>
		{ bar }
		{ content }
	</>
);

/**
 * Router-driven tabs: a real WAI-ARIA tabs widget. The routed page content
 * renders inside the active tab's `Tabs.Panel`, so each tab's `aria-controls`
 * points at the panel that actually contains its content — or, when no visible
 * tab owns the route, as a sibling of the panels (see `unownedContent` below).
 */
const RoutedTabbedNavigation = ( {
	items,
	className,
	disableUpcoming,
	content = null,
	renderShell = defaultRenderShell,
	children = null,
}: TabbedNavigationProps ) => {
	const history = useHistory();
	const { pathname } = useLocation();
	const displayedItems = items.filter( item => ! item.isHiddenInTabbedNavigation );

	// Most-specific match wins, so a tab is never outranked by a sibling that
	// happens to prefix-match the same pathname.
	const activeIndex = displayedItems.reduce( ( best, item, index ) => {
		if ( ! isItemActive( item, pathname ) ) {
			return best;
		}
		if ( best === -1 || ( item.path?.length || 0 ) > ( displayedItems[ best ].path?.length || 0 ) ) {
			return index;
		}
		return best;
	}, -1 );
	const activeValue = activeIndex > -1 ? getItemValue( displayedItems[ activeIndex ], activeIndex ) : null;

	const onTabClick = ( event: MouseEvent, item: TabbedNavigationItem ) => {
		// Modified clicks (new tab/window) keep native anchor behavior. Middle
		// clicks never reach onClick (they dispatch auxclick), so the anchor's
		// href handles those natively too.
		if ( event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return;
		}
		// Route through the history object so navigation guards registered via
		// history.block() (unsaved-changes prompts) are consulted.
		event.preventDefault();
		if ( item.path && item.path !== pathname ) {
			history.push( item.path );
		}
	};

	const bar = (
		<div className={ classnames( 'newspack-tabbed-navigation', className ) }>
			<Tabs.List activateOnFocus={ false }>
				{ displayedItems.map( ( item, index ) => {
					const isDisabled = disableUpcoming && index > activeIndex;
					const href = item.path ? `#${ item.path }` : item.href;
					return (
						<Tabs.Tab
							key={ getItemValue( item, index ) }
							value={ getItemValue( item, index ) }
							disabled={ isDisabled }
							nativeButton={ false }
							render={
								// eslint-disable-next-line jsx-a11y/anchor-has-content, jsx-a11y/anchor-is-valid -- content is supplied via the Tab children through @wordpress/ui's render prop, and disabled tabs intentionally drop the href.
								<a
									href={ isDisabled ? undefined : href }
									onClick={ isDisabled || ! item.path ? undefined : event => onTabClick( event, item ) }
								/>
							}
						>
							{ item.label }
						</Tabs.Tab>
					);
				} ) }
			</Tabs.List>
			{ children }
		</div>
	);

	// Panels must stay 1:1 with the tabs above (@wordpress/ui validates the
	// pairing in development builds); only the active panel holds the content.
	const panels = displayedItems.map( ( item, index ) => {
		const value = getItemValue( item, index );
		return (
			<Tabs.Panel key={ value } value={ value } tabIndex={ -1 } className="newspack-tabbed-navigation__panel">
				{ value === activeValue ? content : null }
			</Tabs.Panel>
		);
	} );

	// A route no visible tab owns — a hidden sub-view (an edit screen), or a path
	// with no matching tab at all — still has to render its content, outside the
	// panels: with no active tab there is no panel entitled to hold it. Without
	// this the page body is blank, and because the router's own fallback (the
	// wizard's trailing `<Redirect>`) lives inside that content, it never mounts
	// to correct the route either.
	//
	// This renders without the panels' `__panel` class, which is styleless today.
	// Keep it that way: styling `__panel` would silently indent/pad owned routes
	// only, and the divergence would be hard to trace back to here.
	const unownedContent = null === activeValue ? content : null;

	return (
		<Tabs.Root value={ activeValue } className="newspack-tabbed-navigation__root">
			{ renderShell(
				bar,
				<>
					{ panels }
					{ unownedContent }
				</>
			) }
		</Tabs.Root>
	);
};

/**
 * Link-driven "tabs": plain navigation for items that trigger full page loads
 * (e.g. the PHP-rendered wizards admin header). These are not tabs in the ARIA
 * sense — there is no in-page panel to control — so they render as a `nav` of
 * links with `aria-current`, styled to match the tabs widget.
 */
const LinkTabbedNavigation = ( { items, className, content = null, renderShell = defaultRenderShell, children = null }: TabbedNavigationProps ) => {
	const displayedItems = items.filter( item => ! item.isHiddenInTabbedNavigation );
	const bar = (
		<nav
			className={ classnames( 'newspack-tabbed-navigation', 'newspack-tabbed-navigation--links', className ) }
			aria-label={ __( 'Secondary navigation', 'newspack-plugin' ) }
		>
			{ displayedItems.map( ( item, index ) => (
				<a
					key={ getItemValue( item, index ) }
					href={ item.href }
					className="newspack-tabbed-navigation__link"
					aria-current={ isItemActive( item, null ) ? 'page' : undefined }
				>
					{ item.label }
				</a>
			) ) }
			{ children }
		</nav>
	);
	return renderShell( bar, content );
};

/**
 * Tabbed navigation over `{ label, path? | href?, exact?, activeTabPaths?, selected?, isHiddenInTabbedNavigation? }` items.
 *
 * Items with router `path`s render as a real tabs widget whose active panel
 * contains `content`; href-only items render as plain navigation links.
 *
 * When no visible tab owns the current route — a hidden sub-view, or an unknown
 * URL — `content` renders as a sibling of the panels with no tab selected. That
 * is a safety net, not the preferred shape: for a route that is conceptually a
 * sub-view of a tab, give that tab an `activeTabPaths` entry (e.g.
 * `activeTabPaths: [ '/edit/*' ]`) so it stays selected and its panel keeps
 * owning the content.
 *
 * `renderShell( bar, content )` is a layout injection point used by `Page` to
 * place the bar inside its sticky header region while the content flows below.
 */
const TabbedNavigation = ( props: TabbedNavigationProps ) => {
	const hasRoutedItems = props.items.some( item => item.path );
	return hasRoutedItems ? <RoutedTabbedNavigation { ...props } /> : <LinkTabbedNavigation { ...props } />;
};

export default TabbedNavigation;
