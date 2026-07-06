/**
 * WordPress dependencies.
 */
import { applyFilters } from '@wordpress/hooks';

const APP_NAME = 'Newspack Story Budget';

export interface Site {
	url: string;
	name: string;
}

export function getSites(): Site[] {
	// `applyFilters` (an untyped `@wordpress/hooks` export) returns `unknown`;
	// consumers of this filter are expected to preserve the `Site[]` shape.
	return applyFilters( 'newspack-story-budget.sites', [] ) as Site[];
}

/**
 * Get authorization success URL.
 *
 * @return {string} Authorization success URL.
 */
function getSuccessUrl(): string {
	const url = new URL( window.location.href );
	url.searchParams.delete( 'budget_id' ); // Remove budget_id from URL.
	url.searchParams.set( 'application_password', '1' );
	url.hash = '';
	return url.toString();
}

/**
 * Get authorization URL given an Application Password endpoint.
 *
 * @param {string} endpoint Application Password endpoint.
 *
 * @return {string} Authorization URL.
 */
function getAuthorizationUrl( endpoint: string ): string {
	const url = new URL( endpoint );
	url.searchParams.set( 'app_name', APP_NAME );
	url.searchParams.set( 'success_url', getSuccessUrl() );
	return url.toString();
}

/**
 * Get site credentials.
 *
 * @param {string} url Site URL.
 *
 * @return {string} Site credentials.
 */
export function getCredentials( url: string ): string | null {
	return window.localStorage.getItem( `newspack-story-budget-site-${ url }` );
}

/**
 * Set site credentials.
 *
 * @param {string} url      Site URL.
 * @param {string} login    Login.
 * @param {string} password Password.
 */
export function setCredentials( url: string, login: string, password: string ): void {
	window.localStorage.setItem( `newspack-story-budget-site-${ url }`, btoa( `${ login }:${ password }` ) );
}

/**
 * Clear site credentials.
 *
 * @param {string} url Site URL.
 */
export function clearCredentials( url: string ): void {
	window.localStorage.removeItem( `newspack-story-budget-site-${ url }` );
}

/**
 * Check if returning from authorization.
 *
 * @return {boolean} Whether returning from authorization.
 */
export function isAuthorizingSite(): boolean {
	const urlParams = new URLSearchParams( window.location.search );
	return urlParams.get( 'application_password' ) === '1';
}

export interface AuthorizationData {
	siteUrl: string | null;
	login: string | null;
	password: string | null;
	success: boolean;
}

/**
 * Get authorization data.
 *
 * @return {Object} Authorization data.
 */
export function getAuthorizationData(): AuthorizationData {
	const urlParams = new URLSearchParams( window.location.search );
	return {
		siteUrl: urlParams.get( 'site_url' ),
		login: urlParams.get( 'user_login' ),
		password: urlParams.get( 'password' ),
		success: urlParams.get( 'success' ) !== 'false',
	};
}

/**
 * The WP REST API index document (self-discovery response) -- only the
 * fields this module reads from it.
 */
interface RestIndexResponse {
	url: string;
	namespaces: string[];
	authentication: {
		'application-passwords': {
			endpoints: {
				authorization: string;
			};
		};
	};
}

/**
 * Validate site connection.
 *
 * @param {string} url Site URL.
 *
 * @throws {Error} If connection is invalid.
 */
async function validateConnection( url: string ): Promise< void > {
	const credentials = getCredentials( url );
	if ( ! credentials ) {
		throw new Error( 'No credentials found' );
	}

	const res = await fetch( url + '?rest_route=/', {
		headers: {
			Authorization: `Basic ${ credentials }`,
		},
	} );

	// `Response.json()` is typed `Promise<any>` by the DOM lib; cast to the
	// known shape of the WP REST API index document.
	const data = ( await res.json() ) as RestIndexResponse;

	if ( ! data.url ) {
		throw new Error( 'Invalid response' );
	}
	if ( ! data.namespaces.includes( 'newspack-story-budget/v1' ) ) {
		throw new Error( 'Story Budget not enabled for this site.' );
	}
}

/**
 * Connect to site.
 *
 * @param {string} url Site URL.
 *
 * @throws {Error} If connection is invalid.
 */
export async function connect( url?: string | null ): Promise< void > {
	if ( ! url ) {
		url = getCurrentSite();
	}

	if ( ! url ) {
		throw new Error( 'No site URL provided.' );
	}

	try {
		await validateConnection( url );
		// Credentials are valid, redirect.
		const redirectUrl = new URL( window.location.href );
		redirectUrl.searchParams.delete( 'budget_id' ); // Remove budget_id from URL.
		redirectUrl.searchParams.set( 'site_url', url );
		redirectUrl.hash = '';
		window.location.href = redirectUrl.toString();
		return; // eslint-disable-line no-useless-return
	} catch ( err ) {
		clearCredentials( url );
		console.warn( err ); // eslint-disable-line no-console
	}

	const res = await fetch( url + '?rest_route=/' );
	const data = ( await res.json() ) as RestIndexResponse;

	if ( ! data.url ) {
		throw new Error( 'Invalid response' );
	}
	if ( ! data.namespaces.includes( 'newspack-story-budget/v1' ) ) {
		throw new Error( 'Story Budget not enabled for this site.' );
	}
	window.location.href = getAuthorizationUrl( data.authentication[ 'application-passwords' ].endpoints.authorization );
}

/**
 * Get current site.
 *
 * @return {string|null} Current site URL or null if not set.
 */
export function getCurrentSite(): string | null {
	const urlParams = new URLSearchParams( window.location.search );
	const siteUrl = urlParams.get( 'site_url' );
	if ( ! siteUrl ) {
		return null;
	}
	return siteUrl;
}

/**
 * Check if current site is remote.
 *
 * @return {boolean} Whether current site is remote.
 */
export function isRemoteSite(): boolean {
	return getCurrentSite() !== null;
}

/**
 * Get current site name.
 *
 * @return {string|null|undefined} Current site name or null/undefined if not set.
 */
export function getCurrentSiteName(): string | null | undefined {
	const url = getCurrentSite();
	if ( ! url ) {
		return null;
	}
	const sites = getSites();
	const site = sites.find( s => s.url === url );
	return site?.name;
}

/**
 * Get "leave site" URL.
 *
 * @return {string} Leave site URL.
 */
export function getLeaveSiteUrl(): string {
	const url = new URL( window.location.href );
	url.searchParams.delete( 'site_url' );
	return url.toString();
}
