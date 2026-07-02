/**
 * WordPress dependencies.
 */
import { NavigableRegion } from '@wordpress/admin-ui';
import { __experimentalHStack as HStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
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
import './style.scss';

/**
 * Newspack page region: a sticky header block (breadcrumbs, actions, optional
 * tabbed navigation) followed by the page content.
 *
 * @param {Object} props
 * @param {Array}  props.breadcrumbItems    Trail items: `{ label, url? }`.
 * @param {*}      [props.badges]
 * @param {*}      [props.subTitle]
 * @param {*}      [props.actions]
 * @param {*}      [props.tabbedNavigation] Tabbed navigation, rendered inside the sticky header block.
 * @param {string} [props.className]
 * @param {*}      props.children
 * @return {JSX.Element} Page component.
 */
const Page = ( { breadcrumbItems = [], badges, subTitle, actions, tabbedNavigation, className, children } ) => {
	const currentLabel = breadcrumbItems[ breadcrumbItems.length - 1 ]?.label;
	return (
		<NavigableRegion className={ classnames( 'newspack-page', className ) } ariaLabel={ currentLabel }>
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
				{ tabbedNavigation }
			</Stack>
			{ children }
		</NavigableRegion>
	);
};

export default Page;
