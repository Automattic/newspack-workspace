/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { InnerBlocks, useBlockProps } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import PanelPreviewToggle from './panel-preview-toggle';
import { panelToggles, subscribeToPanel } from './preview-refs';

const CHILD_LOCK = { move: true, remove: true };
const BLOCKS_TEMPLATE = [
	[ 'newspack/overlay-menu-trigger', { lock: CHILD_LOCK } ],
	[ 'newspack/overlay-menu-panel', { lock: CHILD_LOCK } ],
];

type OverlayMenuAttributes = {
	instanceId: string;
};

/**
 * Edit component for the Overlay Menu block.
 *
 * Provides a locked template containing the trigger and panel child blocks.
 * The instanceId is set on first insert and shared with children via block context.
 *
 * @param props               Block props.
 * @param props.attributes    Block attributes.
 * @param props.setAttributes Attribute setter.
 * @param props.clientId      Block client ID.
 *
 * @return The block editor UI.
 */
export default function OverlayMenuEdit( {
	attributes,
	setAttributes,
	clientId,
}: {
	attributes: OverlayMenuAttributes;
	setAttributes: ( attributes: Partial< OverlayMenuAttributes > ) => void;
	clientId: string;
} ) {
	const { instanceId } = attributes;

	// Keep instanceId in sync with clientId so duplicated blocks get a unique ID.
	useEffect( () => {
		const derived = clientId.replace( /-/g, '' ).slice( 0, 12 );
		if ( instanceId !== derived ) {
			setAttributes( { instanceId: derived } );
		}
	}, [ clientId ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Mirror the panel's open state so the toolbar button label and isPressed stay correct.
	const [ isPreviewOpen, setIsPreviewOpen ] = useState( false );
	useEffect( () => {
		return subscribeToPanel( clientId, setIsPreviewOpen );
	}, [ clientId ] );

	// Delegate the actual toggle to the panel via its registered ref function.
	// The panel keys its entry by parentClientId === this block's clientId.
	const togglePreview = () => panelToggles.get( clientId )?.();

	const blockProps = useBlockProps( {
		className: 'is-layout-flex',
	} );

	return (
		<>
			<PanelPreviewToggle isOpen={ isPreviewOpen } onToggle={ togglePreview } />
			<div { ...blockProps }>
				<InnerBlocks
					template={ BLOCKS_TEMPLATE }
					templateLock="all"
					allowedBlocks={ [ 'newspack/overlay-menu-trigger', 'newspack/overlay-menu-panel' ] }
				/>
			</div>
		</>
	);
}
