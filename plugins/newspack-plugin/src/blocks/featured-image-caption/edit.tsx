import { useBlockProps } from '@wordpress/block-editor';
import { RawHTML } from '@wordpress/element';
import { useEntityProp } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Generates the credit text for the media credit and organization.
 *
 * @param {string} mediaCredit  The media credit.
 * @param {string} organization The organization associated with the media credit. Optional.
 *
 * @return {string} The formatted credit text.
 */
const generateCreditText = ( mediaCredit, organization ) => {
	if ( mediaCredit && organization ) {
		return sprintf(
			/* translators: 1: media credit, 2: organization */
			__( 'Credit: %1$s / %2$s', 'newspack-plugin' ),
			mediaCredit,
			organization
		);
	}

	return sprintf(
		/* translators: %s: media credit */
		__( 'Credit: %s', 'newspack-plugin' ),
		mediaCredit
	);
};

export const Edit = ( { context: { postType, postId } } ) => {
	const blockProps = useBlockProps();

	const [ featuredImage ] = useEntityProp( 'postType', postType, 'featured_media', postId );

	const { caption, credit } = useSelect(
		select => {
			if ( ! featuredImage ) {
				return {};
			}
			const media = select( 'core' ).getMedia( featuredImage );
			if ( ! media ) {
				return {};
			}
			return {
				caption: media.caption?.raw || '',
				credit: media.meta?._media_credit ? generateCreditText( media.meta._media_credit, media.meta?._navis_media_credit_org ) : '',
			};
		},
		[ featuredImage ]
	);

	const displayText = [ caption, credit ].filter( Boolean ).join( ' ' );

	if ( ! featuredImage ) {
		return (
			<figcaption { ...blockProps }>
				<span className="featured-image-caption-placeholder">{ __( 'Featured image caption.', 'newspack-plugin' ) }</span>
			</figcaption>
		);
	}

	if ( ! displayText ) {
		return (
			<figcaption { ...blockProps }>
				<span className="featured-image-caption-placeholder">{ __( 'No caption or credit available.', 'newspack-plugin' ) }</span>
			</figcaption>
		);
	}

	return (
		<figcaption { ...blockProps }>
			<RawHTML>{ displayText }</RawHTML>
		</figcaption>
	);
};
