/**
 * Glyphs for the WordPress post statuses the Audience lists surface in a Status column.
 *
 * A Status column offers its statuses as separate filters, so no two may share a
 * glyph. Anything outside the set falls back to the draft glyph, which is what
 * an unrecognised status is treated as everywhere else.
 */

/**
 * WordPress dependencies.
 */
import { drafts, lock, pending, published, scheduled, trash } from '@wordpress/icons';

const POST_STATUS_ICONS = {
	publish: published,
	future: scheduled,
	draft: drafts,
	pending,
	private: lock,
	trash,
} as const;

export const postStatusIcon = ( status: string ) => POST_STATUS_ICONS[ status as keyof typeof POST_STATUS_ICONS ] ?? drafts;
