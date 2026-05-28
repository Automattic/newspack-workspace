import { INITIAL_STATE } from '../constants';

export const actions = {
	FIELDS_SET: 'FIELDS_SET',
};

export default ( state = INITIAL_STATE.fields, action ) => {
	switch ( action.type ) {
		case actions.FIELDS_SET:
			return action.payload;
		default:
			return state;
	}
};
