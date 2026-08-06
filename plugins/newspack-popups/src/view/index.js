/* globals newspack_popups_view */

/**
 * Internal dependencies
 */
import './style.scss';
import './patterns.scss';
import { handleSegmentation } from './segmentation';
import { handleAnalytics } from './analytics/ga4';
import { reportMatchedSegments } from './analytics/segments';
import { ingestCarriedSegments } from './carried-segments';
import { domReady, logPageview, getPrompts } from './utils';

import './merge-tags';

domReady( () => {
	window.newspackRAS = window.newspackRAS || [];
	// Segment IDs carried in from a newsletter link must land before anything
	// evaluates or reports segment matches.
	window.newspackRAS.push( ingestCarriedSegments );
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
} );
