import { createSelector } from '@wordpress/data';
import { applyFilters } from '@wordpress/hooks';

import utils from '../utils';
import { canUseCache } from './cache';
import type { Budget, State, StoriesMeta, StoriesView, Story } from './types';

export const isLoading = ( state: State ): boolean => state.meta.loading || state.meta.searching;
export const isRefreshing = ( state: State ): boolean => state.meta.refreshing;

export const isBusy = ( state: State ): boolean =>
	state.meta.loading ||
	state.meta.searching ||
	state.meta.refreshing ||
	!! state.meta.savingStories ||
	!! ( state.meta.loadingStory && Object.values( state.meta.loadingStory || {} ).some( v => v ) ) ||
	false;

export const isLoadingStory = ( state: State, id: string | number ): boolean => state.meta.loadingStory?.[ id ] ?? false;

export const isSavingStories = ( state: State ): boolean | undefined => state.meta.savingStories;

export const isLoadingStories = ( state: State ): boolean | undefined =>
	state.meta.loadingStory && Object.values( state.meta.loadingStory ).some( Boolean );

export const isCreatingStory = ( state: State ): boolean => state.meta.isCreatingStory ?? false;

export const isCreatingBudget = ( state: State ): boolean => state.meta.isCreatingBudget ?? false;

export const getProgress = ( state: State ): number | undefined => state.meta.progress;

export const getFields = ( state: State ) => state.fields;

export const getField = ( state: State, slug: string ) => state.fields.find( f => f.slug === slug );

export const isBudgetsLoading = ( state: State ): boolean => state.meta.loadingBudgets || state.meta.searching;

export const getBudgets = createSelector(
	( state: State ): ( Budget | undefined )[] => {
		const { search, budgetsView } = state;

		let budgets: ( Budget | undefined )[];

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
	( state: State ) => [ state.search.budgets, state.budgets, state.budgetsView.search, state.budgetsView.filters ]
);

export const getBudgetsCount = ( state: State ) => {
	return {
		active: state.budgets.filter( budget => ! budget.archived ).length,
		archived: state.budgets.filter( budget => budget.archived ).length,
	};
};

export const getTotalBudgetsCount = ( state: State ): number => {
	return state.budgets.length;
};

export const getBudgetsView = ( state: State ) => state.budgetsView;

export const getLastRefresh = ( state: State ): number | undefined => state.meta.lastRefresh;

export const getAllStories = ( state: State ): Story[] => Object.values( state.stories );

export const getStories = createSelector(
	( state: State ): Story[] => {
		const { search, view, fields } = state;

		let stories: Story[];

		if ( view.search ) {
			stories = search.stories.map( id => ( state.stories[ id ] ? state.stories[ id ] : { id } ) );
		} else {
			stories = Object.values( state.stories );
		}

		if ( view.filters?.length ) {
			stories = utils.stories.filter( stories, fields, view as Pick< StoriesView, 'filters' > );
		}

		if ( view.sort?.field ) {
			stories = utils.stories.sort( stories, fields, view as Pick< StoriesView, 'sort' > );
		}
		return stories;
	},
	( state: State ) => [ state.search.stories, state.stories, state.view.search, state.view.filters, state.view.sort ]
);

export const getStory = ( state: State, id: string | number ) => state.stories[ id ];

export const getView = createSelector(
	( state: State ): StoriesView => {
		// `applyFilters` (an untyped `@wordpress/hooks` export) returns `unknown`;
		// cast to the shape of the default object passed in, which callers of this
		// filter are expected to preserve.
		const defaultView = applyFilters( 'newspack-story-budget.defaultView', {
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
		} ) as StoriesView;

		return {
			...defaultView,
			...state.view,
		};
	},
	( state: State ) => [ state.view ]
);

export const canManage = (): boolean => ! utils.sites.isRemoteSite();

export const canEditStory = ( state: State, id: string | number ): boolean =>
	canManage() && !! ( state.meta.stories.can_edit || state.stories[ id ]?.metadata?.can_edit );

export const getStoriesMeta = ( state: State ): StoriesMeta => state.meta.stories;

export const getStoryMeta = ( state: State, id: string | number, key?: string ): unknown =>
	key ? state.stories[ id ]?.metadata?.[ key ] : state.stories[ id ]?.metadata;

export const getErrors = ( state: State ) => state.errors;

export const getFieldError = ( state: State, storyId: string | number, fieldId: string ) => state.errors[ `${ storyId }-${ fieldId }` ];

export const getStoryError = ( state: State, storyId: string | number ) => state.errors[ `story-${ storyId }` ];

export const getStoryMetaFetchQueue = ( state: State ) => state.meta.storyMetaFetchQueue;

export const getSaveStoriesError = ( state: State ) => state.errors[ 'save-stories' ];

export const getBudgetStoryMeta = ( state: State ): Budget | undefined => {
	// BUG (pre-existing, not fixed here): `budgets` is only a single id when
	// multi-budget mode is off; when it's on (`Fields::get_budgets()`, PHP),
	// it's an array, so this ends up comparing a `Budget.id` (number) against
	// a whole array and never matches. See the `Story['budgets']` doc comment.
	const budgetId = Object.values( state.stories )[ 0 ]?.budgets;
	return state.budgets.find( b => b.id === budgetId );
};

export const canRefreshStories = (): boolean => canUseCache();
