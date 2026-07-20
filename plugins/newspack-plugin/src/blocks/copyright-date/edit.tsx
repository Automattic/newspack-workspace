/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { dateI18n } from '@wordpress/date';
import { getBlockDefaultClassName } from '@wordpress/blocks';
import { useBlockProps, RichText } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import metadata from './block.json';

const blockClass = getBlockDefaultClassName( metadata.name );

/**
 * Attributes of the Copyright Date block.
 */
type CopyrightDateAttributes = {
	prefix: string;
	suffix: string;
};

/**
 * Props for the Copyright Date edit component.
 */
type CopyrightDateEditProps = {
	/** Block attributes. */
	attributes: CopyrightDateAttributes;
	/** Set attributes function. */
	setAttributes: ( attributes: Partial< CopyrightDateAttributes > ) => void;
};

/**
 * Edit component for the copyright date block.
 *
 * @param props               Component props.
 * @param props.attributes    Block attributes.
 * @param props.setAttributes Set attributes function.
 * @return Edit component.
 */
export default function Edit( { attributes, setAttributes }: CopyrightDateEditProps ) {
	const { prefix, suffix } = attributes;
	const blockProps = useBlockProps();
	const year = dateI18n( 'Y' );

	return (
		<div { ...blockProps }>
			<RichText
				className={ `${ blockClass }__prefix` }
				tagName="span"
				placeholder={ __( 'Prefix…', 'newspack-plugin' ) }
				value={ prefix }
				onChange={ ( value: string ) => setAttributes( { prefix: value } ) }
				allowedFormats={ [ 'core/link' ] }
			/>
			<span className={ `${ blockClass }__year` }>{ year }</span>{ ' ' }
			<RichText
				className={ `${ blockClass }__suffix` }
				tagName="span"
				placeholder={ __( 'Suffix…', 'newspack-plugin' ) }
				value={ suffix }
				onChange={ ( value: string ) => setAttributes( { suffix: value } ) }
				allowedFormats={ [ 'core/link' ] }
			/>
		</div>
	);
}
