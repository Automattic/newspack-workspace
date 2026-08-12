/* globals newspack_popups_view */

/**
 * Internal dependencies
 */
import './style.scss';
import './patterns.scss';
import { handleSegmentation } from './segmentation';
import { handleAnalytics } from './analytics/ga4';
import { reportMatchedSegments } from './analytics/segments';
import { handleContextualPromptAnalytics } from './analytics/contextual-prompt';
import { domReady, logPageview, getPrompts } from './utils';

import './merge-tags';

domReady( () => {
	window.newspackRAS = window.newspackRAS || [];
	window.newspackRAS.push( logPageview ); // Pageviews should be logged whether or not prompts are enabled.
	// Segment reach is reported whether or not prompts are enabled, and must run
	// after logPageview so the reported set matches what prompts targeted.
	window.newspackRAS.push( reportMatchedSegments );

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
