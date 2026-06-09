/**
 * DirectVsInfluencedCallout (NPPD-1604).
 *
 * Small dismissable info callout rendered at the top of the Gates
 * tab (immediately below the Phase 1 preview banner, above Section
 * 1's heading). The Direct vs Influenced framing is foundational to
 * Sections 2 and 3, so publishers should see it before reading any
 * section that uses the terms. Per spec, dismissal is session-only
 * (no persisted "don't show again" — the callout reappears on
 * page reload).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Icon, closeSmall, info } from '@wordpress/icons';

const DirectVsInfluencedCallout = () => {
	const [ visible, setVisible ] = useState( true );
	if ( ! visible ) {
		return null;
	}
	return (
		<div className="newspack-insights__gates-callout" role="note">
			<Icon icon={ info } className="newspack-insights__gates-callout-icon" />
			<div className="newspack-insights__gates-callout-body">
				<p className="newspack-insights__gates-callout-title">
					<strong>{ __( 'About Direct vs Influenced conversion', 'newspack-plugin' ) }</strong>
				</p>
				<p>
					<strong>{ __( 'Direct', 'newspack-plugin' ) }</strong>{ ' ' }
					{ __(
						'conversions happen in the same session as a gate impression. The gate is credited regardless of whether checkout happens on the same page (embedded checkout block) or after clicking through to a subscription page.',
						'newspack-plugin'
					) }
				</p>
				<p>
					<strong>{ __( 'Influenced', 'newspack-plugin' ) }</strong>{ ' ' }
					{ __(
						'conversions happen after a gate impression but in a later session, within a lookback window (7 days for free conversions, 14 days for paid).',
						'newspack-plugin'
					) }
				</p>
				<p>
					{ __(
						'Same-session is Direct. Later-session-within-lookback is Influenced. The two are mutually exclusive and together capture every gate-touched conversion within the lookback period.',
						'newspack-plugin'
					) }
				</p>
			</div>
			<button
				type="button"
				className="newspack-insights__gates-callout-dismiss"
				onClick={ () => setVisible( false ) }
				aria-label={ __( 'Dismiss callout', 'newspack-plugin' ) }
			>
				<Icon icon={ closeSmall } />
			</button>
		</div>
	);
};

export default DirectVsInfluencedCallout;
