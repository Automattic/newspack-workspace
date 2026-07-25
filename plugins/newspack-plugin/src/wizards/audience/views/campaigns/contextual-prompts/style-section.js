/**
 * Contextual Prompts Style section.
 *
 * Block themes hand off to the Site Editor's Styles panel; classic themes get
 * controls that write site-wide defaults for the Contextual Prompt block.
 */

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import {
	BaseControl,
	ColorIndicator,
	ColorPalette,
	Disabled,
	Dropdown,
	FlexItem,
	FontSizePicker,
	Notice,
	RangeControl,
	__experimentalBorderBoxControl as BorderBoxControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalParseQuantityAndUnitFromRawValue as parseQuantityAndUnitFromRawValue, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalToolsPanel as ToolsPanel, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalToolsPanelItem as ToolsPanelItem, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalUnitControl as UnitControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import {
	Icon,
	cornerAll,
	link,
	linkOff,
	settings,
	sidesBottom,
	sidesHorizontal,
	sidesLeft,
	sidesRight,
	sidesTop,
	sidesVertical,
} from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { Button, Grid, Handoff, SectionHeader } from '../../../../../../packages/components/src';
import {
	contrastRatio,
	perceivedBrightness,
	presetRefForColor,
	resolveColor,
	spacingPresetOf,
	spacingRefForStep,
	spacingStepOf,
	spacingSteps,
	spacingValueOf,
} from './style-utils';
import './style-section.scss';

const MIN_CONTRAST_RATIO = 4.5;
// The size the editor gives the icon in front of a dimension input.
const ICON_SIZE = 24;
// The radius slider works in the value's own number space, capped where core's
// BorderControl caps its width slider.
const RADIUS_SLIDER_MAX = 100;

const getPath = ( source, path ) => path.reduce( ( node, key ) => node?.[ key ], source );

const hasKeys = value => !! value && 0 < Object.keys( value ).length;

// Per-side padding only. A theme.json may carry the shorthand string instead,
// which no per-side row can read, so it stands in as nothing.
const asSides = value => ( !! value && 'object' === typeof value ? value : {} );

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

// A theme can register its font size presets as plain numbers, and FontSizePicker
// hands back whatever shape it was given. Stored styles are CSS strings, and the
// REST layer keeps string leaves only, so a bare number would be dropped on save.
const withPixelUnit = size => ( 'number' === typeof size ? `${ size }px` : size );

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

// The editor's contrast warning, verbatim: the suggestion pushes the pair the way
// it already leans, so a dark background asks for a darker background still.
// Which one leans dark is core's perceived-brightness question, not a WCAG
// luminance one — the two disagree on saturated hues.
const contrastMessage = ( background, text ) =>
	perceivedBrightness( background ) < perceivedBrightness( text )
		? __(
				'This color combination may be hard for people to read. Try using a darker background color and/or a brighter text color.',
				'newspack-plugin'
		  )
		: __(
				'This color combination may be hard for people to read. Try using a brighter background color and/or a darker text color.',
				'newspack-plugin'
		  );

// Each group is one of the editor's block support panels: the group label and the
// options menu in the header, which offers a reset per item and a Reset all.
const StylePanel = ( { className, ...props } ) => (
	<ToolsPanel headingLevel={ 3 } className={ classnames( 'newspack-prompt-style-panel', className ) } { ...props } />
);

// The editor's color rows, composed from stable primitives: @wordpress/block-editor
// owns ColorGradientSettingsDropdown and is not loaded on the wizard page.
const ColorRow = ( { label, colorValue, disabled, children } ) => (
	<Dropdown
		className="newspack-prompt-style-colors__row"
		contentClassName="newspack-prompt-style-colors__popover"
		popoverProps={ { placement: 'bottom-start', shift: true } }
		renderToggle={ ( { isOpen, onToggle } ) => (
			<Button onClick={ onToggle } aria-expanded={ isOpen } disabled={ disabled } __next40pxDefaultSize>
				<HStack justify="flex-start" spacing={ 3 }>
					<ColorIndicator colorValue={ colorValue || undefined } />
					<FlexItem>{ label }</FlexItem>
				</HStack>
			</Button>
		) }
		renderContent={ () => children }
	/>
);

// One padding row: the axis or side icon, then either a slider over the site's
// spacing presets or, behind the settings toggle, a custom value. Mirrors the
// editor's spacing row — @wordpress/block-editor owns SpacingSizesControl and is
// not loaded on the wizard page.
const SpacingRow = ( { icon, label, steps, value, onChange, disabled } ) => {
	// A resolved default lands on the step it came from; anything else is custom.
	const preset = spacingPresetOf( value, steps );
	const step = spacingStepOf( preset, steps );
	const hasPresets = 1 < steps.length;
	const [ isCustom, setIsCustom ] = useState( () => ! hasPresets || ( undefined !== preset && null === step ) );

	// A value the slider cannot land on — a custom default, or one a reset brings
	// back — moves the row to the input, as the editor's row does.
	useEffect( () => {
		if ( undefined !== preset && null === step ) {
			setIsCustom( true );
		}
	}, [ preset, step ] );

	// Core marks every step but the two ends, which the slider's own stops carry.
	const marks = steps.slice( 1, steps.length - 1 ).map( ( _step, index ) => ( { value: index + 1 } ) );

	return (
		<HStack className="newspack-prompt-style-padding__row" spacing={ 2 }>
			<Icon className="newspack-prompt-style-padding__icon" icon={ icon } size={ ICON_SIZE } />
			{ isCustom ? (
				<UnitControl
					label={ label }
					hideLabelFromVision
					value={ spacingValueOf( preset, steps ) }
					onChange={ next => onChange( next || undefined ) }
					min={ 0 }
					disabled={ disabled }
					__next40pxDefaultSize
				/>
			) : (
				<RangeControl
					label={ label }
					hideLabelFromVision
					value={ step ?? 0 }
					onChange={ next => onChange( spacingRefForStep( next, steps ) ) }
					min={ 0 }
					max={ steps.length - 1 }
					step={ 1 }
					marks={ marks }
					initialPosition={ 0 }
					withInputField={ false }
					aria-valuetext={ steps[ step ?? 0 ]?.name }
					renderTooltipContent={ next => steps[ next ]?.name }
					disabled={ disabled }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			) }
			{ hasPresets && (
				<Button
					size="small"
					icon={ settings }
					iconSize={ ICON_SIZE }
					isPressed={ isCustom }
					label={ isCustom ? __( 'Use preset', 'newspack-plugin' ) : __( 'Set custom value', 'newspack-plugin' ) }
					onClick={ () => setIsCustom( ! isCustom ) }
					disabled={ disabled }
				/>
			) }
		</HStack>
	);
};

// Sides move in pairs until the sides are unlinked, which is how the editor
// opens whenever the two axes each hold one value.
const PaddingControl = ( { steps, values, onChange, disabled } ) => {
	const [ isLinked, setIsLinked ] = useState( () => values.top === values.bottom && values.left === values.right );
	const axialRows = [
		{ key: 'vertical', sides: [ 'top', 'bottom' ], icon: sidesVertical, label: __( 'Vertical padding', 'newspack-plugin' ) },
		{ key: 'horizontal', sides: [ 'left', 'right' ], icon: sidesHorizontal, label: __( 'Horizontal padding', 'newspack-plugin' ) },
	];
	// The editor's order for the unlinked sides, which is not the CSS shorthand's.
	const sideRows = [
		{ key: 'top', sides: [ 'top' ], icon: sidesTop, label: __( 'Top padding', 'newspack-plugin' ) },
		{ key: 'bottom', sides: [ 'bottom' ], icon: sidesBottom, label: __( 'Bottom padding', 'newspack-plugin' ) },
		{ key: 'left', sides: [ 'left' ], icon: sidesLeft, label: __( 'Left padding', 'newspack-plugin' ) },
		{ key: 'right', sides: [ 'right' ], icon: sidesRight, label: __( 'Right padding', 'newspack-plugin' ) },
	];

	return (
		<fieldset className="newspack-prompt-style-padding">
			<HStack className="newspack-prompt-style-padding__header">
				<BaseControl.VisualLabel as="legend">{ __( 'Padding', 'newspack-plugin' ) }</BaseControl.VisualLabel>
				<Button
					size="small"
					icon={ isLinked ? link : linkOff }
					iconSize={ ICON_SIZE }
					label={ isLinked ? __( 'Unlink sides', 'newspack-plugin' ) : __( 'Link sides', 'newspack-plugin' ) }
					onClick={ () => setIsLinked( ! isLinked ) }
					disabled={ disabled }
				/>
			</HStack>
			<VStack spacing={ 1 }>
				{ ( isLinked ? axialRows : sideRows ).map( ( { key, sides, icon, label } ) => (
					<SpacingRow
						key={ key }
						icon={ icon }
						label={ label }
						steps={ steps }
						value={ values[ sides[ 0 ] ] }
						onChange={ next => onChange( sides, next ) }
						disabled={ disabled }
					/>
				) ) }
			</VStack>
		</fieldset>
	);
};

const StyleSection = ( { status, styles = {}, inFlight, onChangeStyles } ) => {
	const {
		is_block_theme: isBlockTheme,
		site_editor_styles_url: siteEditorStylesUrl,
		style_defaults: styleDefaults,
		style_palette: stylePalette,
		style_font_sizes: styleFontSizes,
		style_spacing_sizes: styleSpacingSizes,
	} = status;

	const defaults = styleDefaults || {};
	const palette = stylePalette || [];
	// Global settings presets carry extra keys (fluid font sizes, gradients); the
	// controls only take the shapes they document.
	const paletteColors = palette.map( ( { name, slug, color } ) => ( { name, slug, color } ) );
	const fontSizes = ( styleFontSizes || [] ).map( ( { name, slug, size } ) => ( { name, slug, size: withPixelUnit( size ) } ) );
	const paddingSteps = spacingSteps( styleSpacingSizes, __( 'None', 'newspack-plugin' ) );
	const effective = path => getPath( styles, path ) ?? getPath( defaults, path );

	const radius = effective( [ 'border', 'radius' ] );
	// A stored per-corner object predates this single control: show nothing rather
	// than a wrong number, and leave it stored until an edit replaces it.
	const isSplitRadius = !! radius && 'object' === typeof radius;
	const radiusValue = isSplitRadius ? '' : radius;
	const [ radiusQuantity, radiusUnit ] = parseQuantityAndUnitFromRawValue( radiusValue );

	const background = resolveColor( effective( [ 'color', 'background' ] ), palette );
	const text = resolveColor( effective( [ 'color', 'text' ] ), palette );
	const ratio = background && text ? contrastRatio( background, text ) : null;

	const setColor = ( key, value ) => onChangeStyles( setPath( styles, [ 'color', key ], value ? presetRefForColor( value, palette ) : undefined ) );

	const setFontSize = value => onChangeStyles( setPath( styles, [ 'typography', 'fontSize' ], value ? withPixelUnit( value ) : undefined ) );

	// Each side reads its own effective value, so overriding one leaves the others
	// showing the default they still render with.
	const paddingValues = { ...asSides( defaults.spacing?.padding ), ...asSides( styles.spacing?.padding ) };

	const clearPadding = () => onChangeStyles( setPath( styles, [ 'spacing', 'padding' ], undefined ) );

	// Only the sides the row owns are written: the rest keep whatever they had,
	// default included, so the override stays as small as the edit.
	const setPaddingSides = ( sides, value ) => {
		const next = { ...asSides( styles.spacing?.padding ) };
		sides.forEach( side => {
			if ( undefined === value ) {
				delete next[ side ];
			} else {
				next[ side ] = value;
			}
		} );
		onChangeStyles( setPath( styles, [ 'spacing', 'padding' ], hasKeys( next ) ? next : undefined ) );
	};

	const borderOverride = withoutRadius( styles.border );
	const setBorder = value => {
		const next = withoutRadius( value );
		if ( undefined !== styles.border?.radius ) {
			next.radius = styles.border.radius;
		}
		onChangeStyles( setPath( styles, [ 'border' ], hasKeys( next ) ? next : undefined ) );
	};
	// The radius is its own group, so clearing the border keeps it.
	const clearBorder = () =>
		onChangeStyles( setPath( styles, [ 'border' ], undefined !== styles.border?.radius ? { radius: styles.border.radius } : undefined ) );

	const setRadius = value => onChangeStyles( setPath( styles, [ 'border', 'radius' ], value || undefined ) );
	// The slider only moves the number, so it writes back with the unit already in
	// play, as core's BorderControl does for the border width.
	const setRadiusQuantity = value => setRadius( undefined === value ? undefined : `${ value }${ radiusUnit || 'px' }` );

	// ColorPalette, FontSizePicker, BoxControl and BorderBoxControl take no
	// `disabled` prop, so the whole stack goes inert while a save is in flight.
	const classicControls = (
		<Disabled isDisabled={ !! inFlight }>
			<HStack alignment="top" justify="flex-end">
				<VStack spacing={ 0 } className="newspack-prompt-style-controls">
					<StylePanel
						label={ __( 'Color', 'newspack-plugin' ) }
						className="newspack-prompt-style-panel--color"
						resetAll={ () => onChangeStyles( setPath( styles, [ 'color' ], undefined ) ) }
						__experimentalFirstVisibleItemClass="newspack-prompt-style-colors--first"
						__experimentalLastVisibleItemClass="newspack-prompt-style-colors--last"
					>
						<ToolsPanelItem
							className="newspack-prompt-style-colors"
							label={ __( 'Text', 'newspack-plugin' ) }
							hasValue={ () => undefined !== styles.color?.text }
							onDeselect={ () => setColor( 'text' ) }
							isShownByDefault
						>
							<ColorRow label={ __( 'Text', 'newspack-plugin' ) } colorValue={ text } disabled={ inFlight }>
								<ColorPalette
									aria-label={ __( 'Text', 'newspack-plugin' ) }
									colors={ paletteColors }
									value={ text ?? undefined }
									onChange={ value => setColor( 'text', value ) }
								/>
							</ColorRow>
						</ToolsPanelItem>
						<ToolsPanelItem
							className="newspack-prompt-style-colors"
							label={ __( 'Background', 'newspack-plugin' ) }
							hasValue={ () => undefined !== styles.color?.background }
							onDeselect={ () => setColor( 'background' ) }
							isShownByDefault
						>
							<ColorRow label={ __( 'Background', 'newspack-plugin' ) } colorValue={ background } disabled={ inFlight }>
								<ColorPalette
									aria-label={ __( 'Background', 'newspack-plugin' ) }
									colors={ paletteColors }
									value={ background ?? undefined }
									onChange={ value => setColor( 'background', value ) }
								/>
							</ColorRow>
						</ToolsPanelItem>
						{ null !== ratio && ratio < MIN_CONTRAST_RATIO && (
							<Notice status="warning" isDismissible={ false } className="newspack-prompt-style-notice">
								{ contrastMessage( background, text ) }
							</Notice>
						) }
					</StylePanel>
					<StylePanel
						label={ __( 'Typography', 'newspack-plugin' ) }
						resetAll={ () => onChangeStyles( setPath( styles, [ 'typography' ], undefined ) ) }
					>
						<ToolsPanelItem
							label={ __( 'Font Size', 'newspack-plugin' ) }
							hasValue={ () => undefined !== styles.typography?.fontSize }
							onDeselect={ () => setFontSize() }
							isShownByDefault
						>
							<FontSizePicker
								fontSizes={ fontSizes }
								value={ effective( [ 'typography', 'fontSize' ] ) }
								onChange={ setFontSize }
								withReset={ false }
								__next40pxDefaultSize
							/>
						</ToolsPanelItem>
					</StylePanel>
					<StylePanel label={ __( 'Padding', 'newspack-plugin' ) } resetAll={ clearPadding }>
						<ToolsPanelItem
							label={ __( 'Padding', 'newspack-plugin' ) }
							hasValue={ () => undefined !== styles.spacing?.padding }
							onDeselect={ clearPadding }
							isShownByDefault
						>
							<PaddingControl steps={ paddingSteps } values={ paddingValues } onChange={ setPaddingSides } disabled={ inFlight } />
						</ToolsPanelItem>
					</StylePanel>
					<StylePanel
						label={ __( 'Border', 'newspack-plugin' ) }
						resetAll={ () => onChangeStyles( setPath( styles, [ 'border' ], undefined ) ) }
					>
						<ToolsPanelItem
							label={ __( 'Border', 'newspack-plugin' ) }
							hasValue={ () => hasKeys( borderOverride ) }
							onDeselect={ clearBorder }
							isShownByDefault
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
						</ToolsPanelItem>
						<ToolsPanelItem
							label={ __( 'Radius', 'newspack-plugin' ) }
							hasValue={ () => undefined !== styles.border?.radius }
							onDeselect={ () => setRadius() }
							isShownByDefault
						>
							<VStack spacing={ 2 }>
								<BaseControl.VisualLabel>{ __( 'Radius', 'newspack-plugin' ) }</BaseControl.VisualLabel>
								<HStack spacing={ 4 } className="newspack-prompt-style-radius">
									<Icon className="newspack-prompt-style-radius__icon" icon={ cornerAll } size={ ICON_SIZE } />
									<UnitControl
										label={ __( 'Radius', 'newspack-plugin' ) }
										hideLabelFromVision
										value={ radiusValue }
										onChange={ setRadius }
										min={ 0 }
										disabled={ inFlight }
										__next40pxDefaultSize
									/>
									<RangeControl
										label={ __( 'Border radius', 'newspack-plugin' ) }
										hideLabelFromVision
										value={ radiusQuantity }
										onChange={ setRadiusQuantity }
										min={ 0 }
										max={ RADIUS_SLIDER_MAX }
										step={ 'px' === ( radiusUnit || 'px' ) || '%' === radiusUnit ? 1 : 0.1 }
										initialPosition={ 0 }
										withInputField={ false }
										disabled={ inFlight }
										__nextHasNoMarginBottom
										__next40pxDefaultSize
									/>
								</HStack>
							</VStack>
						</ToolsPanelItem>
					</StylePanel>
				</VStack>
			</HStack>
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
