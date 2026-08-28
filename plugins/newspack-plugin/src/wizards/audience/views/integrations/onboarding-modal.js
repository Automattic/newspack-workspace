/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, Modal } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const DISMISS_PATH = '/newspack/v1/wizard/newspack-audience-integrations/onboarding/dismiss';

/**
 * One-time introduction to the Integrations screen. Visibility and extra
 * notices come from localized data; the dismissal is stored per user so the
 * modal never returns for this admin.
 */
export const OnboardingModal = () => {
	const [ visible, setVisible ] = useState( Boolean( window.newspackAudienceIntegrations?.show_onboarding ) );
	if ( ! visible ) {
		return null;
	}
	const notices = window.newspackAudienceIntegrations?.onboarding_notices || [];
	const dismiss = () => {
		setVisible( false );
		// Fire-and-forget: failing to store the dismissal only means the modal
		// shows once more on the next visit.
		apiFetch( { path: DISMISS_PATH, method: 'POST' } ).catch( () => {} );
	};
	return (
		<Modal title={ __( 'Welcome to Integrations', 'newspack-plugin' ) } onRequestClose={ dismiss }>
			<p>
				{ __(
					'This screen brings reader-data connections into one place. Each service is a card: enable it, configure its settings, and review its activity logs from here.',
					'newspack-plugin'
				) }
			</p>
			{ Boolean( window.newspackAudienceIntegrations?.esp_sync_configured ) && (
				<p>
					{ __(
						'The Mailchimp integration is the reader sync previously configured under Audience → Setup. Its settings carried over; nothing needs to be reconfigured.',
						'newspack-plugin'
					) }
				</p>
			) }
			{ notices.map( ( notice, index ) => (
				<p key={ index }>{ notice }</p>
			) ) }
			<Button variant="primary" onClick={ dismiss }>
				{ __( 'Got it', 'newspack-plugin' ) }
			</Button>
		</Modal>
	);
};
