/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, PanelRow, Placeholder, SelectControl, TextControl, ToggleControl } from '@wordpress/components';
import { useEffect } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { currencyDollar } from '@wordpress/icons';

/**
 * Matches `blocks/price/block.json`'s `attributes`.
 */
export type PriceAttributes = {
	price: number;
	currency: string;
	formattedPrice: string;
	showDecimals: boolean;
};

type PriceEditorProps = {
	attributes: PriceAttributes;
	isSelected: boolean;
	setAttributes: ( attributes: Partial< PriceAttributes > ) => void;
};

export const PriceEditor = ( { attributes, isSelected, setAttributes }: PriceEditorProps ) => {
	const { currencies = {}, currency: defaultCurrency = 'USD' } = window.newspack_listings_data;
	const locale = window.navigator?.language || 'en-US';
	const { currency, formattedPrice, price, showDecimals } = attributes;
	const blockProps = useBlockProps();

	useEffect( () => {
		// Guard against setting invalid price attribute. `price` is declared as a
		// `number` (see `PriceAttributes`), but the `'' === price` check guards
		// against a stored value that doesn't actually match its declared type at
		// runtime -- cast to `unknown` for the comparison rather than widening
		// the declared (and typical) type.
		if ( isNaN( price ) || '' === ( price as unknown ) || 0 > price ) {
			setAttributes( { price: 0 } );
		}
	}, [ isSelected ] );

	useEffect( () => {
		// Guard against rendering invalid price attribute.
		const priceToFormat = isNaN( price ) || '' === ( price as unknown ) || 0 > price ? 0 : price;

		// Format price according to editor's locale.
		setAttributes( {
			formattedPrice: new Intl.NumberFormat( locale, {
				style: 'currency',
				currency: currency || defaultCurrency,
				minimumFractionDigits: showDecimals ? 2 : 0,
				maximumFractionDigits: showDecimals ? 2 : 0,
			} ).format( priceToFormat ),
		} );
	}, [ currency, showDecimals, price ] );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Price Settings' ) }>
					{ 0 < Object.keys( currencies ).length && (
						<SelectControl
							label={ __( 'Select currency', 'newspack-listings' ) }
							value={ currency || defaultCurrency }
							onChange={ value => setAttributes( { currency: value } ) }
							options={ Object.keys( currencies )
								.map( _currency => {
									return {
										value: _currency,
										label: `${ decodeEntities( currencies[ _currency ] ) } (${ _currency })`,
									};
								} )
								.sort( ( a, b ) => a.label.toUpperCase().localeCompare( b.label.toUpperCase() ) ) }
						/>
					) }
					<PanelRow>
						<ToggleControl
							className="newspack-listings__decimals-toggle"
							label={ __( 'Show decimals', 'newspack-listings' ) }
							help={ __( 'If disabled, the price shown will be rounded to the nearest integer.', 'newspack-listings' ) }
							checked={ showDecimals }
							onChange={ value => setAttributes( { showDecimals: value } ) }
						/>
					</PanelRow>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				{ isSelected ? (
					<Placeholder icon={ currencyDollar } label={ __( 'Price', 'newspack-listings' ) } isColumnLayout>
						<TextControl
							label={ sprintf(
								// translators: %s: currency.
								__( 'Price in %s', 'newspack-listings' ),
								currency || defaultCurrency
							) }
							type="number"
							value={ price }
							onChange={ value => {
								setAttributes( {
									// `value` is always a string (`TextControl`'s `onChange`); the
									// original `value < 0` relies on JS's implicit string-to-number
									// coercion for the comparison, and `parseFloat( 0 )` on the
									// truthy branch relies on implicit number-to-string coercion --
									// both made explicit here without changing the result.
									price: parseFloat( Number( value ) < 0 ? '0' : value ),
								} );
							} }
						/>
					</Placeholder>
				) : (
					<p className="newspack-listings__price has-large-font-size">
						<strong>{ formattedPrice }</strong>
					</p>
				) }
			</div>
		</>
	);
};
