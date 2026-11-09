/**
 * WordPress dependencies.
 */
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import NamesTooltip from './names-tooltip';
import { excludedLabel, targetingBaseLabel } from '../views/subscriptions/tabs/discounts/discount';

type ScopeSummaryProps = {
	targeting: 'products' | 'category' | 'all';
	productIds: number[];
	categoryIds: number[];
	excludedProductIds: number[];
	productNames: Record< number, string >;
	categoryNames: Record< number, string >;
};

const namesOf = ( ids: number[], names: Record< number, string > ) => ids.map( id => names[ id ] ).filter( Boolean );

/**
 * What a rule applies to: "N products", "N categories" or "All products", then
 * "N excluded", each count listing the names it stands for in a tooltip.
 */
export default function ScopeSummary( { targeting, productIds, categoryIds, excludedProductIds, productNames, categoryNames }: ScopeSummaryProps ) {
	const scope = { targeting, product_ids: productIds, category_ids: categoryIds, excluded_product_ids: excludedProductIds };
	const baseLabel = targetingBaseLabel( scope );
	const excluded = excludedLabel( scope );
	let base: React.ReactNode = baseLabel;
	if ( 'products' === targeting ) {
		base = <NamesTooltip label={ baseLabel } names={ namesOf( productIds, productNames ) } />;
	} else if ( 'category' === targeting ) {
		base = <NamesTooltip label={ baseLabel } names={ namesOf( categoryIds, categoryNames ) } />;
	}
	if ( ! excluded ) {
		return <span>{ base }</span>;
	}
	return (
		<Stack direction="column" gap="sm" align="flex-start">
			<span>{ base }</span>
			<NamesTooltip muted label={ excluded } names={ namesOf( excludedProductIds, productNames ) } />
		</Stack>
	);
}
