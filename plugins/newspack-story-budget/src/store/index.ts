import { createReduxStore, register, dispatch } from '@wordpress/data';
import { controls } from './controls';

import { NAMESPACE, INITIAL_STATE } from './constants';
import reducer from './reducers';
import * as actions from './actions';
import * as selectors from './selectors';
import * as resolvers from './resolvers';

const store = createReduxStore( NAMESPACE, {
	reducer,
	controls,
	actions,
	selectors,
	resolvers,
	initialState: INITIAL_STATE,
} );

register( store );

// Initialize entities config. `store` (the descriptor returned by `createReduxStore()` above) is
// used instead of `NAMESPACE` here so `dispatch()` resolves through its typed `StoreDescriptor`
// overload rather than the bare-string fallback (which types as `unknown`).
dispatch( store ).initializeEntitiesConfig();
