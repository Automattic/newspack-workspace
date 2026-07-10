/**
 * NextSteps (NPPD-1842)
 *
 * Static, outcome-framed "next steps" affordance for each Insights tab: a small
 * floating card pinned to the bottom-right corner of the viewport (styled in
 * _next-steps.scss), holding 1–2 links to the matching help-site "Playbooks"
 * goal flow. Renders nothing when the tab has no mapped links (Gates, Campaigns,
 * and Advertising in v1). The link label
 * IS the outcome ("Grow reader revenue") — never a generic "Help" / "Learn
 * more"; the wording is the whole point of the affordance. The mapping is
 * product-owned and arrives via the boot config (see get_next_steps_links()
 * in class-insights-wizard.php).
 *
 * Dismissible (DSGNEWS-188): a close button hides the card and persists the
 * choice in localStorage so it stays dismissed across tabs and reloads.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, ExternalLink } from '@wordpress/components';
import { close } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import type { NextStepLink } from './InsightsWizard';

export interface NextStepsProps {
	links: NextStepLink[];
}

const DISMISS_KEY = 'newspack-insights-next-steps-dismissed';

const readDismissed = (): boolean => {
	try {
		return window.localStorage.getItem( DISMISS_KEY ) === '1';
	} catch ( e ) {
		return false; // localStorage unavailable (e.g. privacy mode) — show the card.
	}
};

/**
 * Defense in depth: the links originate from a PHP filter
 * (`newspack_insights_next_steps_links`) and land in an `href`, so only render
 * absolute http(s) URLs. The server already sanitizes with esc_url_raw(); this
 * guards the client too, so a bad filter can never put a `javascript:` (or other
 * unsafe-scheme) URL into the DOM.
 */
const isSafeUrl = ( url: string ): boolean => /^https?:\/\//i.test( url );

const NextSteps = ( { links }: NextStepsProps ) => {
	const [ dismissed, setDismissed ] = useState( readDismissed );

	const safeLinks = links.filter( link => isSafeUrl( link.url ) );
	if ( ! safeLinks.length || dismissed ) {
		return null;
	}

	const dismiss = () => {
		try {
			window.localStorage.setItem( DISMISS_KEY, '1' );
		} catch ( e ) {
			// localStorage unavailable — dismiss for this render at least.
		}
		setDismissed( true );
	};

	return (
		<nav className="newspack-insights__next-steps" aria-label={ __( 'Next steps', 'newspack-plugin' ) }>
			<div className="newspack-insights__next-steps-header">
				<span className="newspack-insights__next-steps-label">{ __( 'Next steps', 'newspack-plugin' ) }</span>
				<Button
					className="newspack-insights__next-steps-dismiss"
					icon={ close }
					label={ __( 'Dismiss next steps', 'newspack-plugin' ) }
					onClick={ dismiss }
					size="small"
				/>
			</div>
			<ul className="newspack-insights__next-steps-list">
				{ safeLinks.map( link => (
					<li key={ link.url }>
						<ExternalLink className="newspack-insights__next-steps-link" href={ link.url }>
							{ link.label }
						</ExternalLink>
					</li>
				) ) }
			</ul>
		</nav>
	);
};

export default NextSteps;
