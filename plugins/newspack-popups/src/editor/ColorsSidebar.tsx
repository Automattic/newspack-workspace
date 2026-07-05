/**
 * Popup color options.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';
import { RangeControl, ToggleControl } from '@wordpress/components';
import { ColorPaletteControl } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import type { PromptEditorProps } from './utils';

type ColorsSidebarProps = Pick<
	PromptEditorProps,
	| 'background_color'
	| 'close_button_background_color'
	| 'enable_close_button_background'
	| 'onMetaFieldChange'
	| 'overlay_opacity'
	| 'overlay_color'
	| 'no_overlay_background'
	| 'isOverlay'
>;

const ColorsSidebar = ( {
	background_color,
	close_button_background_color,
	enable_close_button_background,
	onMetaFieldChange,
	overlay_opacity,
	overlay_color,
	no_overlay_background,
	isOverlay,
}: ColorsSidebarProps ) => (
	<Fragment>
		<ColorPaletteControl
			value={ background_color }
			onChange={ ( value: string ) => onMetaFieldChange( { background_color: value || '#FFFFFF' } ) }
			label={ __( 'Content Background Color', 'newspack-popups' ) }
		/>
		{ isOverlay && (
			<Fragment>
				{ /*
				 * `ToggleControl` doesn't declare a `value` prop (only `checked`); the original JS
				 * also passed `value={ enable_close_button_background }` here, which the component
				 * never reads. Pre-existing dead prop, not introduced by this migration -- dropped
				 * since `boolean` doesn't satisfy the DOM `value` attribute type `ToggleControl`
				 * inherits, and it has no effect on the rendered control either way.
				 */ }
				<ToggleControl
					className="newspack-popups__color-toggle"
					label={ __( 'Customize close button background', 'newspack-popups' ) }
					checked={ enable_close_button_background }
					onChange={ value => onMetaFieldChange( { enable_close_button_background: value } ) }
				/>
				{ enable_close_button_background && (
					<ColorPaletteControl
						value={ close_button_background_color }
						onChange={ ( value: string ) => onMetaFieldChange( { close_button_background_color: value || '#00000000' } ) }
						label={ __( 'Close Button Background Color', 'newspack-popups' ) }
						enableAlpha={ true }
					/>
				) }

				{ /* See the dead-`value`-prop note on the toggle above; same reasoning applies here. */ }
				<ToggleControl
					className="newspack-popups__color-toggle"
					label={ __( 'Display overlay background', 'newspack-popups' ) }
					checked={ ! no_overlay_background }
					onChange={ value => onMetaFieldChange( { no_overlay_background: ! value } ) }
				/>
				{ ! no_overlay_background && (
					<>
						<ColorPaletteControl
							value={ overlay_color }
							onChange={ ( value: string ) => onMetaFieldChange( { overlay_color: value || '#000000' } ) }
							label={ __( 'Overlay Background Color', 'newspack-popups' ) }
						/>
						<RangeControl
							label={ __( 'Overlay Background Opacity', 'newspack-popups' ) }
							value={ overlay_opacity }
							onChange={ value => onMetaFieldChange( { overlay_opacity: value } ) }
							min={ 0 }
							max={ 100 }
						/>
					</>
				) }
			</Fragment>
		) }
	</Fragment>
);

export default ColorsSidebar;
