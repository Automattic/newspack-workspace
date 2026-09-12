/**
 * Internal dependencies
 */
import { getSettledSendLists, validateNewsletter } from './utils';

// Guards the condition that decides whether a resolution check may run at all.
// Getting this wrong in either direction is worse than the bug it supports: too
// eager and Send is disabled on valid newsletters, too lazy and it never fires.
//
// The gate is `hasRetrievedData` — the `retrieve` call — because that is the
// request that asks the ESP for the stored id by id. `hasRetrievedLists` tracks
// a different request that only the sidebar makes; gating on it would leave the
// check asleep whenever the sidebar had not fetched.
describe( 'getSettledSendLists', () => {
	const lists = [ { id: '42', label: 'Weekly' } ];
	const retrieved = { newsletterData: { lists }, hasRetrievedData: true, isRetrievingData: false, isRetrievingLists: false };

	it( 'returns the lists once the newsletter data has been retrieved', () => {
		// `hasRetrievedLists` is the sidebar's flag and is deliberately absent from
		// the gate: the send button must not wait on a panel the author may never
		// open. Setting it false here fails if someone re-adds it to the gate.
		expect( getSettledSendLists( retrieved ) ).toEqual( lists );
		expect( getSettledSendLists( { ...retrieved, hasRetrievedLists: false } ) ).toEqual( lists );
	} );

	it.each( [
		[ 'the newsletter data is being retrieved', { isRetrievingData: true } ],
		[ 'a send-list fetch is in flight', { isRetrievingLists: true } ],
		[ 'the newsletter data has not been retrieved', { hasRetrievedData: false } ],
	] )( 'withholds the lists while %s', ( _label, state ) => {
		expect( getSettledSendLists( { ...retrieved, ...state } ) ).toBeNull();
	} );

	it( 'reports a settled store with no lists key as an empty list', () => {
		expect( getSettledSendLists( { ...retrieved, newsletterData: {} } ) ).toEqual( [] );
	} );

	it( 'reports a settled empty roster as an empty list, not as unknown', () => {
		// An account with no lists is an answer: nothing can resolve against it.
		// Collapsing that into null would skip the check in the one case where it
		// is certain to fail.
		expect( getSettledSendLists( { ...retrieved, newsletterData: { lists: [] } } ) ).toEqual( [] );
	} );

	it( 'tolerates an empty store state', () => {
		expect( getSettledSendLists( {} ) ).toBeNull();
	} );
} );

// A newsletter keeps the list it was saved with when the site switches ESPs, so
// `send_list_id` can hold an id the connected provider has never heard of. The
// send guard has to tell that apart from a list it simply hasn't fetched yet:
// `newsletterData.lists` is an accumulating cache, not the provider's full
// roster, so an id missing from it means nothing until the fetch has settled.
describe( 'validateNewsletter', () => {
	const validMeta = { senderEmail: 'ed@example.com', senderName: 'Ed', send_list_id: '42' };
	const lists = [ { id: '42', label: 'Weekly' } ];

	afterEach( () => {
		delete window.newspack_newsletters_data;
	} );

	it( 'passes a newsletter whose saved list resolves against the fetched lists', () => {
		expect( validateNewsletter( validMeta, lists ) ).toEqual( [] );
	} );

	it( 'matches a saved list id against a numeric id from the provider', () => {
		expect( validateNewsletter( validMeta, [ { id: 42, label: 'Weekly' } ] ) ).toEqual( [] );
	} );

	it( 'reports a saved list that is absent from the fetched lists', () => {
		const errors = validateNewsletter( validMeta, [ { id: '99', label: 'Somebody else' } ] );
		expect( errors ).toContain( 'The saved list isn’t available in the connected email service provider.' );
	} );

	it( 'does not report an unresolved list before the lists have been fetched', () => {
		expect( validateNewsletter( validMeta ) ).toEqual( [] );
		expect( validateNewsletter( validMeta, null ) ).toEqual( [] );
	} );

	it( 'reports an unresolved list when the provider has no lists at all', () => {
		// A settled empty roster is the one case where the stored id certainly
		// cannot resolve, so it must block rather than be treated as unknown.
		expect( validateNewsletter( validMeta, [] ) ).toContain( 'The saved list isn’t available in the connected email service provider.' );
	} );

	it( 'skips every check for the manual provider', () => {
		window.newspack_newsletters_data = { service_provider: 'manual' };
		expect( validateNewsletter( { send_list_id: '99' }, [ { id: '42', label: 'Weekly' } ] ) ).toEqual( [] );
	} );
} );
