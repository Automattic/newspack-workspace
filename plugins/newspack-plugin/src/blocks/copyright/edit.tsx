/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { dateI18n } from '@wordpress/date';
import { getBlockDefaultClassName } from '@wordpress/blocks';
import { InspectorControls, useBlockProps, RichText } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { decodeEntities } from '@wordpress/html-entities';

/**
 * Internal dependencies
 */
import metadata from './block.json';

const blockClass = getBlockDefaultClassName( metadata.name );

type CopyrightAttributes = {
	prefix: string;
	suffix: string;
	showSiteTitle: boolean;
	linkSiteTitle: boolean;
};

/**
 * Edit component for the Copyright block.
 *
 * @return Edit component.
 */
export default function Edit( {
	attributes,
	setAttributes,
}: {
	attributes: CopyrightAttributes;
	setAttributes: ( attributes: Partial< CopyrightAttributes > ) => void;
} ) {
	const { prefix, suffix, showSiteTitle, linkSiteTitle } = attributes;
	const blockProps = useBlockProps();
	const year = dateI18n( 'Y' );
	// The `__unstableBase` record is not in the typed entity map; assert the one field we read.
	const siteTitle = useSelect(
		select => ( select( coreStore ).getEntityRecord( 'root', '__unstableBase' ) as { name?: string } | undefined )?.name,
		[]
	);
	const suffixSeparator = /^[,.;:!?)]/.test( ( suffix || '' ).replace( /<[^>]*>/g, '' ) ) ? '' : ' ';

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'newspack-plugin' ) }>
					<ToggleControl
						label={ __( 'Show site title', 'newspack-plugin' ) }
						help={ __( 'Shows the site title after the year, and updates when the site title changes.', 'newspack-plugin' ) }
						checked={ !! showSiteTitle }
						onChange={ value => setAttributes( { showSiteTitle: value } ) }
					/>
					{ showSiteTitle && (
						<ToggleControl
							label={ __( 'Link to home page', 'newspack-plugin' ) }
							checked={ linkSiteTitle }
							onChange={ value => setAttributes( { linkSiteTitle: value } ) }
						/>
					) }
				</PanelBody>
			</InspectorControls>
			<RichText
				className={ `${ blockClass }__prefix` }
				tagName="span"
				placeholder={ __( 'Prefix…', 'newspack-plugin' ) }
				value={ prefix }
				onChange={ ( value: string ) => setAttributes( { prefix: value } ) }
				allowedFormats={ [ 'core/link' ] }
			/>
			<span className={ `${ blockClass }__year` }>{ year }</span>
			{ showSiteTitle && siteTitle && (
				<>
					{ ' ' }
					<span className={ `${ blockClass }__site-title` }>
						{ linkSiteTitle ? (
							<a href="#copyright-site-title-pseudo-link" onClick={ event => event.preventDefault() }>
								{ decodeEntities( siteTitle ) }
							</a>
						) : (
							decodeEntities( siteTitle )
						) }
					</span>
				</>
			) }
			{ suffixSeparator }
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
