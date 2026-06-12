/**
 * PromptsErrorBanner (NPPD-1607).
 *
 * Tab-level error banner shown when every Prompts section fails to load (e.g.
 * the BigQuery proxy is down or Newspack Manager isn't configured). Per-section
 * error treatments still render beneath it; this is the at-a-glance summary.
 * Publisher-friendly copy only — no internal error codes are surfaced. Mirrors
 * the tab-local Gates `GatesErrorBanner`.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Icon, caution } from '@wordpress/icons';

const PromptsErrorBanner = () => (
	<div className="newspack-insights__prompts-banner" role="alert">
		<Icon icon={ caution } className="newspack-insights__prompts-banner-icon" />
		<p className="newspack-insights__prompts-banner-message">
			<strong>{ __( 'Unable to load this tab.', 'newspack-plugin' ) }</strong>{ ' ' }
			{ __( 'Newspack Manager may need attention. Try again shortly.', 'newspack-plugin' ) }
		</p>
	</div>
);

export default PromptsErrorBanner;
