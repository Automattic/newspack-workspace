/**
 * Internal dependencies
 */
import { NAMESPACE, ALWAYS_FETCH_STORIES } from './constants';
import { canUseCache } from '../store/cache';

export const getFields =
	() =>
	async ( { dispatch } ) => {
		await dispatch.fetchFields();
	};

export const getField =
	() =>
	async ( { dispatch, registry } ) => {
		const { hasStartedResolution, hasFinishedResolution } = registry.select( NAMESPACE );
		if ( hasStartedResolution( 'getFields' ) || hasFinishedResolution( 'getFields' ) ) {
			return;
		}
		await dispatch.fetchFields();
	};

export const getBudgets =
	() =>
	async ( { dispatch } ) => {
		await dispatch.fetchBudgets();
	};

export const getStories =
	() =>
	async ( { dispatch, select } ) => {
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
	async ( { dispatch } ) => {
		await dispatch.fetchStoriesMeta();
	};

export const getStory =
	id =>
	async ( { resolveSelect, dispatch, select } ) => {
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
	( id, key ) =>
	async ( { dispatch, resolveSelect, select } ) => {
		const meta = select.getStoryMeta( id, key );

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
	id =>
	async ( { resolveSelect, select } ) => {
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
