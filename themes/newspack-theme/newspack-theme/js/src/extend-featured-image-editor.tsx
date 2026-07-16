'use strict';

import { addFilter } from '@wordpress/hooks';
import { RadioControl } from '@wordpress/components';
import { withDispatch, withSelect, select } from '@wordpress/data';
import { Component, ComponentType, Fragment } from '@wordpress/element';
import { compose } from '@wordpress/compose';
import { __ } from '@wordpress/i18n';

interface PostMeta {
	newspack_featured_image_position?: string;
	[ key: string ]: unknown;
}

interface RadioCustomProps {
	meta: PostMeta;
	updateFeaturedImagePosition: ( value: string, meta: PostMeta ) => void;
}

interface RadioCustomState {
	value?: string;
}

class RadioCustom extends Component< RadioCustomProps, RadioCustomState > {
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
					// This sets `value` in component state, but nothing ever reads `this.state.value` -- pre-existing dead state, not fixed here.
					this.setState( { value } );
					updateFeaturedImagePosition( value, meta );
				} }
			/>
		);
	}
}

const ComposedRadio = compose(
	withSelect( _select => {
		// The editor selectors are untyped for string-keyed stores; assert at the store boundary.
		const { getCurrentPostAttribute, getEditedPostAttribute } = _select( 'core/editor' ) as {
			getCurrentPostAttribute: ( attribute: string ) => PostMeta | undefined;
			getEditedPostAttribute: ( attribute: string ) => PostMeta | undefined;
		};
		return {
			meta: {
				...getCurrentPostAttribute( 'meta' ),
				...getEditedPostAttribute( 'meta' ),
			},
		};
	} ),
	withDispatch(
		dispatch =>
			( {
				updateFeaturedImagePosition( value: string, meta: PostMeta ) {
					meta = {
						...meta,
						newspack_featured_image_position: value,
					};
					( dispatch( 'core/editor' ) as { editPost: ( edits: Record< string, unknown > ) => void } ).editPost( { meta } );
				},
			} ) as Record< string, ( ...args: unknown[] ) => unknown >
	)
)( RadioCustom ) as ComponentType;

const wrapPostFeaturedImage = ( OriginalComponent: ComponentType ) => {
	// eslint-disable-next-line react/display-name
	return ( props: Record< string, unknown > ) => {
		const post_type = ( select( 'core/editor' ) as Record< string, ( ...args: unknown[] ) => unknown > ).getCurrentPostType() as string;

		// eslint-disable-next-line no-undef
		if ( ! newspack_theme_featured_image_post_types.includes( post_type ) ) {
			return <OriginalComponent { ...props } />;
		}

		return (
			<Fragment>
				<OriginalComponent { ...props } />
				<ComposedRadio />
			</Fragment>
		);
	};
};

addFilter( 'editor.PostFeaturedImage', 'enhance-featured-image/featured-image-position-control', wrapPostFeaturedImage );
