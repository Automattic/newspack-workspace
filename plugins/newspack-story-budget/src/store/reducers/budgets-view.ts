import { INITIAL_STATE } from '../constants';
import type { BudgetsView, StoreAction } from '../types';

export const actions = {
	BUDGETS_VIEW_SET: 'BUDGETS_VIEW_SET',
} as const;

export default ( state: BudgetsView = INITIAL_STATE.budgetsView, action: StoreAction ): BudgetsView => {
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
