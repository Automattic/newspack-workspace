/**
 * GatesErrorBanner (NPPD).
 *
 * Tab-level error banner shown when every Gates section fails to load (e.g. the
 * BigQuery proxy is down or Newspack Manager isn't configured). Per-section
 * error treatments still render beneath it; this is the at-a-glance summary.
 * Publisher-friendly copy only — no internal error codes are surfaced.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Icon, caution } from '@wordpress/icons';

const GatesErrorBanner = () => (
	<div className="newspack-insights__gates-banner" role="alert">
		<Icon icon={ caution } className="newspack-insights__gates-banner-icon" />
		<p className="newspack-insights__gates-banner-message">
			<strong>{ __( 'Unable to load this tab.', 'newspack-plugin' ) }</strong>{ ' ' }
			{ __( 'Newspack Manager may need attention. Try again shortly.', 'newspack-plugin' ) }
		</p>
	</div>
);

export default GatesErrorBanner;
