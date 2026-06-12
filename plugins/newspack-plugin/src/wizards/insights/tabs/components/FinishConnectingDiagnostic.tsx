/**
 * FinishConnectingDiagnostic (NPPD-1618).
 *
 * Shown when a tab is visible but its data source is connected only partway —
 * the orchestrator returns `is_report_ready: false` with one or more
 * `readiness_issues`. Each issue names what's missing and links to the page
 * that fixes it. Same muted "you can't see data yet, here's what to do" family
 * as {@see ConnectBanner}, but itemized for the multi-step GAM connection.
 *
 * Introduced for Tab 8 (Advertising), where tab visibility (GAM ad provider
 * active) and reporting readiness (OAuth scope + network code) are distinct;
 * lives in the shared `components/` dir as other tabs may adopt the pattern.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Notice, Button } from '../../../../../packages/components/src';
import type { ReadinessIssue } from '../../api/advertising';

export interface FinishConnectingDiagnosticProps {
	issues: ReadinessIssue[];
	/** Optional heading override; defaults to the GAM connection prompt. */
	heading?: string;
}

const FinishConnectingDiagnostic = ( { issues, heading }: FinishConnectingDiagnosticProps ) => {
	const headingText = heading || __( 'Finish connecting Google Ad Manager to see ad data', 'newspack-plugin' );

	return (
		<Notice isWarning className="newspack-insights__finish-connecting" noticeText={ <strong>{ headingText }</strong> }>
			{ issues.length > 0 ? (
				<ul className="newspack-insights__finish-connecting-list">
					{ issues.map( issue => (
						<li key={ issue.code } className="newspack-insights__finish-connecting-item">
							<span>{ issue.message }</span>
							{ issue.remediation_url && (
								<Button
									variant="link"
									href={ issue.remediation_url }
									aria-label={ sprintf(
										/* translators: %s: the readiness issue being remediated. */
										__( 'Fix: %s', 'newspack-plugin' ),
										issue.message
									) }
								>
									{ __( 'Fix this →', 'newspack-plugin' ) }
								</Button>
							) }
						</li>
					) ) }
				</ul>
			) : (
				<p>{ __( 'Finish connecting Google Ad Manager in Newspack settings to see ad data.', 'newspack-plugin' ) }</p>
			) }
		</Notice>
	);
};

export default FinishConnectingDiagnostic;
