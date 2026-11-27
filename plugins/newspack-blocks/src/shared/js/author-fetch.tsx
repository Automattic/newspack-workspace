/**
 * Shared author lookups for the editor.
 *
 * Every Author Profile block used to fetch its author with its own request, so a staff page
 * with thirty blocks fired thirty requests at once and, on a site near its PHP worker limit,
 * some were dropped. Lookups made within one short window are combined into a single call to
 * the authors endpoint, results are shared between blocks and kept briefly, and identical
 * suggestion-list requests are de-duplicated the same way.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import type { Author } from '../../blocks/author-profile/single-author';

/** Query params the authors endpoint accepts for a batch request. */
type AuthorsRequestParams = {
	fields: string;
	avatar_hide_default?: number;
	author_ids?: string;
	guest_author_ids?: string;
};

/** A single caller waiting on a batched author lookup. */
type Settler = {
	resolve: ( author: Author | undefined ) => void;
	reject: ( error: unknown ) => void;
};

/** A queued lookup within a pending batch, shared by every caller asking for the same author. */
type BatchEntry = {
	authorId: number;
	isGuestAuthor: boolean;
	settlers: Settler[];
};

/** A pending batch grouping lookups that share fields and avatar options. */
type Batch = {
	fields: string;
	avatarHideDefault: boolean;
	entries: Map< string, BatchEntry >;
	timer: ReturnType< typeof setTimeout >;
};

/** A lightweight author record from the suggestions list (fields=id,name). */
type AuthorListItem = { id: number | string; name: string; is_guest?: boolean };

/** The resolved shape of a suggestion-list request. */
type AuthorListResult = { authors: AuthorListItem[]; total: number };

/** A cache record. The cache is heterogeneous (author records and list results), hence `unknown`. */
type CacheRecord = { expires: number; value: unknown };

const ENDPOINT = '/newspack-blocks/v1/authors';

// Blocks mount in bursts as the editor renders. This is long enough to catch a burst and
// short enough to be invisible next to the request itself.
const BATCH_WINDOW_MS = 50;

// Most ids the endpoint resolves in one request. Larger batches are split to match, so a block
// past the limit is not told its author does not exist.
const MAX_BATCH_IDS = 100;

// Long enough to cover a page load and block re-mounts, short enough that a profile edited in
// another tab shows up on the next reload.
const CACHE_TTL_MS = 60 * 1000;

const cache = new Map< string, CacheRecord >();
const pendingBatches = new Map< string, Batch >();
const inFlightLists = new Map< string, Promise< AuthorListResult > >();

const authorKey = ( authorId: number | string | undefined, isGuestAuthor: unknown ): string => `${ isGuestAuthor ? 'g' : 'u' }:${ authorId }`;

const readCache = ( key: string ): CacheRecord | null => {
	const hit = cache.get( key );
	if ( ! hit ) {
		return null;
	}
	if ( hit.expires < Date.now() ) {
		cache.delete( key );
		return null;
	}
	return hit;
};

const writeCache = ( key: string, value: unknown ) => cache.set( key, { expires: Date.now() + CACHE_TTL_MS, value } );

/**
 * Request a set of authors, giving a failed request one more try.
 *
 * A request dropped by a busy server is the failure this fetcher exists to soften, and a whole
 * page of blocks now rides on each one, so a single failure should not blank every block.
 *
 * @param params Query params.
 * @return The author records.
 */
const requestAuthors = async ( params: AuthorsRequestParams ): Promise< Author[] > => {
	const path = addQueryArgs( ENDPOINT, params );
	try {
		return await apiFetch< Author[] >( { path } );
	} catch {
		return apiFetch< Author[] >( { path } );
	}
};

/**
 * Resolve every caller covered by one request from its response.
 *
 * @param batchKey Key grouping lookups that share fields and avatar options.
 * @param entries  Queued lookups the request carries.
 * @param request  The request.
 */
const settleEntries = async ( batchKey: string, entries: BatchEntry[], request: Promise< Author[] > ) => {
	try {
		const authors = await request;
		const byKey = new Map( ( authors || [] ).map( author => [ authorKey( author.id, author.is_guest ), author ] ) );
		entries.forEach( entry => {
			const key = authorKey( entry.authorId, entry.isGuestAuthor );
			// A guest id that matched no guest author comes back as the WP user with that id.
			const author = byKey.get( key ) || ( entry.isGuestAuthor ? byKey.get( authorKey( entry.authorId, false ) ) : undefined );
			writeCache( `${ batchKey }|${ key }`, author );
			entry.settlers.forEach( ( { resolve } ) => resolve( author ) );
		} );
	} catch ( error ) {
		entries.forEach( entry => entry.settlers.forEach( ( { reject } ) => reject( error ) ) );
	}
};

/**
 * Send the lookups queued under a batch key, in as many requests as the endpoint's limit needs.
 *
 * @param batchKey Key grouping lookups that share fields and avatar options.
 */
const flushBatch = ( batchKey: string ) => {
	const batch = pendingBatches.get( batchKey );
	pendingBatches.delete( batchKey );
	if ( ! batch ) {
		return;
	}

	const entries = Array.from( batch.entries.values() );
	const userEntries = entries.filter( entry => ! entry.isGuestAuthor );
	const guestEntries = entries.filter( entry => entry.isGuestAuthor );
	const requests = Math.max( Math.ceil( userEntries.length / MAX_BATCH_IDS ), Math.ceil( guestEntries.length / MAX_BATCH_IDS ) );

	for ( let i = 0; i < requests; i++ ) {
		const users = userEntries.slice( i * MAX_BATCH_IDS, ( i + 1 ) * MAX_BATCH_IDS );
		const guests = guestEntries.slice( i * MAX_BATCH_IDS, ( i + 1 ) * MAX_BATCH_IDS );
		const params: AuthorsRequestParams = { fields: batch.fields };
		if ( batch.avatarHideDefault ) {
			params.avatar_hide_default = 1;
		}
		if ( users.length ) {
			params.author_ids = users.map( entry => entry.authorId ).join( ',' );
		}
		if ( guests.length ) {
			params.guest_author_ids = guests.map( entry => entry.authorId ).join( ',' );
		}
		settleEntries( batchKey, [ ...users, ...guests ], requestAuthors( params ) );
	}
};

/**
 * Look up one author, sharing the request with every other lookup made in the same window.
 *
 * @param options
 * @param options.authorId          WP user ID, or guest author post ID.
 * @param options.isGuestAuthor     Whether the ID is a guest author.
 * @param options.fields            Comma-separated fields to request.
 * @param options.avatarHideDefault Whether to hide the default avatar.
 * @return The author record, or undefined when none matched.
 */
export function fetchAuthorById( {
	authorId,
	isGuestAuthor = false,
	fields,
	avatarHideDefault = false,
}: {
	authorId: number | string;
	isGuestAuthor?: boolean;
	fields: string;
	avatarHideDefault?: boolean;
} ): Promise< Author | undefined > {
	const id = parseInt( authorId as string, 10 );
	const batchKey = `${ fields }|${ avatarHideDefault ? 1 : 0 }`;
	const key = authorKey( id, !! isGuestAuthor );

	const cached = readCache( `${ batchKey }|${ key }` );
	if ( cached ) {
		return Promise.resolve( cached.value as Author | undefined );
	}

	let batch = pendingBatches.get( batchKey );
	if ( ! batch ) {
		batch = {
			fields,
			avatarHideDefault,
			entries: new Map(),
			timer: setTimeout( () => flushBatch( batchKey ), BATCH_WINDOW_MS ),
		};
		pendingBatches.set( batchKey, batch );
	}

	let entry = batch.entries.get( key );
	if ( ! entry ) {
		entry = { authorId: id, isGuestAuthor: !! isGuestAuthor, settlers: [] };
		batch.entries.set( key, entry );
	}

	// Capture the now-defined entry in a const so the executor closure keeps the narrowed type.
	const queuedEntry = entry;
	return new Promise< Author | undefined >( ( resolve, reject ) => queuedEntry.settlers.push( { resolve, reject } ) );
}

/**
 * Fetch a page of author suggestions, sharing identical in-flight requests.
 *
 * @param options
 * @param options.search Search string.
 * @param options.offset Offset for pagination.
 * @param options.fields Comma-separated fields to request.
 * @return Authors and the total reported by the endpoint.
 */
export function fetchAuthorList( {
	search,
	offset,
	fields = 'id,name',
}: { search?: string | null; offset?: number; fields?: string } = {} ): Promise< AuthorListResult > {
	const params = { search: search || '', offset: offset || 0, fields };
	const key = `list|${ params.search }|${ params.offset }|${ params.fields }`;
	// Only the initial list is worth keeping: every empty block asks for it at once, while a typed
	// search should see an author created or renamed a moment ago.
	const keep = ! params.search && ! params.offset;

	const cached = keep ? readCache( key ) : null;
	if ( cached ) {
		return Promise.resolve( cached.value as AuthorListResult );
	}
	if ( inFlightLists.has( key ) ) {
		return inFlightLists.get( key ) as Promise< AuthorListResult >;
	}

	// The request starts on the next tick, once it is recorded as in flight, so one that fails
	// before it starts is still cleared instead of being handed to every later caller.
	const request = Promise.resolve()
		.then( () => apiFetch( { parse: false, path: addQueryArgs( ENDPOINT, params ) } ) )
		.then( async response => {
			const total = parseInt( ( response.headers.get( 'x-wp-total' ) || 0 ) as string, 10 );
			const authors = ( await response.json() ) as AuthorListItem[];
			const result = { authors, total };
			if ( keep ) {
				writeCache( key, result );
			}
			return result;
		} )
		.finally( () => inFlightLists.delete( key ) );
	inFlightLists.set( key, request );

	return request;
}

/**
 * Forget cached results and queued lookups. Intended for tests.
 */
export function resetAuthorFetchCache() {
	cache.clear();
	pendingBatches.forEach( batch => clearTimeout( batch.timer ) );
	pendingBatches.clear();
	inFlightLists.clear();
}
