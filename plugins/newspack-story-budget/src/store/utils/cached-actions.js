import { actions as fields } from '../reducers/fields';
import { actions as stories } from '../reducers/stories';
import { actions as view } from '../reducers/view';
import { actions as meta } from '../reducers/meta';

export default {
	fields,
	stories: {
		// Only cache actions that modify the stories field data. Metadata are
		// fetched asynchronously.
		STORIES_SET: stories.STORIES_SET,
		STORIES_APPEND: stories.STORIES_APPEND,
		STORIES_REMOVE: stories.STORIES_REMOVE,
		CREATE_STORY_SUCCESS: stories.CREATE_STORY_SUCCESS,
	},
	view,
	meta: {
		FETCH_SUCCESS: meta.FETCH_SUCCESS,
		REFRESH_SUCCESS: meta.REFRESH_SUCCESS,
	},
};
