/**
 * External dependencies
 */
import { isEqual, find } from 'lodash';

/**
 * WordPress dependencies
 */
import { compose } from '@wordpress/compose';
import { parse, serialize } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { withDispatch, withSelect } from '@wordpress/data';
import { useState, useEffect, useMemo } from '@wordpress/element';
import {
	BaseControl,
	Button,
	Modal,
	TextControl,
	Spinner,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import { useLayoutsState } from '../../utils/hooks';
import { LAYOUT_CPT_SLUG } from '../../utils/consts';
import { isUserDefinedLayout } from '../../utils';
import './style.scss';
import { setPreventDeduplicationForPostsInserter } from '../../editor/blocks/posts-inserter/utils';
import NewsletterPreview from '../../components/newsletter-preview';

export default compose( [
	withSelect( select => {
		const { getEditedPostAttribute, isEditedPostEmpty, getCurrentPostId } = select( 'core/editor' );
		const { getBlocks } = select( 'core/block-editor' );
		const meta = getEditedPostAttribute( 'meta' );
		const {
			background_color,
			text_color,
			font_body,
			font_header,
			custom_css,
			senderEmail,
			senderName,
			send_list_id,
			send_sublist_id,
			disable_auto_ads,
		} = meta;
		const layoutMeta = {
			background_color,
			text_color,
			font_body,
			font_header,
			custom_css,
			disable_auto_ads,
		};

		// ESP-agnostic sender and send_to defaults.
		if ( senderEmail || senderName || send_list_id || send_sublist_id ) {
			layoutMeta.campaign_defaults = JSON.stringify( {
				senderEmail,
				senderName,
				send_list_id,
				send_sublist_id,
			} );
		}

		return {
			layoutId: meta.template_id,
			postTitle: getEditedPostAttribute( 'title' ),
			postBlocks: getBlocks(),
			isEditedPostEmpty: isEditedPostEmpty(),
			currentPostId: getCurrentPostId(),
			layoutMeta,
			postStatus: getEditedPostAttribute( 'status' ),
		};
	} ),
	withDispatch( dispatch => {
		const { editPost, savePost } = dispatch( 'core/editor' );
		const { saveEntityRecord } = dispatch( 'core' );
		return {
			editPost,
			savePost,
			saveLayout: payload =>
				saveEntityRecord( 'postType', LAYOUT_CPT_SLUG, {
					status: 'publish',
					...payload,
				} ),
		};
	} ),
] )( ( { editPost, savePost, layoutId, saveLayout, postBlocks, postTitle, isEditedPostEmpty, layoutMeta, postStatus } ) => {
	const [ warningModalVisible, setWarningModalVisible ] = useState( false );
	const { layouts, isFetchingLayouts } = useLayoutsState();

	const [ usedLayout, setUsedLayout ] = useState( {} );

	useEffect( () => {
		setUsedLayout( find( layouts, { ID: layoutId } ) || {} );
	}, [ layouts.length ] );

	const blockPreview = useMemo( () => {
		return usedLayout.post_content ? parse( usedLayout.post_content ) : null;
	}, [ usedLayout ] );

	const [ isSavingLayout, setIsSavingLayout ] = useState( false );
	const [ isManageModalVisible, setIsManageModalVisible ] = useState( null );
	const [ newLayoutName, setNewLayoutName ] = useState( postTitle );

	const handleLayoutUpdate = updatedLayout => {
		setIsSavingLayout( false );
		// Set this new layout as the newsletter's layout
		editPost( { meta: { template_id: updatedLayout.id } } );

		// Update the layout preview
		// The shape of this data is different than the API response for CPT
		setUsedLayout( {
			...updatedLayout,
			ID: updatedLayout.id,
			post_content: updatedLayout.content.raw,
			post_title: updatedLayout.title.raw,
			post_type: LAYOUT_CPT_SLUG,
		} );

		savePost();
	};

	const postContent = useMemo( () => serialize( postBlocks ), [ postBlocks ] );
	const isPostContentSameAsLayout = postContent === usedLayout.post_content && isEqual( usedLayout.meta, layoutMeta );

	const handleSaveAsLayout = () => {
		setIsSavingLayout( true );
		const updatePayload = {
			title: newLayoutName,
			content: postContent,
			meta: layoutMeta,
		};
		saveLayout( updatePayload ).then( newLayout => {
			setIsManageModalVisible( false );
			handleLayoutUpdate( newLayout );
		} );
	};

	const handleLayoutOverwrite = () => {
		if (
			// eslint-disable-next-line no-alert
			confirm( __( 'Are you sure you want to overwrite this layout?', 'newspack-newsletters' ) )
		) {
			setIsSavingLayout( true );
			const updatePayload = {
				id: usedLayout.ID,
				content: postContent,
				meta: layoutMeta,
			};
			saveLayout( updatePayload ).then( handleLayoutUpdate );
		}
	};

	const isUsingCustomLayout = isUserDefinedLayout( usedLayout );

	return (
		<BaseControl
			id="newspack-newsletters-layouts"
			help={ postStatus === 'future' && __( 'Unschedule this newsletter to edit layout.', 'newspack-newsletters' ) }
			__nextHasNoMarginBottom
		>
			<VStack spacing={ 2 }>
				{ Boolean( layoutId && isFetchingLayouts ) && (
					<div className="newspack-newsletters-layouts__spinner">
						<Spinner />
					</div>
				) }
				{ blockPreview !== null && (
					<div className="newspack-newsletters-layouts">
						<div className="newspack-newsletters-layouts__item">
							<div className="newspack-newsletters-layouts__item-preview">
								<NewsletterPreview
									layoutId={ layoutId }
									meta={ usedLayout.meta }
									blocks={ setPreventDeduplicationForPostsInserter( blockPreview ) }
									viewportWidth={ 848 }
								/>
							</div>
							<div className="newspack-newsletters-layouts__item-label">{ usedLayout.post_title }</div>
						</div>
					</div>
				) }
				<div className="newspack-newsletters-buttons-group">
					<Button
						variant="secondary"
						disabled={ isEditedPostEmpty || isSavingLayout }
						onClick={ () => setIsManageModalVisible( true ) }
						__next40pxDefaultSize
					>
						{ __( 'Save new layout', 'newspack-newsletters' ) }
					</Button>

					{ isUsingCustomLayout && (
						<Button
							variant="secondary"
							disabled={ isPostContentSameAsLayout || ( isSavingLayout && isManageModalVisible ) }
							isBusy={ isSavingLayout && ! isManageModalVisible }
							onClick={ handleLayoutOverwrite }
							__next40pxDefaultSize
						>
							{ __( 'Update layout', 'newspack-newsletters' ) }
						</Button>
					) }

					<Button
						variant="secondary"
						isDestructive
						disabled={ isEditedPostEmpty || isSavingLayout || postStatus === 'future' }
						onClick={ () => setWarningModalVisible( true ) }
						__next40pxDefaultSize
					>
						{ __( 'Reset layout', 'newspack-newsletters' ) }
					</Button>
				</div>
			</VStack>

			{ isManageModalVisible && (
				<Modal
					className="newspack-newsletters__modal"
					title={ __( 'Save newsletter as a layout', 'newspack-newsletters' ) }
					onRequestClose={ () => setIsManageModalVisible( null ) }
					size="small"
				>
					<TextControl
						label={ __( 'Title', 'newspack-newsletters' ) }
						disabled={ isSavingLayout }
						value={ newLayoutName }
						onChange={ setNewLayoutName }
					/>
					<div className="newspack-newsletters__modal-buttons">
						<Button variant="primary" disabled={ newLayoutName.length === 0 } isBusy={ isSavingLayout } onClick={ handleSaveAsLayout }>
							{ __( 'Save', 'newspack-newsletters' ) }
						</Button>
						<Button variant="tertiary" onClick={ () => setIsManageModalVisible( null ) }>
							{ __( 'Cancel', 'newspack-newsletters' ) }
						</Button>
					</div>
				</Modal>
			) }

			{ warningModalVisible && (
				<Modal
					className="newspack-newsletters__modal"
					title={ __( 'Reset newsletter layout?', 'newspack-newsletters' ) }
					onRequestClose={ () => setWarningModalVisible( false ) }
					size="small"
				>
					<p>
						{ __(
							'Resetting the layout will remove all customizations and edits you’ve made. This action cannot be undone.',
							'newspack-newsletters'
						) }
					</p>
					<div className="newspack-newsletters__modal-buttons">
						<Button
							variant="primary"
							isDestructive
							onClick={ () => {
								editPost( { content: '', meta: { template_id: -1 } } );
								setWarningModalVisible( false );
							} }
						>
							{ __( 'Reset layout', 'newspack-newsletters' ) }
						</Button>
						<Button variant="tertiary" onClick={ () => setWarningModalVisible( false ) }>
							{ __( 'Cancel', 'newspack-newsletters' ) }
						</Button>
					</div>
				</Modal>
			) }
		</BaseControl>
	);
} );
