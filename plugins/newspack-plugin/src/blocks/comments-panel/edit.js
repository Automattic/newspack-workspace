/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { InnerBlocks, useBlockProps } from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';

/**
 * Internal dependencies
 */
import PanelPreviewToggle from './panel-preview-toggle';
import { panelToggles, subscribeToPanel } from './preview-refs';

const CHILD_LOCK = { move: true, remove: true };
const BLOCKS_TEMPLATE = [
	[ 'newspack/comments-panel-trigger', { lock: CHILD_LOCK } ],
	[ 'newspack/comments-panel-content', { lock: CHILD_LOCK } ],
];

/**
 * Edit component for the Comments Panel block.
 *
 * Provides a locked template containing the trigger and content child blocks.
 *
 * @param {Object} props          Block props.
 * @param {string} props.clientId Block client ID.
 *
 * @return {JSX.Element} The block editor UI.
 */
export default function CommentsPanelEdit( { clientId } ) {
	// Mirror the panel's open state so the toolbar button label and isPressed stay correct.
	const [ isPreviewOpen, setIsPreviewOpen ] = useState( false );
	useEffect( () => {
		return subscribeToPanel( clientId, setIsPreviewOpen );
	}, [ clientId ] );

	// Delegate the actual toggle to the content block via its registered ref function.
	const togglePreview = () => panelToggles.get( clientId )?.();

	// Only the first Comments Panel renders a real panel, so only it shows the toggle.
	const isFirstInstance = useSelect(
		select => {
			const ids = select( 'core/block-editor' ).getBlocksByName( 'newspack/comments-panel' );
			return ! ids.length || ids[ 0 ] === clientId;
		},
		[ clientId ]
	);

	const blockProps = useBlockProps( { className: 'is-layout-flex' } );

	return (
		<>
			{ isFirstInstance && <PanelPreviewToggle isOpen={ isPreviewOpen } onToggle={ togglePreview } /> }
			<div { ...blockProps }>
				<InnerBlocks
					template={ BLOCKS_TEMPLATE }
					templateLock="all"
					allowedBlocks={ [ 'newspack/comments-panel-trigger', 'newspack/comments-panel-content' ] }
				/>
			</div>
		</>
	);
}
