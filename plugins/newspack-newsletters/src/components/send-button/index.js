/**
 * WordPress dependencies
 */
import { withDispatch, withSelect, useSelect } from '@wordpress/data';
import { compose } from '@wordpress/compose';
import { Button, Modal, Spinner } from '@wordpress/components';
import { Fragment, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Testing from '../../newsletter-editor/testing';
import DisableAutoAds from '../../ads/newsletter-editor/disable-auto-ads';

/**
 * External dependencies
 */
import { get } from 'lodash';

/**
 * Internal dependencies
 */
import { getServiceProvider } from '../../service-providers';
import { validateNewsletter } from '../../newsletter-editor/utils';
import { useNewsletterData } from '../../newsletter-editor/store';
import { refreshEmailHtml } from '../../editor/mjml';
import './style.scss';

function PreviewHTML() {
	const { isSaving, isAutosaving, postId, postContent, postTitle } = useSelect( select => {
		const { getCurrentPostId, getCurrentPostType, getEditedPostAttribute, getEditedPostContent, isAutosavingPost, isSavingPost } =
			select( 'core/editor' );
		return {
			isSaving: isSavingPost(),
			isAutosaving: isAutosavingPost(),
			postContent: getEditedPostContent(),
			postId: getCurrentPostId(),
			postTitle: getEditedPostAttribute( 'title' ),
			postType: getCurrentPostType(),
		};
	} );
	const [ previewHtml, setPreviewHtml ] = useState( '' );
	const showSpinner = ( isSaving && ! isAutosaving ) || ! previewHtml;

	useEffect( () => {
		if ( ! previewHtml ) {
			refreshEmailHtml( postId, postTitle, postContent ).then( ( { html } ) => {
				setPreviewHtml( html );
			} );
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
		const { isSavingPost } = select( 'core/editor' );
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

export default compose( [
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
		} = select( 'core/editor' );

		return {
			isPublishable: isEditedPostPublishable(),
			isSaveable: isEditedPostSaveable(),
			status: getEditedPostAttribute( 'status' ),
			isSaving: isSavingPost(),
			saveDidSucceed: didPostSaveRequestSucceed(),
			isEditedPostBeingScheduled: isEditedPostBeingScheduled(),
			hasPublishAction: get( getCurrentPost(), [ '_links', 'wp:action-publish' ], false ),
			visibility: getEditedPostVisibility(),
			meta: getEditedPostAttribute( 'meta' ),
			sent: getCurrentPostAttribute( 'meta' ).newsletter_sent,
			isPublished: isCurrentPostPublished(),
			postDate: getEditedPostAttribute( 'date' ),
			postContent: getEditedPostContent(),
			postId: getCurrentPostId(),
			postTitle: getEditedPostAttribute( 'title' ),
		};
	} ),
] )( ( {
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
} ) => {
	const [ modalVisible, setModalVisible ] = useState( false );

	// If the save request failed, close any open modals so the error message can be seen underneath.
	useEffect( () => {
		if ( ! saveDidSucceed ) {
			setModalVisible( false );
		}
	}, [ saveDidSucceed ] );

	const { is_public } = meta;
	const { newsletterData } = useNewsletterData();

	const newsletterValidationErrors = validateNewsletter( meta );

	const { name: serviceProviderName, renderPreSendInfo, renderPostUpdateInfo } = getServiceProvider();

	const isButtonEnabled =
		( isPublishable || isEditedPostBeingScheduled ) &&
		isSaveable &&
		! isPublished &&
		! isSaving &&
		'future' !== status &&
		( newsletterData.campaign || 'manual' === serviceProviderName ) &&
		0 === newsletterValidationErrors.length;
	let label;
	if ( isPublished ) {
		if ( isSaving ) {
			label = __( 'Sending', 'newspack-newsletters' );
		} else {
			label = is_public ? __( 'Sent and Published', 'newspack-newsletters' ) : __( 'Sent', 'newspack-newsletters' );
		}
	} else if ( 'future' === status ) {
		if ( postDate && new Date( postDate ) < new Date() ) {
			// Scheduled, but in the past ¯\_(ツ)_/¯.
			label = __( 'Send', 'newspack-newsletters' );
		} else {
			// Scheduled to be sent.
			label = __( 'Scheduled', 'newspack-newsletters' );
		}
	} else if ( isEditedPostBeingScheduled ) {
		label = __( 'Schedule sending', 'newspack-newsletters' );
	} else {
		label = is_public ? __( 'Send and Publish', 'newspack-newsletters' ) : __( 'Send', 'newspack-newsletters' );
	}

	let updateLabel;
	if ( isSaving ) {
		updateLabel = __( 'Updating…', 'newspack-newsletters' );
	} else if ( 'manual' === serviceProviderName ) {
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

	let modalSubmitLabel;
	if ( 'manual' === serviceProviderName ) {
		modalSubmitLabel = is_public ? __( 'Mark as sent and publish', 'newspack-newsletters' ) : __( 'Mark as sent', 'newspack-newsletters' );
	} else {
		modalSubmitLabel = label;
	}

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
								{ 'manual' !== serviceProviderName && (
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
		const res = await refreshEmailHtml( postId, postTitle, postContent );
		await savePost();
		if ( res.result === 'success' && saveDidSucceed ) {
			setModalVisible( true );
		} else {
			let noticeString = __( 'Something went wrong when converting the post to email', 'newspack-newsletters' );
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
			<Button
				className="editor-post-publish-button"
				isBusy={ isSaving && 'publish' === status }
				variant="primary"
				onClick={ handleModalOpen }
				disabled={ ! isButtonEnabled }
			>
				{ label }
			</Button>
			{ modalVisible && (
				<Modal
					className="newspack-newsletters__modal"
					title={ __( 'Send your newsletter?', 'newspack-newsletters' ) }
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
							{ 'manual' !== serviceProviderName && (
								<Testing testEmail={ testEmail } onChangeEmail={ setTestEmail } disabled={ isSaving } inlineNotifications />
							) }
							<div className="newspack-newsletters__modal__spacer" />
							{ renderPreSendInfo( newsletterData, meta ) }
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
} );
