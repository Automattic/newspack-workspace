/**
 * Contextual Prompts Style section.
 *
 * Block themes hand off to the Site Editor's Styles panel; classic themes get
 * controls that write site-wide defaults for the Contextual Prompt block.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	BaseControl,
	ColorPalette,
	Disabled,
	FontSizePicker,
	Notice,
	__experimentalBorderBoxControl as BorderBoxControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalBoxControl as BoxControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalHeading as Heading, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalUnitControl as UnitControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { link, linkOff } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { Button, Grid, Handoff, SectionHeader } from '../../../../../../packages/components/src';
import { contrastRatio, presetRefForColor, resolveColor } from './style-utils';

const MIN_CONTRAST_RATIO = 4.5;
const PADDING_SIDES = [ 'top', 'right', 'bottom', 'left' ];
const RADIUS_CORNERS = [
	[ 'topLeft', __( 'Top left', 'newspack-plugin' ) ],
	[ 'topRight', __( 'Top right', 'newspack-plugin' ) ],
	[ 'bottomLeft', __( 'Bottom left', 'newspack-plugin' ) ],
	[ 'bottomRight', __( 'Bottom right', 'newspack-plugin' ) ],
];

const getPath = ( source, path ) => path.reduce( ( node, key ) => node?.[ key ], source );

const hasKeys = value => !! value && 0 < Object.keys( value ).length;

// Immutable deep-set that deletes the key when the value is undefined and prunes
// nodes left empty: the REST layer replaces the whole object, and an all-empty
// one clears the stored option, so `{ color: {} }` residue would keep it alive.
const setPath = ( source, path, value ) => {
	const [ head, ...rest ] = path;
	const next = { ...source };
	if ( 0 === rest.length ) {
		if ( undefined === value ) {
			delete next[ head ];
		} else {
			next[ head ] = value;
		}
	} else {
		const child = setPath( next[ head ] || {}, rest, value );
		if ( 0 === Object.keys( child ).length ) {
			delete next[ head ];
		} else {
			next[ head ] = child;
		}
	}
	return next;
};

// Border radius is its own group, so the border group reads and writes every
// border key except the radius.
const withoutRadius = border => {
	const next = {};
	Object.entries( border || {} ).forEach( ( [ key, value ] ) => {
		if ( 'radius' !== key && undefined !== value ) {
			next[ key ] = value;
		}
	} );
	return next;
};

const StyleGroup = ( { label, hasOverride, onReset, disabled, children } ) => (
	<VStack spacing={ 3 }>
		<HStack justify="space-between" alignment="center" spacing={ 2 }>
			<Heading level={ 3 } size={ 13 }>
				{ label }
			</Heading>
			{ hasOverride && (
				<Button variant="tertiary" isSmall onClick={ onReset } disabled={ disabled }>
					{ __( 'Reset', 'newspack-plugin' ) }
				</Button>
			) }
		</HStack>
		{ children }
	</VStack>
);

const StyleSection = ( { status, styles = {}, inFlight, onChangeStyles } ) => {
	const {
		is_block_theme: isBlockTheme,
		site_editor_styles_url: siteEditorStylesUrl,
		style_defaults: styleDefaults,
		style_palette: stylePalette,
		style_font_sizes: styleFontSizes,
	} = status;

	const defaults = styleDefaults || {};
	const palette = stylePalette || [];
	// Global settings presets carry extra keys (fluid font sizes, gradients); the
	// controls only take the shapes they document.
	const paletteColors = palette.map( ( { name, slug, color } ) => ( { name, slug, color } ) );
	const fontSizes = ( styleFontSizes || [] ).map( ( { name, slug, size } ) => ( { name, slug, size } ) );
	const effective = path => getPath( styles, path ) ?? getPath( defaults, path );

	const radius = effective( [ 'border', 'radius' ] );
	const isSplitRadius = !! radius && 'object' === typeof radius;
	const [ radiusLinked, setRadiusLinked ] = useState( ! isSplitRadius );

	const background = resolveColor( effective( [ 'color', 'background' ] ), palette );
	const text = resolveColor( effective( [ 'color', 'text' ] ), palette );
	const ratio = background && text ? contrastRatio( background, text ) : null;

	const setColor = ( key, value ) => onChangeStyles( setPath( styles, [ 'color', key ], value ? presetRefForColor( value, palette ) : undefined ) );

	const setFontSize = value => onChangeStyles( setPath( styles, [ 'typography', 'fontSize' ], value || undefined ) );

	const setPadding = value => {
		const sides = {};
		PADDING_SIDES.forEach( side => {
			if ( undefined !== value?.[ side ] ) {
				sides[ side ] = value[ side ];
			}
		} );
		onChangeStyles( setPath( styles, [ 'spacing', 'padding' ], hasKeys( sides ) ? sides : undefined ) );
	};

	const borderOverride = withoutRadius( styles.border );
	const setBorder = value => {
		const next = withoutRadius( value );
		if ( undefined !== styles.border?.radius ) {
			next.radius = styles.border.radius;
		}
		onChangeStyles( setPath( styles, [ 'border' ], hasKeys( next ) ? next : undefined ) );
	};

	const setRadius = value => onChangeStyles( setPath( styles, [ 'border', 'radius' ], value || undefined ) );
	// Unlinking an unsplit radius carries its value onto every corner, so the
	// corners the publisher does not touch keep what they were showing.
	const setRadiusCorner = ( corner, value ) => {
		const next = isSplitRadius ? { ...radius } : {};
		if ( ! isSplitRadius && radius ) {
			RADIUS_CORNERS.forEach( ( [ key ] ) => {
				next[ key ] = radius;
			} );
		}
		if ( value ) {
			next[ corner ] = value;
		} else {
			delete next[ corner ];
		}
		onChangeStyles( setPath( styles, [ 'border', 'radius' ], hasKeys( next ) ? next : undefined ) );
	};

	// ColorPalette, FontSizePicker, BoxControl and BorderBoxControl take no
	// `disabled` prop, so the whole stack goes inert while a save is in flight.
	const classicControls = (
		<Disabled isDisabled={ !! inFlight }>
			<VStack spacing={ 6 }>
				<StyleGroup
					label={ __( 'Color', 'newspack-plugin' ) }
					hasOverride={ hasKeys( styles.color ) }
					onReset={ () => onChangeStyles( setPath( styles, [ 'color' ], undefined ) ) }
					disabled={ inFlight }
				>
					<VStack spacing={ 2 }>
						<BaseControl.VisualLabel>{ __( 'Background', 'newspack-plugin' ) }</BaseControl.VisualLabel>
						<ColorPalette
							aria-label={ __( 'Background', 'newspack-plugin' ) }
							colors={ paletteColors }
							value={ background ?? undefined }
							onChange={ value => setColor( 'background', value ) }
						/>
					</VStack>
					<VStack spacing={ 2 }>
						<BaseControl.VisualLabel>{ __( 'Text', 'newspack-plugin' ) }</BaseControl.VisualLabel>
						<ColorPalette
							aria-label={ __( 'Text', 'newspack-plugin' ) }
							colors={ paletteColors }
							value={ text ?? undefined }
							onChange={ value => setColor( 'text', value ) }
						/>
					</VStack>
					{ null !== ratio && ratio < MIN_CONTRAST_RATIO && (
						<Notice status="warning" isDismissible={ false } style={ { margin: 0 } }>
							{ __( 'This color combination may be hard for people to read.', 'newspack-plugin' ) }
						</Notice>
					) }
				</StyleGroup>
				<StyleGroup
					label={ __( 'Typography', 'newspack-plugin' ) }
					hasOverride={ hasKeys( styles.typography ) }
					onReset={ () => onChangeStyles( setPath( styles, [ 'typography' ], undefined ) ) }
					disabled={ inFlight }
				>
					<FontSizePicker
						fontSizes={ fontSizes }
						value={ effective( [ 'typography', 'fontSize' ] ) }
						onChange={ setFontSize }
						withReset={ false }
						__next40pxDefaultSize
					/>
				</StyleGroup>
				<StyleGroup
					label={ __( 'Padding', 'newspack-plugin' ) }
					hasOverride={ undefined !== styles.spacing?.padding }
					onReset={ () => onChangeStyles( setPath( styles, [ 'spacing', 'padding' ], undefined ) ) }
					disabled={ inFlight }
				>
					<BoxControl
						label={ __( 'Padding', 'newspack-plugin' ) }
						values={ effective( [ 'spacing', 'padding' ] ) }
						onChange={ setPadding }
						splitOnAxis={ false }
						allowReset={ false }
						__next40pxDefaultSize
					/>
				</StyleGroup>
				<StyleGroup
					label={ __( 'Border', 'newspack-plugin' ) }
					hasOverride={ hasKeys( borderOverride ) }
					onReset={ () =>
						onChangeStyles(
							setPath( styles, [ 'border' ], undefined !== styles.border?.radius ? { radius: styles.border.radius } : undefined )
						)
					}
					disabled={ inFlight }
				>
					<BorderBoxControl
						label={ __( 'Border', 'newspack-plugin' ) }
						hideLabelFromVision
						colors={ paletteColors }
						value={ hasKeys( borderOverride ) ? borderOverride : withoutRadius( defaults.border ) }
						onChange={ setBorder }
						enableStyle
						__next40pxDefaultSize
					/>
				</StyleGroup>
				<StyleGroup
					label={ __( 'Border Radius', 'newspack-plugin' ) }
					hasOverride={ undefined !== styles.border?.radius }
					onReset={ () => onChangeStyles( setPath( styles, [ 'border', 'radius' ], undefined ) ) }
					disabled={ inFlight }
				>
					<HStack alignment="flex-end" justify="space-between" spacing={ 2 }>
						{ radiusLinked ? (
							<UnitControl
								label={ __( 'Radius', 'newspack-plugin' ) }
								value={ isSplitRadius ? '' : radius }
								onChange={ setRadius }
								min={ 0 }
								disabled={ inFlight }
								__next40pxDefaultSize
							/>
						) : (
							<Grid columns={ 2 } gutter={ 8 } noMargin>
								{ RADIUS_CORNERS.map( ( [ corner, cornerLabel ] ) => (
									<UnitControl
										key={ corner }
										label={ cornerLabel }
										value={ isSplitRadius ? radius[ corner ] : radius }
										onChange={ value => setRadiusCorner( corner, value ) }
										min={ 0 }
										disabled={ inFlight }
										__next40pxDefaultSize
									/>
								) ) }
							</Grid>
						) }
						<Button
							icon={ radiusLinked ? link : linkOff }
							label={ radiusLinked ? __( 'Unlink Corners', 'newspack-plugin' ) : __( 'Link Corners', 'newspack-plugin' ) }
							onClick={ () => setRadiusLinked( ! radiusLinked ) }
							disabled={ inFlight }
							isSmall
						/>
					</HStack>
				</StyleGroup>
			</VStack>
		</Disabled>
	);

	return (
		<Grid columns={ 2 } gutter={ 32 } noMargin>
			<SectionHeader
				heading={ 2 }
				title={ __( 'Style', 'newspack-plugin' ) }
				description={
					isBlockTheme
						? __( "Contextual Prompt styles are managed in the Site Editor's Styles panel.", 'newspack-plugin' )
						: __(
								'Site-wide default styles for the Contextual Prompt block. Styles set on an individual block override these.',
								'newspack-plugin'
						  )
				}
				noMargin
			/>
			{ isBlockTheme ? (
				<VStack spacing={ 6 } alignment="start">
					<Handoff url={ siteEditorStylesUrl } __next40pxDefaultSize>
						{ __( 'Edit Styles', 'newspack-plugin' ) }
					</Handoff>
				</VStack>
			) : (
				classicControls
			) }
		</Grid>
	);
};

export default StyleSection;
