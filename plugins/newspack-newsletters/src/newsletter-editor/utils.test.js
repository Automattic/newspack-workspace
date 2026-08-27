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
// a different request that only the sidebar makes, and gating on it left the
// check permanently asleep whenever the sidebar had not fetched.
describe( 'getSettledSendLists', () => {
	const lists = [ { id: '42', label: 'Weekly' } ];
	const retrieved = { newsletterData: { lists }, hasRetrievedData: true, isRetrievingData: false, isRetrievingLists: false };

	it( 'returns the lists once the newsletter data has been retrieved', () => {
		expect( getSettledSendLists( retrieved ) ).toEqual( lists );
	} );

	it( 'returns the lists even though no send-list fetch has run', () => {
		// The sidebar populates `hasRetrievedLists`; the send button must not
		// wait on a panel the author may never open.
		expect( getSettledSendLists( { ...retrieved, hasRetrievedLists: false } ) ).toEqual( lists );
	} );

	it( 'withholds the lists while the newsletter data is being retrieved', () => {
		expect( getSettledSendLists( { ...retrieved, isRetrievingData: true } ) ).toBeNull();
	} );

	it( 'withholds the lists while a send-list fetch is in flight', () => {
		expect( getSettledSendLists( { ...retrieved, isRetrievingLists: true } ) ).toBeNull();
	} );

	it( 'withholds the lists before the newsletter data has been retrieved', () => {
		expect( getSettledSendLists( { ...retrieved, hasRetrievedData: false } ) ).toBeNull();
	} );

	it( 'returns null rather than undefined when the store holds no lists yet', () => {
		expect( getSettledSendLists( { ...retrieved, newsletterData: {} } ) ).toBeNull();
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

	it( 'reports a missing list when no list is set', () => {
		expect( validateNewsletter( { ...validMeta, send_list_id: '' }, lists ) ).toContain( 'Missing required list.' );
	} );

	it( 'reports missing sender info when either sender field is blank', () => {
		expect( validateNewsletter( { ...validMeta, senderName: '' }, lists ) ).toContain( 'Missing required sender info.' );
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

	it( 'does not report an unresolved list when the fetch returned nothing', () => {
		expect( validateNewsletter( validMeta, [] ) ).toEqual( [] );
	} );

	it( 'skips every check for the manual provider', () => {
		window.newspack_newsletters_data = { service_provider: 'manual' };
		expect( validateNewsletter( { send_list_id: '99' }, [ { id: '42', label: 'Weekly' } ] ) ).toEqual( [] );
	} );
} );
