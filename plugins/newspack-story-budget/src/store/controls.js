/* globals newspackStoryBudget */
/**
 * WordPress dependencies.
 */
import triggerFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies.
 */
import { getCurrentSite, getCredentials } from '../utils/sites';

const { apiNamespace, siteUrl } = newspackStoryBudget;

const remoteSite = getCurrentSite();

triggerFetch.use( ( options, next ) => {
	if ( ! options.isStoryBudget ) {
		return next( options );
	}

	options.path = options.fullPath || apiNamespace + options.path;

	if ( remoteSite ) {
		const { path, data, method } = options;
		const authorization = getCredentials( remoteSite );

		if ( ! authorization ) {
			return Promise.reject( {
				message: 'Credentials not found.',
			} );
		}

		const [ routePath, queryString ] = path.split( '?' );
		const route = encodeURIComponent( routePath );
		const url = `${ remoteSite }/?rest_route=/${ route }${ queryString ? '&' + queryString : '' }`;
		return next( {
			method,
			data,
			url,
			credentials: 'omit', // Prevent cookies from being sent
			headers: {
				Authorization: `Basic ${ authorization }`,
				'X-Network-Site-Url': siteUrl,
			},
		} );
	}

	return next( options );
} );

export function apiFetch( request ) {
	return {
		type: 'STORY_BUDGET_FETCH',
		request,
	};
}

export const controls = {
	STORY_BUDGET_FETCH( { request } ) {
		request.isStoryBudget = true;
		return triggerFetch( request );
	},
};
