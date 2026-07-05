/**
 * WordPress dependencies
 */
import { useContext } from '@wordpress/element';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ExternalLink } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { getSharedAuthorContext } from '../../shared/author-context';
import { roundIconSize } from '../author-profile-social/utils';
import { getSocialIconSvg } from './social-icons';
import { getServiceUrl, getServiceData, getServiceLabel } from './utils';

/**
 * Attributes of the Author Social Link block.
 */
type AuthorSocialLinkAttributes = {
	service: string;
};

/**
 * Props for the Author Social Link edit component.
 */
type AuthorSocialLinkEditProps = {
	/** Block attributes. */
	attributes: AuthorSocialLinkAttributes;
	/** Block context. */
	context: {
		'newspack-blocks/iconSize'?: number;
	};
};

/**
 * Edit component for a single Author Social Link block.
 *
 * @param props            Block props.
 * @param props.attributes Block attributes.
 * @param props.context    Block context.
 * @return The edit component.
 */
export default function AuthorSocialLinkEdit( { attributes, context }: AuthorSocialLinkEditProps ) {
	const AuthorContext = getSharedAuthorContext();
	const author: NewspackAuthorProfileData | null = useContext( AuthorContext );
	const { service } = attributes;
	const iconSize = context?.[ 'newspack-blocks/iconSize' ] ?? 24;

	const blockProps = useBlockProps( {
		className: 'wp-block-newspack-author-social-link',
		'data-service': service,
	} );

	const url = getServiceUrl( author, service );
	if ( ! url ) {
		return null;
	}

	const serviceData = getServiceData( author, service );
	const svg = getSocialIconSvg( service, serviceData );
	const serviceLabel = service ? getServiceLabel( service ) : service;

	return (
		<>
			<InspectorControls>
				<PanelBody title={ serviceLabel }>
					<p style={ { wordBreak: 'break-all', fontSize: '12px', color: '#757575' } }>
						<ExternalLink href={ url }>{ url }</ExternalLink>
					</p>
				</PanelBody>
			</InspectorControls>
			<li { ...blockProps }>
				<a href={ url } aria-label={ serviceLabel } onClick={ e => e.preventDefault() }>
					{ svg ? (
						<span
							dangerouslySetInnerHTML={ { __html: svg } }
							style={ { width: roundIconSize( iconSize ), height: roundIconSize( iconSize ) } }
							aria-hidden="true"
						/>
					) : (
						<span className="service-name">{ service }</span>
					) }
				</a>
			</li>
		</>
	);
}
