/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { ExternalLink, Guide } from '@wordpress/components';

/**
 * Internal dependencies
 */
import './guide.scss';

/**
 * An integration's how-to (see Integration::get_guide()), one step per page.
 *
 * The Guide labels its dialog with `contentLabel` and shows no title of its
 * own, so each page's heading is the dialog's first: an `h1`, as in core's
 * guides.
 *
 * @param {Object}   props             Component props.
 * @param {Object}   props.integration The integration whose `guide` to show.
 * @param {Function} props.onClose     Called when the guide is finished or dismissed.
 */
export const IntegrationGuide = ( { integration, onClose } ) => (
	<Guide
		className="newspack-integration-guide"
		contentLabel={ __( 'How it works', 'newspack-plugin' ) }
		onFinish={ onClose }
		pages={ integration.guide.map( step => ( {
			content: (
				<div className="newspack-integration-guide__page">
					<h1>{ step.title }</h1>
					<p>{ step.description }</p>
					{ step.link?.url && (
						<p>
							<ExternalLink href={ step.link.url }>{ step.link.label }</ExternalLink>
						</p>
					) }
				</div>
			),
		} ) ) }
	/>
);
