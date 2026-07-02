/**
 * WordPress dependencies.
 */
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
		return pathname === item.path || pathname.startsWith( item.path + '/' );
	}
	return false;
};

const TabbedNavigationView = ( { items, className, disableUpcoming, history = null, pathname = null, children = null } ) => {
	const displayedItems = items.filter( item => ! item.isHiddenInTabbedNavigation );
	const activeIndex = displayedItems.findIndex( item => isItemActive( item, pathname ) );
	const activeValue = activeIndex > -1 ? getItemValue( displayedItems[ activeIndex ] ) : null;

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
				{ displayedItems.map( item => (
					<Tabs.Panel key={ getItemValue( item ) } value={ getItemValue( item ) } tabIndex={ -1 } />
				) ) }
			</Tabs.Root>
			{ children }
		</div>
	);
};

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
