/**
 * External dependencies
 */
import { find } from 'lodash';
import { stringify } from 'qs';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { RichText, useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextareaControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { SHARE_BLOCK_NOTICE_ID } from './consts';
import './style.scss';

export default function ShareBlockEdit( { attributes, setAttributes } ) {
	const { content, shareMessage } = attributes;
	const blockProps = useBlockProps( { className: 'newspack-newsletters-share-block' } );

	const { is_public, permalink, postTitle, hasNotice } = useSelect( select => {
		const { getEditedPostAttribute, getPermalink } = select( 'core/editor' );
		const meta = getEditedPostAttribute( 'meta' ) || {};
		return {
			is_public: meta.is_public,
			permalink: getPermalink() || '',
			postTitle: getEditedPostAttribute( 'title' ) || '',
			hasNotice: Boolean( find( select( 'core/notices' ).getNotices(), [ 'id', SHARE_BLOCK_NOTICE_ID ] ) ),
		};
	}, [] );

	const { createWarningNotice, removeNotice } = useDispatch( 'core/notices' );
	const { editPost } = useDispatch( 'core/editor' );

	useEffect( () => {
		if ( is_public ) {
			removeNotice( SHARE_BLOCK_NOTICE_ID );
		} else if ( ! hasNotice ) {
			createWarningNotice(
				__( 'This post is not public - the share block will not be displayed, since there is no post to link to.', 'newspack-newsletters' ),
				{
					id: SHARE_BLOCK_NOTICE_ID,
					isDismissible: false,
					actions: [
						{
							label: __( 'Make public', 'newspack-newsletters' ),
							onClick: () => editPost( { meta: { is_public: true } } ),
						},
					],
				}
			);
		}
		return () => removeNotice( SHARE_BLOCK_NOTICE_ID );
	}, [ is_public ] );

	useEffect( () => {
		const href = `mailto:?${ stringify( {
			body: shareMessage.replace( '[LINK]', permalink ),
			subject: 'Fwd: ' + postTitle,
		} ) }`;
		setAttributes( { href } );
	}, [ shareMessage, permalink, postTitle ] );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Share settings', 'newspack-newsletters' ) }>
					<TextareaControl
						label={ __( 'Forwarded email content', 'newspack-newsletters' ) }
						help={ __(
							'Content of the email that will be pre-filled when a reader clicks this link in their email client. Use the "[LINK]" placeholder where the link to the public post should be placed.',
							'newspack-newsletters'
						) }
						value={ shareMessage }
						onChange={ value => setAttributes( { shareMessage: value } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<RichText
				identifier="content"
				tagName="a"
				{ ...blockProps }
				value={ content }
				allowedFormats={ [ 'core/bold', 'core/italic', 'core/text-color' ] }
				onChange={ newContent => setAttributes( { content: newContent } ) }
				aria-label={ __( 'Share block', 'newspack-newsletters' ) }
				data-empty={ content ? false : true }
			/>
		</>
	);
}
