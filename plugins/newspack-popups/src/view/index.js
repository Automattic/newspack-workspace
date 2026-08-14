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
	window.newspackRAS = window.newspackRAS || [];
	window.newspackRAS.push( logPageview ); // Pageviews should be logged whether or not prompts are enabled.

	// After the pageview push on purpose: link rewriting is a preview nicety, and
	// nothing about it should be able to cost a real reader their pageview.
	propagatePreviewParams();

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
