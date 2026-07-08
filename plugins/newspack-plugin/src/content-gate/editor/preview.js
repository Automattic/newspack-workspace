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
import WebPreview from '../../../packages/components/src/web-preview';

/**
 * Preview button for gate layouts. Autosaves the layout, then opens a modal
 * iframe of a recent published post with the gate force-rendered. Mirrors the
 * newspack-popups prompt preview: the reader's unsaved meta rides along in the
 * URL (autosaves don't persist meta), and in-iframe article links are rewritten
 * to keep the preview active as the reader navigates.
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

	const { preview_post: previewPost, frontend_url: frontendUrl, query_param: queryParam, preview_query_keys: previewQueryKeys } = preview;

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

	const onWebPreviewLoad = iframeEl => {
		if ( ! iframeEl ) {
			return;
		}
		[ ...iframeEl.contentWindow.document.querySelectorAll( 'a[href^="' + frontendUrl + '"]' ) ].forEach( anchor => {
			anchor.setAttribute( 'href', addQueryArgs( anchor.getAttribute( 'href' ), query ) );
		} );
	};

	return (
		<WebPreview
			url={ addQueryArgs( previewPost, query ) }
			onLoad={ onWebPreviewLoad }
			renderButton={ ( { showPreview } ) => (
				<Button variant="primary" isBusy={ isSavingPost } disabled={ isSavingPost } onClick={ () => autosave().then( showPreview ) }>
					{ __( 'Preview', 'newspack-plugin' ) }
				</Button>
			) }
		/>
	);
}
