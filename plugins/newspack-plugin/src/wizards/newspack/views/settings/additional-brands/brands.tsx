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
import { EmptyState, Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import Brand from './brand';
import { Card, Button, Notice } from '../../../../../../packages/components/src';
import { TAB_PATH } from './constants';

const LEARN_MORE_URL = 'https://help.newspack.com/federated-sites/multi-branded-site/';

export default function Brands( {
	brands,
	isFetching,
	hasLoaded,
	loadError,
	deleteBrand,
}: {
	brands: Brand[];
	isFetching: boolean;
	hasLoaded: boolean;
	loadError: string;
	deleteBrand: ( brand: Brand ) => void;
} ) {
	if ( loadError ) {
		return <Notice isError noticeText={ loadError } />;
	}

	if ( hasLoaded && ! isFetching && ! brands.length ) {
		return (
			<Stack direction="column" align="center">
				<EmptyState.Root>
					<EmptyState.Icon icon={ siteLogo } />
					<EmptyState.Title>{ __( 'Get started with brands', 'newspack-plugin' ) }</EmptyState.Title>
					<EmptyState.Description>
						{ __( 'Give parts of your site their own name, logo, colors, and menus.', 'newspack-plugin' ) }
					</EmptyState.Description>
					<EmptyState.Actions>
						<Stack direction="column" gap="lg" align="center">
							<Button variant="primary" href={ `#${ TAB_PATH }/new` }>
								{ __( 'Add Brand', 'newspack-plugin' ) }
							</Button>
							<ExternalLink
								href={ LEARN_MORE_URL }
								aria-label={
									/* translators: accessibility text. Names the link's destination for screen readers; keep the new-tab clause, which replaces the one the link would otherwise announce. */
									__( 'Learn more about brands (opens in a new tab)', 'newspack-plugin' )
								}
							>
								{ __( 'Learn more', 'newspack-plugin' ) }
							</ExternalLink>
						</Stack>
					</EmptyState.Actions>
				</EmptyState.Root>
			</Stack>
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
