/**
 * Shared local types for `src/components`.
 *
 * The plugin's Redux store (`../store`) and utils (`../utils`) are not yet migrated to
 * TypeScript, so several of their exports are either untyped (inferred `unknown`/`any`)
 * or carry a loose `@return {Object}` JSDoc annotation. The interfaces below re-declare
 * the real runtime shapes this plugin's components rely on, so call sites can cast into
 * them at the boundary instead of operating on `unknown`/`Object`.
 */

/**
 * A remote site entry, as returned by `../utils/sites`' `getSites()`
 * (a bare `applyFilters()` call, so it types as `unknown`).
 */
export interface Site {
	name: string;
	url: string;
}

/**
 * A budget, as stored/returned by the plugin's Redux store (`../store`).
 */
export interface Budget {
	id: number | string;
	name: string;
	archive_at?: string | number | null;
	archived?: boolean;
	order?: number;
	story_count?: number;
}

/**
 * A single selectable option for a `Field` with `options` (e.g. `budgets`, `status`).
 * Mirrors the shapes built server-side in `includes/fields/class-fields.php` and
 * `includes/fields/class-status.php`'s `to_array()`.
 */
export interface FieldOption {
	value: string | number | boolean;
	label?: string;
	/** Legacy/fallback key some call sites read instead of `label`. */
	name?: string;
	disabled?: boolean;
	user_can_apply?: boolean;
}

/**
 * A story custom-field descriptor, as returned by the `fields` REST endpoint and
 * registered server-side in `includes/fields/class-fields.php`.
 */
export interface Field {
	slug: string;
	name?: string;
	/**
	 * Not part of the server-side field shape (which only has `name`) -- some call
	 * sites read `field.title` instead, which is always `undefined` at runtime.
	 * Declared here only to type-match that pre-existing (buggy) usage.
	 */
	title?: string;
	description?: string;
	type?: string;
	is_multiple?: boolean;
	is_editable?: boolean;
	is_filterable?: boolean | 'no' | 'always';
	is_sortable?: boolean;
	show_in_table?: boolean;
	show_in_add_new_story?: boolean;
	always_visible_in_table?: boolean;
	options?: FieldOption[];
}

/**
 * The value of a single story field. Its real shape varies with `Field.type`
 * (text/number/date store as `string`/`number`, `boolean` fields store a
 * `boolean`, multi-value fields store an array).
 */
export type FieldValue = string | number | boolean | ( string | number )[] | null | undefined;

/**
 * A story, as stored/returned by the plugin's Redux store (`../store`).
 * Custom field values are stored directly on the object, keyed by field slug.
 */
export interface Story {
	id: number | string;
	name?: string;
	title?: string;
	metadata?: {
		can_preview?: boolean;
		preview_url?: string;
		edit_url?: string;
	};
	[ fieldSlug: string ]: unknown;
}

/**
 * The DataViews-style view state used for both the stories table and the budgets list.
 */
export interface StoryBudgetView {
	search: string;
	page: number;
	perPage: number;
	filters?: { field: string; value: unknown }[];
	fields?: string[];
}

/** An error object shape used by a handful of `getErrors()` keys (e.g. `budgetError`, `storyError`). */
export interface StoryBudgetErrorObject {
	message: string;
}

/**
 * Minimal typed surface for this plugin's `@wordpress/data` store selectors (`../store`),
 * covering only the selectors this `components` subtree calls. The store registers under
 * the bare string `NAMESPACE` (see `../store/constants`) rather than a typed store
 * descriptor object, so `select( NAMESPACE )` resolves to `never` inside a `useSelect`
 * callback; cast to this interface at that boundary instead of leaving it `never`.
 */
export interface StoryBudgetSelectors {
	getBudgets: () => Budget[];
	getBudgetsView: () => StoryBudgetView;
	getBudgetsCount: () => { active?: number; archived?: number };
	isBudgetsLoading: () => boolean;
	getErrors: () => {
		stories?: string;
		budgets?: string;
		budgetError?: StoryBudgetErrorObject | null;
		storyError?: StoryBudgetErrorObject | null;
		[ key: string ]: unknown;
	};
	isCreatingBudget: () => boolean;
	isCreatingStory: () => boolean;
	getSaveStoriesError: () => string | null | undefined;
	isSavingStories: () => boolean;
	getStories: () => Story[];
	isLoading: () => boolean;
	isRefreshing: () => boolean;
	getProgress: () => number | undefined;
	canManage: () => boolean;
	canRefreshStories: () => boolean;
	isLoadingStory: ( storyId: Story[ 'id' ] ) => boolean;
	canEditStory: ( storyId: Story[ 'id' ] ) => boolean;
	getFieldError: ( storyId: Story[ 'id' ], fieldSlug: string ) => string | undefined;
	getStoryError: ( storyId: Story[ 'id' ] ) => string | undefined;
}
