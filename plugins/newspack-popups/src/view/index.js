/* globals newspack_popups_view */

/**
 * Internal dependencies
 */
import './style.scss';
import './patterns.scss';
import { handleSegmentation } from './segmentation';
import { handleAnalytics } from './analytics/ga4';
import { handleContextualPromptAnalytics } from './analytics/contextual-prompt';
import { propagatePreviewParams } from './preview-links';
import { domReady, logPageview, getPrompts } from './utils';

import './merge-tags';

domReady( () => {
	// Runs regardless of the prompt-disabled flag: a preview has to survive
	// navigation even on a page where prompts are switched off.
	propagatePreviewParams();

	window.newspackRAS = window.newspackRAS || [];
	window.newspackRAS.push( logPageview ); // Pageviews should be logged whether or not prompts are enabled.

	if ( ! newspack_popups_view?.has_disabled_prompts ) {
		// Fetch all prompts on the page just once.
		const prompts = getPrompts();

		handleSegmentation( prompts );
		handleAnalytics( prompts );
	}

	// The Contextual Prompt card is body content, not a prompt, so its tracking
	// is independent of the prompt-disabled flag — it runs whenever the card is
	// on the page.
	handleContextualPromptAnalytics();
} );
