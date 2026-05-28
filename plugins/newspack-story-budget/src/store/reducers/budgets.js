import { INITIAL_STATE } from '../constants';
import { sortByOrder } from '../../utils/budgets';

export default ( state = INITIAL_STATE.budgets, action ) => {
	switch ( action.type ) {
		case 'BUDGETS_SET':
			return action.payload;
		case 'CREATE_BUDGET_SUCCESS':
		case 'BUDGETS_ADD':
			if ( state.find( budget => budget.id === action.payload.id ) ) {
				return state.map( budget => ( budget.id === action.payload.id ? action.payload : budget ) );
			}
			return [ ...state, action.payload ];
		case 'BUDGET_UPDATE':
			const newState = state.map( budget => ( budget.id === action.payload.id ? action.payload : budget ) );
			return [ ...newState ];
		case 'BUDGETS_ORDER':
			const budgets = state.map( budget => ( {
				...budget,
				order: action.payload.indexOf( budget.id ) + 1,
			} ) );
			return sortByOrder( budgets, action.payload );
		default:
			return state;
	}
};
