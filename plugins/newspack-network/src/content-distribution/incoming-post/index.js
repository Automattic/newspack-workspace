/* globals newspack_network_incoming_post */

/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	Button,
	__experimentalConfirmDialog as ConfirmDialog, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { broadcast } from 'newspack-icons';
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies.
 */
import './style.scss';
import ContentDistributionPanel from '../content-distribution-panel';

const originalSiteUrl = newspack_network_incoming_post.originalSiteUrl;
const originalPostEditUrl = newspack_network_incoming_post.originalPostEditUrl;
const unlinked = newspack_network_incoming_post.unlinked;

// Sidebar fields a linked post takes from its origin. The stylesheet's overlay can't
// lock them, because their markup changes between WordPress versions, so they are
// removed from the editor instead. A removed panel stays removed until the editor
// reloads, which is why unlinking reloads the page.
const LINKED_PANELS = [ 'post-excerpt', 'discussion-panel', 'featured-image' ];

function IncomingPost() {
	const { createNotice } = useDispatch( 'core/notices' );
	const { lockPostAutosaving, unlockPostAutosaving, removeEditorPanel } = useDispatch( 'core/editor' );
	const { openGeneralSidebar } = useDispatch( 'core/edit-post' );
	const [ isUnLinkedToggling, setIsUnLinkedToggling ] = useState( false );
	// Start from the stored state: a first render as linked would remove the panels until reload.
	const [ isUnLinked, setIsUnLinked ] = useState( unlinked );
	const [ hasInitializedSidebar, setHasInitializedSidebar ] = useState( false );
	const [ showConfirmDialog, setShowConfirmDialog ] = useState( false );

	const { postId, areMetaBoxesInitialized } = useSelect( select => {
		const { getCurrentPostId } = select( 'core/editor' );
		const { areMetaBoxesInitialized: _areMetaBoxesInitialized } = select( 'core/edit-post' );
		return {
			postId: getCurrentPostId(),
			areMetaBoxesInitialized: _areMetaBoxesInitialized(),
		};
	} );

	useEffect( () => {
		if ( ! hasInitializedSidebar && areMetaBoxesInitialized ) {
			openGeneralSidebar( 'newspack-network-incoming-post/newspack-network-content-distribution-panel' );
			setHasInitializedSidebar( true ); // We only want to strongarm this once.
		}
	}, [ areMetaBoxesInitialized ] );

	useEffect( () => {
		createNotice(
			'warning',
			isUnLinked
				? sprintf(
						// translators: %s: original site URL.
						__( 'Originally distributed from %s.', 'newspack-network' ),
						originalSiteUrl
				  )
				: sprintf(
						// translators: %s: original site URL.
						__( 'Distributed from %s.', 'newspack-network' ),
						originalSiteUrl
				  ),

			{
				id: 'newspack-network-incoming-post-notice',
			}
		);

		const lockName = 'distributed-incoming-post-lock';
		if ( isUnLinked ) {
			unlockPostAutosaving( lockName );
		} else {
			lockPostAutosaving( lockName );
			LINKED_PANELS.forEach( panel => removeEditorPanel( panel ) );
		}
		// Toggle the CSS overlay.
		document.querySelector( '#editor' )?.classList.toggle( 'newspack-network-incoming-post-linked', ! isUnLinked );
	}, [ isUnLinked ] );

	const toggleUnlinkedState = async _unlinked => {
		return apiFetch( {
			path: `newspack-network/v1/content-distribution/unlink/${ postId }`,
			method: 'POST',
			data: {
				unlinked: _unlinked,
			},
		} )
			.then( data => {
				setIsUnLinked( data.unlinked );
				return data;
			} )
			.catch( error => {
				createNotice( 'error', error.message );
				return null;
			} );
	};

	const toggleUnlinkedClicked = _unlinked => {
		setIsUnLinkedToggling( true );
		if ( isUnLinked ) {
			// For relinking, we need to save the post (it will be overwritten by the origin post)
			// to avoid the browser warning when reloading.
			wp.data
				.dispatch( 'core/editor' )
				.savePost()
				.then( () => {
					// Remove the 'draft saved' notice.
					wp.data.dispatch( 'core/notices' ).removeNotice( 'SAVE_POST_NOTICE_ID' );
					toggleUnlinkedState( _unlinked ).then( data => {
						if ( data ) {
							window.location.reload(); // Reload to get the origin post content.
						} else {
							setIsUnLinkedToggling( false );
						}
					} );
				} );
		} else {
			toggleUnlinkedState( _unlinked ).then( async data => {
				if ( ! data ) {
					setIsUnLinkedToggling( false );
					return;
				}
				// Save edits made while linked, so the reload doesn't prompt to discard them.
				if ( wp.data.select( 'core/editor' ).isEditedPostDirty() ) {
					await wp.data.dispatch( 'core/editor' ).savePost();
				}
				window.location.reload();
			} );
		}
	};

	return (
		<>
			<ContentDistributionPanel
				header={
					isUnLinked
						? sprintf(
								// translators: %1$s: post type
								__(
									'This %1$s has been unlinked from its origin. Edits to the origin %1$s will not update this version.',
									'newspack-network'
								),
								newspack_network_incoming_post.postTypeLabel.toLowerCase()
						  )
						: sprintf(
								// translators: %1$s: post type
								__( 'This %1$s is linked to its origin. Edits to the origin %1$s will update this version.', 'newspack-network' ),
								newspack_network_incoming_post.postTypeLabel.toLowerCase()
						  )
				}
				buttons={
					<>
						<Button variant="secondary" target="_blank" href={ originalPostEditUrl }>
							{ sprintf(
								// translators: %s: post type
								__( 'Edit origin %s', 'newspack-network' ),
								newspack_network_incoming_post.postTypeLabel.toLowerCase()
							) }
						</Button>
						<Button
							variant={ isUnLinked ? 'primary' : 'secondary' }
							isDestructive={ ! isUnLinked }
							disabled={ isUnLinkedToggling }
							onClick={ () => {
								setShowConfirmDialog( true );
							} }
						>
							{ isUnLinkedToggling
								? isUnLinked
									? __( 'Relinking…', 'newspack-network' )
									: __( 'Unlinking…', 'newspack-network' )
								: ! isUnLinked
								? sprintf(
										// translators: %s: post type
										__( 'Unlink from origin %s', 'newspack-network' ),
										newspack_network_incoming_post.postTypeLabel.toLowerCase()
								  )
								: sprintf(
										// translators: %s: post type
										__( 'Relink to origin %s', 'newspack-network' ),
										newspack_network_incoming_post.postTypeLabel.toLowerCase()
								  ) }
						</Button>
					</>
				}
			/>
			<ConfirmDialog
				isOpen={ showConfirmDialog }
				onConfirm={ () => {
					toggleUnlinkedClicked( ! isUnLinked );
					setShowConfirmDialog( false );
				} }
				onCancel={ () => setShowConfirmDialog( false ) }
				confirmButtonText={ isUnLinked ? __( 'Relink', 'newspack-network' ) : __( 'Unlink', 'newspack-network' ) }
				size="small"
			>
				{ isUnLinked
					? sprintf(
							// translators: %s: post type
							__( "Are you sure you want to relink this %s to its origin? Any changes you've made will be lost.", 'newspack-network' ),
							newspack_network_incoming_post.postTypeLabel.toLowerCase()
					  )
					: sprintf(
							// translators: %s: post type
							__( 'Are you sure you want to unlink this %s from its origin?', 'newspack-network' ),
							newspack_network_incoming_post.postTypeLabel.toLowerCase()
					  ) }
			</ConfirmDialog>
		</>
	);
}

registerPlugin( 'newspack-network-incoming-post', {
	render: IncomingPost,
	icon: broadcast,
} );
