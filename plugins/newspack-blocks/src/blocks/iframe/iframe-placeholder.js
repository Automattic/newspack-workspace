/* global newspack_blocks_data */

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Placeholder, FormFileUpload, Spinner, Notice } from '@wordpress/components';
import { BlockIcon, URLPopover, MediaUpload, MediaUploadCheck } from '@wordpress/block-editor';
import { keyboardReturn } from '@wordpress/icons';

/**
 * External dependencies
 */
import classnames from 'classnames';

const allowedTypes = newspack_blocks_data?.iframe_accepted_file_mimes || [];

const InsertFromURLPopover = ( { src, onChange, onSubmit, onClose } ) => (
	<URLPopover onClose={ onClose }>
		<form className="wp-block-newspack-blocks-iframe__url-input-form" onSubmit={ onSubmit }>
			<input
				className="wp-block-newspack-blocks-iframe__url-input-form__url-input-field"
				type="url"
				aria-label={ __( 'URL' ) }
				placeholder={ __( 'Paste or type URL' ) }
				onChange={ onChange }
				value={ src }
			/>
			<Button
				className="wp-block-newspack-blocks-iframe__url-input-form__url-input-submit-button"
				icon={ keyboardReturn }
				label={ __( 'Apply' ) }
				type="submit"
			/>
		</form>
	</URLPopover>
);

const IframePlaceholder = ( { icon, label, src, onSelectURL, onSelectMedia, isUploadingArchive, archiveFolder, uploadIframeArchive, error } ) => {
	const [ isURLInputVisible, setIsURLInputVisible ] = useState( false );
	const [ urlFieldValue, setUrlFieldValue ] = useState( src );

	// URL text field
	const onChangeUrlField = event => {
		setUrlFieldValue( event.target.value );
	};

	const openURLInput = () => {
		setIsURLInputVisible( true );
	};
	const closeURLInput = () => {
		setIsURLInputVisible( false );
	};

	const onSubmitSrc = event => {
		event.preventDefault();
		if ( urlFieldValue && onSelectURL ) {
			onSelectURL( urlFieldValue );
			closeURLInput();
		}
	};

	const onSelectImage = media => {
		onSelectMedia( media.id );
	};

	// Uploader
	const onUpload = event => {
		const files = event.target.files;
		if ( 0 < files.length ) {
			uploadIframeArchive( files.item( 0 ) );
		}
	};

	const renderMediaLibraryButton = onSelect => {
		return (
			<MediaUploadCheck>
				<MediaUpload
					onSelect={ onSelect }
					allowedTypes={ Object.keys( allowedTypes ) }
					render={ ( { open } ) => {
						return (
							<Button variant="tertiary" onClick={ open }>
								{ __( 'Media Library', 'newspack-blocks' ) }
							</Button>
						);
					} }
				/>
			</MediaUploadCheck>
		);
	};

	const renderUrlSelectionUI = isUpdate => {
		return (
			onSelectURL && (
				<div className="wp-block-newspack-blocks-iframe__url-input-container">
					<Button isTertiary onClick={ openURLInput } isPressed={ isURLInputVisible } variant="tertiary">
						{ isUpdate ? __( 'Update from URL', 'newspack-blocks' ) : __( 'Embed from URL', 'newspack-blocks' ) }
					</Button>
					{ isURLInputVisible && (
						<InsertFromURLPopover
							src={ urlFieldValue }
							onChange={ onChangeUrlField }
							onSubmit={ onSubmitSrc }
							onClose={ closeURLInput }
						/>
					) }
				</div>
			)
		);
	};

	return (
		<Placeholder
			icon={ <BlockIcon icon={ icon } showColors /> }
			label={ label }
			className="wp-block-newspack-blocks-iframe"
			instructions={ sprintf(
				// Translators: %s: describes what kinds of files/embeds the current user can use.
				__( 'Upload a document file (PDF, Word, Excel sheet, or a PPT), choose one from the media library, %s.', 'newspack-blocks' ),
				newspack_blocks_data?.iframe_can_upload_archives || false
					? __( 'embed from a URL, or upload a .zip archive containing HTML assets', 'newspack-blocks' )
					: __( 'or embed from a URL', 'newspack-blocks' )
			) }
		>
			{ error && (
				<Notice status="error" className="wp-block-newspack-blocks-iframe-notice" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<div>
				{ isUploadingArchive ? (
					<Spinner />
				) : (
					<FormFileUpload
						accept={ Object.keys( allowedTypes )
							.map( mime => '.' + allowedTypes[ mime ] )
							.join( ',' ) }
						onChange={ onUpload }
						multiple={ false }
						render={ ( { openFileDialog } ) => (
							<>
								<Button
									isPrimary
									className={ classnames(
										'wp-block-newspack-blocks-iframe__button',
										'wp-block-newspack-blocks-iframe__upload-button'
									) }
									onClick={ openFileDialog }
								>
									{ archiveFolder ? __( 'Update', 'newspack-blocks' ) : __( 'Upload', 'newspack-blocks' ) }
								</Button>
								{ renderMediaLibraryButton( onSelectImage ) }
								{ renderUrlSelectionUI( '' !== archiveFolder ) }
							</>
						) }
					/>
				) }
			</div>
		</Placeholder>
	);
};

export default IframePlaceholder;
