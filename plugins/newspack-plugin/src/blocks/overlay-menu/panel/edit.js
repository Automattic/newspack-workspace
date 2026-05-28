/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { close as closeIcon } from '@wordpress/icons';
import { useLayoutEffect, useRef, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalColorGradientSettingsDropdown as ColorGradientSettingsDropdown,
	InnerBlocks,
	InspectorControls,
	useBlockProps,
	useSettings,
} from '@wordpress/block-editor';
import {
	PanelBody,
	ToggleControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import PanelPreviewToggle from '../panel-preview-toggle';
import { panelToggles, notifySubscribers } from '../preview-refs';

const DIRECTION_CONFIG = {
	left: { positionClass: 'overlay-menu__panel--left' },
	right: { positionClass: 'overlay-menu__panel--right' },
};

const INNER_BLOCKS_TEMPLATE = [ [ 'core/navigation', { layout: { type: 'flex', orientation: 'vertical' } } ] ];

const PANEL_WIDTH_OPTIONS = [
	{ value: 'x-small', label: __( 'XS', 'newspack-plugin' ), ariaLabel: __( 'Extra small', 'newspack-plugin' ) },
	{ value: 'small', label: __( 'S', 'newspack-plugin' ), ariaLabel: __( 'Small', 'newspack-plugin' ) },
	{ value: 'medium', label: __( 'M', 'newspack-plugin' ), ariaLabel: __( 'Medium', 'newspack-plugin' ) },
	{ value: 'large', label: __( 'L', 'newspack-plugin' ), ariaLabel: __( 'Large', 'newspack-plugin' ) },
	{ value: 'x-large', label: __( 'XL', 'newspack-plugin' ), ariaLabel: __( 'Extra large', 'newspack-plugin' ) },
];

/**
 * Edit component for the Overlay Menu Panel block.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {string}   props.clientId      Block client ID.
 * @param {Function} props.setAttributes Attribute setter.
 *
 * @return {JSX.Element} The block editor UI.
 */
export default function OverlayMenuPanelEdit( { attributes, clientId, setAttributes } ) {
	const { slideDirection, overlayColor, panelBackgroundColor, panelTextColor, isFullScreen, panelWidth } = attributes;

	const [ isPreviewOpen, setIsPreviewOpen ] = useState( false );

	// Keep a ref to the current open state so the toggle never has a stale closure.
	const isOpenRef = useRef( false );
	isOpenRef.current = isPreviewOpen;

	// Key everything by the parent's clientId so the parent/trigger can look up the toggle using their own clientId or getBlockRootClientId.
	const parentClientId = useSelect( select => select( 'core/block-editor' ).getBlockRootClientId( clientId ), [ clientId ] );

	// Keep a stable ref to the toggle function so the Map entry is always current.
	const toggleFnRef = useRef( null );
	toggleFnRef.current = () => {
		const next = ! isOpenRef.current;
		setIsPreviewOpen( next );
		if ( parentClientId ) {
			notifySubscribers( parentClientId, next );
		}
	};

	// Render-phase registration: runs even when the component renders inside a
	// React transition that hasn't committed yet (e.g. the site editor wrapping a
	// template-part switch in startTransition). This is the fallback that makes the
	// toggle available before the commit phase fires.
	if ( parentClientId ) {
		panelToggles.set( parentClientId, () => toggleFnRef.current?.() );
	}

	// Authoritative registration and cleanup in the commit phase. useLayoutEffect
	// overwrites the render-phase entry once the component commits, and its cleanup
	// reliably removes the entry on unmount or parentClientId change — preventing
	// stale Map entries from aborted renders lingering after the component unmounts.
	useLayoutEffect( () => {
		if ( ! parentClientId ) {
			return;
		}
		panelToggles.set( parentClientId, () => toggleFnRef.current?.() );
		return () => panelToggles.delete( parentClientId );
	}, [ parentClientId ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Update local state and notify all subscribers (parent + trigger toolbar buttons).
	const togglePreview = open => {
		setIsPreviewOpen( open );
		if ( parentClientId ) {
			notifySubscribers( parentClientId, open );
		}
	};

	const { positionClass } = DIRECTION_CONFIG[ slideDirection ] ?? DIRECTION_CONFIG.left;

	// Fetch the theme's color palette for the color panel.
	const [ colorSettings ] = useSettings( 'color.palette' );

	const openPanelClasses = isFullScreen
		? 'overlay-menu__panel is-layout-constrained overlay-menu__panel--full-screen overlay-menu__panel--open'
		: `overlay-menu__panel is-layout-constrained ${ positionClass } overlay-menu__panel--width--${ panelWidth } overlay-menu__panel--open`;

	const panelClassName = isPreviewOpen ? openPanelClasses : 'overlay-menu__editor-panel-hidden';
	const panelStyle = isPreviewOpen
		? {
				// Force fixed positioning in the editor — Gutenberg can override
				// class-based position on block root elements, so we use an inline
				// style to guarantee it takes effect.
				position: 'fixed',
				...( panelBackgroundColor && { background: panelBackgroundColor } ),
				...( panelTextColor && { color: panelTextColor } ),
		  }
		: {};

	const blockProps = useBlockProps( { className: panelClassName, style: panelStyle } );

	return (
		<>
			<PanelPreviewToggle isOpen={ isPreviewOpen } onToggle={ () => togglePreview( ! isPreviewOpen ) } />

			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'newspack-plugin' ) } initialOpen={ true }>
					<ToggleControl
						label={ __( 'Full screen', 'newspack-plugin' ) }
						help={ __( 'When enabled, the panel expands to fill the entire viewport.', 'newspack-plugin' ) }
						checked={ isFullScreen }
						onChange={ val => setAttributes( { isFullScreen: val } ) }
					/>
					{ ! isFullScreen && (
						<>
							<ToggleGroupControl
								label={ __( 'Slide direction', 'newspack-plugin' ) }
								help={ __( 'Choose which side of the screen the panel slides in from.', 'newspack-plugin' ) }
								value={ slideDirection }
								onChange={ val => setAttributes( { slideDirection: val } ) }
								isBlock
							>
								<ToggleGroupControlOption value="left" label={ __( 'Left', 'newspack-plugin' ) } />
								<ToggleGroupControlOption value="right" label={ __( 'Right', 'newspack-plugin' ) } />
							</ToggleGroupControl>
							<ToggleGroupControl
								label={ __( 'Width', 'newspack-plugin' ) }
								help={ __(
									'Set how wide the panel appears when opened. Use smaller sizes for simple navigation and larger sizes for rich content layouts.',
									'newspack-plugin'
								) }
								value={ panelWidth }
								onChange={ val => setAttributes( { panelWidth: val } ) }
								isBlock
							>
								{ PANEL_WIDTH_OPTIONS.map( ( { value, label, ariaLabel } ) => (
									<ToggleGroupControlOption key={ value } value={ value } label={ label } aria-label={ ariaLabel } />
								) ) }
							</ToggleGroupControl>
						</>
					) }
				</PanelBody>
			</InspectorControls>

			<InspectorControls group="color">
				<ColorGradientSettingsDropdown
					settings={ [
						{
							label: __( 'Text', 'newspack-plugin' ),
							colorValue: panelTextColor,
							onColorChange: val => setAttributes( { panelTextColor: val ?? '' } ),
						},
						{
							label: __( 'Background', 'newspack-plugin' ),
							colorValue: panelBackgroundColor,
							onColorChange: val => setAttributes( { panelBackgroundColor: val ?? '' } ),
						},
						{
							label: __( 'Overlay', 'newspack-plugin' ),
							colorValue: overlayColor,
							onColorChange: val => setAttributes( { overlayColor: val ?? '' } ),
						},
					] }
					panelId={ clientId }
					colors={ colorSettings }
					gradients={ [] }
					enableAlpha
					disableCustomGradients
					__experimentalIsRenderedInSidebar
				/>
			</InspectorControls>

			{ /* Scrim — outside block wrapper so it covers the full editor canvas. */ }
			{ isPreviewOpen && (
				<div
					className="overlay-menu__scrim alignfull"
					style={ overlayColor ? { background: overlayColor } : {} }
					onClick={ () => togglePreview( false ) }
					aria-hidden="true"
				/>
			) }

			<div { ...blockProps }>
				<div className="overlay-menu__close-wrapper">
					<button type="button" className="overlay-menu__close" onClick={ () => togglePreview( false ) }>
						<span className="overlay-menu__icon" aria-hidden="true">
							{ closeIcon }
						</span>
						<span className="screen-reader-text">{ __( 'Close', 'newspack-plugin' ) }</span>
					</button>
				</div>
				<div className="overlay-menu__content">
					<InnerBlocks template={ INNER_BLOCKS_TEMPLATE } templateLock={ false } />
				</div>
			</div>
		</>
	);
}
