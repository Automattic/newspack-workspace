/**
 * WordPress dependencies.
 */
import { caution, drafts, error, notAllowed, pending, published, scheduled, trash, update, lock } from '@wordpress/icons';

/**
 * The glyph for each status a list can report.
 *
 * Two names may resolve to the same glyph where they read differently at the call
 * site but mean the same to a reader: a sent newsletter is finished rather than
 * live, and an ad that expired was not cancelled. Splitting them leaves room to
 * draw them apart later without touching a consumer, and it means the rule a
 * Status column keeps is about glyphs rather than names. `statusGlyph` is
 * exported so a column's own test can assert it.
 */
const STATUS_GLYPHS = {
	active: published,
	done: published,
	scheduled,
	draft: drafts,
	pending,
	attention: caution,
	error,
	progress: update,
	cancelled: notAllowed,
	ended: notAllowed,
	private: lock,
	trash,
} as const;

export type StatusName = keyof typeof STATUS_GLYPHS;

export const statusGlyph = ( status: StatusName ) => STATUS_GLYPHS[ status ];

export const STATUS_NAMES = Object.freeze( Object.keys( STATUS_GLYPHS ) as StatusName[] );
