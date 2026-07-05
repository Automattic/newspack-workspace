/**
 * External dependencies.
 */
import classnames from 'classnames';
import findIndex from 'lodash/findIndex';
import Router from '../proxied-imports/router';

/**
 * Internal dependencies.
 */
import './style.scss';

const { NavLink, useHistory } = Router;

export interface TabbedNavigationItem {
	/** The tab's label. */
	label?: React.ReactNode;
	/** The tab's route path. */
	path: string;
	/** Whether the route has to match exactly. Defaults to exact matching. */
	exact?: boolean;
	/** Additional paths marking this tab as active. A trailing `*` matches by prefix. */
	activeTabPaths?: string[];
	/** Whether the tab is excluded from the navigation. */
	isHiddenInTabbedNavigation?: boolean;
}

type TabbedNavigationProps = {
	/** The tabs to display. */
	items: TabbedNavigationItem[];
	/** Additional CSS class name. */
	className?: string;
	/** Whether to disable tabs after the active one. */
	disableUpcoming?: boolean;
	children?: React.ReactNode;
};

const TabbedNavigation = ( { items, className, disableUpcoming, children = null }: TabbedNavigationProps ) => {
	const displayedItems = items.filter( item => ! item.isHiddenInTabbedNavigation );
	const { location } = useHistory();
	const currentIndex = findIndex( displayedItems, [ 'path', location.pathname ] );

	function isActive( item: TabbedNavigationItem, match: unknown, pathname: string ) {
		if ( item.path === pathname ) {
			return true;
		}
		if ( Array.isArray( item?.activeTabPaths ) ) {
			return item.activeTabPaths.some( path => {
				if ( path.endsWith( '*' ) ) {
					const basePath = path.slice( 0, -1 );
					return pathname.startsWith( basePath );
				}
				return item.activeTabPaths?.includes( pathname );
			} );
		}
		return !! match;
	}

	return (
		<div className={ classnames( 'newspack-tabbed-navigation', className ) }>
			<ul>
				{ displayedItems.map( ( item, index ) => (
					<li key={ index }>
						<NavLink
							to={ item.path }
							isActive={ ( match, { pathname } ) => isActive( item, match, pathname ) }
							exact={ item.hasOwnProperty( 'exact' ) ? item.exact : true }
							activeClassName={ 'selected' }
							className={ classnames( {
								disabled: disableUpcoming && index > currentIndex,
							} ) }
						>
							{ item.label }
						</NavLink>
					</li>
				) ) }
			</ul>
			{ children }
		</div>
	);
};

export default TabbedNavigation;
