/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { registerPlugin } from '@wordpress/plugins';
import { format } from '@wordpress/date';

function NewslettersDebugSend() {
	const { sendErrors, sent } = useSelect( select => {
		const { getCurrentPostAttribute } = select( 'core/editor' );
		const meta = getCurrentPostAttribute( 'meta' );
		return {
			sendErrors: meta.newsletter_send_errors,
			sent: meta.newsletter_sent,
		};
	} );
	if ( sent || ! sendErrors?.length ) {
		return null;
	}
	return (
		<PluginDocumentSettingPanel name="newsletters-ads-settings-panel" title={ __( 'Send Errors', 'newspack-newsletters' ) }>
			<p>{ __( 'The following errors occurred when trying to send the newsletter:', 'newspack-newsletters' ) }</p>
			<ul>
				{ sendErrors
					.slice( 0 )
					.reverse()
					.map( error => (
						<li key={ error.timestamp }>
							<hr />
							<strong>{ format( 'F j, Y, g:i a', new Date( error.timestamp * 1000 ) ) }</strong>:
							<br />
							{ error.message }
						</li>
					) ) }
			</ul>
			{ sendErrors.length === 10 && <p>{ __( 'Only the last 10 errors are stored.', 'newspack-newsletters' ) }</p> }
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'newspack-newsletters-debug-send', {
	render: NewslettersDebugSend,
	icon: null,
} );
