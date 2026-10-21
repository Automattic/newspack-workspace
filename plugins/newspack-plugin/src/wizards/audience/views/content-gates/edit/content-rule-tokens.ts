/**
 * Value handling for the content-rule token field.
 *
 * A rule's stored value is a list of IDs whose type depends on the writer: the
 * editor saves strings, PHP callers (the migration CLI among them) save
 * integers. Server-side matching casts to int, so both gate correctly; the
 * editor must treat them alike too, or an integer-saved restriction renders as
 * an empty field and vanishes on the next edit.
 */

export type ContentRuleTokenItem = { value: string; label: string };

/**
 * The rule's IDs as strings, the form the lookup endpoint and the token labels use.
 */
export function normalizeRuleIds( value: unknown ): string[] {
	if ( ! Array.isArray( value ) ) {
		return [];
	}
	return value.filter( id => id !== null && id !== undefined && id !== '' ).map( id => String( id ) );
}

/**
 * Labels for the stored IDs the lookup has named so far, in item order.
 */
export function selectedTokenLabels( value: unknown, items: ContentRuleTokenItem[] ): string[] {
	const ids = normalizeRuleIds( value );
	return [ ...new Set( items.filter( item => ids.includes( item.value ) ).map( item => item.label ) ) ];
}

/**
 * The stored value after the user edits the visible tokens.
 *
 * Only IDs the lookup has named are visible, so only those can be removed by
 * the user. An ID the lookup could not name (a private post, a lookup that has
 * not returned yet, a transient error) stays in the value untouched, in its
 * original position; newly picked tokens are appended.
 */
export function mergeTokenSelection( value: unknown, items: ContentRuleTokenItem[], newTokens: ( string | { value: string } )[] ): string[] {
	const ids = normalizeRuleIds( value );
	const resolve = ( token: string | { value: string } ) => {
		const raw = typeof token === 'string' ? token : token.value;
		const [ id ] = raw.split( ':' );
		return items.find( item => item.value === id );
	};
	const kept = new Set(
		newTokens
			.map( resolve )
			.filter( ( item ): item is ContentRuleTokenItem => item !== undefined )
			.map( item => item.value )
	);
	const known = new Set( items.map( item => item.value ) );
	const remaining = ids.filter( id => ! known.has( id ) || kept.has( id ) );
	const added = [ ...kept ].filter( id => ! ids.includes( id ) );
	return [ ...remaining, ...added ];
}
