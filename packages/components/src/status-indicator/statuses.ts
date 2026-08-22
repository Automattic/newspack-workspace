/**
 * WordPress dependencies.
 */
import { caution, drafts, error, notAllowed, pending, published, scheduled, trash, update, lock } from '@wordpress/icons';

/**
 * The glyph for each status a list can report.
 *
 * Screens name a meaning here rather than picking a glyph, so one meaning draws
 * one mark everywhere. Two names may resolve to the same glyph where they read
 * differently at the call site but mean the same thing to a reader: `sent` is
 * not `active`, and an ad that expired was not cancelled. Splitting them leaves
 * room to draw them apart later without touching a consumer.
 *
 * Because of that, the rule a Status column has to keep is about glyphs, not
 * names: a column offers its statuses as separate filters, so two that resolve
 * to the same mark make two different states indistinguishable in the one place
 * the difference matters. `statusGlyph` is exported so a column's own test can
 * assert it.
 */
const STATUS_GLYPHS = {
	/** Live now: a published plan, a running subscription. */
	active: published,
	/** Finished successfully: a sent newsletter, a completed sync. */
	done: published,
	/** Waiting for a date to arrive. */
	scheduled,
	/** Not live yet, or switched off. */
	draft: drafts,
	/** Waiting on something outside the publisher's hands. */
	pending,
	/** Live but needing a look, usually a payment. */
	attention: caution,
	/** Failed, and the one state that asks the reader to act. */
	error,
	/** Running right now. */
	progress: update,
	/** Stopped on purpose. */
	cancelled: notAllowed,
	/** Stopped because its window closed. */
	ended: notAllowed,
	/** Live, but not publicly reachable. */
	private: lock,
	/** Binned. */
	trash,
} as const;

export type StatusName = keyof typeof STATUS_GLYPHS;

export const statusGlyph = ( status: StatusName ) => STATUS_GLYPHS[ status ];

export const STATUS_NAMES = Object.keys( STATUS_GLYPHS ) as StatusName[];
