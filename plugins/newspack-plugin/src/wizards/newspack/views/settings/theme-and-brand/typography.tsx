/**
 * Newspack > Settings > Theme and Brand > Typography. Component for setting typography to use in your theme.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { TextareaControl, ToggleControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { Grid, SelectControl, TextControl } from '../../../../../../packages/components/src';
import { getFontImportURL, getFontsList, isFontInOptions, TYPOGRAPHY_OPTIONS } from './utils';

/**
 * Font Group schema.
 */
type FontGroup = {
	label: string;
	fallback?: string;
	options: Array< {
		label: string;
		value: string;
	} >;
};

export default function Typography( { data, isFetching, update }: ThemeModComponentProps & { isFetching: boolean } ) {
	const [ typographyOptionsType, updateTypographyOptionsType ] = useState< null | 'curated' | 'custom' >( null );

	useEffect( () => {
		if ( typographyOptionsType ) {
			return;
		}
		if ( data.font_body && data.font_header ) {
			updateTypographyOptionsType( getType() );
		}
	}, [ data.font_body, data.font_body ] );

	function getType() {
		const { font_header: headerFont, font_body: bodyFont } = data;
		if ( ( headerFont && ! isFontInOptions( headerFont ) ) || ( bodyFont && ! isFontInOptions( bodyFont ) ) ) {
			return TYPOGRAPHY_OPTIONS[ 1 ].value;
		}
		return TYPOGRAPHY_OPTIONS[ 0 ].value;
	}

	function updateTypographyState( objectOrKey: Partial< Typography > | string, change?: string | boolean ) {
		if ( objectOrKey instanceof Object ) {
			update( { ...data, ...objectOrKey } );
			return;
		}
		if ( typeof change === 'undefined' ) {
			return;
		}
		update( { ...data, [ objectOrKey ]: change } );
	}

	const renderCustomFontChoice = ( type: string ) => {
		const isHeadings = type === 'headings';
		const label = isHeadings ? __( 'Headings', 'newspack-plugin' ) : __( 'Body', 'newspack-plugin' );
		return (
			<Grid columns={ 1 } gutter={ 16 }>
				<TextareaControl
					label={ label + ' - ' + __( 'Font provider import code or URL', 'newspack-plugin' ) }
					placeholder={ 'https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,400;0,700;1,400;1,700&display=swap' }
					value={ ( isHeadings ? data.custom_font_import_code : data.custom_font_import_code_alternate ) ?? '' }
					onChange={ e => {
						updateTypographyState( isHeadings ? 'custom_font_import_code' : 'custom_font_import_code_alternate', e );
					} }
					rows={ 3 }
				/>
				<TextControl
					label={ label + ' - ' + __( 'Font name', 'newspack-plugin' ) }
					value={ isHeadings ? data.font_header : data.font_body }
					onChange={ ( e: string ) => {
						updateTypographyState( isHeadings ? 'font_header' : 'font_body', e );
					} }
				/>
				<SelectControl
					label={ label + ' - ' + __( 'Font fallback stack', 'newspack-plugin' ) }
					options={ [
						{
							value: 'serif',
							label: __( 'Serif', 'newspack-plugin' ),
						},
						{
							value: 'sans-serif',
							label: __( 'Sans Serif', 'newspack-plugin' ),
						},
						{
							value: 'display',
							label: __( 'Display', 'newspack-plugin' ),
						},
						{
							value: 'monospace',
							label: __( 'Monospace', 'newspack-plugin' ),
						},
					] }
					value={ isHeadings ? data.font_header_stack : data.font_body_stack }
					onChange={ ( e: string ) => updateTypographyState( isHeadings ? 'font_header_stack' : 'font_body_stack', e ) }
				/>
			</Grid>
		);
	};

	return (
		<Grid columns={ 1 } gutter={ 16 }>
			<SelectControl
				label={ __( 'Typography Options', 'newspack-plugin' ) }
				hideLabelFromVision
				disabled={ true }
				value={ typographyOptionsType ? typographyOptionsType : 'curated' }
				onChange={ updateTypographyOptionsType }
				buttonOptions={
					isFetching
						? [
								{
									label: __( 'Loading…', 'newspack-plugin' ),
									value: null,
								},
						  ]
						: TYPOGRAPHY_OPTIONS
				}
			/>
			<Grid gutter={ 32 }>
				{ typographyOptionsType === 'curated' || null === typographyOptionsType ? (
					<>
						<SelectControl
							label={ __( 'Headings', 'newspack-plugin' ) }
							optgroups={ getFontsList( true ) }
							value={ data.font_header }
							onChange={ ( value: string, group: FontGroup ) => {
								updateTypographyState( {
									font_header: value,
									custom_font_import_code: getFontImportURL( value ),
									font_header_stack: group?.fallback,
								} );
							} }
						/>
						<SelectControl
							label={ __( 'Body', 'newspack-plugin' ) }
							optgroups={ getFontsList() }
							value={ data.font_body }
							onChange={ ( value: string, group: FontGroup ) => {
								updateTypographyState( {
									font_body: value,
									custom_font_import_code_alternate: getFontImportURL( value ),
									font_body_stack: group?.fallback,
								} );
							} }
						/>
					</>
				) : (
					<>
						{ renderCustomFontChoice( 'headings' ) }
						{ renderCustomFontChoice( 'body' ) }
					</>
				) }
			</Grid>
			<ToggleControl
				checked={ data.accent_allcaps }
				onChange={ checked => updateTypographyState( 'accent_allcaps', checked ) }
				label={ __( 'Use all-caps for accent text', 'newspack-plugin' ) }
			/>
		</Grid>
	);
}
