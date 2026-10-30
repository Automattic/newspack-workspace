/**
 * Internal dependencies
 */
import { getSettledSendLists, validateNewsletter } from './utils';

// Guards the condition that decides whether a resolution check may run at all.
// Getting this wrong in either direction is worse than the bug it supports: too
// eager and Send is disabled on valid newsletters, too lazy and it never fires.
// Why the gate is `hasRetrievedData` and why it latches: see the docblock on
// `getSettledSendLists`.
describe( 'getSettledSendLists', () => {
	const lists = [ { id: '42', label: 'Weekly' } ];
	const retrieved = { newsletterData: { lists }, hasRetrievedData: true, isRetrievingData: false, isRetrievingLists: false };

	it( 'returns the lists once the newsletter data has been retrieved', () => {
		// Setting `hasRetrievedLists` false here fails if someone re-adds the
		// sidebar's flag to the gate.
		expect( getSettledSendLists( retrieved ) ).toEqual( lists );
		expect( getSettledSendLists( { ...retrieved, hasRetrievedLists: false } ) ).toEqual( lists );
	} );

	it( 'withholds the lists until a retrieve has succeeded', () => {
		expect( getSettledSendLists( { ...retrieved, hasRetrievedData: false } ) ).toBeNull();
	} );

	it.each( [
		[ 'a retrieve is in flight', { isRetrievingData: true } ],
		[ 'a send-list fetch is in flight', { isRetrievingLists: true } ],
	] )( 'keeps a settled roster while %s', ( _label, state ) => {
		// `retrieve` re-runs after every save. Withholding the answer for the
		// duration of each one re-enabled Send on the newsletters this check
		// exists to block, for as long as the request took.
		expect( getSettledSendLists( { ...retrieved, ...state } ) ).toEqual( lists );
	} );

	it( 'reports a settled store with no lists key as an empty list', () => {
		expect( getSettledSendLists( { ...retrieved, newsletterData: {} } ) ).toEqual( [] );
	} );

	it( 'reports a settled empty roster as an empty list, not as unknown', () => {
		expect( getSettledSendLists( { ...retrieved, newsletterData: { lists: [] } } ) ).toEqual( [] );
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
		expect( errors ).toContain( 'The saved list isn’t available in the connected email service provider. Choose a new one before sending.' );
	} );

	it( 'does not report an unresolved list before the lists have been fetched', () => {
		expect( validateNewsletter( validMeta ) ).toEqual( [] );
		expect( validateNewsletter( validMeta, null ) ).toEqual( [] );
	} );

	it( 'reports an unresolved list when the provider has no lists at all', () => {
		// A settled empty roster is the one case where the stored id certainly
		// cannot resolve, so it must block rather than be treated as unknown.
		expect( validateNewsletter( validMeta, [] ) ).toContain(
			'The saved list isn’t available in the connected email service provider. Choose a new one before sending.'
		);
	} );

	it( 'names the list the way the connected provider does', () => {
		// Every other case here runs with the global deleted, so only the fallback
		// is exercised: without this the label lookup could be dropped and the
		// suite would stay green.
		window.newspack_newsletters_data = { labels: { list: 'audience' } };
		expect( validateNewsletter( validMeta, [] ) ).toContain(
			'The saved audience isn’t available in the connected email service provider. Choose a new one before sending.'
		);
	} );

	it( 'skips every check for the manual provider', () => {
		window.newspack_newsletters_data = { service_provider: 'manual' };
		expect( validateNewsletter( { send_list_id: '99' }, [ { id: '42', label: 'Weekly' } ] ) ).toEqual( [] );
	} );
} );
