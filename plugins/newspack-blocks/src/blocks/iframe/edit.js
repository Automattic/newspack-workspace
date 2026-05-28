/**
 * Newspack dependencies
 */
import { iframe as icon } from 'newspack-icons';

/**
 * WordPress dependencies
 */
import { Fragment, useState } from '@wordpress/element';
import { InspectorControls, BlockControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	ToggleControl,
	Toolbar,
	FocusableIframe,
	Notice,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalUnitControl as UnitControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { seen } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import IframePlaceholder from './iframe-placeholder';

const IframeEdit = ( { attributes, setAttributes } ) => {
	const blockProps = useBlockProps();
	const label = __( 'Iframe', 'block title' );
	const { mode, src, archiveFolder, isFullScreen, height, width } = attributes;
	const [ showPreview, setShowPreview ] = useState( true );
	const [ isUploadingArchive, setIsUploadingArchive ] = useState();
	const [ error, setError ] = useState();

	const sizeUnits = [
		{ value: 'px', label: 'px' },
		{ value: '%', label: '%' },
		{ value: 'em', label: 'em' },
	];

	const embedURL = async url => {
		setError( null );
		setIsUploadingArchive( true );

		try {
			const formData = new FormData();
			formData.append( 'iframe_url', url );

			const { mode: iframeMode } = await apiFetch( {
				path: '/newspack-blocks/v1/newspack-blocks-iframe-mode-from-url',
				method: 'POST',
				body: formData,
			} );

			setAttributes( {
				mode: iframeMode,
				src: url,
			} );
			setShowPreview( true );

			// remove current archive folder if exists.
			if ( archiveFolder ) {
				deleteIframeArchive();
			}
		} catch ( e ) {
			setError( e.message || __( 'An error occured when uploading the iframe archive.', 'newspack-blocks' ) );
		}

		setIsUploadingArchive( false );
	};

	const uploadIframeArchive = async archiveFile => {
		setError( null );
		setIsUploadingArchive( true );

		try {
			const formData = new FormData();
			formData.append( 'archive_folder', archiveFolder );
			formData.append( 'iframe_file', archiveFile );

			const {
				src: iframeArchiveSrc,
				dir: iframeArchiveFolder,
				mode: iframeMode,
			} = await apiFetch( {
				path: '/newspack-blocks/v1/newspack-blocks-iframe-archive',
				method: 'POST',
				body: formData,
			} );

			setAttributes( {
				mode: iframeMode,
				src: iframeArchiveSrc,
				archiveFolder: iframeArchiveFolder,
			} );

			setShowPreview( true );
		} catch ( e ) {
			setError( e.message || __( 'An error occured when uploading the iframe archive.', 'newspack-blocks' ) );
		}

		setIsUploadingArchive( false );
	};

	const setIframeArchiveFromMedia = async mediaId => {
		setError( null );
		setIsUploadingArchive( true );

		try {
			const formData = new FormData();
			formData.append( 'media_id', mediaId );

			const {
				src: iframeArchiveSrc,
				dir: iframeArchiveFolder,
				mode: iframeMode,
			} = await apiFetch( {
				path: '/newspack-blocks/v1/newspack-blocks-iframe-archive-from-media',
				method: 'POST',
				body: formData,
			} );

			setAttributes( {
				mode: iframeMode,
				src: iframeArchiveSrc,
				archiveFolder: iframeArchiveFolder,
			} );
			setShowPreview( true );
		} catch ( e ) {
			setError( e.message || __( 'An error occured when setting the iframe from the archive media.', 'newspack-blocks' ) );
		}

		setIsUploadingArchive( false );
	};

	const deleteIframeArchive = () => {
		// Do not remove archive if it's used (if a user clicks on embed after uploading the archive.)
		if ( src && archiveFolder && ! src.includes( archiveFolder ) ) {
			apiFetch( {
				path: '/newspack-blocks/v1/newspack-blocks-remove-iframe-archive',
				method: 'DELETE',
				body: JSON.stringify( { archive_folder: archiveFolder } ),
			} );
		}
	};

	const iframeControls = [
		{
			icon: seen,
			title: showPreview ? __( 'Hide iframe preview', 'newspack-blocks' ) : __( 'Show iframe preview', 'newspack-blocks' ),
			onClick: () => setShowPreview( ! showPreview ),
			isActive: showPreview,
		},
	];

	return (
		<Fragment>
			<BlockControls>
				<Toolbar controls={ src && iframeControls } />
			</BlockControls>

			<InspectorControls>
				<PanelBody title={ __( 'Iframe Settings', 'newspack-blocks' ) } initialOpen={ true }>
					<Fragment>
						<ToggleControl
							label={ __( 'Fullscreen', 'newspack-blocks' ) }
							help={ __( 'If enabled, the iframe will be full screen and hide all the post content.', 'newspack-blocks' ) }
							checked={ isFullScreen }
							onChange={ _isFullScreen => setAttributes( { isFullScreen: _isFullScreen } ) }
							required
						/>

						{ ! isFullScreen && (
							<div className="wp-block-newspack-blocks-iframe__unit-control">
								<UnitControl
									label={ __( 'Width', 'newspack-blocks' ) }
									onChange={ _width => setAttributes( { width: _width } ) }
									value={ width }
									units={ sizeUnits }
								/>
								<UnitControl
									label={ __( 'Height', 'newspack-blocks' ) }
									onChange={ _height => setAttributes( { height: _height } ) }
									value={ height }
									units={ sizeUnits }
								/>
							</div>
						) }
					</Fragment>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				{ isFullScreen && (
					<Notice status="warning" className="wp-block-newspack-blocks-iframe-notice" isDismissible={ false }>
						{ __( 'This block will take over the page content.', 'newspack-blocks' ) }
					</Notice>
				) }
				{ src && showPreview ? (
					<div className="iframe-container">
						<FocusableIframe
							title={ __( 'Newspack embedded iframe', 'newspack-blocks' ) }
							src={ 'document' === mode ? `https://docs.google.com/gview?embedded=true&url=${ encodeURIComponent( src ) }` : src }
							style={ {
								width: isFullScreen ? '100vw' : width,
								height: isFullScreen ? '100vh' : height,
								maxWidth: '100%',
								maxHeight: '100%',
								pointerEvents: 'none',
							} }
						/>
					</div>
				) : (
					<IframePlaceholder
						icon={ icon }
						label={ label }
						src={ src }
						onSelectURL={ embedURL }
						onSelectMedia={ setIframeArchiveFromMedia }
						isUploadingArchive={ isUploadingArchive }
						archiveFolder={ archiveFolder }
						uploadIframeArchive={ uploadIframeArchive }
						error={ error }
					/>
				) }
			</div>
		</Fragment>
	);
};

export default IframeEdit;
