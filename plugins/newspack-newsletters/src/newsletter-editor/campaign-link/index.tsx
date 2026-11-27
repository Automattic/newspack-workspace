/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { getServiceProvider } from '../../service-providers';
import { useNewsletterData } from '../../newsletter-editor/store';

export default function CampaignLink() {
	const { newsletterData } = useNewsletterData();
	if ( ! newsletterData.link ) {
		return null;
	}
	// `link` is a dynamic ESP-specific field not covered by the shared NewsletterData shape.
	const link = newsletterData.link as string;
	// Only rendered once a connected (non-manual) ESP has supplied a campaign link, so displayName is always set.
	const displayName = getServiceProvider().displayName as string;
	return (
		<div className="newspack-newsletters-buttons-group">
			<Button variant="secondary" href={ link } target="_blank" rel="noopener noreferrer" __next40pxDefaultSize>
				{ sprintf(
					// translators: %s: service provider name.
					__( 'View Campaign in %s', 'newspack-newsletters' ),
					displayName
				) }
			</Button>
		</div>
	);
}
