/**
 * Shared "Applies to" targeting fields for the discount and restriction
 * editors: the targeting-mode radio plus the product, category, and
 * excluded-products pickers. Controlled via a single `value` object
 * ({ targeting, productIds, category, excludedIds }); `onChange` receives a
 * partial with just the changed keys. Help copy differs per feature, so it
 * comes in as props.
 */

import { __ } from '@wordpress/i18n';
import { FormTokenField, RadioControl } from '@wordpress/components';
import { SelectControl } from '../../../../packages/components/src';
import { CATEGORIES, PRODUCTS, getProductById } from '../data/mock-catalog';
import { productsForRule } from '../data/targeting';

export default function TargetingFields( { value, onChange, appliesHelp, categoryHelp } ) {
	const { targeting, productIds, category, excludedIds } = value;

	const onProductsChange = names => {
		onChange( { productIds: names.map( name => PRODUCTS.find( p => p.name === name )?.id ).filter( Boolean ) } );
	};

	// Products currently in scope with no exclusions applied yet, so the
	// exclusion picker only ever offers what could actually be excluded.
	const scopeProducts = productsForRule( { targeting, productIds, category, excludedIds: [] } );

	const onExcludedChange = names => {
		onChange( { excludedIds: names.map( name => scopeProducts.find( p => p.name === name )?.id ).filter( Boolean ) } );
	};

	return (
		<>
			<RadioControl
				label={ __( 'Applies to', 'newspack-plugin' ) }
				help={ appliesHelp }
				selected={ targeting }
				onChange={ next => onChange( { targeting: next } ) }
				options={ [
					{ value: 'products', label: __( 'Specific products', 'newspack-plugin' ) },
					{ value: 'category', label: __( 'Category', 'newspack-plugin' ) },
					{ value: 'all', label: __( 'All products', 'newspack-plugin' ) },
				] }
			/>
			{ targeting === 'products' && (
				<FormTokenField
					label={ __( 'Products', 'newspack-plugin' ) }
					value={ productIds.map( id => getProductById( id )?.name ).filter( Boolean ) }
					suggestions={ PRODUCTS.map( p => p.name ) }
					onChange={ onProductsChange }
					__experimentalExpandOnFocus
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
			) }
			{ targeting === 'category' && (
				<SelectControl
					label={ __( 'Category', 'newspack-plugin' ) }
					help={ categoryHelp }
					value={ category }
					options={ CATEGORIES.map( c => ( { label: c, value: c } ) ) }
					onChange={ next => onChange( { category: next } ) }
					__next40pxDefaultSize
				/>
			) }
			{ targeting !== 'products' && (
				<FormTokenField
					label={ __( 'Excluded products', 'newspack-plugin' ) }
					value={ excludedIds.map( id => scopeProducts.find( p => p.id === id )?.name ).filter( Boolean ) }
					suggestions={ scopeProducts.map( p => p.name ) }
					onChange={ onExcludedChange }
					__experimentalExpandOnFocus
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
			) }
		</>
	);
}
