/**
 * Newspack > Settings > Theme and Brand > Typography. Component for setting typography to use in your theme.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import {
	TextareaControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { SelectControl, TextControl } from '../../../../../../packages/components/src';
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

export default function Typography( { data, update }: ThemeModComponentProps ) {
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
			<>
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
					withMargin={ false }
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
			</>
		);
	};

	return (
		<Stack direction="column" gap="xl">
			<ToggleGroupControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				isBlock
				label={ __( 'Typography Options', 'newspack-plugin' ) }
				value={ typographyOptionsType ?? 'curated' }
				onChange={ value => updateTypographyOptionsType( value as 'curated' | 'custom' ) }
			>
				{ TYPOGRAPHY_OPTIONS.map( option => (
					<ToggleGroupControlOption key={ option.value } value={ option.value } label={ option.label } />
				) ) }
			</ToggleGroupControl>
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
			<ToggleGroupControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				isBlock
				label={ __( 'Accent Text', 'newspack-plugin' ) }
				value={ data.accent_allcaps ? 'allcaps' : 'as-written' }
				onChange={ value => updateTypographyState( 'accent_allcaps', value === 'allcaps' ) }
			>
				<ToggleGroupControlOption value="allcaps" label={ __( 'All caps', 'newspack-plugin' ) } />
				<ToggleGroupControlOption value="as-written" label={ __( 'As written', 'newspack-plugin' ) } />
			</ToggleGroupControl>
		</Stack>
	);
}
