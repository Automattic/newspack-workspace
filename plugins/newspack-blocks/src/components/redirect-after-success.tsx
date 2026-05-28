/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { TextControl, SelectControl } from '@wordpress/components';

type Props = {
	attributes: {
		afterSuccessBehavior: string;
		afterSuccessButtonLabel: string;
		afterSuccessURL: string;
	};
	setAttributes: ( attributes: Partial< Props[ 'attributes' ] > ) => void;
};

const RedirectAfterSuccess = ( { attributes, setAttributes }: Props ) => (
	<>
		<SelectControl
			label={ __( 'Post-Checkout Button', 'newspack-blocks' ) }
			help={ __( 'After a successful purchase, a button will be presented to finish the process.', 'newspack-blocks' ) }
			value={ attributes.afterSuccessBehavior }
			options={ [
				{ label: __( 'Close the modal', 'newspack-blocks' ), value: '' },
				{ label: __( 'Go to a custom URL', 'newspack-blocks' ), value: 'custom' },
				{ label: __( 'Go to the previous page', 'newspack-blocks' ), value: 'referrer' },
			] }
			onChange={ ( value: string ) => {
				setAttributes( { afterSuccessBehavior: value.toString() } );
			} }
			__next40pxDefaultSize
		/>
		<TextControl
			label={ __( 'Button Label', 'newspack-blocks' ) }
			value={ attributes.afterSuccessButtonLabel || '' }
			onChange={ ( value: string ) => setAttributes( { afterSuccessButtonLabel: value } ) }
			__next40pxDefaultSize
		/>
		{ attributes.afterSuccessBehavior === 'custom' && (
			<TextControl
				label={ __( 'Custom URL', 'newspack-blocks' ) }
				placeholder={ __( 'https://example.com', 'newspack-blocks' ) }
				value={ attributes.afterSuccessURL || '' }
				onChange={ ( value: string ) => setAttributes( { afterSuccessURL: value } ) }
				__next40pxDefaultSize
			/>
		) }
	</>
);

export default RedirectAfterSuccess;
