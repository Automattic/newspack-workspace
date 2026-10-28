/* eslint-disable @wordpress/no-unsafe-wp-apis */
/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import {
	RichText,
	useBlockProps,
	__experimentalGetBorderClassesAndStyles as getBorderClassesAndStyles,
	__experimentalGetColorClassesAndStyles as getColorClassesAndStyles,
	__experimentalGetSpacingClassesAndStyles as getSpacingClassesAndStyles,
} from '@wordpress/block-editor';

import metadata from './block.json';

const v1 = {
	...metadata,
	attributes: {
		...metadata.attributes,
		afterSuccessButtonLabel: {
			type: 'string',
			default: 'Continue browsing',
		},
	},

	save( { attributes, className } ) {
		const { textAlign, fontSize, style, text, product, price, variation, is_variable, width } = attributes;

		if ( ! text || ! product ) {
			return null;
		}

		const borderProps = getBorderClassesAndStyles( attributes );
		const colorProps = getColorClassesAndStyles( attributes );
		const spacingProps = getSpacingClassesAndStyles( attributes );
		const buttonClasses = classnames( 'wp-block-button__link', colorProps.className, borderProps.className, {
			[ `has-text-align-${ textAlign }` ]: textAlign,
			// For backwards compatibility add style that isn't provided via
			// block support.
			'no-border-radius': style?.border?.radius === 0,
		} );
		const buttonStyle = {
			...borderProps.style,
			...colorProps.style,
			...spacingProps.style,
		};

		const wrapperClasses = classnames( className, 'wp-block-button', {
			[ `has-custom-font-size` ]: fontSize || style?.typography?.fontSize,
			[ `has-custom-width wp-block-button__width-${ width }` ]: width,
		} );

		return (
			<div { ...useBlockProps.save( { className: wrapperClasses } ) }>
				<form>
					<RichText.Content tagName="button" className={ buttonClasses } style={ buttonStyle } value={ text } />
					<input type="hidden" name="product_id" value={ product } />
					<input type="hidden" name="newspack_checkout" value="1" />

					{ price && <input type="hidden" name="price" value={ price } /> }
					{ variation && <input type="hidden" name="variation_id" value={ variation } /> }
					{ is_variable && <input type="hidden" name="is_variable" value="1" /> }

					{ attributes.afterSuccessBehavior && (
						<input type="hidden" name="after_success_behavior" value={ attributes.afterSuccessBehavior } />
					) }

					{ attributes.afterSuccessButtonLabel && (
						<input type="hidden" name="after_success_button_label" value={ attributes.afterSuccessButtonLabel } />
					) }
					{ attributes.afterSuccessBehavior && <input type="hidden" name="after_success_url" value={ attributes.afterSuccessURL || '' } /> }
				</form>
			</div>
		);
	},
};
export default [ v1 ];
