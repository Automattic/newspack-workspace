import { useBlockProps } from '@wordpress/block-editor';
import { RawHTML } from '@wordpress/element';
import { useEntityProp } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Generates the credit text for the media credit and organization.
 *
 * @param mediaCredit  The media credit.
 * @param organization The organization associated with the media credit. Optional.
 *
 * @return The formatted credit text.
 */
const generateCreditText = ( mediaCredit: string, organization?: string ) => {
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

export const Edit = ( { context: { postType, postId } }: { context: { postType: string; postId: number } } ) => {
	const blockProps = useBlockProps();

	const [ featuredImage ] = useEntityProp( 'postType', postType, 'featured_media', postId );

	const { caption, credit } = useSelect(
		( select ): { caption?: string; credit?: string } => {
			if ( ! featuredImage ) {
				return {};
			}
			const media = (
				select( 'core' ) as {
					getMedia: ( id: number ) =>
						| {
								caption?: { raw?: string };
								meta?: { _media_credit?: string; _navis_media_credit_org?: string };
						  }
						| undefined;
				}
			 ).getMedia( featuredImage );
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
