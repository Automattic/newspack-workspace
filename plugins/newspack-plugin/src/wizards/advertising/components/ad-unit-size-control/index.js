/**
 * Ad Unit Size Control.
 *
 * Select from a subset of sizes, or enter custom width and height.
 */

/**
 * External dependencies.
 */
import startCase from 'lodash/startCase';

/**
 * WordPress dependencies.
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { SelectControl, TextControl } from '../../../../../packages/components/src';

const IAB_SIZES = window.newspack_ads_wizard.iab_sizes;

/**
 * Get the list of sizes.
 *
 * @return {Array} List of sizes.
 */
export function getSizes() {
	return [ ...Object.keys( IAB_SIZES ).map( sizeString => sizeString.split( 'x' ).map( Number ) ), 'fluid' ];
}

/**
 * Get size label from IAB standard sizes. Returns {width}x{height} if label is
 * not found.
 *
 * @param {Array|string} size Size array or string.
 * @return {string} Size label.
 */
export function getSizeLabel( size ) {
	if ( Array.isArray( size ) ) {
		size = size.join( 'x' );
	}
	const label = IAB_SIZES[ size ];
	if ( label ) {
		return `${ label } (${ size })`;
	}
	return size;
}

/**
 * Ad Unit Size Control.
 */
const AdUnitSizeControl = ( { value, selectedOptions, onChange } ) => {
	const [ isCustom, setIsCustom ] = useState( false );
	const options = getSizes().filter(
		size =>
			JSON.stringify( value ) === JSON.stringify( size ) ||
			! selectedOptions.find( selectedOption => JSON.stringify( selectedOption ) === JSON.stringify( size ) )
	);
	const sizeIndex = isCustom ? -1 : options.findIndex( size => JSON.stringify( size ) === JSON.stringify( value ) );
	return (
		<>
			<SelectControl
				label={ __( 'Size', 'newspack-plugin' ) }
				value={ sizeIndex }
				options={ [
					...options.map( ( size, index ) => ( {
						label: Array.isArray( size ) ? getSizeLabel( size ) : startCase( size ),
						value: index,
					} ) ),
					{ label: __( 'Custom', 'newspack-plugin' ), value: -1 },
				] }
				onChange={ index => {
					const size = options[ index ];
					setIsCustom( ! size );
					onChange( size || [] );
				} }
				hideLabelFromVision
			/>
			{ value === 'fluid' && ! isCustom ? (
				<div className="newspack-ads-display-ads__ad-unit-fluid">
					{ __(
						'Fluid is a native ad size that allows more flexibility when styling your ad. It automatically sizes the ad by filling the width of the enclosing column and adjusting the height as appropriate.',
						'newspack-plugin'
					) }
				</div>
			) : (
				<>
					<TextControl
						label={ __( 'Width', 'newspack-plugin' ) }
						value={ value[ 0 ] }
						onChange={ newWidth => onChange( [ newWidth, value[ 1 ] ] ) }
						disabled={ ! isCustom && sizeIndex !== -1 }
						type="number"
						hideLabelFromVision
					/>
					<TextControl
						label={ __( 'Height', 'newspack-plugin' ) }
						value={ value[ 1 ] }
						onChange={ newHeight => onChange( [ value[ 0 ], newHeight ] ) }
						disabled={ ! isCustom && sizeIndex !== -1 }
						type="number"
						hideLabelFromVision
					/>
				</>
			) }
		</>
	);
};

export default AdUnitSizeControl;
