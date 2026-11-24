import meta from './meta';
import { getLastRefresh } from '../selectors';

const hydrate = data => meta( undefined, { type: 'HYDRATE', payload: { key: 'meta', data } } );

describe( 'meta reducer hydration', () => {
	it( 'drops a refresh time cached from the browser clock, so the first load is a full fetch', () => {
		const state = hydrate( { lastRefresh: 1792690235000 } );
		expect( getLastRefresh( { meta: state } ) ).toBeUndefined();
	} );

	it( 'restores a refresh time cached from the server clock', () => {
		const state = hydrate( { lastRefreshServerTime: 1792690060000 } );
		expect( getLastRefresh( { meta: state } ) ).toBe( 1792690060000 );
	} );
} );
