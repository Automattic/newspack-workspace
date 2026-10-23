/**
 * WordPress dependencies.
 */
import { NavigableRegion } from '@wordpress/admin-ui';
import { __experimentalHStack as HStack, Popover } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { useViewportMatch } from '@wordpress/compose';
import { cloneElement, createPortal, isValidElement, useState } from '@wordpress/element';
import { Icon } from '@wordpress/icons';
import { Stack, Text } from '@wordpress/ui';

/**
 * External dependencies.
 */
import classnames from 'classnames';
import { newspack } from 'newspack-icons';

/**
 * Internal dependencies.
 */
import Breadcrumbs from '../breadcrumbs';
import HeaderActions, { isActionList } from './header-actions';
import useScrollEdges from './use-scroll-edges';
import './style.scss';

// WordPress `Popover`s opened from the page content (`Dropdown`, `DropdownMenu`
// and the like) render into this slot, stacked under the sticky header, instead
// of the default one, which stacks them over it. Popovers opened from the header
// keep the default slot. Ariakit-based menus, such as DataViews' row and column
// menus, and `@wordpress/ui` overlays, such as `InfoButton`, portal on their own
// and don't follow it.
const CONTENT_POPOVER_SLOT = 'newspack-page-content';

// Routing by subtree leans on an unstable part of WordPress's own
// `wp.components`. If a release drops it, the page renders its content as is and
// content popovers fall back to the default slot, rather than the page failing to
// render. Recheck it on WordPress upgrades.
const SlotNameProvider = Popover.__unstableSlotNameProvider ?? ( ( { children } ) => <>{ children }</> );

/**
 * Newspack page region: a sticky header block (breadcrumbs, actions, optional
 * tabbed navigation) followed by the page content.
 *
 * This intentionally does not use `@wordpress/admin-ui`'s `Page`: that
 * component has no slot for content rendered between the header and the body
 * inside the sticky region, which is exactly where the tab bar must live. Until
 * admin-ui grows such a slot, the header markup is assembled here from the same
 * design-system primitives and tokens, so it can be swapped back later.
 *
 * @param {Object} props
 * @param {Array}  props.breadcrumbItems    Trail items: `{ label, url? }`.
 * @param {*}      [props.badges]
 * @param {*}      [props.subTitle]         Plain text, hidden visually below 782px.
 * @param {*}      [props.actions]          Rendered nodes, or a list of action objects (see
 *                                          `PageAction` in `./header-actions`), which below
 *                                          782px collapse into one menu beside the primary action.
 * @param {*}      [props.tabbedNavigation] A `TabbedNavigation` element; its bar renders inside
 *                                          the sticky header block and the page children render
 *                                          inside its active tab panel — or, when no visible tab
 *                                          owns the route, as a sibling of the panels.
 * @param {string} [props.className]
 * @param {*}      [props.children]
 * @return {JSX.Element} Page component.
 */
const Page = ( { breadcrumbItems = [], badges, subTitle, actions, tabbedNavigation, className, children } ) => {
	const currentLabel = breadcrumbItems[ breadcrumbItems.length - 1 ]?.label;
	const [ crumbsElement, setCrumbsElement ] = useState( null );
	const crumbsKey = breadcrumbItems
		.map( ( item, index ) => `${ 'string' === typeof item.label ? item.label : index }|${ item.count ?? '' }` )
		.join( '\u0000' );
	const crumbEdges = useScrollEdges( crumbsElement, crumbsKey );
	const isWide = useViewportMatch( 'medium' );
	const hasActionList = isActionList( actions );

	const renderShell = ( tabBar, content ) => (
		<>
			<Stack direction="column" className="newspack-page__header-region">
				<Stack direction="column" className="newspack-page__header">
					<Stack direction="row" justify="space-between" wrap="wrap" className="newspack-page__header-row">
						<Stack direction="row" gap="sm" align="start" justify="start" className="newspack-page__header-title">
							<div className="newspack-page__header-visual" aria-hidden="true">
								<Icon icon={ newspack } />
							</div>
							<HStack
								ref={ setCrumbsElement }
								className={ classnames( 'newspack-page__breadcrumbs', {
									'is-overflowing-start': crumbEdges.start,
									'is-overflowing-end': crumbEdges.end,
								} ) }
								justify="flex-start"
								// Tabbing through the links can't bring the middle of a long current-page title into view.
								tabIndex={ ! isWide && crumbEdges.overflowing ? 0 : undefined }
							>
								<Breadcrumbs items={ breadcrumbItems } />
							</HStack>
							{ badges && (
								<Stack direction="row" gap="sm" align="center" className="newspack-page__header-badges">
									{ badges }
								</Stack>
							) }
						</Stack>
						{ actions && (
							<Stack direction="row" gap="sm" align="center" className="newspack-page__header-actions">
								{ hasActionList ? <HeaderActions actions={ actions } /> : actions }
							</Stack>
						) }
					</Stack>
					{ subTitle && (
						<Text
							render={ <p /> }
							variant="body-md"
							// Helper text, not needed to use the screen: on phones it would cost the header a second line.
							className={ classnames( 'newspack-page__header-subtitle', { 'screen-reader-text': ! isWide } ) }
						>
							{ subTitle }
						</Text>
					) }
				</Stack>
				{ tabBar }
			</Stack>
			<SlotNameProvider value={ CONTENT_POPOVER_SLOT }>{ content }</SlotNameProvider>
			{ /* At the end of the document, like WordPress's own popover container, so
			     no ancestor of the page clips the popovers or bounds their placement.
			     A page with no content of its own (the PHP-page header) registers no
			     slot, since it often has no SlotFillProvider to register one with. */ }
			{ children &&
				createPortal(
					<div className="newspack-page__content-popovers">
						<Popover.Slot name={ CONTENT_POPOVER_SLOT } />
					</div>,
					document.body
				) }
		</>
	);

	return (
		<NavigableRegion className={ classnames( 'newspack-page', className ) } ariaLabel={ currentLabel }>
			{ isValidElement( tabbedNavigation )
				? cloneElement( tabbedNavigation, { renderShell, content: children } )
				: renderShell( null, children ) }
		</NavigableRegion>
	);
};

export default Page;
