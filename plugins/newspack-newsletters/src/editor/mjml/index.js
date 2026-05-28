/* global newspack_email_editor_data */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { usePrevious } from '../../newsletter-editor/utils';

/**
 * Internal dependencies
 */
import { getServiceProvider } from '../../service-providers';
import { fetchNewsletterData, fetchSyncErrors, updateIsRefreshingHtml } from '../../newsletter-editor/store';

/**
 * External dependencies
 */
import mjml2html from 'mjml-browser';

/**
 * Refresh the email-compliant HTML for a post.
 *
 * @param {number} postId      The current post ID.
 * @param {string} postTitle   The current post title.
 * @param {string} postContent The current post content.
 * @return {Promise<string>} The refreshed email HTML.
 */
export const refreshEmailHtml = async ( postId, postTitle, postContent ) => {
	return apiFetch( {
		path: `/newspack-newsletters/v1/post-mjml`,
		method: 'POST',
		data: {
			post_id: postId,
			title: postTitle,
			content: postContent,
		},
	} )
		.then( mjml => {
			// Once received MJML markup, convert it to email-compliant HTML and save as post meta.
			const { html } = mjml2html( mjml, { keepComments: false, minify: true } );
			return { result: 'success', html };
		} )
		.catch( error => {
			return { result: 'error', error };
		} );
};

function MJML() {
	const { saveSucceeded, isPublishing, isAutosaving, isAutosaveLocked, isSaving, isSent, postContent, postId, postTitle, postType, isTakeover } =
		useSelect( select => {
			const {
				didPostSaveRequestSucceed,
				getCurrentPostAttribute,
				getCurrentPostId,
				getCurrentPostType,
				getEditedPostAttribute,
				getEditedPostContent,
				isSavingPost,
				isPostAutosavingLocked,
				isAutosavingPost,
				isCurrentPostPublished,
				isPostLockTakeover,
			} = select( 'core/editor' );

			return {
				postContent: getEditedPostContent(),
				postId: getCurrentPostId(),
				postTitle: getEditedPostAttribute( 'title' ),
				postType: getCurrentPostType(),
				isPublished: isCurrentPostPublished(),
				saveSucceeded: didPostSaveRequestSucceed(),
				isSaving: isSavingPost(),
				isSent: getCurrentPostAttribute( 'meta' ).newsletter_sent,
				isAutosaving: isAutosavingPost(),
				isAutosaveLocked: isPostAutosavingLocked(),
				isTakeover: isPostLockTakeover(),
			};
		} );
	const { createNotice } = useDispatch( 'core/notices' );
	const { lockPostAutosaving, lockPostSaving, unlockPostSaving, editPost } = useDispatch( 'core/editor' );
	const updateMetaValue = ( key, value ) => editPost( { meta: { [ key ]: value } } );

	// Disable autosave requests in the editor.
	useEffect( () => {
		if ( ! isAutosaveLocked ) {
			lockPostAutosaving();
		}
	}, [ isAutosaveLocked ] );

	// After the post is successfully saved, refresh the email HTML.
	const wasSaving = usePrevious( isSaving );
	const { name: serviceProviderName } = getServiceProvider();
	const { supported_esps: supportedESPs } = newspack_email_editor_data || [];
	const isSupportedESP = serviceProviderName && 'manual' !== serviceProviderName && supportedESPs?.includes( serviceProviderName );

	useEffect( () => {
		if ( wasSaving && ! isSaving && ! isAutosaving && ! isPublishing && ! isSent && ! isTakeover && saveSucceeded ) {
			refreshHtml();
		}
	}, [ isSaving, isAutosaving ] );

	const refreshHtml = async () => {
		try {
			lockPostSaving( 'newspack-newsletters-refresh-html' );
			if ( isSupportedESP ) {
				updateIsRefreshingHtml( true );
			}
			const refreshedHtml = await refreshEmailHtml( postId, postTitle, postContent );
			if ( refreshedHtml.html ) {
				updateMetaValue( newspack_email_editor_data.email_html_meta, refreshedHtml.html );
			} else {
				const errorMessage = __( 'Failed to refresh email HTML', 'newspack-newsletters' );
				throw new Error( `${ errorMessage }${ refreshedHtml.error?.message ? `: ${ refreshedHtml.error?.message }` : '.' }` );
			}

			// Save the refreshed HTML to post meta.
			await apiFetch( {
				data: { meta: { [ newspack_email_editor_data.email_html_meta ]: refreshedHtml.html } },
				method: 'POST',
				path: `/wp/v2/${ postType }/${ postId }`,
			} );

			// Rehydrate ESP newsletter data after completing sync.
			if ( isSupportedESP ) {
				await fetchNewsletterData( postId );
				await fetchSyncErrors( postId );
				updateIsRefreshingHtml( false );
			}
			unlockPostSaving( 'newspack-newsletters-refresh-html' );
		} catch ( e ) {
			createNotice( 'error', e?.message || __( 'Error refreshing email HTML.', 'newspack-newsletters' ), {
				id: 'newspack-newsletters-mjml-error',
				isDismissible: true,
			} );
		}
	};
}

export default MJML;
