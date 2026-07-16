/**
 * Shared admin-shell types.
 *
 * Domain shapes for the DataView list screens (newsletters, ads,
 * advertisers, layouts). The DataViews primitives (`View`, `Field`,
 * `Action`, `Filter`) are re-exported from `@wordpress/dataviews/wp` so
 * screens type their field/action/view state against the same contract
 * the component consumes.
 */

import type { ReactElement } from 'react';
// Types resolve from the package root under TS node resolution; the runtime
// `DataViews` value is imported from `@wordpress/dataviews/wp` at each call site.
import type { Action, Field, Filter, Operator, SortDirection, View } from '@wordpress/dataviews';

export type { Action, Field, Filter, Operator, SortDirection, View };

/**
 * A `_embedded.author` entry from a WP REST post response.
 */
export interface EmbeddedAuthor {
	id?: number;
	name?: string;
	avatar_urls?: Record< number, string >;
	[ key: string ]: unknown;
}

/**
 * A WP REST taxonomy term. Used both as the Advertisers list row shape
 * and as the `_embedded[ 'wp:term' ]` group entries on posts. Extra
 * server fields are typed `unknown` via the index signature.
 */
export interface Term {
	id: number;
	name?: string;
	slug?: string;
	description?: string;
	count?: number;
	parent?: number;
	taxonomy?: string;
	[ key: string ]: unknown;
}

/**
 * A `{ id, name }` selection tracked by the Quick Edit `FormTokenField`
 * pickers. `id` is always a resolved numeric term id.
 */
export interface TermSelection {
	id: number;
	name: string;
}

/**
 * Post meta bag. Only the keys the admin-shell reads are declared; the
 * index signature keeps stray server-side keys type-safe (`unknown`).
 */
export interface PostMeta {
	send_list_id?: string | number;
	send_sublist_id?: string | number;
	is_public?: boolean;
	start_date?: string;
	expiry_date?: string;
	price?: string | number;
	tracking_impressions?: number;
	tracking_clicks?: number;
	font_header?: string;
	font_body?: string;
	background_color?: string;
	text_color?: string;
	custom_css?: string;
	campaign_defaults?: string;
	disable_auto_ads?: boolean;
	[ key: string ]: unknown;
}

/**
 * The consolidated status REST field shared (structurally) by the
 * newsletters (`newspack_newsletters_status`) and ads
 * (`newspack_newsletters_ad_status`) screens.
 */
export interface StatusField {
	kind?: string;
	sent_at?: number;
	scheduled_at?: number;
	expires_at?: number;
	starts_at?: number;
}

/**
 * A WP REST post row as consumed by the list screens. Every screen's
 * data flows through this single shape; the index signature carries the
 * additional server fields as `unknown`.
 */
export interface PostItem {
	id: number;
	status?: string;
	link?: string;
	date?: string;
	modified?: string;
	author?: number;
	post_author?: number;
	is_prebuilt?: boolean;
	title?: { raw?: string; rendered?: string };
	content?: { raw?: string; rendered?: string };
	meta?: PostMeta;
	newspack_newsletters_status?: StatusField;
	newspack_newsletters_ad_status?: StatusField;
	_embedded?: {
		author?: EmbeddedAuthor[];
		'wp:term'?: Term[][];
	};
	[ key: string ]: unknown;
}

/**
 * Props passed to a DataView field's `render` / `getValue` callback.
 * `Item` defaults to `PostItem`; the Advertisers screen narrows it to
 * `Term`.
 */
export interface FieldRenderProps< Item = PostItem > {
	item: Item;
}

/**
 * A DataViews action extended with the `isDestructive` flag the screens
 * pass to their trash/delete actions (not part of the upstream type).
 */
export type ListAction< Item = PostItem > = Action< Item > & { isDestructive?: boolean };

/**
 * A registered chassis header action, rendered by `<PageHeader>` and
 * registered via `useHeaderActions`.
 */
export interface HeaderAction {
	type: 'primary' | 'secondary';
	label: string;
	id?: string;
	icon?: ReactElement;
	href?: string;
	onClick?: () => void;
}

/**
 * An option for a filter dropdown / `elements` list, as localized by the
 * REST filter-options endpoint.
 */
export interface FilterOption {
	id: string | number;
	label: string;
}

/**
 * The `{ id, label }` element shape a DataView field's `elements` list
 * expects (string-coerced).
 */
export interface FieldElement {
	value: string;
	label: string;
}

/**
 * A loose view shape for the query-building layer. Broader than the
 * DataViews `View`: the layouts screen adds `offset` and an `author`
 * array-param, and `buildQueryParams` reads array-valued fields by
 * dynamic key (hence the index signature).
 */
export interface QueryView {
	perPage?: number;
	page?: number;
	offset?: number;
	search?: string;
	sort?: { field?: string; direction?: string };
	filters?: Filter[];
	[ key: string ]: unknown;
}

/**
 * The URL-seeded view patch produced by `makeGetInitialView`, spread
 * over a screen's `DEFAULT_VIEW`.
 */
export interface InitialViewPatch {
	filters?: Filter[];
	search?: string;
	sort?: { field: string; direction: 'asc' | 'desc' };
}
