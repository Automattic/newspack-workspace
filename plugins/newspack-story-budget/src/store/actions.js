import { __ } from '@wordpress/i18n';
import { apiFetch } from './controls';
import { resolveSelect, select, dispatch } from '@wordpress/data';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import { STORAGE_KEYS, getCache, canUseCache } from './cache';
import { NAMESPACE, REFRESH_CACHE } from './constants';
import { isBudgetStories, getCurrentBudget } from '../utils/budgets';

export function* initializeEntitiesConfig() {
	// Hydrate state from cache if available.
	if ( canUseCache() ) {
		for ( const key in STORAGE_KEYS ) {
			const stored = getCache( key );
			if ( stored?.data ) {
				yield {
					type: 'HYDRATE',
					payload: {
						key,
						timestamp: stored.timestamp,
						data: stored.data,
					},
				};
			}
		}
	}

	yield resolveSelect( NAMESPACE ).getFields();
	yield resolveSelect( NAMESPACE ).getBudgets();
	yield resolveSelect( NAMESPACE ).getStoriesMeta();

	// Periodically refresh cacheable state.
	if ( canUseCache() && REFRESH_CACHE ) {
		for ( const key in STORAGE_KEYS ) {
			const cache = STORAGE_KEYS[ key ];
			if ( cache?.actions?.length && cache?.ttl ) {
				setInterval( () => cache.actions.forEach( action => dispatch( NAMESPACE )[ action ]() ), cache.ttl );
			}
		}
	}
}

export function setSearching() {
	return {
		type: 'SEARCH_START',
	};
}

function refreshAbortController( controller ) {
	if ( controller ) {
		controller.abort();
	}
	return typeof AbortController === 'undefined' ? undefined : new AbortController();
}

let searchAbortController = refreshAbortController();

export function* search( str ) {
	if ( ! str ) {
		return {
			type: 'SEARCH_CLEAR',
			payload: { type: 'stories' },
		};
	}

	yield { type: 'SEARCH_START' };
	searchAbortController = refreshAbortController( searchAbortController );
	try {
		const result = yield apiFetch( {
			path: '/stories/search',
			data: { s: str },
			method: 'POST',
			signal: searchAbortController?.signal,
		} );
		return {
			type: 'SEARCH_SUCCESS',
			payload: {
				type: 'stories',
				ids: result.story_ids,
			},
		};
	} catch ( error ) {
		if ( error.name === 'AbortError' ) {
			return;
		}
		return {
			type: 'SEARCH_ERROR',
			payload: error,
		};
	}
}

export function setView( args ) {
	const view = select( NAMESPACE ).getView();
	if ( args.fields && view.fields ) {
		const fields = select( NAMESPACE ).getFields();
		args.fields = args.fields.sort( ( a, b ) => {
			// Allow visible columns to be sorted.
			if ( -1 < view.fields.indexOf( a ) ) {
				return 0;
			}
			// When displaying hidden columns, sort by default order.
			return fields.find( f => f.slug === a )?.default_order - fields.find( f => f.slug === b )?.default_order;
		} );
	}
	return {
		type: 'VIEW_SET',
		payload: args,
	};
}

export function* fetchFields() {
	try {
		const result = yield apiFetch( { path: '/fields' } );
		return {
			type: 'FIELDS_SET',
			payload: result,
		};
	} catch ( error ) {
		return {
			type: 'FIELDS_ERROR',
			payload: error,
		};
	}
}

export function* fetchBudgets() {
	yield { type: 'FETCH_BUDGETS_START' };
	try {
		const result = yield apiFetch( { path: '/budgets' } );
		const { budgets, total } = result;
		while ( budgets.length < total ) {
			const next = yield apiFetch( {
				path: `/budgets?offset=${ budgets.length }`,
			} );
			budgets.push( ...next.budgets );
		}
		return {
			type: 'BUDGETS_SET',
			payload: budgets,
		};
	} catch ( error ) {
		return {
			type: 'BUDGETS_ERROR',
			payload: error,
		};
	} finally {
		yield { type: 'FETCH_BUDGETS_END' };
	}
}

/**
 * Create a new budget.
 *
 * @param {Object} budget      Budget data.
 * @param {string} budget.name Budget name.
 *
 * @return {Object} Action object.
 */
export function* createBudget( budget ) {
	yield {
		type: 'CREATE_BUDGET_START',
		payload: { budget },
	};

	try {
		const result = yield apiFetch( {
			path: '/budgets',
			method: 'POST',
			data: budget,
		} );
		yield {
			type: 'CREATE_BUDGET_SUCCESS',
			payload: result,
		};
		return result;
	} catch ( error ) {
		const message = error?.message || __( 'Error creating budget.', 'newspack-story-budget' );

		yield {
			type: 'CREATE_BUDGET_ERROR',
			payload: { message },
		};
	}
}

/**
 * Create a new story.
 *
 * @param {Object} storyData Story data.
 *
 * @return {Object} Action object.
 */
export function* createStory( storyData ) {
	yield {
		type: 'CREATE_STORY_START',
		payload: { storyData },
	};

	// If a new budget is being created, create it first.
	if ( storyData.newBudgetName ) {
		const budgetResult = yield createBudget( {
			name: storyData.newBudgetName,
		} );
		if ( budgetResult?.id ) {
			storyData.budgets = [ budgetResult.id ];
		} else {
			return {
				type: 'CREATE_STORY_ERROR',
				payload: { message: budgetResult.message },
			};
		}
	}

	// eslint-disable-next-line @typescript-eslint/no-unused-vars
	const { newBudgetName, ...storyDataToSend } = storyData;

	// fallback to title if name is not provided.
	if ( ! storyDataToSend.title || ! storyDataToSend.title.trim() ) {
		storyDataToSend.title = storyDataToSend.name.trim();
	}

	try {
		const result = yield apiFetch( {
			path: '/stories',
			method: 'POST',
			data: storyDataToSend,
		} );
		yield {
			type: 'CREATE_STORY_SUCCESS',
			payload: result,
		};
		return result;
	} catch ( error ) {
		const message = error?.message || __( 'Error creating story.', 'newspack-story-budget' );

		yield {
			type: 'CREATE_STORY_ERROR',
			payload: { message },
		};
	}
}

/**
 * Fetch all stories from the API.
 *
 * @return {Object} Action object.
 */
export function* fetchStories() {
	yield {
		type: 'FETCH_PROGRESS',
		payload: { progress: 0 }, // Start progress bar.
	};
	yield { type: 'FETCH_START' };
	const timestamp = Date.now(); // eslint-disable-line @wordpress/no-unused-vars-before-return
	try {
		const path = isBudgetStories() ? addQueryArgs( `/budgets/${ getCurrentBudget() }/stories` ) : '/stories';
		const result = yield apiFetch( { path } );
		const { stories, total } = result;
		yield {
			type: 'FETCH_PROGRESS',
			payload: { result, progress: stories.length / total },
		};
		while ( stories.length < total ) {
			const next = yield apiFetch( {
				path: addQueryArgs( '/stories', {
					offset: stories.length,
				} ),
			} );
			stories.push( ...next.stories );
			yield {
				type: 'FETCH_PROGRESS',
				payload: { result: next, progress: stories.length / total },
			};
		}
		yield { type: 'FETCH_SUCCESS', payload: { timestamp } };
		return {
			type: 'STORIES_SET',
			payload: stories.reduce( ( acc, story ) => {
				acc[ story.id ] = story;
				return acc;
			}, {} ),
		};
	} catch ( error ) {
		const message = error?.message || __( 'Error fetching stories. Please try again.', 'newspack-story-budget' );
		return {
			type: 'STORIES_ERROR',
			payload: { message },
		};
	} finally {
		yield { type: 'FETCH_END' };
	}
}

/**
 * Refresh stories modified since a certain timestamp from the API.
 *
 * @param {boolean} silent Whether to suppress errors and loading state.
 *
 * @return {Object} Action object.
 */
export function* refreshStories( silent = true ) {
	const lastRefresh = select( NAMESPACE ).getLastRefresh();

	// If no last refresh timestamp is found, bail or refresh the page.
	if ( ! lastRefresh ) {
		if ( ! silent ) {
			window.location.reload();
		}
		return;
	}

	const timestamp = Date.now(); // eslint-disable-line @wordpress/no-unused-vars-before-return
	yield { type: 'REFRESH_START', payload: { silent } };
	try {
		const params = { metadata: true };
		if ( lastRefresh ) {
			params.since = Math.floor( lastRefresh / 1000 ); // UNIX timestamp in seconds.
		}
		const result = yield apiFetch( {
			path: addQueryArgs( '/stories', params ),
		} );
		const { stories, total } = result;
		while ( stories.length < total ) {
			params.offset = stories.length;
			const next = yield apiFetch( {
				path: addQueryArgs( '/stories', params ),
			} );
			stories.push( ...next.stories );
		}
		yield { type: 'REFRESH_SUCCESS', payload: { timestamp } };
		if ( ! stories.length ) {
			return;
		}
		return {
			type: 'STORIES_APPEND',
			payload: stories.reduce( ( acc, story ) => {
				acc[ story.id ] = story;
				return acc;
			}, {} ),
		};
	} catch ( error ) {
		if ( silent ) {
			return;
		}
		const message = error?.message || __( 'Error refreshing stories. Please try again.', 'newspack-story-budget' );
		return {
			type: 'STORIES_ERROR',
			payload: { message },
		};
	} finally {
		yield { type: 'REFRESH_END' };
	}
}

export function* fetchStoriesMeta() {
	try {
		const result = yield apiFetch( {
			path: '/stories/meta',
			method: 'GET',
		} );
		return {
			type: 'STORIES_META_SET',
			payload: result,
		};
	} catch ( error ) {
		return {
			type: 'STORIES_META_ERROR',
			payload: error,
		};
	}
}

export function* fetchStory( id ) {
	yield { type: 'FETCH_STORY_START', payload: { id } };
	try {
		const result = yield apiFetch( {
			path: `/stories/${ id }`,
		} );
		yield { type: 'FETCH_STORY_SUCCESS', payload: { id } };
		return {
			type: 'STORIES_APPEND',
			payload: { [ id ]: result },
		};
	} catch ( error ) {
		const message = error?.message || __( 'Error fetching story. Please try again.', 'newspack-story-budget' );
		return {
			type: 'FETCH_STORY_ERROR',
			payload: { id, message },
		};
	}
}

export function* fetchStoryMeta( id ) {
	try {
		const result = yield apiFetch( {
			path: `/stories/${ id }/meta`,
			method: 'GET',
		} );
		return {
			type: 'STORY_META_SET',
			payload: { id, result },
		};
	} catch ( error ) {
		return {
			type: 'STORY_META_ERROR',
			payload: error,
		};
	}
}

export function* fetchStoryMetaBatch( storyIds ) {
	yield { type: 'STORY_META_BATCH_START' };
	try {
		const result = yield apiFetch( {
			path: '/stories/meta/batch',
			method: 'POST',
			data: { ids: storyIds },
		} );
		// Remove stories that are invalid.
		for ( const [ id, item ] of Object.entries( result ) ) {
			if ( item.errors?.invalid_story ) {
				yield {
					type: 'STORIES_REMOVE',
					payload: id,
				};
				delete result[ id ];
			}
		}
		return {
			type: 'STORY_META_BATCH_SET',
			payload: result,
		};
	} catch ( error ) {
		return {
			type: 'STORY_META_BATCH_ERROR',
			payload: error,
		};
	}
}

let storyMetaFetchTimeout;

const debouncedFetchStoryMetaBatch = () => {
	clearTimeout( storyMetaFetchTimeout );
	storyMetaFetchTimeout = setTimeout( () => {
		const storyIds = Object.keys( select( NAMESPACE ).getStoryMetaFetchQueue() );
		if ( storyIds.length > 0 ) {
			dispatch( NAMESPACE ).fetchStoryMetaBatch( storyIds );
		}
	}, 300 );
};

export function queueStoryMetaFetch( id ) {
	debouncedFetchStoryMetaBatch();
	return {
		type: 'STORY_META_FETCH_QUEUE',
		payload: { id },
	};
}

export function* saveStory( id, story ) {
	yield { type: 'SAVE_STORY_START', payload: { id, story } };
	try {
		const result = yield apiFetch( {
			path: `/stories/${ id }`,
			method: 'POST',
			data: story,
		} );
		yield { type: 'STORIES_APPEND', payload: { [ id ]: result } };
		return {
			type: 'SAVE_STORY_SUCCESS',
			payload: result,
		};
	} catch ( error ) {
		const message = error?.message || __( 'Error saving story.', 'newspack-story-budget' );
		return { type: 'SAVE_STORY_ERROR', payload: { id, story, message } };
	}
}

export function* saveStoryField( id, slug, value ) {
	yield { type: 'SAVE_STORY_FIELD_START', payload: { id, slug, value } };
	try {
		const result = yield apiFetch( {
			path: `/stories/${ id }/${ slug }`,
			method: 'POST',
			data: { value },
		} );
		yield { type: 'STORIES_APPEND', payload: { [ id ]: result } };
		return {
			type: 'SAVE_STORY_FIELD_SUCCESS',
			payload: { id, slug, value: result[ slug ] },
		};
	} catch ( error ) {
		const message = error?.message || __( 'Error saving field.', 'newspack-story-budget' );
		return {
			type: 'SAVE_STORY_FIELD_ERROR',
			payload: { id, slug, value, message },
		};
	}
}

/**
 * Save one or multiple stories.
 *
 * @param {Array} ids    Array of story IDs.
 * @param {Array} fields Array of fields to update.
 *
 * @return {Object} Action object.
 */
export function* saveStories( ids, fields ) {
	yield { type: 'SAVE_STORIES_START', payload: { ids, fields } };
	try {
		const result = yield apiFetch( {
			path: '/stories/update',
			method: 'POST',
			data: { ids, fields },
		} );
		if ( result.stories?.length ) {
			yield {
				type: 'STORIES_APPEND',
				payload: result.stories.reduce( ( acc, story ) => {
					acc[ story.id ] = story;
					return acc;
				}, {} ),
			};
		}
		return {
			type: 'SAVE_STORIES_SUCCESS',
			payload: { ids, fields, result },
		};
	} catch ( error ) {
		return {
			type: 'SAVE_STORIES_ERROR',
			payload: { ids, fields, message: error?.message },
		};
	}
}

/**
 * Clears all errors or specific errors
 *
 * @param {string|null} storyId The story ID to clear errors for, or null for all
 * @param {string|null} fieldId The field ID to clear errors for, or null for all
 *
 * @return {Object} Action object
 */
export function clearErrors( storyId = null, fieldId = null ) {
	if ( ! storyId ) {
		return {
			type: 'CLEAR_ALL_ERRORS',
		};
	}
	return {
		type: fieldId ? 'CLEAR_FIELD_ERROR' : 'CLEAR_STORY_ERROR',
		payload: { id: storyId, slug: fieldId },
	};
}

export function clearSaveStoriesErrors() {
	return {
		type: 'CLEAR_SAVE_STORIES_ERRORS',
	};
}

export function setBudgetsView( args ) {
	return {
		type: 'BUDGETS_VIEW_SET',
		payload: args,
	};
}

export function* searchBudgets( str ) {
	if ( ! str ) {
		return {
			type: 'SEARCH_CLEAR',
			payload: { type: 'budgets' },
		};
	}

	yield { type: 'SEARCH_START' };
	searchAbortController = refreshAbortController( searchAbortController );
	try {
		const result = yield apiFetch( {
			path: '/budgets/search',
			data: { s: str },
			method: 'POST',
			signal: searchAbortController?.signal,
		} );
		return {
			type: 'SEARCH_SUCCESS',
			payload: {
				type: 'budgets',
				ids: result.budget_ids,
			},
		};
	} catch ( error ) {
		if ( error.name === 'AbortError' ) {
			return;
		}
		return {
			type: 'SEARCH_ERROR',
			payload: error,
		};
	}
}

export function* updateBudget( budgetId = null, budget = null ) {
	try {
		const result = yield apiFetch( {
			path: `/budgets/${ budgetId }`,
			method: 'PUT',
			data: budget,
		} );
		return {
			type: 'BUDGET_UPDATE',
			payload: result,
		};
	} catch ( error ) {
		return {
			type: 'UPDATE_BUDGET_ERROR',
			payload: { id: budgetId, message: error.message },
		};
	}
}

export function* saveActiveBudgetOrder( budgetIds = [] ) {
	try {
		yield apiFetch( {
			path: '/budgets/order',
			method: 'POST',
			data: { ids: budgetIds },
		} );
		return {
			type: 'BUDGETS_ORDER',
			payload: budgetIds,
		};
	} catch ( error ) {
		console.error( error ); // eslint-disable-line no-console
	}
}
