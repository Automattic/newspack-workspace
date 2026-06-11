/**
 * DirectVsInfluencedCallout (NPPD-1604, refactored NPPD-1618).
 *
 * The Gates-tab explainer for the Direct vs Influenced framing used by Sections
 * 2 and 3. Now a thin wrapper over the shared {@see InfoCallout}; this file
 * keeps only the Gates-specific copy.
 *
 * Dismissal persists across page loads (localStorage, via InfoCallout's
 * `storageKey`). NOTE: this changes the original NPPD-1604 behavior, which was
 * session-only (reappeared on reload) — done per the NPPD-1618 request to share
 * the persisted-callout pattern.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import InfoCallout from '../components/InfoCallout';

const DirectVsInfluencedCallout = () => (
	<InfoCallout heading={ __( 'About Direct vs Influenced conversion', 'newspack-plugin' ) } dismissible storageKey="gates-conversion-types">
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
	</InfoCallout>
);

export default DirectVsInfluencedCallout;
