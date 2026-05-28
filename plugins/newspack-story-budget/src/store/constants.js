export const NAMESPACE = 'newspack-story-budget';

export const INITIAL_STATE = {
	budgets: [],
	stories: {},
	search: {
		stories: [],
		budgets: [],
	},
	fields: [],
	errors: {},
	meta: {
		loading: false,
		refreshing: false,
		searching: false,
		storyMetaFetchQueue: {},
		stories: {
			can_edit: false,
		},
		isCreatingStory: false,
		isCreatingBudget: false,
	},
	budgetsView: {
		search: '',
		page: 1,
		perPage: 10,
		filters: [],
	},
};

export const ALWAYS_FETCH_STORIES = window.newspackStoryBudget?.alwaysFetchStories ?? false;

export const REFRESH_CACHE = window.newspackStoryBudget?.refreshCache ?? false;

export const NOTICE_CONTEXT = 'newspack-story-budget';
