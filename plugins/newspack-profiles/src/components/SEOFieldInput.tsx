import { FormTokenField } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { sanitizeSEOFieldToken } from '../utils';

interface SEOFieldInputProps {
	label: string;
	value: string[];
	fields: string[];
	onChange: ( tokens: string[] ) => void;
}

export const SEOFieldInput = ( {
	label,
	value,
	fields,
	onChange,
}: SEOFieldInputProps ) => (
	<div>
		<FormTokenField
			label={ label }
			value={ value }
			suggestions={ fields }
			onChange={ ( tokens ) =>
				onChange(
					( tokens as string[] )
						.map( ( t ) => sanitizeSEOFieldToken( String( t ) ) )
						.filter( Boolean )
				)
			}
			__experimentalExpandOnFocus
			__experimentalShowHowTo={ false }
			__next40pxDefaultSize
			__nextHasNoMarginBottom
		/>
		<p className="newspack-profiles__help-text">
			{ __(
				'Add fields from the list or type a delimiter (, / | - _) to separate them. Any other text will be included as-is.',
				'newspack-profiles'
			) }
		</p>
	</div>
);
