/* globals newspack_content_gate */

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
// Deep-import the component directly rather than from the `newspack-components`
// barrel: this is a standalone editor entry, and the barrel would pull in
// unrelated wizard modules (and their data store) that don't belong here.
import WebPreview from '../../../packages/components/src/web-preview';

/**
 * Preview button for gate layouts. Autosaves the layout, then opens a modal
 * iframe of a recent published post with the gate force-rendered. Mirrors the
 * newspack-popups prompt preview: the reader's unsaved meta rides along in the
 * URL (autosaves don't persist meta), and in-iframe article links are rewritten
 * to keep the preview active as the reader navigates.
 *
 * That rewriting happens in the previewed document — see
 * propagateGatePreviewParams() in src/content-gate/preview-links.js — not from
 * out here. This component used to reach into the preview iframe, guarded by a
 * try/catch for genuinely cross-origin setups. WordPress 7.1 turned that guard
 * into a silent failure: it serves the block editor with
 * `Document-Isolation-Policy`, which severs access to the frame even when it is
 * same-origin, so the rewrite stopped happening and only a console warning
 * marked it.
 */
export default function GatePreview() {
	const { postId, meta, isSavingPost } = useSelect( select => {
		const { getCurrentPostId, getEditedPostAttribute, isSavingPost: getIsSavingPost } = select( 'core/editor' );
		return {
			postId: getCurrentPostId(),
			meta: getEditedPostAttribute( 'meta' ),
			isSavingPost: getIsSavingPost(),
		};
	} );
	const { autosave } = useDispatch( 'core/editor' );

	const preview = newspack_content_gate?.preview;
	if ( ! preview?.preview_post || ! postId || ! meta ) {
		return null;
	}

	const { preview_post: previewPost, query_param: queryParam, preview_query_keys: previewQueryKeys } = preview;

	// Map edited meta onto the abbreviated query keys the server understands.
	const abbreviatedKeys = {};
	Object.keys( previewQueryKeys || {} ).forEach( metaKey => {
		if ( undefined !== meta[ metaKey ] ) {
			abbreviatedKeys[ previewQueryKeys[ metaKey ] ] = meta[ metaKey ];
		}
	} );

	const query = {
		[ queryParam ]: postId,
		...abbreviatedKeys,
	};

	// Open the preview after the autosave settles. On a failed autosave, still
	// open it: the server falls back to the layout's saved content, so the reader
	// gets a (slightly stale) preview rather than a dead button.
	const previewAfterAutosave = showPreview =>
		autosave()
			.catch( e => {
				// eslint-disable-next-line no-console
				console.warn( 'Gate preview: autosave failed; previewing saved content.', e );
			} )
			.then( showPreview );

	return (
		<WebPreview
			url={ addQueryArgs( previewPost, query ) }
			renderButton={ ( { showPreview } ) => (
				<Button variant="primary" isBusy={ isSavingPost } disabled={ isSavingPost } onClick={ () => previewAfterAutosave( showPreview ) }>
					{ __( 'Preview', 'newspack-plugin' ) }
				</Button>
			) }
		/>
	);
}
