/**
 * Internal dependencies
 */
import { NAMESPACE, ALWAYS_FETCH_STORIES } from './constants';
import { canUseCache } from '../store/cache';
import type { Story, StoriesMeta, StoryMetadata } from './types';

/**
 * The `{ dispatch, select, resolveSelect, registry }` argument `@wordpress/data`
 * passes to a "thunk" resolver (a function returning an async callback) --
 * typed here to just the subset of actions/selectors these resolvers call,
 * since the registered store (`store/index.ts`) isn't exported as a typed
 * `StoreDescriptor` for `@wordpress/data`'s own `ThunkArgs<S>` to apply to.
 */
interface ResolverArgs {
	dispatch: {
		fetchFields: () => Promise< unknown >;
		fetchBudgets: () => Promise< unknown >;
		fetchStories: () => Promise< unknown >;
		refreshStories: ( silent?: boolean ) => Promise< unknown >;
		fetchStoriesMeta: () => Promise< unknown >;
		fetchStory: ( id: string | number ) => Promise< unknown >;
		queueStoryMetaFetch: ( id: string | number ) => unknown;
	};
	select: {
		getLastRefresh: () => number | undefined;
		getStory: ( id: string | number ) => Story | undefined;
		getStoryMeta: {
			( id: string | number ): StoryMetadata | undefined;
			( id: string | number, key: string ): unknown;
		};
		getStoriesMeta: () => StoriesMeta;
	};
	resolveSelect: {
		getStoryMeta: ( id: string | number ) => Promise< unknown >;
		getStory: ( id: string | number ) => Promise< Story | undefined >;
	};
	registry: {
		select: ( store: string ) => {
			hasStartedResolution: ( selectorName: string, args?: unknown[] ) => boolean;
			hasFinishedResolution: ( selectorName: string, args?: unknown[] ) => boolean;
		};
	};
}

export const getFields =
	() =>
	async ( { dispatch }: ResolverArgs ) => {
		await dispatch.fetchFields();
	};

export const getField =
	() =>
	async ( { dispatch, registry }: ResolverArgs ) => {
		const { hasStartedResolution, hasFinishedResolution } = registry.select( NAMESPACE );
		if ( hasStartedResolution( 'getFields' ) || hasFinishedResolution( 'getFields' ) ) {
			return;
		}
		await dispatch.fetchFields();
	};

export const getBudgets =
	() =>
	async ( { dispatch }: ResolverArgs ) => {
		await dispatch.fetchBudgets();
	};

export const getStories =
	() =>
	async ( { dispatch, select }: ResolverArgs ) => {
		if ( ALWAYS_FETCH_STORIES ) {
			await dispatch.fetchStories();
			return;
		}
		// If we have a last refresh timestamp or if we want to fetch a budget's stories, refresh the stories. Otherwise, do a full fetch.
		if ( canUseCache() && select.getLastRefresh() ) {
			await dispatch.refreshStories( false );
		} else {
			await dispatch.fetchStories();
		}
	};

export const getStoriesMeta =
	() =>
	async ( { dispatch }: ResolverArgs ) => {
		await dispatch.fetchStoriesMeta();
	};

export const getStory =
	( id: string | number ) =>
	async ( { resolveSelect, dispatch, select }: ResolverArgs ) => {
		// Fetch the entire story if it's not already fetched.
		if ( ! select.getStory( id ) ) {
			await dispatch.fetchStory( id );
			return;
		}
		// Bail if the story meta is already fetched.
		if ( select.getStoryMeta( id ) ) {
			return;
		}
		// Fetch the story meta.
		await resolveSelect.getStoryMeta( id );
	};

export const getStoryMeta =
	( id: string | number, key?: string ) =>
	async ( { dispatch, resolveSelect, select }: ResolverArgs ) => {
		const meta = key ? select.getStoryMeta( id, key ) : select.getStoryMeta( id );

		// Bail if the metadata is already fetched.
		if ( meta && Object.keys( meta ).length > 0 ) {
			return;
		}
		// Fetch story and bail if it's not fetched.
		if ( ! select.getStory( id ) ) {
			await resolveSelect.getStory( id );
			return;
		}
		// Fetch the story meta.
		await dispatch.queueStoryMetaFetch( id );
	};

export const canEditStory =
	( id: string | number ) =>
	async ( { resolveSelect, select }: ResolverArgs ) => {
		// If the user can edit stories, they can edit any story.
		if ( select.getStoriesMeta()?.can_edit ) {
			return;
		}
		// Bail if the story `can_edit` metadata is fetched.
		if ( select.getStoryMeta( id, 'can_edit' ) !== undefined ) {
			return;
		}
		// Fetch the story meta.
		await resolveSelect.getStoryMeta( id );
	};
