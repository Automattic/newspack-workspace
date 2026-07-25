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
	__experimentalBoxControl as BoxControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalParseQuantityAndUnitFromRawValue as parseQuantityAndUnitFromRawValue, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalToolsPanel as ToolsPanel, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalToolsPanelItem as ToolsPanelItem, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalUnitControl as UnitControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { Icon, cornerAll } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { Button, Grid, Handoff, SectionHeader } from '../../../../../../packages/components/src';
import { contrastRatio, presetRefForColor, relativeLuminance, resolveColor } from './style-utils';
import './style-section.scss';

const MIN_CONTRAST_RATIO = 4.5;
// The size the editor gives the icon in front of a dimension input.
const ICON_SIZE = 24;
const PADDING_SIDES = [ 'top', 'right', 'bottom', 'left' ];
// The radius slider works in the value's own number space, capped where core's
// BorderControl caps its width slider.
const RADIUS_SLIDER_MAX = 100;

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

// The editor's contrast warning, verbatim: the suggestion pushes the pair the way
// it already leans, so a dark background asks for a darker background still.
const contrastMessage = ( background, text ) =>
	relativeLuminance( background ) < relativeLuminance( text )
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
	// A stored per-corner object predates this single control: show nothing rather
	// than a wrong number, and leave it stored until an edit replaces it.
	const isSplitRadius = !! radius && 'object' === typeof radius;
	const radiusValue = isSplitRadius ? '' : radius;
	const [ radiusQuantity, radiusUnit ] = parseQuantityAndUnitFromRawValue( radiusValue );

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
					<StylePanel
						label={ __( 'Padding', 'newspack-plugin' ) }
						resetAll={ () => onChangeStyles( setPath( styles, [ 'spacing', 'padding' ], undefined ) ) }
					>
						<ToolsPanelItem
							label={ __( 'Padding', 'newspack-plugin' ) }
							hasValue={ () => undefined !== styles.spacing?.padding }
							onDeselect={ () => setPadding() }
							isShownByDefault
						>
							<BoxControl
								label={ __( 'Padding', 'newspack-plugin' ) }
								values={ effective( [ 'spacing', 'padding' ] ) }
								onChange={ setPadding }
								splitOnAxis={ false }
								allowReset={ false }
								__next40pxDefaultSize
							/>
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
