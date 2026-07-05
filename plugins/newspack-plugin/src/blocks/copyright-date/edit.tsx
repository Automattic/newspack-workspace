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
 * Edit component for the copyright date block.
 *
 * @param {Object}   props               Component props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Set attributes function.
 * @return {JSX.Element} Edit component.
 */
export default function Edit( { attributes, setAttributes } ) {
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
				onChange={ value => setAttributes( { prefix: value } ) }
				allowedFormats={ [ 'core/link' ] }
			/>
			<span className={ `${ blockClass }__year` }>{ year }</span>{ ' ' }
			<RichText
				className={ `${ blockClass }__suffix` }
				tagName="span"
				placeholder={ __( 'Suffix…', 'newspack-plugin' ) }
				value={ suffix }
				onChange={ value => setAttributes( { suffix: value } ) }
				allowedFormats={ [ 'core/link' ] }
			/>
		</div>
	);
}
