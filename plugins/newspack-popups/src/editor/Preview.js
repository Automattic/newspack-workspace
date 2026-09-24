/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { withSelect, withDispatch } from '@wordpress/data';
import { compose } from '@wordpress/compose';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

/**
 * External dependencies
 */
import { WebPreview } from 'newspack-components';

const PreviewSetting = ( { autosavePost, isSavingPost, postId, metaFields } ) => {
	if ( ! postId || ! metaFields ) {
		return null;
	}

	const previewQueryKeys = window.newspack_popups_data?.preview_query_keys || {};
	const abbreviatedKeys = {};
	Object.keys( metaFields ).forEach( key => {
		if ( previewQueryKeys.hasOwnProperty( key ) ) {
			abbreviatedKeys[ previewQueryKeys[ key ] ] = metaFields[ key ];
		}
	} );

	const query = {
		pid: postId,
		// Autosave does not handle meta fields, so these will be passed in the URL
		...abbreviatedKeys,
	};

	const isArchivePagesPrompt = metaFields.placement === 'archives';
	const previewURL = window.newspack_popups_data[ isArchivePagesPrompt ? 'preview_archive' : 'preview_post' ] || '/';

	// Links inside the preview keep their preview params, but the previewed
	// document does that for itself — see propagatePreviewParams() in
	// src/view/preview-links.js. The editor used to reach into the preview
	// iframe and rewrite the links from out here, which WordPress 7.1 no longer
	// permits: it serves the block editor with `Document-Isolation-Policy`,
	// putting the editor in its own agent cluster and severing synchronous
	// access to the frame even though it is same-origin.
	return (
		<WebPreview
			url={ addQueryArgs( previewURL, query ) }
			renderButton={ ( { showPreview } ) => (
				<Button isPrimary isBusy={ isSavingPost } disabled={ isSavingPost } onClick={ () => autosavePost().then( showPreview ) }>
					{ __( 'Preview', 'newspack-popups' ) }
				</Button>
			) }
		/>
	);
};

const connectPreviewSetting = compose( [
	withSelect( select => {
		const { isSavingPost, getCurrentPostId, getEditedPostAttribute } = select( 'core/editor' );
		return {
			postId: getCurrentPostId(),
			metaFields: getEditedPostAttribute( 'meta' ),
			isSavingPost: isSavingPost(),
		};
	} ),
	withDispatch( dispatch => {
		return {
			autosavePost: () => dispatch( 'core/editor' ).autosave(),
		};
	} ),
] );

export default connectPreviewSetting( PreviewSetting );
