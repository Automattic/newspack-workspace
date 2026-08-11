/**
 * Shared "Applies to" fields for subscriber-commerce rule editors: the
 * targeting-mode radio plus the product, category and excluded-product
 * pickers.
 *
 * Controlled through a single `value` object; `onChange` receives a partial
 * with only the changed keys. Help copy differs per feature, so it comes in as
 * props.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { RadioControl } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import SearchTokenField from './search-token-field';
import { SEARCH_ENDPOINTS } from '../constants';
import type { RuleTargeting } from '../types';

interface TargetingFieldsProps {
	value: RuleTargeting;
	onChange: ( partial: Partial< RuleTargeting > ) => void;
	appliesHelp?: string;
	categoryHelp?: string;
	disabled?: boolean;
}

export default function TargetingFields( { value, onChange, appliesHelp, categoryHelp, disabled }: TargetingFieldsProps ) {
	const { targeting, product_ids: productIds, category_ids: categoryIds, excluded_product_ids: excludedIds } = value;

	return (
		<>
			<RadioControl
				label={ __( 'Applies to', 'newspack-plugin' ) }
				help={ appliesHelp }
				selected={ targeting }
				onChange={ ( next: string ) => onChange( { targeting: next as RuleTargeting[ 'targeting' ] } ) }
				options={ [
					{ value: 'products', label: __( 'Specific products', 'newspack-plugin' ) },
					{ value: 'category', label: __( 'Category', 'newspack-plugin' ) },
					{ value: 'all', label: __( 'All products', 'newspack-plugin' ) },
				] }
			/>
			{ 'products' === targeting && (
				<SearchTokenField
					endpoint={ SEARCH_ENDPOINTS.products }
					label={ __( 'Products', 'newspack-plugin' ) }
					value={ productIds }
					onChange={ ids => onChange( { product_ids: ids } ) }
					disabled={ disabled }
				/>
			) }
			{ 'category' === targeting && (
				<SearchTokenField
					endpoint={ SEARCH_ENDPOINTS.productCategories }
					label={ __( 'Product categories', 'newspack-plugin' ) }
					help={ categoryHelp || __( 'Subcategories are included.', 'newspack-plugin' ) }
					value={ categoryIds }
					onChange={ ids => onChange( { category_ids: ids } ) }
					disabled={ disabled }
				/>
			) }
			{ 'products' !== targeting && (
				<SearchTokenField
					endpoint={ SEARCH_ENDPOINTS.products }
					label={ __( 'Excluded products', 'newspack-plugin' ) }
					help={ __( 'Products left out of the rule.', 'newspack-plugin' ) }
					value={ excludedIds }
					onChange={ ids => onChange( { excluded_product_ids: ids } ) }
					disabled={ disabled }
				/>
			) }
		</>
	);
}
