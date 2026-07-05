/* global newspack_email_editor_data */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useSelect, useDispatch } from '@wordpress/data';
import type { DataRegistry } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { isLayoutEditor, usePrevious } from '../../newsletter-editor/utils';

/**
 * Internal dependencies
 */
import { getServiceProvider } from '../../service-providers';
import { fetchNewsletterData, fetchSyncErrors, updateIsRefreshingHtml, updateLastRefreshHadError } from '../../newsletter-editor/store';

/**
 * External dependencies
 */
import mjml2html from 'mjml-browser';
// See ./mjml-browser.d.ts for the local ambient type declaration this relies on.

interface RefreshEmailHtmlResult {
	result: 'success' | 'error';
	html?: string;
	error?: { message?: string };
}

/**
 * Refresh the email-compliant HTML for a post.
 *
 * @param {number} postId      The current post ID.
 * @param {string} postTitle   The current post title.
 * @param {string} postContent The current post content.
 * @return {Promise<string>} The refreshed email HTML.
 */
export const refreshEmailHtml = async ( postId: number, postTitle: string, postContent: string ): Promise< RefreshEmailHtmlResult > => {
	return apiFetch< string >( {
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
			return { result: 'success' as const, html };
		} )
		.catch( error => {
			return { result: 'error' as const, error };
		} );
};

// `isPublishing` is read below (see the `useEffect` guard) but this selector only ever computes
// `isPublished` -- a pre-existing name mismatch, so `isPublishing` is always `undefined` (and the
// `! isPublishing` guard always passes). Preserved as-is (not a typing concern to fix); `isPublished`
// is otherwise unused.
interface EditorSelectResult {
	postContent: string;
	postId: number;
	postTitle: string;
	postType: string;
	isPublished: boolean;
	isPublishing?: undefined;
	saveSucceeded: boolean;
	isSaving: boolean;
	isSent: unknown;
	isAutosaving: boolean;
	isAutosaveLocked: boolean;
	isTakeover: boolean;
}

// This component has no `return` statement (falls through to an implicit `undefined`) -- it
// exists purely for its hooks' side effects. Preserved as-is; the explicit `undefined` return
// type below documents this rather than changing it.
function MJML(): undefined {
	const { saveSucceeded, isPublishing, isAutosaving, isAutosaveLocked, isSaving, isSent, postContent, postId, postTitle, postType, isTakeover } =
		useSelect( ( select: DataRegistry[ 'select' ] ): EditorSelectResult => {
			const editorSelectors = select( 'core/editor' );
			const didPostSaveRequestSucceed = editorSelectors.didPostSaveRequestSucceed as () => boolean;
			const getCurrentPostAttribute = editorSelectors.getCurrentPostAttribute as ( attribute: string ) => { newsletter_sent?: unknown };
			const getCurrentPostId = editorSelectors.getCurrentPostId as () => number;
			const getCurrentPostType = editorSelectors.getCurrentPostType as () => string;
			const getEditedPostAttribute = editorSelectors.getEditedPostAttribute as ( attribute: string ) => string;
			const getEditedPostContent = editorSelectors.getEditedPostContent as () => string;
			const isSavingPost = editorSelectors.isSavingPost as () => boolean;
			const isPostAutosavingLocked = editorSelectors.isPostAutosavingLocked as () => boolean;
			const isAutosavingPost = editorSelectors.isAutosavingPost as () => boolean;
			const isCurrentPostPublished = editorSelectors.isCurrentPostPublished as () => boolean;
			const isPostLockTakeover = editorSelectors.isPostLockTakeover as () => boolean;

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
	const { receiveEntityRecords } = useDispatch( 'core' );
	const updateMetaValue = ( key: string, value: unknown ) => editPost( { meta: { [ key ]: value } } );

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
		// Toggle the flag for layouts too — Testing waits on its transition.
		// Only the ESP rehydrate calls below are layout-skipped.
		const shouldTrackRefresh = isSupportedESP || isLayoutEditor();
		let hadError = false;
		try {
			lockPostSaving( 'newspack-newsletters-refresh-html' );
			if ( shouldTrackRefresh ) {
				updateLastRefreshHadError( false );
				updateIsRefreshingHtml( true );
			}
			const refreshedHtml = await refreshEmailHtml( postId, postTitle, postContent );
			if ( refreshedHtml.html ) {
				updateMetaValue( newspack_email_editor_data.email_html_meta, refreshedHtml.html );
			} else {
				const errorMessage = __( 'Failed to refresh email HTML', 'newspack-newsletters' );
				throw new Error( `${ errorMessage }${ refreshedHtml.error?.message ? `: ${ refreshedHtml.error?.message }` : '.' }` );
			}

			// Save the refreshed HTML to post meta. Persisted out-of-band (not via
			// savePost) to avoid re-triggering this post-save refresh.
			const updatedRecord = await apiFetch( {
				data: { meta: { [ newspack_email_editor_data.email_html_meta ]: refreshedHtml.html } },
				method: 'POST',
				path: `/wp/v2/${ postType }/${ postId }`,
			} );

			// Reconcile the editor's persisted baseline with the saved record so the
			// updateMetaValue() above isn't left as a phantom "unsaved" edit. The
			// rendered HTML embeds a server timestamp, so it never matches the prior
			// baseline and would otherwise keep the post permanently dirty after every
			// save (false "unsaved changes" prompt). See NPPM-2722.
			//
			// invalidateCache is false: this only refreshes the persisted baseline (so
			// the email-HTML edit becomes transient), without discarding any unrelated
			// edits the user may have made during the refresh — the editor stays the
			// source of truth for those.
			if ( updatedRecord ) {
				receiveEntityRecords( 'postType', postType, [ updatedRecord ], undefined, false );
			}

			// Layouts have no ESP campaign — these would 404 noisily.
			if ( isSupportedESP && ! isLayoutEditor() ) {
				await fetchNewsletterData( postId );
				await fetchSyncErrors( postId );
			}
		} catch ( e ) {
			hadError = true;
			const message = e && typeof e === 'object' && 'message' in e ? ( e as { message?: unknown } ).message : undefined;
			createNotice( 'error', ( message as string | undefined ) || __( 'Error refreshing email HTML.', 'newspack-newsletters' ), {
				id: 'newspack-newsletters-mjml-error',
				isDismissible: true,
			} );
		} finally {
			// Set the error flag before flipping the refresh flag — Testing's
			// effect fires on the boolean transition and needs an up-to-date
			// error read to decide whether to send.
			if ( shouldTrackRefresh ) {
				updateLastRefreshHadError( hadError );
				updateIsRefreshingHtml( false );
			}
			unlockPostSaving( 'newspack-newsletters-refresh-html' );
		}
	};
}

export default MJML;
