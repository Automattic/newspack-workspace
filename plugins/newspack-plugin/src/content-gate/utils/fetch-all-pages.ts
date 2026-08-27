/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

/**
 * Fetch every page of a REST collection.
 *
 * The access rule pickers map the value they hold back through the options they
 * loaded, both to render tokens and to build the value `onChange` returns. A
 * truncated list therefore does more than hide the entries past the first page:
 * an item selected earlier and no longer in the list drops out of the next value
 * the operator saves, which on a rule that grants access when empty widens the
 * gate rather than narrowing it.
 *
 * @param path    The REST path, with or without a query string.
 * @param perPage Items per request.
 *
 * @return Every item in the collection.
 */
export async function fetchAllPages< T >( path: string, perPage = 100 ): Promise< T[] > {
	const items: T[] = [];
	let page = 1;
	let totalPages = 1;
	do {
		// `parse: false` to read X-WP-TotalPages, which is the only statement the
		// response makes about what it left out.
		const response = ( await apiFetch( {
			path: addQueryArgs( path, { per_page: perPage, page } ),
			parse: false,
		} ) ) as Response;
		totalPages = parseInt( response.headers.get( 'X-WP-TotalPages' ) ?? '1', 10 ) || 1;
		items.push( ...( ( await response.json() ) as T[] ) );
		page++;
	} while ( page <= totalPages );
	return items;
}
