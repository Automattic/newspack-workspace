/**
 * PreviewBanner (NPPD-1604, Phase 1).
 *
 * Top-of-tab dismissable banner that calls out the Phase 1
 * placeholder state. Dismissal is session-only (component state) per
 * spec — the banner reappears on page reload so the visual cue isn't
 * accidentally hidden across sessions.
 *
 * Remove this component entirely when Phase 2 (NPPD-1630) lands and
 * the tab carries real BQ data.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Icon, closeSmall, info } from '@wordpress/icons';

const PreviewBanner = () => {
	const [ visible, setVisible ] = useState( true );
	if ( ! visible ) {
		return null;
	}
	return (
		<div className="newspack-insights__gates-banner" role="status">
			<Icon icon={ info } className="newspack-insights__gates-banner-icon" />
			<p className="newspack-insights__gates-banner-message">
				<strong>{ __( 'This tab is live in preview mode.', 'newspack-plugin' ) }</strong>{ ' ' }
				{ __(
					'Real-time metrics will populate once BigQuery integration is complete. The structure, sections, and visualizations are final.',
					'newspack-plugin'
				) }
			</p>
			<button
				type="button"
				className="newspack-insights__gates-banner-dismiss"
				onClick={ () => setVisible( false ) }
				aria-label={ __( 'Dismiss preview banner', 'newspack-plugin' ) }
			>
				<Icon icon={ closeSmall } />
			</button>
		</div>
	);
};

export default PreviewBanner;
