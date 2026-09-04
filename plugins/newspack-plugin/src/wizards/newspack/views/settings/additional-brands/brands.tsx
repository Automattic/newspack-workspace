/**
 * Additional Brands Brands page.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';
import { ExternalLink } from '@wordpress/components';
import { siteLogo } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import Brand from './brand';
import { Card, Button } from '../../../../../../packages/components/src';
import EmptyState from '../../../../../../packages/components/src/empty-state';
import { TAB_PATH } from './constants';

const LEARN_MORE_URL = 'https://help.newspack.com/federated-sites/multi-branded-site/';

export default function Brands( {
	brands,
	isFetching,
	hasFetched,
	deleteBrand,
}: {
	brands: Brand[];
	isFetching: boolean;
	hasFetched: boolean;
	deleteBrand: ( brand: Brand ) => void;
} ) {
	if ( hasFetched && ! isFetching && ! brands.length ) {
		return (
			<EmptyState.Root>
				<EmptyState.Header
					icon={ siteLogo }
					title={ __( 'Get started with brands', 'newspack-plugin' ) }
					description={ __( 'Give parts of your site their own name, logo, colors, and menus.', 'newspack-plugin' ) }
				/>
				<EmptyState.Actions orientation="column" gap="lg">
					<Button variant="primary" href={ `#${ TAB_PATH }/new` }>
						{ __( 'Add Brand', 'newspack-plugin' ) }
					</Button>
					<ExternalLink
						href={ LEARN_MORE_URL }
						/* translators: accessibility text. Names the link's destination for screen readers; keep the new-tab clause, which replaces the one the link would otherwise announce. */
						aria-label={ __( 'Learn more about brands (opens in a new tab)', 'newspack-plugin' ) }
					>
						{ __( 'Learn more', 'newspack-plugin' ) }
					</ExternalLink>
				</EmptyState.Actions>
			</EmptyState.Root>
		);
	}

	return (
		<Fragment>
			<Card headerActions noBorder>
				<h2>{ __( 'Site brands', 'newspack-plugin' ) }</h2>
				<Button variant="primary" href={ `#${ TAB_PATH }/new` } disabled={ isFetching }>
					{ __( 'Add Brand', 'newspack-plugin' ) }
				</Button>
			</Card>
			{ brands.length ? (
				brands.map( brand => <Brand key={ brand.id } brand={ brand } deleteBrand={ deleteBrand } /> )
			) : (
				<p>{ __( 'Fetching brands…', 'newspack-plugin' ) }</p>
			) }
		</Fragment>
	);
}
