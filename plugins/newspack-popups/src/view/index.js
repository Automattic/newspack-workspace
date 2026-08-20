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
import { propagatePreviewParams } from './preview-links';
import { domReady, logPageview, getPrompts } from './utils';

import './merge-tags';

domReady( () => {
	window.newspackRAS = window.newspackRAS || [];
	window.newspackRAS.push( logPageview ); // Pageviews should be logged whether or not prompts are enabled.
	// Segment reach is reported whether or not prompts are enabled, and must run
	// after logPageview so the reported set matches what prompts targeted.
	window.newspackRAS.push( reportMatchedSegments );

	// Wrapped, and after the pageview push, on purpose. Everything below runs for
	// every reader, while link rewriting is an admin-only preview nicety; a throw
	// here would otherwise take prompt display and prompt analytics down with it.
	// Nothing in it can throw today, so this keeps that a property of the structure
	// rather than of the current implementation.
	try {
		propagatePreviewParams();
	} catch ( e ) {
		// eslint-disable-next-line no-console
		console.warn( 'Prompt preview: could not propagate preview params.', e );
	}

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
