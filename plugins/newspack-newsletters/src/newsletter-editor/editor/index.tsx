/* global newspack_email_editor_data */

/**
 * External dependencies
 */
import { get, isEmpty } from 'lodash';
import type { ComponentType } from 'react';

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
import SendButtonComponent from '../../components/send-button';
import './style.scss';
import { validateNewsletter } from '../utils';
import type { NewsletterMeta } from '../../service-providers/types';

// `send-button` composes its own HOC chain the same way this file does; until
// that unit's export settles on a concrete type, treat it as an opaque
// boundary here (mirrors the untyped-third-party cast convention).
const SendButton = SendButtonComponent as ComponentType;

/** A color-palette entry as returned by the block-editor settings selector. */
interface ColorPaletteItem {
	slug: string;
	color: string;
}

/** Subset of `core/block-editor`'s `getSettings()` result used here. */
interface BlockEditorSettingsSubset {
	colors?: ColorPaletteItem[];
	__experimentalFeatures?: {
		global?: {
			color?: {
				palette?: ColorPaletteItem[];
			};
		};
	};
}

interface EditorProps {
	apiFetchWithErrorHandling: ( options: { path: string; data?: unknown; method?: string } ) => Promise< unknown >;
	colorPalette: Record< string, string >;
	createNotice: ( status: string, content: string, options?: Record< string, unknown > ) => void;
	html: string;
	isCustomFieldsMetaBoxActive: boolean;
	lockPostAutosaving: () => void;
	lockPostSaving: ( lockName: string ) => void;
	meta: NewsletterMeta;
	newsletterSendErrors?: Array< { message: string; timestamp?: number } >;
	openModal: ( name: string ) => void;
	removeNotice: ( id: string ) => void;
	unlockPostSaving: ( lockName: string ) => void;
	sent?: number | boolean;
	successNote?: string;
}

const Editor = compose(
	withApiHandler(),
	withSelect( select => {
		const { getEditedPostAttribute } = select( 'core/editor' ) as {
			getEditedPostAttribute: ( attribute: string ) => NewsletterMeta;
		};
		const { getAllMetaBoxes } = select( 'core/edit-post' ) as {
			getAllMetaBoxes: () => Array< { id: string } >;
		};
		const { getSettings } = select( 'core/block-editor' ) as {
			getSettings: () => BlockEditorSettingsSubset;
		};
		const meta = getEditedPostAttribute( 'meta' );
		const sent = meta.newsletter_sent;
		const settings = getSettings();
		const experimentalSettingsColors = get( settings, [ '__experimentalFeatures', 'global', 'color', 'palette' ] );
		const colors = settings.colors || experimentalSettingsColors || [];

		return {
			html: meta[ newspack_email_editor_data.email_html_meta ] as string,
			colorPalette: colors.reduce< Record< string, string > >( ( _colors, { slug, color } ) => ( { ..._colors, [ slug ]: color } ), {} ),
			meta,
			sent,
			newsletterSendErrors: meta.newsletter_send_errors as EditorProps[ 'newsletterSendErrors' ],
			isCustomFieldsMetaBoxActive: getAllMetaBoxes().some( box => box.id === 'postcustom' ),
		};
	} ),
	withDispatch( dispatch => {
		// `dispatch()`'s non-generic overload already types unknown-string stores as
		// `Record<string, (...args: any[]) => any>`, so no cast is needed here (unlike
		// `select()` above, whose `withSelect`-scoped type resolves unknown stores to `never`).
		const { lockPostAutosaving, lockPostSaving, unlockPostAutosaving, unlockPostSaving, editPost } = dispatch( 'core/editor' );
		const { createNotice, removeNotice } = dispatch( 'core/notices' );
		const { openModal } = dispatch( 'core/interface' );
		return {
			lockPostAutosaving,
			lockPostSaving,
			unlockPostAutosaving,
			unlockPostSaving,
			editPost,
			createNotice,
			removeNotice,
			openModal,
		};
	} )
)(
	( {
		apiFetchWithErrorHandling,
		colorPalette,
		createNotice,
		html,
		isCustomFieldsMetaBoxActive,
		lockPostAutosaving,
		lockPostSaving,
		meta,
		newsletterSendErrors,
		openModal,
		removeNotice,
		unlockPostSaving,
		sent,
		successNote,
	}: EditorProps ) => {
		const [ publishEl ] = useState( document.createElement( 'div' ) );
		const newsletterValidationErrors = validateNewsletter( meta );
		const isReady = newsletterValidationErrors.length === 0;

		useEffect( () => {
			// Create alternate publish button.
			const publishButton = document.getElementsByClassName( 'editor-post-publish-button__button' )[ 0 ];
			publishButton.parentNode!.insertBefore( publishEl, publishButton.nextSibling );
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

		// Lock or unlock post publishing.
		useEffect( () => {
			if ( isReady ) {
				unlockPostSaving( 'newspack-newsletters-post-lock' );
			} else {
				lockPostSaving( 'newspack-newsletters-post-lock' );
			}
		}, [ isReady ] );

		useEffect( () => {
			if ( sent ) {
				// `sent` may be a legacy boolean flag or a sent timestamp; `Number( true )` is `1`, matching the original comparison.
				const sentValue = Number( sent );
				const sentDate = 0 < sentValue ? new Date( sentValue * 1000 ) : null;
				const dateTime = sentDate ? sentDate.toLocaleString() : '';

				// Lock autosaving after a newsletter is sent.
				lockPostAutosaving();

				// Show an editor notice if the newsletter has been sent.
				createNotice( 'success', successNote + dateTime, {
					id: 'newspack-newsletters-campaign-sent-notice',
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
	}
) as ComponentType;

export default () => {
	registerPlugin( 'newspack-newsletters-edit', {
		render: Editor,
	} );
};
