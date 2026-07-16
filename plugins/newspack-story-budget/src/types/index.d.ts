declare global {
	/**
	 * Data localized as `newspackStoryBudget` by Class_Admin::enqueue_scripts()
	 * (see includes/class-admin.php). Available as a bare global (scripts
	 * enqueued with this as a dependency) and, defensively, on `window`.
	 */
	interface NewspackStoryBudgetData {
		apiNamespace: string;
		siteUrl: string;
		refreshCache: boolean;
		alwaysFetchStories: boolean;
	}

	const newspackStoryBudget: NewspackStoryBudgetData;

	interface Window {
		newspackStoryBudget?: NewspackStoryBudgetData;
	}
}

/**
 * `store/controls.ts` tags outgoing requests with `isStoryBudget` (so the
 * shared `api-fetch` middleware chain can identify and route this plugin's
 * requests) and `fullPath` (an already-namespaced path override); neither is
 * part of the upstream `APIFetchOptions` shape.
 */
declare module '@wordpress/api-fetch' {
	interface APIFetchOptions {
		isStoryBudget?: boolean;
		fullPath?: string;
	}
}

export {};
