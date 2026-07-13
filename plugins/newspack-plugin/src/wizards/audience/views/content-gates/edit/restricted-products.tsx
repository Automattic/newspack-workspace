/**
 * Member-only purchasing: products a reader may only buy if they pass this gate's access rules.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { __experimentalVStack as VStack, BaseControl, CardBody } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { useCallback, useMemo } from '@wordpress/element';

/**
 * Internal dependencies
 */
import ContentRuleControlTokenField from './content-rule-control-tokenfield';

// Defined at module scope so their identity is stable: the token field memoizes its
// fetching on the config it receives, and a new object each render would refetch in a loop.
const wizardApi = window.newspackAudienceContentGates?.api ?? '';
const PRODUCTS_CONFIG = {
	name: __( 'Products', 'newspack-plugin' ),
	endpoint: `${ wizardApi }/products-search`,
};
const PRODUCT_CATEGORIES_CONFIG = {
	name: __( 'Product categories', 'newspack-plugin' ),
	endpoint: `${ wizardApi }/product-categories-search`,
};

interface RestrictedProductsProps {
	products: number[];
	productCategories: number[];
	onChange: ( value: { restricted_products?: number[]; restricted_product_categories?: number[] } ) => void;
}

export default function RestrictedProducts( { products, productCategories, onChange }: RestrictedProductsProps ) {
	// The token field speaks strings; the gate stores IDs. Memoized because the token field
	// re-fetches its saved items whenever the `value` identity changes, and this component
	// re-renders on every edit elsewhere in the gate (title, access rules, metering).
	const productTokens = useMemo( () => products.map( String ), [ products ] );
	const productCategoryTokens = useMemo( () => productCategories.map( String ), [ productCategories ] );

	const handleProductsChange = useCallback( ( value: string[] ) => onChange( { restricted_products: value.map( Number ) } ), [ onChange ] );
	const handleProductCategoriesChange = useCallback(
		( value: string[] ) => onChange( { restricted_product_categories: value.map( Number ) } ),
		[ onChange ]
	);

	return (
		<CardBody size="small">
			<VStack spacing={ 4 }>
				<div>
					<BaseControl.VisualLabel>{ __( 'Member-only purchasing', 'newspack-plugin' ) }</BaseControl.VisualLabel>
					<p className="components-base-control__help">
						{ __(
							'Readers who do not match this gate’s access rules can still view these products, but cannot purchase them. Restricting a product category also restricts its subcategories.',
							'newspack-plugin'
						) }
					</p>
				</div>
				<ContentRuleControlTokenField
					slug="restricted_products"
					label={ PRODUCTS_CONFIG.name }
					config={ PRODUCTS_CONFIG }
					value={ productTokens }
					onChange={ handleProductsChange }
				/>
				<ContentRuleControlTokenField
					slug="restricted_product_categories"
					label={ PRODUCT_CATEGORIES_CONFIG.name }
					config={ PRODUCT_CATEGORIES_CONFIG }
					value={ productCategoryTokens }
					onChange={ handleProductCategoriesChange }
				/>
			</VStack>
		</CardBody>
	);
}
