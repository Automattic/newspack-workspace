/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { InnerBlocks, useBlockProps } from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';
import { getBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import PanelPreviewToggle from './panel-preview-toggle';
import { panelToggles, subscribeToPanel } from './preview-refs';

/**
 * Before WP 7.2, InnerBlocks only reads the template from props.
 * Core List has a settings template from 7.2 on, so it marks support.
 * TODO: Remove once WP 7.2 is released, and the plugin's minimum WordPress version is updated to 7.2.
 *
 * @param {string} name Block name.
 * @return {Object} InnerBlocks template props, empty on WP 7.2+.
 */
const getLegacyTemplateProps = name => {
	if ( getBlockType( 'core/list' )?.template ) {
		return {};
	}
	return { template: getBlockType( name ).template };
};

/**
 * Edit component for the Comments Panel block.
 *
 * Provides a locked template containing the trigger and content child blocks.
 *
 * @param {Object} props          Block props.
 * @param {string} props.clientId Block client ID.
 * @param {string} props.name     Block name.
 *
 * @return {JSX.Element} The block editor UI.
 */
export default function CommentsPanelEdit( { clientId, name } ) {
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
					{ ...getLegacyTemplateProps( name ) }
					templateLock="all"
					allowedBlocks={ [ 'newspack/comments-panel-trigger', 'newspack/comments-panel-content' ] }
				/>
			</div>
		</>
	);
}
