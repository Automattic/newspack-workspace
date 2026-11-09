/**
 * WordPress dependencies.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import { CellList } from '../../../../packages/components/src';
import { worthCollapsing } from '../../../../packages/components/src/cell-list/collapse';
import NamesSummary from './names-summary';
import NamesTooltip from './names-tooltip';
import { allProductsLabel, excludedLabel } from '../views/subscriptions/tabs/discounts/discount';
import { MAX_NAMED_ITEMS } from '../views/subscriptions/constants';

type ScopeSummaryProps = {
	targeting: 'products' | 'category' | 'all';
	productIds: number[];
	categoryIds: number[];
	excludedProductIds: number[];
	productNames: Record< number, string >;
	categoryNames: Record< number, string >;
	/** Lead with the first names and a "+N more" tooltip, for the list's title column. */
	asTitle?: boolean;
};

const namesOf = ( ids: number[], names: Record< number, string > ) => ids.map( id => names[ id ] ).filter( Boolean );

const moreLabel = ( targeting: 'products' | 'category', remaining: number ) =>
	'products' === targeting
		? sprintf(
				/* translators: %d: number of further products. */
				_n( '+%d more product', '+%d more products', remaining, 'newspack-plugin' ),
				remaining
		  )
		: sprintf(
				/* translators: %d: number of further product categories. */
				_n( '+%d more category', '+%d more categories', remaining, 'newspack-plugin' ),
				remaining
		  );

/**
 * What a rule applies to: "All products", or the product or category names
 * (a `CellList`, or leading names with a "+N more" tooltip in a title column),
 * then a muted "N excluded" line whose tooltip lists the excluded names.
 */
export default function ScopeSummary( {
	targeting,
	productIds,
	categoryIds,
	excludedProductIds,
	productNames,
	categoryNames,
	asTitle = false,
}: ScopeSummaryProps ) {
	const excluded = excludedLabel( { targeting, product_ids: productIds, category_ids: categoryIds, excluded_product_ids: excludedProductIds } );
	let base: React.ReactNode;
	if ( 'all' === targeting ) {
		base = <span>{ allProductsLabel() }</span>;
	} else {
		const names = 'products' === targeting ? namesOf( productIds, productNames ) : namesOf( categoryIds, categoryNames );
		if ( ! names.length ) {
			base = <span>{ 'products' === targeting ? __( 'No products', 'newspack-plugin' ) : __( 'No categories', 'newspack-plugin' ) }</span>;
		} else if ( asTitle ) {
			const limit = worthCollapsing( names.length - MAX_NAMED_ITEMS ) ? MAX_NAMED_ITEMS : names.length;
			const remaining = names.length - limit;
			base = (
				<NamesSummary
					shown={ names.slice( 0, limit ) }
					more={ remaining ? moreLabel( targeting, remaining ) : undefined }
					hidden={ names.slice( limit ) }
				/>
			);
		} else {
			base = (
				<CellList>
					{ names.map( ( name, index ) => (
						<span key={ `${ name }-${ index }` }>{ name }</span>
					) ) }
				</CellList>
			);
		}
	}
	if ( ! excluded ) {
		return <>{ base }</>;
	}
	return (
		<Stack direction="column" gap="sm" align="flex-start" className="newspack-dataviews__stack">
			{ base }
			<NamesTooltip muted label={ excluded } names={ namesOf( excludedProductIds, productNames ) } />
		</Stack>
	);
}
