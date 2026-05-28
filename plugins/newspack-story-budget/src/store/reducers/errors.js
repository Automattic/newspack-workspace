import { INITIAL_STATE } from '../constants';

export default ( state = INITIAL_STATE.errors, action ) => {
	switch ( action.type ) {
		case 'SEARCH_ERROR':
		case 'STORIES_ERROR':
			return {
				...state,
				stories: action.payload.message,
			};
		case 'SAVE_STORIES_ERROR':
			return {
				...state,
				'save-stories': action.payload.message,
			};
		case 'CLEAR_SAVE_STORIES_ERROR':
			return {
				...state,
				'save-stories': null,
			};
		case 'BUDGETS_ERROR':
			return {
				...state,
				budgets: action.payload.message,
			};
		case 'SAVE_STORY_FIELD_ERROR':
			return {
				...state,
				[ `${ action.payload.id }-${ action.payload.slug }` ]: action.payload.message,
			};
		case 'FETCH_STORY_ERROR':
		case 'SAVE_STORY_ERROR':
		case 'PULL_STORY_ERROR':
			return {
				...state,
				[ `story-${ action.payload.id }` ]: action.payload.message,
			};
		case 'UPDATE_BUDGET_ERROR':
			return {
				...state,
				[ `budget-${ action.payload.id }` ]: action.payload.message,
			};
		case 'SET_STORY_ERROR':
			return {
				...state,
				storyError: action.payload,
			};
		case 'SET_BUDGET_ERROR':
			return {
				...state,
				budgetError: action.payload,
			};
		case 'CLEAR_FIELD_ERROR': {
			const newState = { ...state };
			delete newState[ `${ action.payload.id }-${ action.payload.slug }` ];
			return newState;
		}
		case 'CLEAR_STORY_ERROR': {
			const newState = { ...state };
			delete newState[ `story-${ action.payload.id }` ];
			return newState;
		}
		case 'CLEAR_ALL_ERRORS':
			return INITIAL_STATE.errors;
		default:
			return state;
	}
};
