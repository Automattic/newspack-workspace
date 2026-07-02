/**
 * NextSteps (NPPD-1842)
 *
 * Static, outcome-framed "next steps" strip pinned in each Insights tab
 * footer, below the metrics (Option B placement). Holds 1–2 links to the
 * matching help-site "Playbooks" goal flow. Renders nothing when the tab has
 * no mapped links (Gates, Campaigns, and Advertising in v1). The link label
 * IS the outcome ("Grow reader revenue") — never a generic "Help" / "Learn
 * more"; the wording is the whole point of the affordance. The mapping is
 * product-owned and arrives via the boot config (see get_next_steps_links()
 * in class-insights-wizard.php).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { NextStepLink } from './InsightsWizard';

export interface NextStepsProps {
	links: NextStepLink[];
}

/**
 * Defense in depth: the links originate from a PHP filter
 * (`newspack_insights_next_steps_links`) and land in an `href`, so only render
 * absolute http(s) URLs. The server already sanitizes with esc_url_raw(); this
 * guards the client too, so a bad filter can never put a `javascript:` (or other
 * unsafe-scheme) URL into the DOM.
 */
const isSafeUrl = ( url: string ): boolean => /^https?:\/\//i.test( url );

const NextSteps = ( { links }: NextStepsProps ) => {
	const safeLinks = links.filter( link => isSafeUrl( link.url ) );
	if ( ! safeLinks.length ) {
		return null;
	}
	return (
		<nav className="newspack-insights__next-steps" aria-label={ __( 'Next steps', 'newspack-plugin' ) }>
			<span className="newspack-insights__next-steps-label">{ __( 'Next steps', 'newspack-plugin' ) }</span>
			<ul className="newspack-insights__next-steps-list">
				{ safeLinks.map( link => (
					<li key={ link.url }>
						<a className="newspack-insights__next-steps-link" href={ link.url } target="_blank" rel="noreferrer">
							{ link.label }
						</a>
					</li>
				) ) }
			</ul>
		</nav>
	);
};

export default NextSteps;
