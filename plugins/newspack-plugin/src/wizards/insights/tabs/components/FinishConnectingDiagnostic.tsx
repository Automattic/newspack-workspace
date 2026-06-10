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
import type { ReadinessIssue } from '../../api/advertising';

export interface FinishConnectingDiagnosticProps {
	issues: ReadinessIssue[];
	/** Optional heading override; defaults to the GAM connection prompt. */
	heading?: string;
}

const FinishConnectingDiagnostic = ( { issues, heading }: FinishConnectingDiagnosticProps ) => (
	<div className="newspack-insights__finish-connecting" role="status">
		<h2 className="newspack-insights__finish-connecting-heading">
			{ heading || __( 'Finish connecting Google Ad Manager to see ad data', 'newspack-plugin' ) }
		</h2>
		{ issues.length > 0 ? (
			<ul className="newspack-insights__finish-connecting-list">
				{ issues.map( issue => (
					<li key={ issue.code } className="newspack-insights__finish-connecting-item">
						<span className="newspack-insights__finish-connecting-message">{ issue.message }</span>
						{ issue.remediation_url && (
							<a
								className="newspack-insights__finish-connecting-cta"
								href={ issue.remediation_url }
								aria-label={ sprintf(
									/* translators: %s: the readiness issue being remediated. */
									__( 'Fix: %s', 'newspack-plugin' ),
									issue.message
								) }
							>
								{ __( 'Fix this', 'newspack-plugin' ) } &rarr;
							</a>
						) }
					</li>
				) ) }
			</ul>
		) : (
			<p className="newspack-insights__finish-connecting-message">
				{ __( 'Finish connecting Google Ad Manager in Newspack settings to see ad data.', 'newspack-plugin' ) }
			</p>
		) }
	</div>
);

export default FinishConnectingDiagnostic;
