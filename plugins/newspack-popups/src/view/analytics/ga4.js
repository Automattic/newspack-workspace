/* global gtag */

import { manageLoadedEvents } from './loaded';
import { manageSeenEvents } from './seen';
import { manageDismissals } from './dismissed';
import { manageClickedEvents } from './clicked';

export const handleAnalytics = prompts => {
	// Must have a gtag instance to proceed.
	if ( 'function' === typeof gtag ) {
		manageLoadedEvents( prompts );
		manageSeenEvents();
		manageDismissals( prompts );
		manageClickedEvents( prompts );
	}
};
