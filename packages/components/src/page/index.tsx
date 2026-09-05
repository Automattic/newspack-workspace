/**
 * WordPress dependencies.
 */
import { NavigableRegion } from '@wordpress/admin-ui';
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { __experimentalHStack as HStack } from '@wordpress/components';
import { cloneElement, isValidElement } from '@wordpress/element';
import { Icon } from '@wordpress/icons';
import { Stack, Text } from '@wordpress/ui';

/**
 * External dependencies.
 */
import classnames from 'classnames';
import { newspack } from 'newspack-icons';
import type { ReactElement, ReactNode } from 'react';

/**
 * Internal dependencies.
 */
import Breadcrumbs from '../breadcrumbs';
import type { BreadcrumbItem } from '../breadcrumbs';
import './style.scss';

interface PageProps {
	/** Breadcrumb trail; its last item is the current page. */
	breadcrumbItems?: BreadcrumbItem[];
	badges?: ReactNode;
	subTitle?: ReactNode;
	actions?: ReactNode;
	/**
	 * A `TabbedNavigation` element; its bar renders inside the sticky header
	 * block and the page children render inside its active tab panel — or, when
	 * no visible tab owns the route, as a sibling of the panels.
	 */
	tabbedNavigation?: ReactNode;
	className?: string;
	children?: ReactNode;
}

/**
 * Newspack page region: a sticky header block (breadcrumbs, actions, optional
 * tabbed navigation) followed by the page content.
 *
 * This intentionally does not use `@wordpress/admin-ui`'s `Page`: that
 * component has no slot for content rendered between the header and the body
 * inside the sticky region, which is exactly where the tab bar must live. Until
 * admin-ui grows such a slot, the header markup is assembled here from the same
 * design-system primitives and tokens, so it can be swapped back later.
 */
const Page = ( { breadcrumbItems = [], badges, subTitle, actions, tabbedNavigation, className, children }: PageProps ) => {
	const currentLabel = breadcrumbItems[ breadcrumbItems.length - 1 ]?.label;

	const renderShell = ( tabBar: ReactNode, content: ReactNode ) => (
		<>
			<Stack direction="column" className="newspack-page__header-region">
				<Stack direction="column" className="newspack-page__header">
					<Stack direction="row" gap="sm" justify="space-between">
						<Stack direction="row" gap="sm" align="center" justify="start">
							<div className="newspack-page__header-visual" aria-hidden="true">
								<Icon icon={ newspack } />
							</div>
							<HStack className="newspack-page__breadcrumbs" justify="flex-start">
								<Breadcrumbs items={ breadcrumbItems } />
							</HStack>
							{ badges }
						</Stack>
						{ actions && (
							<Stack direction="row" gap="sm" align="center" className="newspack-page__header-actions">
								{ actions }
							</Stack>
						) }
					</Stack>
					{ subTitle && (
						<Text render={ <p /> } variant="body-md" className="newspack-page__header-subtitle">
							{ subTitle }
						</Text>
					) }
				</Stack>
				{ tabBar }
			</Stack>
			{ content }
		</>
	);

	return (
		<NavigableRegion
			className={ classnames( 'newspack-page', className ) }
			ariaLabel={ typeof currentLabel === 'string' ? currentLabel : undefined }
		>
			{ isValidElement( tabbedNavigation )
				? cloneElement( tabbedNavigation as ReactElement< { renderShell?: typeof renderShell; content?: ReactNode } >, {
						renderShell,
						content: children,
				  } )
				: renderShell( null, children ) }
		</NavigableRegion>
	);
};

export default Page;
