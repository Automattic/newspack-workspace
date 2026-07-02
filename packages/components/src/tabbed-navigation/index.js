/**
 * WordPress dependencies.
 */
import { ThemeProvider } from '@wordpress/theme';
import { Tabs } from '@wordpress/ui';

/**
 * External dependencies.
 */
import classnames from 'classnames';
import Router from '../proxied-imports/router';

/**
 * Internal dependencies.
 */
import './style.scss';

const { useHistory, useLocation } = Router;

const getItemValue = item => item.path || item.href;

/**
 * Whether a navigation item is the active one.
 *
 * Router-driven items match on `path` (exact by default, prefix when
 * `exact: false`) and optionally on `activeTabPaths` (a trailing `*` makes an
 * entry a prefix match) so hidden subpages can keep a parent tab selected.
 * Outside a router, an item is active when explicitly marked `selected` or
 * when its `href` is the current URL.
 *
 * @param {Object}      item     Navigation item.
 * @param {string|null} pathname Current router pathname, or null when rendered outside a router.
 * @return {boolean} Whether the item is active.
 */
export const isItemActive = ( item, pathname ) => {
	if ( item.selected ) {
		return true;
	}
	if ( null === pathname ) {
		return Boolean( item.href ) && window.location.href === item.href;
	}
	if ( item.path === pathname ) {
		return true;
	}
	if ( Array.isArray( item.activeTabPaths ) ) {
		return item.activeTabPaths.some( path => ( path.endsWith( '*' ) ? pathname.startsWith( path.slice( 0, -1 ) ) : path === pathname ) );
	}
	if ( false === item.exact && item.path ) {
		return pathname.startsWith( item.path );
	}
	return false;
};

/**
 * Tabbed navigation, built on @wordpress/ui's Tabs.
 *
 * Tabs render as anchors so navigation stays native: hash links when items
 * provide a router `path`, full URLs when they provide an `href`. Selection is
 * controlled from the current location rather than by the Tabs component, so
 * the active tab always mirrors the route (or a `selected` flag).
 *
 * @param {Object}      props
 * @param {Array}       props.items             Items: `{ path?, href?, label, exact?, activeTabPaths?, selected?, isHiddenInTabbedNavigation? }`.
 * @param {string}      [props.className]
 * @param {boolean}     [props.disableUpcoming] Disable tabs after the active one.
 * @param {Object}      [props.history]         Router history, when rendered inside a router.
 * @param {string|null} [props.pathname]        Current router pathname, or null outside a router.
 * @param {*}           [props.children]
 * @return {JSX.Element} Tabbed navigation component.
 */
const TabbedNavigationView = ( { items, className, disableUpcoming, history = null, pathname = null, children = null } ) => {
	const displayedItems = items.filter( item => ! item.isHiddenInTabbedNavigation );
	const activeIndex = displayedItems.findIndex( item => isItemActive( item, pathname ) );
	const activeValue = activeIndex > -1 ? getItemValue( displayedItems[ activeIndex ] ) : null;

	// Route through history.push (like NavLink) rather than native hash
	// navigation: a raw hashchange reaches the router as a POP, which
	// `history.block` guards (e.g. unsaved-changes dialogs) can't intercept
	// cleanly. Modified/middle clicks fall through to the href.
	const onTabClick = ( event, item ) => {
		if ( ! history || ! item.path ) {
			return;
		}
		if ( event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0 ) {
			return;
		}
		event.preventDefault();
		if ( item.path !== pathname ) {
			history.push( item.path );
		}
	};

	return (
		<div className={ classnames( 'newspack-tabbed-navigation', className ) }>
			<ThemeProvider>
				<Tabs.Root value={ activeValue } className="newspack-tabbed-navigation__tabs">
					<Tabs.List activateOnFocus={ false }>
						{ displayedItems.map( ( item, index ) => {
							const isDisabled = disableUpcoming && index > activeIndex;
							const href = item.path ? `#${ item.path }` : item.href;
							return (
								<Tabs.Tab
									key={ getItemValue( item ) }
									value={ getItemValue( item ) }
									disabled={ isDisabled }
									nativeButton={ false }
									render={
										// eslint-disable-next-line jsx-a11y/anchor-has-content, jsx-a11y/anchor-is-valid -- content is supplied via the Tab children through @wordpress/ui's render prop, and disabled tabs intentionally drop the href.
										<a
											href={ isDisabled ? undefined : href }
											onClick={ isDisabled ? undefined : event => onTabClick( event, item ) }
										/>
									}
								>
									{ item.label }
								</Tabs.Tab>
							);
						} ) }
					</Tabs.List>
					{ /* The real "panel" is the routed page content rendered outside this
					     component; these empty panels keep the required tab/panel ARIA pairing. */ }
					{ displayedItems.map( item => (
						<Tabs.Panel key={ getItemValue( item ) } value={ getItemValue( item ) } tabIndex={ -1 } />
					) ) }
				</Tabs.Root>
			</ThemeProvider>
			{ children }
		</div>
	);
};

/**
 * Router-driven variant: navigates via history and mirrors the current route.
 * Only rendered when items navigate by router `path`, since the router hooks
 * require a router ancestor. `useLocation` provides the re-render on route
 * change — `useHistory` alone reads a stable context and never re-renders.
 *
 * @param {Object} props See TabbedNavigationView.
 * @return {JSX.Element} Tabbed navigation component.
 */
const RoutedTabbedNavigation = props => {
	const history = useHistory();
	const { pathname } = useLocation();
	return <TabbedNavigationView { ...props } history={ history } pathname={ pathname } />;
};

const TabbedNavigation = props => {
	const hasRoutedItems = props.items.some( item => item.path );
	return hasRoutedItems ? <RoutedTabbedNavigation { ...props } /> : <TabbedNavigationView { ...props } />;
};

export default TabbedNavigation;
