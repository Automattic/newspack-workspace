/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { BlockControls } from '@wordpress/block-editor';
import { ToolbarButton, ToolbarGroup } from '@wordpress/components';

/**
 * Toolbar toggle button shared by all three Overlay Menu edit components.
 *
 * @param props          Component props.
 * @param props.isOpen   Whether the panel preview is currently open.
 * @param props.onToggle Callback invoked when the button is clicked.
 *
 * @return The toolbar button inside BlockControls.
 */
export default function PanelPreviewToggle( { isOpen, onToggle }: { isOpen: boolean; onToggle: () => void } ) {
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
