import { INITIAL_STATE } from '../constants';

export const actions = {
	BUDGETS_VIEW_SET: 'BUDGETS_VIEW_SET',
};

export default ( state = INITIAL_STATE.budgetsView, action ) => {
	switch ( action.type ) {
		case actions.BUDGETS_VIEW_SET:
			return {
				...state,
				...action.payload,
			};
		default:
			return state;
	}
};
