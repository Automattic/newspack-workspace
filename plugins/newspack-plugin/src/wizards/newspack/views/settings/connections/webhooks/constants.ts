/**
 * Settings Wizard: Connections > Webhooks > Constants.
 */

/** Wizard API Fetch namespace used for hooks */
export const API_NAMESPACE = '/newspack-settings/connections/webhooks';

/** Cache key for get requests, primary storage for endpoints */
export const ENDPOINTS_CACHE_KEY: Record< string, ApiMethods > = {
	'/newspack/v1/webhooks/endpoints': 'GET',
};

export default {
	API_NAMESPACE,
	ENDPOINTS_CACHE_KEY,
};
