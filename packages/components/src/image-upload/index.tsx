/**
 * Image Upload
 */

/**
 * WordPress dependencies.
 */
import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { BaseControl } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { Button } from '../';
import './style.scss';

/**
 * External dependencies.
 */
import classnames from 'classnames';
import type { CSSProperties, ReactNode } from 'react';

/**
 * An attachment as serialized by the WP media modal.
 */
export type ImageAttachment = {
	id?: number;
	url?: string;
	[ key: string ]: unknown;
};

type MediaFrame = {
	open: () => void;
	on: ( event: string, handler: () => void ) => void;
	state: () => {
		get: ( key: string ) => {
			first: () => {
				toJSON: () => ImageAttachment;
			};
		};
	};
};

declare global {
	// The classic-editor media library global, loaded via the 'media' script dependency.
	const wp: {
		media: ( options: {
			title: string;
			button: { text: string };
			library: { type: string };
			multiple: boolean;
		} ) => MediaFrame;
	};
}

type ImageUploadProps = {
	buttonLabel?: ReactNode;
	className?: string;
	disabled?: boolean;
	help?: ReactNode;
	image?: ImageAttachment | null;
	isCovering?: boolean;
	label?: ReactNode;
	onChange: ( image: ImageAttachment | null ) => void;
	style?: CSSProperties;
};

type ImageUploadState = {
	frame: MediaFrame | false;
};

class ImageUpload extends Component< ImageUploadProps, ImageUploadState > {
	/**
	 * Constructor.
	 */
	constructor( props: ImageUploadProps ) {
		super( props );
		this.state = {
			frame: false,
		};
	}

	/**
	 * Open the WP media modal.
	 */
	openModal = () => {
		if ( this.state.frame ) {
			this.state.frame.open();
			return;
		}

		const frame = wp.media( {
			title: __( 'Select or upload image' ),
			button: {
				text: __( 'Select' ),
			},
			library: {
				type: 'image',
			},
			multiple: false,
		} );
		this.setState( { frame }, () => {
			frame.on( 'select', this.handleImageSelect );
			frame.open();
		} );
	};

	/**
	 * Update the state when an image is selected from the media modal.
	 */
	handleImageSelect = () => {
		const { onChange } = this.props;
		const { frame } = this.state;
		if ( ! frame ) {
			return;
		}
		const attachment = frame.state().get( 'selection' ).first().toJSON();
		onChange( attachment );
	};

	/**
	 * Render.
	 */
	render = () => {
		const { buttonLabel, className, disabled, help, image, isCovering, label, onChange, style = {} } = this.props;
		const classes = classnames(
			'newspack-image-upload__image',
			{ 'newspack-image-upload__image--has-image': image },
			{ 'newspack-image-upload__image--covering': isCovering }
		);
		return (
			<BaseControl __nextHasNoMarginBottom className={ classnames( 'newspack-image-upload', className ) } help={ help }>
				{ label && <BaseControl.VisualLabel>{ label }</BaseControl.VisualLabel> }
				<div className={ classes } style={ style }>
					{ image?.url ? (
						<>
							<img data-testid="image-upload" src={ image.url } alt={ __( 'Image preview', 'newspack-plugin' ) } />
							<div className="newspack-image-upload__controls">
								<Button disabled={ disabled } onClick={ this.openModal } variant="tertiary">
									{ __( 'Replace', 'newspack-plugin' ) }
								</Button>
								<Button disabled={ disabled } onClick={ () => onChange( null ) } variant="tertiary" isDestructive>
									{ __( 'Remove', 'newspack-plugin' ) }
								</Button>
							</div>
						</>
					) : (
						<Button disabled={ disabled } onClick={ this.openModal } variant="tertiary">
							{ buttonLabel ? buttonLabel : __( 'Upload', 'newspack-plugin' ) }
						</Button>
					) }
				</div>
			</BaseControl>
		);
	};
}
export default ImageUpload;
