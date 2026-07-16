/* globals newspack_popups_view */

/**
 * Internal dependencies
 */
import './style.scss';
import './patterns.scss';
import { handleSegmentation } from './segmentation';
import { handleAnalytics } from './analytics/ga4';
import { domReady, logPageview, getPrompts } from './utils';

import './merge-tags';

domReady( () => {
	window.newspackRAS = window.newspackRAS || [];
	window.newspackRAS.push( logPageview ); // Pageviews should be logged whether or not prompts are enabled.

	if ( ! newspack_popups_view?.has_disabled_prompts ) {
		// Fetch all prompts on the page just once.
		const prompts = getPrompts();

		handleSegmentation( prompts );
		handleAnalytics( prompts );
	}
} );
