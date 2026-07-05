/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { BlockControls } from '@wordpress/block-editor';
import { ToolbarButton, ToolbarGroup } from '@wordpress/components';

/**
 * Toolbar toggle button shared by all three Overlay Menu edit components.
 *
 * @param {Object}   props          Component props.
 * @param {boolean}  props.isOpen   Whether the panel preview is currently open.
 * @param {Function} props.onToggle Callback invoked when the button is clicked.
 *
 * @return {JSX.Element} The toolbar button inside BlockControls.
 */
export default function PanelPreviewToggle( { isOpen, onToggle } ) {
	return (
		<BlockControls>
			<ToolbarGroup>
				<ToolbarButton isPressed={ isOpen } onClick={ onToggle }>
					{ isOpen ? __( 'Close panel', 'newspack-plugin' ) : __( 'Open panel', 'newspack-plugin' ) }
				</ToolbarButton>
			</ToolbarGroup>
		</BlockControls>
	);
}
