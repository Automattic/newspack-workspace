/**
 * Shared type definitions for the `newspack-story-budget` data store.
 *
 * A "field" is a story-level piece of metadata registered by
 * `includes/fields/class-abstract-field.php` (see `Abstract_Field::to_array()`
 * for the canonical shape mirrored by `Field` below). A "budget" is a term
 * from `includes/class-budget.php` (`Budget::to_array()`).
 */

/**
 * A single story budget field, as returned by the `/fields` REST endpoint.
 */
export interface Field {
	slug: string;
	name: string;
	description?: string;
	type: string;
	default_order?: number;
	is_editable?: boolean;
	is_multiple?: boolean;
	/** yes: filterable; no: not filterable; always: filterable and always shown in the filter bar. */
	is_filterable?: 'yes' | 'no' | 'always';
	is_searchable?: boolean;
	is_sortable?: boolean;
	show_in_table?: boolean;
	always_visible_in_table?: boolean;
	show_in_editor?: boolean;
	show_in_wp_posts_table?: boolean;
	show_in_add_new_story?: boolean;
	title?: string;
	options?: { value: string | number | boolean; label: string }[];
}

/**
 * A story budget (a WP term), as returned by the `/budgets` REST endpoint.
 */
export interface Budget {
	id: number;
	name: string;
	description?: string;
	slug?: string;
	archived?: boolean;
	archive_at?: string | number | null;
	story_count?: number;
	order: number;
}

/** Metadata attached to a story, fetched separately from the story's field data. */
export interface StoryMetadata {
	can_edit?: boolean;
	can_preview?: boolean;
	edit_url?: string;
	[ key: string ]: unknown;
}

/**
 * A story. Field values are keyed by each registered field's `slug`, so most
 * of this shape is inherently dynamic (per-site field configuration).
 */
export interface Story {
	id: number;
	title?: string;
	name?: string;
	/**
	 * The "budgets" field's `get_value_callback` (Fields::get_budgets(), PHP)
	 * returns a plain array only when multi-budget mode is enabled for the
	 * site; otherwise it returns a single id (or `null`). See the
	 * `getBudgetStoryMeta` selector for a spot that assumes the single-id shape
	 * unconditionally.
	 */
	budgets?: number | number[] | null;
	metadata?: StoryMetadata;
	[ key: string ]: unknown;
}

/** The payload accepted by `createStory()` -- a story draft, not a persisted `Story`. */
export interface NewStoryData {
	name?: string;
	title?: string;
	newBudgetName?: string;
	budgets?: number[];
	[ key: string ]: unknown;
}

export interface ViewFilter {
	field: string;
	operator?: string;
	value?: unknown;
}

export interface SortConfig {
	field: string;
	direction: 'asc' | 'desc';
}

/** `state.view` -- the Stories DataViews view. */
export interface StoriesView {
	type?: string;
	search: string;
	page: number;
	perPage: number;
	fields: string[];
	filters: ViewFilter[];
	sort?: SortConfig;
	layout?: { density?: string };
}

/** `state.budgetsView` -- the Budgets DataViews view. */
export interface BudgetsView {
	search: string;
	page: number;
	perPage: number;
	filters: ViewFilter[];
}

export interface StoriesMeta {
	can_edit: boolean;
	[ key: string ]: unknown;
}

/** `state.meta` -- assorted loading/UI flags, not persisted data. */
export interface MetaState {
	loading: boolean;
	refreshing: boolean;
	searching: boolean;
	storyMetaFetchQueue: Record< string, boolean >;
	stories: StoriesMeta;
	isCreatingStory: boolean;
	isCreatingBudget: boolean;
	progress?: number;
	lastRefresh?: number;
	loadingStory?: Record< string, boolean >;
	savingStories?: boolean;
	loadingBudgets?: boolean;
}

export interface SearchState {
	stories: number[];
	budgets: number[];
}

/** Error messages keyed by story id, `story-<id>`, `<id>-<slug>`, `budget-<id>`, or a fixed key like `save-stories`. */
export type ErrorsState = Record< string, string | null | undefined >;

/** The combined shape of the registered `@wordpress/data` store's state. */
export interface State {
	budgets: Budget[];
	stories: Record< string, Story >;
	search: SearchState;
	fields: Field[];
	errors: ErrorsState;
	meta: MetaState;
	budgetsView: BudgetsView;
	/**
	 * The `view` reducer's own default state is `{}` (not `INITIAL_STATE`, which
	 * has no `view` key), so this slice stays partial until the first
	 * `VIEW_SET`/`HYDRATE` action -- unlike every other slice, which is always
	 * fully populated. `getView()` (selectors.ts) is what callers should use for
	 * a fully-populated view (it merges this with `newspack-story-budget.defaultView` filter defaults).
	 */
	view: Partial< StoriesView >;
}

/** A single cached-in-`sessionStorage` slice, as read back by `cache/index.ts`. */
export type CacheableStateKey = 'fields' | 'stories' | 'view' | 'meta';

interface HydrateFieldsAction {
	type: 'HYDRATE';
	payload: { key: 'fields'; timestamp: number; data: Field[] };
}
interface HydrateStoriesAction {
	type: 'HYDRATE';
	payload: { key: 'stories'; timestamp: number; data: Record< string, Story > };
}
interface HydrateViewAction {
	type: 'HYDRATE';
	payload: { key: 'view'; timestamp: number; data: Partial< StoriesView > };
}
interface HydrateMetaAction {
	type: 'HYDRATE';
	payload: { key: 'meta'; timestamp: number; data: MetaState };
}

/** Dispatched once per cached key at startup, to hydrate state from `sessionStorage`. */
export type HydrateAction = HydrateFieldsAction | HydrateStoriesAction | HydrateViewAction | HydrateMetaAction;

interface SearchStartAction {
	type: 'SEARCH_START';
}
interface SearchClearAction {
	type: 'SEARCH_CLEAR';
	payload: { type: 'stories' | 'budgets' };
}
interface SearchSuccessAction {
	type: 'SEARCH_SUCCESS';
	payload: { type: 'stories' | 'budgets'; ids: number[] };
}
interface SearchErrorAction {
	type: 'SEARCH_ERROR';
	payload: CaughtError;
}

interface ViewSetAction {
	type: 'VIEW_SET';
	payload: StoriesView;
}

interface FieldsSetAction {
	type: 'FIELDS_SET';
	payload: Field[];
}
interface FieldsErrorAction {
	type: 'FIELDS_ERROR';
	payload: unknown;
}

interface FetchBudgetsStartAction {
	type: 'FETCH_BUDGETS_START';
}
interface FetchBudgetsEndAction {
	type: 'FETCH_BUDGETS_END';
}
interface BudgetsSetAction {
	type: 'BUDGETS_SET';
	payload: Budget[];
}
interface BudgetsErrorAction {
	type: 'BUDGETS_ERROR';
	payload: CaughtError;
}

interface CreateBudgetStartAction {
	type: 'CREATE_BUDGET_START';
	payload: { budget: Partial< Budget > };
}
interface CreateBudgetSuccessAction {
	type: 'CREATE_BUDGET_SUCCESS';
	payload: Budget;
}
interface CreateBudgetErrorAction {
	type: 'CREATE_BUDGET_ERROR';
	payload: { message: string };
}

interface CreateStoryStartAction {
	type: 'CREATE_STORY_START';
	payload: { storyData: NewStoryData };
}
interface CreateStorySuccessAction {
	type: 'CREATE_STORY_SUCCESS';
	payload: Story;
}
interface CreateStoryErrorAction {
	type: 'CREATE_STORY_ERROR';
	payload: { message?: string };
}

interface FetchProgressAction {
	type: 'FETCH_PROGRESS';
	payload: { progress: number; result?: { stories: Story[]; total: number } };
}
interface FetchStartAction {
	type: 'FETCH_START';
}
interface FetchEndAction {
	type: 'FETCH_END';
}
interface FetchSuccessAction {
	type: 'FETCH_SUCCESS';
	payload: { timestamp: number };
}
interface StoriesSetAction {
	type: 'STORIES_SET';
	payload: Record< string, Story >;
}
interface StoriesErrorAction {
	type: 'STORIES_ERROR';
	payload: { message?: string };
}

interface RefreshStartAction {
	type: 'REFRESH_START';
	payload: { silent: boolean };
}
interface RefreshSuccessAction {
	type: 'REFRESH_SUCCESS';
	payload: { timestamp: number };
}
interface RefreshEndAction {
	type: 'REFRESH_END';
}
interface StoriesAppendAction {
	type: 'STORIES_APPEND';
	payload: Record< string, Story >;
}
interface StoriesRemoveAction {
	type: 'STORIES_REMOVE';
	payload: string;
}

interface StoriesMetaSetAction {
	type: 'STORIES_META_SET';
	payload: StoriesMeta;
}
interface StoriesMetaErrorAction {
	type: 'STORIES_META_ERROR';
	payload: unknown;
}

interface FetchStoryStartAction {
	type: 'FETCH_STORY_START';
	payload: { id: string | number };
}
interface FetchStoryStorySuccessAction {
	type: 'FETCH_STORY_SUCCESS';
	payload: { id: string | number };
}
interface FetchStoryErrorAction {
	type: 'FETCH_STORY_ERROR';
	payload: { id: string | number; message?: string };
}

interface StoryMetaSetAction {
	type: 'STORY_META_SET';
	payload: { id: string | number; result: StoryMetadata };
}
interface StoryMetaErrorAction {
	type: 'STORY_META_ERROR';
	payload: unknown;
}

interface StoryMetaBatchStartAction {
	type: 'STORY_META_BATCH_START';
}
interface StoryMetaBatchSetAction {
	type: 'STORY_META_BATCH_SET';
	payload: Record< string, StoryMetadata >;
}
interface StoryMetaBatchErrorAction {
	type: 'STORY_META_BATCH_ERROR';
	payload: unknown;
}
interface StoryMetaFetchQueueAction {
	type: 'STORY_META_FETCH_QUEUE';
	payload: { id: string | number };
}

interface SaveStoryStartAction {
	type: 'SAVE_STORY_START';
	payload: { id: string | number; story: Partial< Story > };
}
interface SaveStorySuccessAction {
	type: 'SAVE_STORY_SUCCESS';
	payload: Story;
}
interface SaveStoryErrorAction {
	type: 'SAVE_STORY_ERROR';
	payload: { id: string | number; story: Partial< Story >; message?: string };
}

interface SaveStoryFieldStartAction {
	type: 'SAVE_STORY_FIELD_START';
	payload: { id: string | number; slug: string; value: unknown };
}
interface SaveStoryFieldSuccessAction {
	type: 'SAVE_STORY_FIELD_SUCCESS';
	payload: { id: string | number; slug: string; value: unknown };
}
interface SaveStoryFieldErrorAction {
	type: 'SAVE_STORY_FIELD_ERROR';
	payload: { id: string | number; slug: string; value: unknown; message?: string };
}

interface SaveStoriesStartAction {
	type: 'SAVE_STORIES_START';
	payload: { ids: ( string | number )[]; fields: Partial< Story > };
}
interface SaveStoriesSuccessAction {
	type: 'SAVE_STORIES_SUCCESS';
	payload: { ids: ( string | number )[]; fields: Partial< Story >; result: { stories?: Story[] } };
}
interface SaveStoriesErrorAction {
	type: 'SAVE_STORIES_ERROR';
	payload: { ids: ( string | number )[]; fields: Partial< Story >; message?: string };
}

interface ClearAllErrorsAction {
	type: 'CLEAR_ALL_ERRORS';
}
interface ClearFieldErrorAction {
	type: 'CLEAR_FIELD_ERROR';
	payload: { id: string | number; slug: string };
}
interface ClearStoryErrorAction {
	type: 'CLEAR_STORY_ERROR';
	payload: { id: string | number; slug?: string | null };
}
interface ClearSaveStoriesErrorsAction {
	type: 'CLEAR_SAVE_STORIES_ERRORS';
}

interface BudgetsViewSetAction {
	type: 'BUDGETS_VIEW_SET';
	payload: Partial< BudgetsView >;
}
interface BudgetsOrderAction {
	type: 'BUDGETS_ORDER';
	payload: number[];
}
interface BudgetUpdateAction {
	type: 'BUDGET_UPDATE';
	payload: Budget;
}
interface UpdateBudgetErrorAction {
	type: 'UPDATE_BUDGET_ERROR';
	payload: { id: number | null; message?: string };
}

/**
 * Reducer-only cases with no matching action creator in `actions.ts` -- dead
 * code today (not dispatched anywhere), kept only so the reducers'
 * pre-existing `case` branches keep type-checking.
 */
interface BudgetsAddAction {
	type: 'BUDGETS_ADD';
	payload: Budget;
}
interface PullStoryErrorAction {
	type: 'PULL_STORY_ERROR';
	payload: { id: string | number; message?: string };
}
interface SetStoryErrorAction {
	type: 'SET_STORY_ERROR';
	payload: unknown;
}
interface SetBudgetErrorAction {
	type: 'SET_BUDGET_ERROR';
	payload: unknown;
}
/**
 * Note the singular "ERROR": `clearSaveStoriesErrors()` in actions.ts actually
 * dispatches the plural `CLEAR_SAVE_STORIES_ERRORS` (see that action creator),
 * so this reducer case can never match it -- a pre-existing bug, not fixed
 * here. Modeled so this dead `case` keeps type-checking.
 */
interface ClearSaveStoriesErrorAction {
	type: 'CLEAR_SAVE_STORIES_ERROR';
}

/**
 * A caught value re-dispatched verbatim as an action payload (as opposed to
 * being unwrapped into a plain `{ message: string }` object first). Real
 * errors and REST API rejection objects both duck-type this shape.
 */
export type CaughtError = { message?: string; [ key: string ]: unknown };

/** Every action shape dispatched (or, for a few dead cases, only ever switched on) across this store. */
export type StoreAction =
	| HydrateAction
	| SearchStartAction
	| SearchClearAction
	| SearchSuccessAction
	| SearchErrorAction
	| ViewSetAction
	| FieldsSetAction
	| FieldsErrorAction
	| FetchBudgetsStartAction
	| FetchBudgetsEndAction
	| BudgetsSetAction
	| BudgetsErrorAction
	| CreateBudgetStartAction
	| CreateBudgetSuccessAction
	| CreateBudgetErrorAction
	| CreateStoryStartAction
	| CreateStorySuccessAction
	| CreateStoryErrorAction
	| FetchProgressAction
	| FetchStartAction
	| FetchEndAction
	| FetchSuccessAction
	| StoriesSetAction
	| StoriesErrorAction
	| RefreshStartAction
	| RefreshSuccessAction
	| RefreshEndAction
	| StoriesAppendAction
	| StoriesRemoveAction
	| StoriesMetaSetAction
	| StoriesMetaErrorAction
	| FetchStoryStartAction
	| FetchStoryStorySuccessAction
	| FetchStoryErrorAction
	| StoryMetaSetAction
	| StoryMetaErrorAction
	| StoryMetaBatchStartAction
	| StoryMetaBatchSetAction
	| StoryMetaBatchErrorAction
	| StoryMetaFetchQueueAction
	| SaveStoryStartAction
	| SaveStorySuccessAction
	| SaveStoryErrorAction
	| SaveStoryFieldStartAction
	| SaveStoryFieldSuccessAction
	| SaveStoryFieldErrorAction
	| SaveStoriesStartAction
	| SaveStoriesSuccessAction
	| SaveStoriesErrorAction
	| ClearAllErrorsAction
	| ClearFieldErrorAction
	| ClearStoryErrorAction
	| ClearSaveStoriesErrorsAction
	| BudgetsViewSetAction
	| BudgetsOrderAction
	| BudgetUpdateAction
	| UpdateBudgetErrorAction
	| BudgetsAddAction
	| PullStoryErrorAction
	| SetStoryErrorAction
	| SetBudgetErrorAction
	| ClearSaveStoriesErrorAction;
