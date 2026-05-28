/**
 * External dependencies
 */
import { addFilter } from '@wordpress/hooks';
import { useSelect } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { createHigherOrderComponent } from '@wordpress/compose';

import * as ImageBlockTypes from './types';

const currentUrl = window.location.href;

/**
 * Add image credit meta to core/image block attributes
 */
addFilter( 'blocks.registerBlockType', 'newspack-plugin/register-hook/core-image', ( settings, name ) => {
	if ( name !== 'core/image' ) {
		return settings;
	}
	return {
		...settings,
		attributes: {
			...settings.attributes,
			meta: {
				type: 'object',
				default: {},
			},
		},
	};
} );

/**
 * Display spinner and lock post saving until meta attributes are added to block
 */
const AttributesLoader = ( { setAttributes, attributes }: ImageBlockTypes.AttributeProps ) => {
	const imageId = attributes.id;
	const { meta }: { meta: ImageBlockTypes.AttributesMeta } = useSelect(
		// @ts-expect-error Not sure why this is throwing an error.
		select => select( 'core' ).getMedia( imageId ),
		[ imageId ]
	) ?? {
		meta: {},
	};
	useEffect( () => {
		// Meta added, proceed
		if ( Object.keys( meta ).length ) {
			const { _media_credit, _media_credit_url, _navis_media_credit_org } = meta;
			// Only trigger if our meta has updated.
			if (
				_media_credit !== attributes?.meta?._media_credit ||
				_media_credit_url !== attributes?.meta?._media_credit_url ||
				_navis_media_credit_org !== attributes?.meta?._navis_media_credit_org
			) {
				setAttributes( { meta: { _media_credit, _media_credit_url, _navis_media_credit_org } } );
			}
		}
	}, [ meta ] );

	return <></>;
};

/**
 * Compare two urls strings and determine if they're from the same origin.
 */
const isSameOrigin = ( urlOne: string, urlTwo = currentUrl ) => {
	const hostOne = new URL( urlOne ).hostname;
	const hostTwo = new URL( urlTwo ).hostname;
	return hostOne === hostTwo;
};

/**
 * Populate attributes with meta data.
 */
addFilter(
	'editor.BlockEdit',
	'newspack-plugin/block-edit-hook/core-image',
	createHigherOrderComponent( BlockEdit => {
		const blockEditComponent = ( props: ImageBlockTypes.BaseProps< ImageBlockTypes.Attributes > ) => {
			if ( props.name === 'core/image' ) {
				return (
					<>
						<BlockEdit { ...props } />
						{ props.attributes.url && isSameOrigin( props.attributes.url ) && <AttributesLoader { ...props } /> }
					</>
				);
			}
			return <BlockEdit { ...props } />;
		};
		return blockEditComponent;
	}, 'withCustomMetaData' )
);
