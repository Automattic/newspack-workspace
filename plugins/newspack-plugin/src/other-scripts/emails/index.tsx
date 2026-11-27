/* globals newspack_emails */

/**
 * External dependencies
 */
import type { ComponentType } from 'react';

/**
 * WordPress dependencies
 */
import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { withSelect, withDispatch } from '@wordpress/data';
import { compose } from '@wordpress/compose';
import { Button, Spinner, TextControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import { hooks } from '../../../packages/components/src';
import './style.scss';

type ReaderRevenueEmailSidebarProps = {
	postId: number;
	savePost: () => Promise< void >;
	title: string;
	postMeta: Record< string, string >;
	updatePostTitle: ( title: string ) => void;
	createNotice: ( status: string, content: string, options?: { id?: string; isDismissible?: boolean } ) => void;
};

// compose() is loosely typed (its result takes and returns `unknown`), so the
// composed component is asserted as ComponentType at the trailing boundary.
const ReaderRevenueEmailSidebar = compose(
	withSelect( select => {
		// The editor selectors are untyped for string-keyed stores; assert at the store boundary.
		const { getEditedPostAttribute, getCurrentPostId } = select( 'core/editor' ) as {
			getEditedPostAttribute: {
				( attribute: 'meta' ): Record< string, string >;
				( attribute: 'title' ): string;
			};
			getCurrentPostId: () => number;
		};
		const postMeta = getEditedPostAttribute( 'meta' );
		return {
			title: getEditedPostAttribute( 'title' ),
			postId: getCurrentPostId(),
			postMeta,
		};
	} ),
	// The mapper returns specifically-typed action props; withDispatch's own
	// signature widens them to an unknown-arg index, so cast the mapper at the boundary.
	withDispatch( ( ( dispatch: ( store: string ) => Record< string, ( ...args: unknown[] ) => unknown > ) => {
		const wpDispatch = dispatch;
		const { savePost, editPost } = wpDispatch( 'core/editor' ) as {
			savePost: () => Promise< void >;
			editPost: ( edits: { meta?: Record< string, string >; title?: string } ) => void;
		};
		const { createNotice } = wpDispatch( 'core/notices' ) as {
			createNotice: ( status: string, content: string, options?: { id?: string; isDismissible?: boolean } ) => void;
		};
		return {
			savePost,
			createNotice,
			updatePostMeta: ( key: string ) => ( value: string ) => editPost( { meta: { [ key ]: value } } ),
			updatePostTitle: ( title: string ) => editPost( { title } ),
		};
	} ) as Parameters< typeof withDispatch >[ 0 ] )
)( ( { postId, savePost, title, postMeta, updatePostTitle, createNotice }: ReaderRevenueEmailSidebarProps ) => {
	const [ inFlight, setInFlight ] = useState( false );
	const [ settings, updateSettings ] = hooks.useObjectState( {
		testRecipient: newspack_emails.current_user_email,
	} );
	const configMetaName = postMeta[ newspack_emails.email_config_name_meta ];
	const config = newspack_emails.configs[ configMetaName ];

	useEffect( () => {
		if ( config?.editor_notice ) {
			createNotice( 'info', config.editor_notice, {
				id: 'newspack_email_info',
				isDismissible: false,
			} );
		}
		createNotice(
			'info',
			sprintf(
				/* translators: 1: "From" email address 2: "From" email name */
				__( 'This email will be sent from %1$s <%2$s>.', 'newspack-plugin' ),
				config.from_name || newspack_emails.from_name,
				config.from_email || newspack_emails.from_email
			),
			{
				id: 'newspack_email_sender',
				isDismissible: false,
			}
		);
	}, [] );

	const sendTestEmail = async () => {
		setInFlight( true );
		// Single try/catch around BOTH savePost() and apiFetch() with a
		// shared finally cleanup. Previously only the apiFetch().finally()
		// cleared the in-flight state, so a rejected savePost() (a failed
		// draft save) left the sidebar stuck in "Sending…" with no notice —
		// the apiFetch chain never ran. Now either failure surfaces a notice
		// and always resets inFlight.
		try {
			await savePost();
			await apiFetch( {
				path: `/newspack/v1/newspack-emails/test`,
				method: 'POST',
				data: {
					recipient: settings.testRecipient,
					post_id: postId,
				},
			} );
			createNotice( 'success', __( 'Test email sent!', 'newspack-plugin' ) );
		} catch ( error ) {
			// Surface the server's specific message when present (NPPD-1547
			// added structured error codes / messages for each prerequisite
			// failure: invalid recipient, missing HTML payload, trashed post,
			// etc.). The generic is the fallback for unstructured errors
			// (a savePost failure, network failures, CORS) with no `.message`.
			const errorMessage = error && typeof error === 'object' && 'message' in error ? error.message : null;
			const message = ( typeof errorMessage === 'string' && errorMessage ) || __( 'Test email was not sent.', 'newspack-plugin' );
			createNotice( 'error', message );
		} finally {
			setInFlight( false );
		}
	};
	return (
		<>
			{ config.available_placeholders?.length && (
				<PluginDocumentSettingPanel name="email-instructions-panel" title={ __( 'Instructions', 'newspack-plugin' ) }>
					{ __( 'Use the following placeholders to insert dynamic content in the email:', 'newspack-plugin' ) }
					<ul>
						{ config.available_placeholders.map( ( item, i ) => (
							<li key={ i }>
								– <code>{ item.template }</code>: { item.label }
							</li>
						) ) }
					</ul>
				</PluginDocumentSettingPanel>
			) }
			<PluginDocumentSettingPanel name="email-settings-panel" title={ __( 'Settings', 'newspack-plugin' ) }>
				<TextControl label={ __( 'Subject', 'newspack-plugin' ) } value={ title } onChange={ updatePostTitle } />
			</PluginDocumentSettingPanel>
			<PluginDocumentSettingPanel name="email-testing-panel" title={ __( 'Testing', 'newspack-plugin' ) }>
				<TextControl
					label={ __( 'Send to', 'newspack-plugin' ) }
					value={ settings.testRecipient }
					type="email"
					onChange={ updateSettings( 'testRecipient' ) }
				/>
				<div className="newspack__testing-controls">
					<Button isPrimary onClick={ sendTestEmail } disabled={ inFlight }>
						{ inFlight ? __( 'Sending…', 'newspack-plugin' ) : __( 'Send', 'newspack-plugin' ) }
					</Button>
					{ inFlight && <Spinner /> }
				</div>
			</PluginDocumentSettingPanel>
		</>
	);
} ) as ComponentType;

registerPlugin( 'newspack-emails-sidebar', {
	render: ReaderRevenueEmailSidebar,
	// An explicit falsy icon overrides registerPlugin's default plugins icon via object spread.
	icon: undefined,
} );
