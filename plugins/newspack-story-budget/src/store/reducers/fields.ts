import { INITIAL_STATE } from '../constants';
import type { Field, StoreAction } from '../types';

export const actions = {
	FIELDS_SET: 'FIELDS_SET',
} as const;

export default ( state: Field[] = INITIAL_STATE.fields, action: StoreAction ): Field[] => {
	switch ( action.type ) {
		case actions.FIELDS_SET:
			return action.payload;
		default:
			return state;
	}
};
