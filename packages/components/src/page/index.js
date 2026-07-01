/**
 * WordPress dependencies.
 */
import { Page as AdminUIPage } from '@wordpress/admin-ui';
import { __experimentalHStack as HStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { Icon } from '@wordpress/icons';

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
 * Newspack page header + content region, built on @wordpress/admin-ui's Page.
 *
 * The branded Newspack logo is supplied as admin-ui's decorative (aria-hidden)
 * `visual` slot. The breadcrumb trail (with the current page as the single h1)
 * is supplied as admin-ui's `breadcrumbs` ReactNode.
 *
 * @param {Object} props
 * @param {Array}  props.breadcrumbItems Trail items: `{ label, url? }`.
 * @param {*}      [props.badges]
 * @param {*}      [props.subTitle]
 * @param {*}      [props.actions]
 * @param {string} [props.className]
 * @param {*}      props.children
 * @return {JSX.Element} Page component.
 */
const Page = ( { breadcrumbItems = [], badges, subTitle, actions, className, children } ) => {
	const currentLabel = breadcrumbItems[ breadcrumbItems.length - 1 ]?.label;
	return (
		<AdminUIPage
			className={ classnames( 'newspack-page', className ) }
			ariaLabel={ currentLabel }
			visual={ <Icon icon={ newspack } /> }
			breadcrumbs={
				<HStack className="newspack-page__breadcrumbs" justify="flex-start">
					<Breadcrumbs items={ breadcrumbItems } />
				</HStack>
			}
			badges={ badges }
			subTitle={ subTitle }
			actions={ actions }
			hasPadding={ false }
		>
			{ children }
		</AdminUIPage>
	);
};

export default Page;
