import { createSelector } from '@wordpress/data';
import { applyFilters } from '@wordpress/hooks';

import utils from '../utils';
import { canUseCache } from './cache';

export const isLoading = state => state.meta.loading || state.meta.searching;
export const isRefreshing = state => state.meta.refreshing;

export const isBusy = state =>
	state.meta.loading ||
	state.meta.searching ||
	state.meta.refreshing ||
	state.meta.savingStories ||
	( state.meta.loadingStory && Object.values( state.meta.loadingStory || {} ).some( v => v ) ) ||
	false;

export const isLoadingStory = ( state, id ) => state.meta.loadingStory?.[ id ] ?? false;

export const isSavingStories = state => state.meta.savingStories;

export const isLoadingStories = state => state.meta.loadingStory && Object.values( state.meta.loadingStory ).some( Boolean );

export const isCreatingStory = state => state.meta.isCreatingStory ?? false;

export const isCreatingBudget = state => state.meta.isCreatingBudget ?? false;

export const getProgress = state => state.meta.progress;

export const getFields = state => state.fields;

export const getField = ( state, slug ) => state.fields.find( f => f.slug === slug );

export const isBudgetsLoading = state => state.meta.loadingBudgets || state.meta.searching;

export const getBudgets = createSelector(
	state => {
		const { search, budgetsView } = state;

		let budgets;

		if ( budgetsView.search ) {
			budgets = search.budgets.map( id => state.budgets.find( budget => id === budget.id ) );
		} else {
			budgets = Object.values( state.budgets );
		}

		if ( budgetsView.filters?.length ) {
			budgets = utils.budgets.filter( budgets, budgetsView );
		}

		return budgets;
	},
	state => [ state.search.budgets, state.budgets, state.budgetsView.search, state.budgetsView.filters ]
);

export const getBudgetsCount = state => {
	return {
		active: state.budgets.filter( budget => ! budget.archived ).length,
		archived: state.budgets.filter( budget => budget.archived ).length,
	};
};

export const getTotalBudgetsCount = state => {
	return state.budgets.length;
};

export const getBudgetsView = state => state.budgetsView;

export const getLastRefresh = state => state.meta.lastRefresh;

export const getAllStories = state => Object.values( state.stories );

export const getStories = createSelector(
	state => {
		const { search, view, fields } = state;

		let stories;

		if ( view.search ) {
			stories = search.stories.map( id => ( state.stories[ id ] ? state.stories[ id ] : { id } ) );
		} else {
			stories = Object.values( state.stories );
		}

		if ( view.filters?.length ) {
			stories = utils.stories.filter( stories, fields, view );
		}

		if ( view.sort?.field ) {
			stories = utils.stories.sort( stories, fields, view );
		}
		return stories;
	},
	state => [ state.search.stories, state.stories, state.view.search, state.view.filters, state.view.sort ]
);

export const getStory = ( state, id ) => state.stories[ id ];

export const getView = createSelector(
	state => ( {
		...applyFilters( 'newspack-story-budget.defaultView', {
			type: 'table',
			search: '',
			page: 1,
			perPage: 10,
			fields: [],
			filters: [],
			sort: {
				field: 'last_modified',
				direction: 'desc',
			},
			layout: {
				density: 'compact',
			},
		} ),
		...state.view,
	} ),
	state => [ state.view ]
);

export const canManage = () => ! utils.sites.isRemoteSite();

export const canEditStory = ( state, id ) => canManage() && ( state.meta.stories.can_edit || state.stories[ id ]?.metadata?.can_edit );

export const getStoriesMeta = state => state.meta.stories;

export const getStoryMeta = ( state, id, key ) => ( key ? state.stories[ id ]?.metadata?.[ key ] : state.stories[ id ]?.metadata );

export const getErrors = state => state.errors;

export const getFieldError = ( state, storyId, fieldId ) => state.errors[ `${ storyId }-${ fieldId }` ];

export const getStoryError = ( state, storyId ) => state.errors[ `story-${ storyId }` ];

export const getStoryMetaFetchQueue = state => state.meta.storyMetaFetchQueue;

export const getSaveStoriesError = state => state.errors[ 'save-stories' ];

export const getBudgetStoryMeta = state => {
	const budgetId = Object.values( state.stories )[ 0 ]?.budgets;
	return state.budgets.find( b => b.id === budgetId );
};

export const canRefreshStories = () => canUseCache();
