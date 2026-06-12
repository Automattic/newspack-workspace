/**
 * TabErrorBanner.
 *
 * Tab-level error banner shown when every section of a tab fails to load
 * (e.g. the BigQuery proxy is down or Newspack Manager isn't configured).
 * Per-section error treatments still render beneath it; this is the
 * at-a-glance summary. Publisher-friendly copy only — no internal error
 * codes are surfaced. Replaces the tab-local GatesErrorBanner /
 * PromptsErrorBanner with a single shared component backed by the
 * Newspack `Notice` (`isError`) for project-wide visual consistency.
 * The outer `<div role="alert">` wrapper preserves live-region semantics
 * so assistive tech announces the banner when it appears (the Newspack
 * `Notice` component renders a plain `<div>` without `role="alert"`).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Notice } from '../../../../../packages/components/src';

export interface TabErrorBannerProps {
	/** Headline (defaults to "Unable to load this tab."). */
	heading?: string;
	/** Body explanation (defaults to "Newspack Manager may need attention. Try again shortly."). */
	body?: string;
}

const TabErrorBanner = ( { heading, body }: TabErrorBannerProps ) => (
	<div role="alert">
		<Notice
			isError
			noticeText={
				<>
					<strong>{ heading ?? __( 'Unable to load this tab.', 'newspack-plugin' ) }</strong>{ ' ' }
					{ body ?? __( 'Newspack Manager may need attention. Try again shortly.', 'newspack-plugin' ) }
				</>
			}
		/>
	</div>
);

export default TabErrorBanner;
