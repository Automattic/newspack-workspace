/**
 * ConnectBanner (NPPD-1649).
 *
 * Full-tab state shown when the orchestrator returns
 * `{ tab_error: 'oauth_not_connected', banner_text }` — i.e. the publisher
 * has no Google Analytics connection. Replaces all section content with a
 * single connect CTA. Used by both AudienceTab and EngagementTab.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

// Localized, subdirectory-safe URL to the Newspack settings page (where the
// Google connection lives), built with admin_url() in the PHP boot config.
// Falls back to a relative admin path if the global isn't present.
const SETTINGS_URL = window.newspackInsights?.settingsUrl || 'admin.php?page=newspack-settings';

export interface ConnectBannerProps {
	text?: string;
}

const ConnectBanner = ( { text }: ConnectBannerProps ) => (
	<div className="newspack-insights__connect-banner" role="status">
		<p className="newspack-insights__connect-banner-text">
			{ text || __( 'Connect Google Analytics in Newspack → Settings → Connections to see this tab.', 'newspack-plugin' ) }
		</p>
		<a className="newspack-insights__connect-banner-cta" href={ SETTINGS_URL }>
			{ __( 'Connect Google Analytics →', 'newspack-plugin' ) }
		</a>
	</div>
);

export default ConnectBanner;
