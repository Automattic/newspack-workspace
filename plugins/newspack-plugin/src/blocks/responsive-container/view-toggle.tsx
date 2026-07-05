/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { BlockControls } from '@wordpress/block-editor';
import { ToolbarGroup, ToolbarButton } from '@wordpress/components';

/**
 * Toolbar toggle for switching the edited view (desktop / mobile).
 *
 * Rendered in the BlockControls of both the container and its breakpoints so the
 * active view can be switched from whichever block is selected — mirroring how
 * the Overlay Menu surfaces its toggle on more than one related block, and
 * avoiding the need to climb back up to the container to switch views.
 *
 * @param {Object}   props          Component props.
 * @param {string}   props.value    Current view ( 'desktop' | 'mobile' ).
 * @param {Function} props.onChange Called with the chosen view.
 *
 * @return {JSX.Element} The toolbar control.
 */
export default function ViewToggle( { value, onChange } ) {
	return (
		<BlockControls>
			<ToolbarGroup>
				<ToolbarButton
					text={ __( 'Mobile', 'newspack-plugin' ) }
					label={ __( 'Mobile', 'newspack-plugin' ) }
					isActive={ value === 'mobile' }
					onClick={ () => onChange( 'mobile' ) }
				/>
				<ToolbarButton
					text={ __( 'Desktop', 'newspack-plugin' ) }
					label={ __( 'Desktop', 'newspack-plugin' ) }
					isActive={ value === 'desktop' }
					onClick={ () => onChange( 'desktop' ) }
				/>
			</ToolbarGroup>
		</BlockControls>
	);
}
