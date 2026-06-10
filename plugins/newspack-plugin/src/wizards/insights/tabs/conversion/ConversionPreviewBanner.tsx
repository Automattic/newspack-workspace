/**
 * ConversionPreviewBanner (NPPD-1609, Phase 1 only).
 *
 * Top-of-tab banner rendered above Section 1 during the placeholder phase,
 * signaling that the tab's structure is final but real data is pending.
 * Removed entirely when Phase 2 lands.
 *
 * Dismissal is session-only per the spec ("dismissible via X but reappears
 * on page reload — don't persist"): a self-contained `useState`, like the
 * Gates / Prompts DirectVsInfluencedCallout, rather than the shared
 * InfoCallout whose dismissible mode persists in localStorage.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Icon, closeSmall, info } from '@wordpress/icons';

const ConversionPreviewBanner = () => {
	const [ visible, setVisible ] = useState( true );
	if ( ! visible ) {
		return null;
	}
	return (
		<div className="newspack-insights__conversion-preview-banner" role="note">
			<Icon icon={ info } className="newspack-insights__conversion-preview-banner-icon" />
			<div className="newspack-insights__conversion-preview-banner-body">
				<p className="newspack-insights__conversion-preview-banner-title">
					<strong>{ __( 'This tab is live in preview mode.', 'newspack-plugin' ) }</strong>{ ' ' }
					{ __(
						'Real-time metrics will populate once BigQuery integration is complete. The structure, sections, and visualizations are final.',
						'newspack-plugin'
					) }
				</p>
			</div>
			<button
				type="button"
				className="newspack-insights__conversion-preview-banner-dismiss"
				onClick={ () => setVisible( false ) }
				aria-label={ __( 'Dismiss', 'newspack-plugin' ) }
			>
				<Icon icon={ closeSmall } />
			</button>
		</div>
	);
};

export default ConversionPreviewBanner;
