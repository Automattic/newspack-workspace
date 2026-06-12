/**
 * WordPress dependencies
 */
import { InnerBlocks, useBlockProps } from '@wordpress/block-editor';
import { useSelect, useDispatch } from '@wordpress/data';

/**
 * Internal dependencies
 */
import ViewToggle from '../view-toggle';
import { useView } from '../view-state';

/**
 * Edit component for a Responsive Container breakpoint.
 *
 * Reads the container's edited view from ephemeral shared state and hides itself
 * when its own `view` is not the active one (content stays in the DOM, so it is
 * preserved and editable once switched to). It also renders the shared view
 * toggle so the view can be switched without reselecting the container; because
 * the view is ephemeral, toggling never dirties the post.
 *
 * Switching views moves the selection to the breakpoint that is becoming visible
 * — the hidden breakpoint uses `display: none`, and a selected-but-hidden block
 * has no position for its toolbar to anchor to, so selection must follow the view.
 *
 * @param {Object} props            Block props.
 * @param {Object} props.attributes Block attributes.
 * @param {string} props.clientId   Block client ID.
 *
 * @return {JSX.Element} The block editor UI.
 */
export default function ResponsiveContainerBreakpointEdit( { attributes, clientId } ) {
	const { view } = attributes;

	const { parentClientId, siblings, isEmpty } = useSelect(
		select => {
			const { getBlockRootClientId, getBlocks, getBlockOrder } = select( 'core/block-editor' );
			const root = getBlockRootClientId( clientId );
			return {
				parentClientId: root,
				// Guard against a missing root during transitions (e.g. template-part
				// switches): getBlocks( falsy ) returns the top-level blocks, not [].
				siblings: root ? getBlocks( root ) : [],
				isEmpty: getBlockOrder( clientId ).length === 0,
			};
		},
		[ clientId ]
	);

	// Key to our own clientId until the parent resolves, so transient state is
	// never shared under a `null` key; useView re-subscribes when it changes.
	const [ activeView, setView ] = useView( parentClientId || clientId );
	const isActive = activeView === view;

	const { selectBlock } = useDispatch( 'core/block-editor' );

	const switchView = newView => {
		setView( newView );
		// Move selection onto the breakpoint that is becoming visible (falling
		// back to the container) so the toolbar always anchors to a visible block.
		const target = siblings.find( block => block.attributes?.view === newView );
		selectBlock( target ? target.clientId : parentClientId || clientId );
	};

	const className =
		'newspack-responsive-container-breakpoint' +
		` newspack-responsive-container-breakpoint--${ view }` +
		( isActive ? '' : ' is-inactive-view' ) +
		( isEmpty ? ' is-empty' : '' );

	const blockProps = useBlockProps( { className } );

	return (
		<>
			<ViewToggle value={ activeView } onChange={ switchView } />
			<div { ...blockProps }>
				<InnerBlocks
					templateLock={ false }
					// Show the placeholder appender only while empty; once the breakpoint
					// has content, fall back to the default appender (undefined) so more
					// blocks can still be added in place.
					renderAppender={ isEmpty ? InnerBlocks.ButtonBlockAppender : undefined }
				/>
			</div>
		</>
	);
}
