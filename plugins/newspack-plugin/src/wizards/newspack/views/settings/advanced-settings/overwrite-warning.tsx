/**
 * Newspack > Settings > Advanced Settings > Overwrite Warning
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';

export default function OverwriteWarning( { postCount }: { postCount?: string } ) {
	return (
		<Notice isDismissible={ false } status="warning" spokenMessage="">
			{ __( 'After saving the settings with this option selected, all posts will be updated. This cannot be undone.', 'newspack-plugin' ) }
			{ Number( postCount ) > 1000 &&
				' ' + __( 'You have more than 1000 posts. Applying these settings might take a moment.', 'newspack-plugin' ) }
		</Notice>
	);
}
