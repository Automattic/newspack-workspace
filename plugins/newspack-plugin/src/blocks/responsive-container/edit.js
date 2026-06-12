/**
 * WordPress dependencies
 */
import { InnerBlocks, useBlockProps } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import ViewToggle from './view-toggle';
import { useView } from './view-state';

const ALLOWED_BLOCKS = [ 'newspack/responsive-container-breakpoint' ];
const BLOCKS_TEMPLATE = [
	[ 'newspack/responsive-container-breakpoint', { view: 'desktop' } ],
	[ 'newspack/responsive-container-breakpoint', { view: 'mobile' } ],
];

/**
 * Edit component for the Responsive Container block.
 *
 * Renders a locked template of exactly two breakpoints (desktop + mobile) and
 * the view toggle. The edited view defaults to desktop and is held in ephemeral
 * editor-only state (shared with the breakpoints, so toggling never dirties the
 * post); the inactive breakpoint hides itself in the editor. The same toggle is
 * rendered by each breakpoint so it can be switched without reselecting the
 * container.
 *
 * @param {Object} props          Block props.
 * @param {string} props.clientId Block client ID.
 *
 * @return {JSX.Element} The block editor UI.
 */
export default function ResponsiveContainerEdit( { clientId } ) {
	const [ view, setView ] = useView( clientId );

	const blockProps = useBlockProps( {
		className: 'newspack-responsive-container',
	} );

	return (
		<>
			<ViewToggle value={ view } onChange={ setView } />
			<div { ...blockProps }>
				<InnerBlocks template={ BLOCKS_TEMPLATE } templateLock="all" allowedBlocks={ ALLOWED_BLOCKS } />
			</div>
		</>
	);
}
