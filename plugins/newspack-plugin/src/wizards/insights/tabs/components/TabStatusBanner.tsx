/**
 * TabStatusBanner (NEWS-2603).
 *
 * Two-tier top-of-tab status banner driven by the envelope's top-level
 * `data_status` field (`complete | warming | incomplete`), set by the
 * backend snapshot cache. `complete` (or the field being absent, e.g. on
 * older cached payloads) renders nothing — the common case. `warming`
 * renders a soft/info notice: the snapshot is mid-backfill and some
 * metrics may still be catching up. `incomplete` renders a warning notice:
 * the last fetch didn't finish, so some figures may be stale or missing.
 * Backed by the shared Newspack `Notice` component, same as TabErrorBanner.
 * The outer `<div role="status">` / `<div role="alert">` wrapper preserves
 * live-region semantics (polite for warming, assertive for incomplete) so
 * assistive tech announces the banner when it appears — the Newspack
 * `Notice` component renders a plain `<div>` without a role.
 *
 * This component only renders whatever `data_status` currently says; it
 * does not poll or escalate warming → incomplete over time (see Task 6).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Notice } from '../../../../../packages/components/src';

export interface TabStatusBannerProps {
	/** Envelope-level data freshness status; `complete`/absent renders nothing. */
	status?: 'complete' | 'warming' | 'incomplete';
}

const TabStatusBanner = ( { status }: TabStatusBannerProps ) => {
	if ( status === 'warming' ) {
		return (
			<div role="status">
				<Notice noticeText={ __( 'Some metrics are still being calculated and will appear shortly.', 'newspack-plugin' ) } />
			</div>
		);
	}

	if ( status === 'incomplete' ) {
		return (
			<div role="alert">
				<Notice
					isWarning
					noticeText={ __( "The last data fetch didn't finish, so some figures may be missing or out of date.", 'newspack-plugin' ) }
				/>
			</div>
		);
	}

	return null;
};

export default TabStatusBanner;
