/* global newspack_email_editor_data */

/**
 * External dependencies
 */
import { get, isEmpty } from 'lodash';

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { compose } from '@wordpress/compose';
import { withDispatch, withSelect } from '@wordpress/data';
import { createPortal, useEffect, useState } from '@wordpress/element';
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import withApiHandler from '../../components/with-api-handler';
import SendButton from '../../components/send-button';
import './style.scss';
import { CAMPAIGN_SENT_NOTICE_ID } from '../../utils/consts';

const Editor = compose( [
	withApiHandler(),
	withSelect( select => {
		const { getEditedPostAttribute } = select( 'core/editor' );
		const { getAllMetaBoxes } = select( 'core/edit-post' );
		const { getSettings } = select( 'core/block-editor' );
		const meta = getEditedPostAttribute( 'meta' );
		const sent = meta.newsletter_sent;
		const settings = getSettings();
		const experimentalSettingsColors = get( settings, [ '__experimentalFeatures', 'global', 'color', 'palette' ] );
		const colors = settings.colors || experimentalSettingsColors || [];

		return {
			html: meta[ newspack_email_editor_data.email_html_meta ],
			colorPalette: colors.reduce( ( _colors, { slug, color } ) => ( { ..._colors, [ slug ]: color } ), {} ),
			sent,
			newsletterSendErrors: meta.newsletter_send_errors,
			isCustomFieldsMetaBoxActive: getAllMetaBoxes().some( box => box.id === 'postcustom' ),
		};
	} ),
	withDispatch( dispatch => {
		const { lockPostAutosaving, unlockPostAutosaving, editPost } = dispatch( 'core/editor' );
		const { createNotice, removeNotice } = dispatch( 'core/notices' );
		const { openModal } = dispatch( 'core/interface' );
		return {
			lockPostAutosaving,
			unlockPostAutosaving,
			editPost,
			createNotice,
			removeNotice,
			openModal,
		};
	} ),
] )( ( {
	apiFetchWithErrorHandling,
	colorPalette,
	createNotice,
	html,
	isCustomFieldsMetaBoxActive,
	lockPostAutosaving,
	newsletterSendErrors,
	openModal,
	removeNotice,
	sent,
	successNote,
} ) => {
	// This component holds no validation-based saving lock, by design. Sending is
	// gated by the Send button itself (see components/send-button), which is the
	// control that dispatches to the ESP; an incomplete newsletter is still a
	// perfectly good draft. WordPress 7.1 added the post-saving lock to core's
	// "Save draft" disabled condition, so holding one here left authors unable to
	// save work in progress until they had filled in sender and list (NEWS-2888).
	//
	// Note this is only about the *validation* lock: `editor/mjml` still takes a
	// short-lived `newspack-newsletters-refresh-html` saving lock around the
	// post-save HTML refresh, so a greyed-out "Save draft" is not automatically
	// this file's doing.
	const [ publishEl ] = useState( document.createElement( 'div' ) );

	useEffect( () => {
		// Create alternate publish button.
		const publishButton = document.getElementsByClassName( 'editor-post-publish-button__button' )[ 0 ];
		publishButton.parentNode.insertBefore( publishEl, publishButton.nextSibling );
	}, [] );

	// Set color palette option.
	useEffect( () => {
		if ( isEmpty( colorPalette ) ) {
			return;
		}
		apiFetchWithErrorHandling( {
			path: `/newspack-newsletters/v1/color-palette`,
			data: colorPalette,
			method: 'POST',
		} );
	}, [ JSON.stringify( colorPalette ) ] );

	useEffect( () => {
		if ( sent ) {
			const sentDate = 0 < sent ? new Date( sent * 1000 ) : null;
			const dateTime = sentDate ? sentDate.toLocaleString() : '';

			// Lock autosaving after a newsletter is sent.
			lockPostAutosaving();

			// Show an editor notice if the newsletter has been sent. The separating space lives here
			// rather than in `successNote` so the translatable string carries no flanking whitespace.
			createNotice( 'success', `${ successNote } ${ dateTime }`, {
				id: CAMPAIGN_SENT_NOTICE_ID,
				isDismissible: false,
			} );

			// Remove error notice.
			removeNotice( 'newspack-newsletters-newsletter-send-error' );
		}
	}, [ sent ] );

	useEffect( () => {
		if ( isCustomFieldsMetaBoxActive ) {
			createNotice(
				'error',
				__(
					'"Custom Fields" meta box is active in the UI. This will prevent the newsletter editor from functioning correctly. Please disable this meta box in the "Panels" section of the Editor Preferences.',
					'newspack-newsletters'
				),
				{
					id: 'newspack-newsletters-custom-fields-warning',
					isDismissible: false,
					actions: [
						{
							label: __( 'Open Editor Preferences', 'newspack-newsletters' ),
							onClick: () => openModal( 'edit-post/preferences' ),
						},
					],
				}
			);
		}
	}, [ isCustomFieldsMetaBoxActive ] );

	useEffect( () => {
		if ( ! sent && newsletterSendErrors?.length ) {
			const message = sprintf(
				/* translators: %s: error message */
				__( 'Error sending newsletter: %s', 'newspack-newsletters' ),
				newsletterSendErrors[ newsletterSendErrors.length - 1 ].message
			);
			createNotice( 'error', message, {
				id: 'newspack-newsletters-newsletter-send-error',
				isDismissible: true,
			} );
		} else {
			removeNotice( 'newspack-newsletters-newsletter-send-error' );
		}
	}, [ newsletterSendErrors ] );

	// Notify if email content is larger than ~100kb.
	useEffect( () => {
		const noticeId = 'newspack-newsletters-email-content-too-large';
		const message = __( 'Email content is too long and may get clipped by email clients.', 'newspack-newsletters' );
		if ( html.length > 100000 ) {
			createNotice( 'warning', message, {
				id: noticeId,
				isDismissible: false,
			} );
		} else {
			removeNotice( noticeId );
		}
	}, [ html ] );

	return createPortal( <SendButton />, publishEl );
} );

export default () => {
	registerPlugin( 'newspack-newsletters-edit', {
		render: Editor,
	} );
};
