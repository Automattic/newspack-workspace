import { INITIAL_STATE } from '../constants';

export const actions = {
	FETCH_START: 'FETCH_START',
	FETCH_END: 'FETCH_END',
	FETCH_PROGRESS: 'FETCH_PROGRESS',
	FETCH_SUCCESS: 'FETCH_SUCCESS',
	REFRESH_START: 'REFRESH_START',
	REFRESH_SUCCESS: 'REFRESH_SUCCESS',
	REFRESH_END: 'REFRESH_END',
};

export default ( state = INITIAL_STATE.meta, action ) => {
	switch ( action.type ) {
		case 'HYDRATE':
			if ( action.payload.key === 'meta' ) {
				return {
					...INITIAL_STATE.meta,
					lastRefresh: action.payload?.data?.lastRefresh || undefined,
				};
			}
			return state;
		case actions.FETCH_START:
			return {
				...state,
				loading: true,
			};
		case actions.FETCH_END:
			return {
				...state,
				loading: false,
			};
		case actions.FETCH_PROGRESS:
			return {
				...state,
				progress: action.payload.progress,
			};
		case actions.FETCH_SUCCESS:
			return {
				...state,
				lastRefresh: action.payload.timestamp,
			};
		case actions.REFRESH_START:
			return {
				...state,
				refreshing: action.payload.silent ? false : true,
			};
		case actions.REFRESH_SUCCESS:
			return {
				...state,
				lastRefresh: action.payload.timestamp,
			};
		case actions.REFRESH_END:
			return {
				...state,
				refreshing: false,
			};
		case 'SEARCH_START':
			return {
				...state,
				searching: true,
			};
		case 'SEARCH_SUCCESS':
		case 'SEARCH_ERROR':
			return {
				...state,
				searching: false,
			};
		case 'FETCH_STORY_START':
		case 'SAVE_STORY_START':
		case 'SAVE_STORY_FIELD_START':
			return {
				...state,
				loadingStory: {
					...state.loadingStory,
					[ action.payload.id ]: true,
				},
			};
		case 'FETCH_STORY_SUCCESS':
		case 'FETCH_STORY_ERROR':
		case 'SAVE_STORY_SUCCESS':
		case 'SAVE_STORY_ERROR':
		case 'SAVE_STORY_FIELD_SUCCESS':
		case 'SAVE_STORY_FIELD_ERROR':
			return {
				...state,
				loadingStory: {
					...state.loadingStory,
					[ action.payload.id ]: false,
				},
			};
		case 'STORIES_META_SET':
			return {
				...state,
				stories: action.payload,
			};
		case 'STORY_META_FETCH_QUEUE':
			return {
				...state,
				storyMetaFetchQueue: {
					...state.storyMetaFetchQueue,
					[ action.payload.id ]: true,
				},
			};
		case 'STORY_META_BATCH_START':
		case 'STORY_META_BATCH_SET':
			return {
				...state,
				storyMetaFetchQueue: {},
			};
		case 'SAVE_STORIES_START':
			return {
				...state,
				savingStories: true,
				loadingStory: {
					...state.loadingStory,
					...action.payload.ids.reduce( ( acc, id ) => {
						acc[ id ] = true;
						return acc;
					}, {} ),
				},
			};
		case 'SAVE_STORIES_SUCCESS':
		case 'SAVE_STORIES_ERROR':
			return {
				...state,
				savingStories: false,
				loadingStory: {
					...state.loadingStory,
					...action.payload.ids.reduce( ( acc, id ) => {
						acc[ id ] = false;
						return acc;
					}, {} ),
				},
			};
		case 'FETCH_BUDGETS_START':
			return {
				...state,
				loadingBudgets: true,
			};
		case 'FETCH_BUDGETS_END':
			return {
				...state,
				loadingBudgets: false,
			};
		case 'CREATE_STORY_START':
			return {
				...state,
				isCreatingStory: true,
			};
		case 'CREATE_STORY_SUCCESS':
		case 'CREATE_STORY_ERROR':
			return {
				...state,
				isCreatingStory: false,
			};
		case 'CREATE_BUDGET_START':
			return {
				...state,
				isCreatingBudget: true,
			};
		case 'CREATE_BUDGET_SUCCESS':
		case 'CREATE_BUDGET_ERROR':
			return {
				...state,
				isCreatingBudget: false,
			};
		default:
			return state;
	}
};
