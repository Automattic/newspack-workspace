/**
 * Local types for the Layouts list screen.
 *
 * The shared `PostItem` (`../../types`) types `id: number` — true for every
 * other DataView screen, where rows are always real WP REST posts. Layouts
 * mix that with synthetic prebuilt rows carrying a string `id`
 * (`` `prebuilt-${ item.ID }` ``, see `use-prebuilt-layouts.ts`), so `PostItem`
 * doesn't fit here.
 *
 * `LayoutItem` is declared from scratch rather than as `Omit< PostItem, 'id' >
 * & { id: ... }`: `PostItem` carries a `[ key: string ]: unknown` index
 * signature (for its own forward-compat reasons), and `Omit`/`Pick` on a type
 * with a string index signature collapses every *other* named property to
 * `unknown` too (a known TS quirk — `keyof` widens to `string` once an index
 * signature is present, so `Omit`'s `Pick<T, Exclude<keyof T, K>>` can no
 * longer resolve the specific property types). A plain interface avoids that.
 */
import type { EmbeddedAuthor, PostMeta } from '../../types';

export interface LayoutItem {
	id: string | number;
	status?: string;
	is_prebuilt?: boolean;
	author?: number;
	// Prebuilt rows report `modified: null` (see `use-prebuilt-layouts.ts`).
	modified?: string | null;
	title?: { raw?: string; rendered?: string };
	content?: { raw?: string; rendered?: string };
	meta?: PostMeta;
	_embedded?: {
		author?: EmbeddedAuthor[];
	};
	[ key: string ]: unknown;
}

/**
 * Raw shape of a prebuilt layout entry from
 * `/newspack-newsletters/v1/layouts?defaults_only=1` — a bundled JSON
 * record, not a REST post (hence `ID` and `post_*` keys rather than the
 * WP REST post shape `LayoutItem` otherwise uses).
 */
export interface RawPrebuiltLayout {
	ID: number;
	post_author?: number;
	post_title?: string;
	post_content?: string;
}
