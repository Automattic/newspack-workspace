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

const TabbedNavigation = ( { items, className, disableUpcoming, children = null } ) => {
	const displayedItems = items.filter( item => ! item.isHiddenInTabbedNavigation );
	const { location } = useHistory();
	const currentIndex = findIndex( displayedItems, [ 'path', location.pathname ] );

	function isActive( item, match, pathname ) {
		if ( item.path === pathname ) {
			return true;
		}
		if ( Array.isArray( item?.activeTabPaths ) ) {
			return item.activeTabPaths.some( path => {
				if ( path.endsWith( '*' ) ) {
					const basePath = path.slice( 0, -1 );
					return pathname.startsWith( basePath );
				}
				return item.activeTabPaths.includes( pathname );
			} );
		}
		return match;
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
