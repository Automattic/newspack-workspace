/* global newspack_email_editor_data */

/**
 * WordPress dependencies
 */
import { withDispatch, withSelect, useSelect, useDispatch } from '@wordpress/data';
import { compose } from '@wordpress/compose';
import { Button, Modal, Spinner } from '@wordpress/components';
import { Fragment, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import TestingComponent from '../../newsletter-editor/testing';
import DisableAutoAds from '../../ads/newsletter-editor/disable-auto-ads';

/**
 * External dependencies
 */
import { get } from 'lodash';
import type { ComponentType } from 'react';

/**
 * Internal dependencies
 */
import { getServiceProvider } from '../../service-providers';
import { isManualProvider } from '../../utils/service-provider';
import { getSettledSendLists, validateNewsletter } from '../../newsletter-editor/utils';
import { useNewsletterData } from '../../newsletter-editor/store';
import { refreshEmailHtml } from '../../editor/mjml';
import type { NewsletterMeta } from '../../service-providers/types';
import './style.scss';

// `Testing` (`src/newsletter-editor/testing`) isn't typed yet (its `compose(...)`
// composition and untyped inner props infer to something with no JSX call
// signature); treat it as an opaque boundary here with the props this file passes.
interface TestingProps {
	testEmail: string;
	onChangeEmail: ( value: string ) => void;
	disabled?: boolean;
	inlineNotifications?: boolean;
}
const Testing = TestingComponent as ComponentType< TestingProps >;

/** Props the `withDispatch`/`withSelect` HOCs inject into the composed component below. */
interface SendButtonProps {
	editPost: ( edits: Record< string, unknown > ) => void;
	savePost: () => Promise< void >;
	createNotice: ( status: string, content: string ) => void;
	isPublishable: boolean;
	isSaveable: boolean;
	isSaving: boolean;
	saveDidSucceed: boolean;
	status: string;
	isEditedPostBeingScheduled: boolean;
	hasPublishAction: boolean;
	visibility: string;
	meta: NewsletterMeta;
	sent: NewsletterMeta[ 'newsletter_sent' ];
	isPublished: boolean;
	postDate: string | null;
	postContent: string;
	postId: number;
	postTitle: string;
}

// `refreshEmailHtml` (`src/editor/mjml`) isn't typed yet; its result is read at
// this boundary with the shape its own JSDoc documents.
interface RefreshEmailHtmlResult {
	result: string;
	html?: string;
	error?: { message?: string };
}

function PreviewHTML() {
	const { isSaving, isAutosaving, isDirty, postId, postContent, postTitle } = useSelect( select => {
		// `core/editor`'s shipped selector types don't cover these methods; cast at
		// the opaque store boundary.
		const { getCurrentPostId, getEditedPostAttribute, getEditedPostContent, isAutosavingPost, isEditedPostDirty, isSavingPost } = select(
			'core/editor'
		) as {
			getCurrentPostId: () => number;
			getEditedPostAttribute: ( attribute: string ) => unknown;
			getEditedPostContent: () => string;
			isAutosavingPost: () => boolean;
			isEditedPostDirty: () => boolean;
			isSavingPost: () => boolean;
		};
		return {
			isSaving: isSavingPost(),
			isAutosaving: isAutosavingPost(),
			isDirty: isEditedPostDirty(),
			postContent: getEditedPostContent(),
			postId: getCurrentPostId(),
			postTitle: getEditedPostAttribute( 'title' ) as string,
		};
	} );
	const { savePost } = useDispatch( 'core/editor' );
	const { createNotice } = useDispatch( 'core/notices' );
	const [ previewHtml, setPreviewHtml ] = useState( '' );
	const showSpinner = ( isSaving && ! isAutosaving ) || ! previewHtml;

	useEffect( () => {
		if ( ! previewHtml ) {
			// Mount-once: the modal remounts on each open, so capturing isDirty/savePost
			// at mount is intentional — do not add them to deps (would cause double-fetches).
			( async () => {
				try {
					if ( newspack_email_editor_data?.use_woo_renderer && isDirty ) {
						await savePost();
					}
					const res = ( await refreshEmailHtml( postId, postTitle, postContent ) ) as RefreshEmailHtmlResult;
					if ( res?.html ) {
						setPreviewHtml( res.html );
					} else {
						createNotice( 'error', res?.error?.message || __( 'Failed to load email preview.', 'newspack-newsletters' ) );
						// Resolve the spinner with non-empty fallback markup.
						setPreviewHtml( `<p>${ __( 'Could not load preview.', 'newspack-newsletters' ) }</p>` );
					}
				} catch ( error ) {
					createNotice(
						'error',
						( error as { message?: string } )?.message || __( 'Failed to load email preview.', 'newspack-newsletters' )
					);
					// Resolve the spinner with non-empty fallback markup.
					setPreviewHtml( `<p>${ __( 'Could not load preview.', 'newspack-newsletters' ) }</p>` );
				}
			} )();
		}
	}, [] );

	return (
		<div className="newsletter-preview-html">
			{ showSpinner && (
				<div className="newsletter-preview-html__spinner">
					<Spinner />
				</div>
			) }
			{ ! showSpinner ? (
				<iframe title={ __( 'Preview email', 'newspack-newsletters' ) } srcDoc={ previewHtml } className="newsletter-preview-html__iframe" />
			) : null }
		</div>
	);
}

function PreviewHTMLButton() {
	const { isSaving } = useSelect( select => {
		const { isSavingPost } = select( 'core/editor' ) as { isSavingPost: () => boolean };
		return {
			isSaving: isSavingPost(),
		};
	} );
	const [ isModalOpen, setIsModalOpen ] = useState( false );

	return (
		<Fragment>
			<Button
				className="newsletter-preview-html-button"
				variant="secondary"
				disabled={ isSaving }
				onClick={ async () => {
					setIsModalOpen( true );
				} }
			>
				{ __( 'Preview email', 'newspack-newsletters' ) }
			</Button>
			{ isModalOpen && (
				<Modal
					title={ __( 'Preview email', 'newspack-newsletters' ) }
					onRequestClose={ () => setIsModalOpen( false ) }
					className="newspack-newsletters__modal newsletter-preview-html-modal"
					overlayClassName="newsletter-preview-html-modal__overlay"
					shouldCloseOnClickOutside={ false }
					isFullScreen
				>
					<PreviewHTML />
				</Modal>
			) }
		</Fragment>
	);
}

export default compose(
	withDispatch( dispatch => {
		const { editPost, savePost } = dispatch( 'core/editor' );
		const { createNotice } = dispatch( 'core/notices' );
		return { editPost, savePost, createNotice };
	} ),
	withSelect( select => {
		const {
			didPostSaveRequestSucceed,
			getCurrentPost,
			getCurrentPostAttribute,
			getEditedPostAttribute,
			getEditedPostVisibility,
			isEditedPostPublishable,
			isEditedPostSaveable,
			isSavingPost,
			isEditedPostBeingScheduled,
			isCurrentPostPublished,
			getEditedPostContent,
			getCurrentPostId,
		} = select( 'core/editor' ) as {
			didPostSaveRequestSucceed: () => boolean;
			getCurrentPost: () => unknown;
			getCurrentPostAttribute: ( attribute: string ) => NewsletterMeta;
			getEditedPostAttribute: ( attribute: string ) => unknown;
			getEditedPostVisibility: () => string;
			isEditedPostPublishable: () => boolean;
			isEditedPostSaveable: () => boolean;
			isSavingPost: () => boolean;
			isEditedPostBeingScheduled: () => boolean;
			isCurrentPostPublished: () => boolean;
			getEditedPostContent: () => string;
			getCurrentPostId: () => number;
		};

		return {
			isPublishable: isEditedPostPublishable(),
			isSaveable: isEditedPostSaveable(),
			status: getEditedPostAttribute( 'status' ) as string,
			isSaving: isSavingPost(),
			saveDidSucceed: didPostSaveRequestSucceed(),
			isEditedPostBeingScheduled: isEditedPostBeingScheduled(),
			hasPublishAction: get( getCurrentPost(), [ '_links', 'wp:action-publish' ], false ),
			visibility: getEditedPostVisibility(),
			meta: getEditedPostAttribute( 'meta' ) as NewsletterMeta,
			sent: getCurrentPostAttribute( 'meta' ).newsletter_sent,
			isPublished: isCurrentPostPublished(),
			postDate: getEditedPostAttribute( 'date' ) as string | null,
			postContent: getEditedPostContent(),
			postId: getCurrentPostId(),
			postTitle: getEditedPostAttribute( 'title' ) as string,
		};
	} )
)(
	( {
		editPost,
		savePost,
		createNotice,
		isPublishable,
		isSaveable,
		isSaving,
		saveDidSucceed,
		status,
		isEditedPostBeingScheduled,
		hasPublishAction,
		visibility,
		meta,
		sent,
		isPublished,
		postDate,
		postContent,
		postId,
		postTitle,
	}: SendButtonProps ) => {
		const [ modalVisible, setModalVisible ] = useState( false );

		// If the save request failed, close any open modals so the error message can be seen underneath.
		useEffect( () => {
			if ( ! saveDidSucceed ) {
				setModalVisible( false );
			}
		}, [ saveDidSucceed ] );

		const { is_public } = meta;
		const newsletterDataState = useNewsletterData();
		const { newsletterData } = newsletterDataState;

		const newsletterValidationErrors = validateNewsletter( meta, getSettledSendLists( newsletterDataState ) );

		const { renderPreSendInfo, renderPostUpdateInfo } = getServiceProvider();

		// The manual provider doesn't send through an ESP – the publisher copies the rendered HTML and sends it
		// themselves. So the editor keeps WordPress' native publish/published terminology instead of send/sent.
		const isManual = isManualProvider();

		const isButtonEnabled =
			( isPublishable || isEditedPostBeingScheduled ) &&
			isSaveable &&
			! isPublished &&
			! isSaving &&
			'future' !== status &&
			( newsletterData.campaign || isManual ) &&
			0 === newsletterValidationErrors.length;
		let label;
		if ( isPublished ) {
			if ( isSaving ) {
				label = isManual ? __( 'Publishing…', 'newspack-newsletters' ) : __( 'Sending…', 'newspack-newsletters' );
			} else if ( isManual ) {
				label = __( 'Published', 'newspack-newsletters' );
			} else {
				label = is_public ? __( 'Sent and Published', 'newspack-newsletters' ) : __( 'Sent', 'newspack-newsletters' );
			}
		} else if ( 'future' === status ) {
			if ( postDate && new Date( postDate ) < new Date() ) {
				// Scheduled, but in the past ¯\_(ツ)_/¯.
				label = isManual ? __( 'Publish', 'newspack-newsletters' ) : __( 'Send', 'newspack-newsletters' );
			} else {
				// Scheduled to be sent.
				label = __( 'Scheduled', 'newspack-newsletters' );
			}
		} else if ( isEditedPostBeingScheduled ) {
			label = isManual ? __( 'Schedule', 'newspack-newsletters' ) : __( 'Schedule sending', 'newspack-newsletters' );
		} else if ( isManual ) {
			label = __( 'Publish', 'newspack-newsletters' );
		} else {
			label = is_public ? __( 'Send and Publish', 'newspack-newsletters' ) : __( 'Send', 'newspack-newsletters' );
		}

		let updateLabel;
		if ( isSaving ) {
			updateLabel = __( 'Updating…', 'newspack-newsletters' );
		} else if ( isManual ) {
			updateLabel = __( 'Update and copy HTML', 'newspack-newsletters' );
		} else {
			updateLabel = __( 'Update', 'newspack-newsletters' );
		}

		let publishStatus;
		if ( ! hasPublishAction ) {
			publishStatus = 'pending';
		} else if ( visibility === 'private' ) {
			publishStatus = 'private';
		} else if ( isEditedPostBeingScheduled ) {
			publishStatus = 'future';
		} else {
			publishStatus = 'publish';
		}

		const [ testEmail, setTestEmail ] = useState( window?.newspack_newsletters_data?.user_test_emails?.join( ',' ) || '' );

		// `label` already carries the manual provider's "Publish" wording, so it works as the modal submit label too.
		const modalSubmitLabel = label;

		const triggerCampaignSend = async () => {
			editPost( { status: publishStatus } );
			await savePost();
		};

		const unscheduleNewsletter = () => {
			editPost( {
				status: 'draft',
				date: null, // Reset the scheduled date.
			} );
			savePost();
		};

		// For sent newsletters, display the generic button text.
		if ( isPublished || sent || 'future' === status ) {
			return (
				<div style={ { display: 'flex' } }>
					{ 'future' === status && (
						<Button
							className="newsletter-unschedule-button"
							isBusy={ isSaving }
							variant="tertiary"
							disabled={ isSaving }
							onClick={ unscheduleNewsletter }
						>
							{ __( 'Unschedule', 'newspack-newsletters' ) }
						</Button>
					) }
					<PreviewHTMLButton />
					<Button
						className="editor-post-publish-button"
						isBusy={ isSaving }
						isPrimary
						disabled={ isSaving }
						onClick={ async () => {
							try {
								await savePost();
								if ( saveDidSucceed && renderPostUpdateInfo ) {
									setModalVisible( true );
								}
							} catch ( e ) {
								setModalVisible( false );
							}
						} }
					>
						{ updateLabel }
					</Button>
					{ modalVisible && renderPostUpdateInfo && (
						<Modal
							className="newspack-newsletters__modal"
							title={ __( 'Newsletter HTML', 'newspack-newsletters' ) }
							onRequestClose={ () => setModalVisible( false ) }
							shouldCloseOnClickOutside={ false }
							isFullScreen
						>
							<div className="newspack-newsletters__modal__container">
								<div className="newspack-newsletters__modal__preview">
									<PreviewHTML />
								</div>
								<div className="newspack-newsletters__modal__content">
									<DisableAutoAds saveOnToggle />
									<hr />
									{ ! isManual && (
										<Testing testEmail={ testEmail } onChangeEmail={ setTestEmail } disabled={ isSaving } inlineNotifications />
									) }
									<hr />
									{ renderPostUpdateInfo( newsletterData ) }
								</div>
							</div>
						</Modal>
					) }
				</div>
			);
		}

		const handleModalOpen = async () => {
			// Under the Woo renderer the endpoint renders saved content, so save first
			// to avoid rendering stale content. When the flag is off, preserve the
			// original order (refresh first, then save) byte-for-byte.
			let res: RefreshEmailHtmlResult;
			if ( newspack_email_editor_data?.use_woo_renderer ) {
				await savePost();
				res = ( await refreshEmailHtml( postId, postTitle, postContent ) ) as RefreshEmailHtmlResult;
			} else {
				res = ( await refreshEmailHtml( postId, postTitle, postContent ) ) as RefreshEmailHtmlResult;
				await savePost();
			}
			if ( res.result === 'success' && saveDidSucceed ) {
				setModalVisible( true );
			} else {
				let noticeString: string = __( 'Something went wrong when converting the post to email', 'newspack-newsletters' );
				if ( res.error?.message ) {
					noticeString += `: ${ res.error.message }`;
				}
				createNotice( 'error', noticeString );
				setModalVisible( false );
			}
		};

		return (
			<div style={ { display: 'flex' } }>
				<PreviewHTMLButton />
				{ /* The button carries the reason it is disabled, and has to stay
			     focusable to deliver it: a natively disabled control leaves the tab
			     order, where neither a tooltip nor aria-describedby ever fires for a
			     keyboard user. `accessibleWhenDisabled` swaps `disabled` for
			     `aria-disabled` and suppresses click and mousedown itself, so Send
			     stays inert. `description` feeds both the tooltip and
			     aria-describedby; `label` becomes aria-label, so it carries the
			     button's own text rather than the reason, and `showTooltip` is
			     required because the tooltip is otherwise suppressed on a button
			     that has children. Without this the only signal is the sidebar
			     warning, which never renders when the author has that panel
			     collapsed. */ }
				<Button
					className="editor-post-publish-button"
					isBusy={ isSaving && 'publish' === status }
					variant="primary"
					onClick={ handleModalOpen }
					disabled={ ! isButtonEnabled }
					accessibleWhenDisabled
					label={ label }
					showTooltip={ newsletterValidationErrors.length > 0 }
					description={ newsletterValidationErrors.length ? newsletterValidationErrors.join( ' ' ) : undefined }
				>
					{ label }
				</Button>
				{ modalVisible && (
					<Modal
						className="newspack-newsletters__modal"
						title={
							isManual
								? __( 'Publish your newsletter?', 'newspack-newsletters' )
								: __( 'Send your newsletter?', 'newspack-newsletters' )
						}
						onRequestClose={ () => setModalVisible( false ) }
						shouldCloseOnClickOutside={ false }
						isFullScreen
					>
						<div className="newspack-newsletters__modal__container">
							<div className="newspack-newsletters__modal__preview">
								<PreviewHTML />
							</div>
							<div className="newspack-newsletters__modal__content">
								<DisableAutoAds saveOnToggle />
								<hr />
								{ ! isManual && (
									<Testing testEmail={ testEmail } onChangeEmail={ setTestEmail } disabled={ isSaving } inlineNotifications />
								) }
								<div className="newspack-newsletters__modal__spacer" />
								{ /* `renderPreSendInfo` is optional on `ServiceProvider`; called unconditionally here as in the original. */ }
								{ renderPreSendInfo!( newsletterData, meta ) }
								<div className="modal-buttons">
									<Button variant="secondary" onClick={ () => setModalVisible( false ) } disabled={ isSaving }>
										{ __( 'Cancel', 'newspack-newsletters' ) }
									</Button>
									<Button
										variant="primary"
										disabled={ newsletterValidationErrors.length > 0 || isSaving }
										onClick={ () => {
											triggerCampaignSend();
											setModalVisible( false );
										} }
									>
										{ modalSubmitLabel }
									</Button>
								</div>
							</div>
						</div>
					</Modal>
				) }
			</div>
		);
	}
) as ComponentType;
