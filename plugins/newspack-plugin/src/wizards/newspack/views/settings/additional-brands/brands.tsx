/**
 * Additional Brands Brands page.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';

/**
 * Internal dependencies
 */
import Brand from './brand';
import { Card, Button, Router } from '../../../../../../packages/components/src';
import { TAB_PATH } from './constants';

const { NavLink } = Router;

export default function Brands( {
	brands,
	isFetching,
	deleteBrand,
}: {
	brands: Brand[];
	isFetching: boolean;
	deleteBrand: ( brand: Brand ) => void;
} ) {
	return (
		<Fragment>
			<Card headerActions noBorder>
				<h2>
					{ ! brands.length && ! isFetching
						? __( 'You have no saved brands.', 'newspack-plugin' )
						: __( 'Site brands', 'newspack-plugin' ) }
				</h2>
				<NavLink to={ `${ TAB_PATH }/new` }>
					<Button variant="primary" disabled={ isFetching }>
						{ __( 'Add New Brand', 'newspack-plugin' ) }
					</Button>
				</NavLink>
			</Card>
			{ brands.length ? (
				brands.map( brand => <Brand key={ brand.id } brand={ brand } deleteBrand={ deleteBrand } /> )
			) : (
				<Fragment>
					{ isFetching ? (
						<p>{ __( 'Fetching brands…', 'newspack-plugin' ) }</p>
					) : (
						<p>{ __( 'Create brands to enhance your readers experience.', 'newspack-plugin' ) }</p>
					) }
				</Fragment>
			) }
		</Fragment>
	);
}
