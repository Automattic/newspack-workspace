/**
 * External dependencies
 */
import clsx from 'clsx';
import type { CSSProperties } from 'react';

/**
 * WordPress dependencies
 */
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { __experimentalUseBorderProps as useBorderProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { addQueryArgs, removeQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import type { AvatarData } from './hooks';
import type { AvatarAttributes } from './utils';

/**
 * Props for the AvatarWrapper component.
 */
type AvatarWrapperProps = {
	/** Avatar data with src and alt. */
	avatar?: AvatarData;
	/** Avatar size in pixels. */
	size: number;
	/** Block attributes. */
	attributes: AvatarAttributes;
	/** Whether to render a placeholder instead of an image. */
	placeholder?: boolean;
	/** Style object with --overlap-mask CSS custom property. */
	overlapMaskStyle?: Record< string, string >;
};

/**
 * Render a single avatar image with optional link, placeholder, and overlap mask.
 *
 * @param props                  Component props.
 * @param props.avatar           Avatar data with src and alt.
 * @param props.size             Avatar size in pixels.
 * @param props.attributes       Block attributes.
 * @param props.placeholder      Whether to render a placeholder instead of an image.
 * @param props.overlapMaskStyle Style object with --overlap-mask CSS custom property.
 * @return The avatar wrapper element.
 */
const AvatarWrapper = ( { avatar, size, attributes, placeholder = false, overlapMaskStyle = {} }: AvatarWrapperProps ) => {
	const borderProps = useBorderProps( attributes );

	// Debounce the size used for image fetching so dragging the slider
	// doesn't fire a network request on every pixel change.
	const [ imageFetchSize, setImageFetchSize ] = useState( attributes?.size ?? 48 );
	useEffect( () => {
		const timer = setTimeout( () => setImageFetchSize( attributes?.size ?? 48 ), 500 );
		return () => clearTimeout( timer );
	}, [ attributes?.size ] );

	// Render placeholder for text-only bylines.
	if ( placeholder ) {
		return (
			<div
				className="newspack-avatar-wrapper newspack-avatar-wrapper--placeholder"
				style={
					{
						'--avatar-size': size + 'px',
						...borderProps.style,
					} as CSSProperties
				}
				role="img"
				aria-label={ __( 'No avatar available', 'newspack-plugin' ) }
			>
				<svg
					fill="none"
					xmlns="http://www.w3.org/2000/svg"
					viewBox="0 0 60 60"
					preserveAspectRatio="none"
					aria-hidden="true"
					focusable="false"
				>
					<path vectorEffect="non-scaling-stroke" d="M60 60 0 0" />
				</svg>
			</div>
		);
	}

	const avatarSrc = avatar?.src;
	if ( ! avatarSrc ) {
		return null;
	}

	const doubledSizedSrc = addQueryArgs( removeQueryArgs( avatarSrc, 's' ), {
		s: imageFetchSize * 2,
	} );
	const avatarImage = (
		<img
			src={ doubledSizedSrc }
			alt={ avatar?.alt || '' }
			className={ clsx( 'avatar', 'avatar-' + size, 'photo', 'wp-block-newspack-avatar__image', borderProps.className ) }
			style={ {
				width: size,
				height: size,
				...borderProps.style,
			} }
		/>
	);
	return (
		<div
			className="newspack-avatar-wrapper"
			style={
				{
					'--avatar-size': size + 'px',
					...overlapMaskStyle,
				} as CSSProperties
			}
		>
			{ attributes.linkToAuthorArchive ? (
				<a href="#avatar-pseudo-link" className="wp-block-newspack-avatar__link" onClick={ event => event.preventDefault() }>
					{ avatarImage }
				</a>
			) : (
				avatarImage
			) }
		</div>
	);
};

export default AvatarWrapper;
