'use strict';

import { addFilter } from '@wordpress/hooks';
import { RadioControl, TextControl } from '@wordpress/components';
import { withDispatch, withSelect, select } from '@wordpress/data';
import { Component, Fragment } from '@wordpress/element';
import { compose } from '@wordpress/compose';
import { store as coreStore } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';

class RadioCustom extends Component {
	render() {
		const { meta, updateFeaturedImagePosition } = this.props;

		return (
			<RadioControl
				label={ __( 'Featured Image Position' ) }
				selected={ meta.newspack_featured_image_position }
				options={ [
					{
						label: __( 'Default (set in Customizer)', 'newspack-theme' ),
						value: '',
					},
					{ label: __( 'Large', 'newspack-theme' ), value: 'large' },
					{ label: __( 'Small', 'newspack-theme' ), value: 'small' },
					{
						label: __( 'Behind article title', 'newspack-theme' ),
						value: 'behind',
					},
					{
						label: __( 'Beside article title', 'newspack-theme' ),
						value: 'beside',
					},
					{
						label: __( 'Above article title', 'newspack-theme' ),
						value: 'above',
					},
					{ label: __( 'Hidden', 'newspack-theme' ), value: 'hidden' },
				] }
				onChange={ value => {
					this.setState( { value } );
					updateFeaturedImagePosition( value, meta );
				} }
			/>
		);
	}
}

const ComposedRadio = compose( [
	withSelect( _select => {
		const { getCurrentPostAttribute, getEditedPostAttribute } = _select( 'core/editor' );
		return {
			meta: {
				...getCurrentPostAttribute( 'meta' ),
				...getEditedPostAttribute( 'meta' ),
			},
		};
	} ),
	withDispatch( dispatch => ( {
		updateFeaturedImagePosition( value, meta ) {
			meta = {
				...meta,
				newspack_featured_image_position: value,
			};
			dispatch( 'core/editor' ).editPost( { meta } );
		},
	} ) ),
] )( RadioCustom );

const CaptionControl = compose( [
	withSelect( _select => {
		const { getCurrentPostAttribute, getEditedPostAttribute } = _select( 'core/editor' );

		// featured_media updates live when the user swaps the featured image.
		const featuredMediaId = getEditedPostAttribute( 'featured_media' );

		// WP 6.9+: getMedia is deprecated; use the attachment entity instead.
		// Edit context returns caption.raw (the unfiltered post_excerpt);
		// the record resolves async and re-renders this component when the fetch lands.
		const media = featuredMediaId ? _select( coreStore ).getEntityRecord( 'postType', 'attachment', featuredMediaId, { context: 'edit' } ) : null;

		return {
			featuredMediaId,
			meta: {
				...getCurrentPostAttribute( 'meta' ),
				...getEditedPostAttribute( 'meta' ),
			},
			defaultCaption: media?.caption?.raw ?? '',
		};
	} ),
	withDispatch( dispatch => ( {
		updateCaption( value, meta ) {
			dispatch( 'core/editor' ).editPost( {
				meta: { ...meta, newspack_featured_image_caption: value },
			} );
		},
	} ) ),
] )( ( { featuredMediaId, meta, defaultCaption, updateCaption } ) => {
	// Only show the caption field once a featured image has been selected.
	if ( ! featuredMediaId ) {
		return null;
	}

	const value = meta.newspack_featured_image_caption || '';
	const hasOverride = value.trim().length > 0;

	return (
		<TextControl
			label={ __( 'Featured Image Caption', 'newspack-theme' ) }
			value={ value }
			placeholder={
				defaultCaption || __( 'No default caption set for this image; the image credit (if available) will be displayed.', 'newspack-theme' )
			}
			onChange={ v => updateCaption( v, meta ) }
			help={
				hasOverride
					? __( "This caption applies to this article only. Other articles keep the image's default caption.", 'newspack-theme' )
					: __( "Leave blank to use the image's default caption (shown above) or credit (if available).", 'newspack-theme' )
			}
			__nextHasNoMarginBottom
		/>
	);
} );

const wrapPostFeaturedImage = OriginalComponent => {
	// eslint-disable-next-line react/display-name
	return props => {
		const post_type = select( 'core/editor' ).getCurrentPostType();

		// eslint-disable-next-line no-undef
		if ( ! newspack_theme_featured_image_post_types.includes( post_type ) ) {
			return <OriginalComponent { ...props } />;
		}

		return (
			<Fragment>
				<OriginalComponent { ...props } />
				<ComposedRadio />
				<CaptionControl />
			</Fragment>
		);
	};
};

addFilter( 'editor.PostFeaturedImage', 'enhance-featured-image/featured-image-position-control', wrapPostFeaturedImage );
