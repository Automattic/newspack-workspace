/**
 * The shared status name for each WordPress post status the Audience lists surface
 * in a Status column.
 *
 * Anything outside the set is treated as a draft, which is how an unrecognised
 * status is treated everywhere else. Pricing rules reads its statuses from a plugin
 * outside this repo and builds its filter elements from the rows themselves, so an
 * unrecognised status can reach a column and draw the draft mark alongside Draft.
 */

/**
 * Internal dependencies.
 */
import type { StatusName } from '../../../packages/components/src/status-indicator/statuses';

const POST_STATUSES = {
	publish: 'active',
	future: 'scheduled',
	draft: 'draft',
	pending: 'pending',
	private: 'private',
	trash: 'trash',
} as const;

export const postStatus = ( status: string ): StatusName => POST_STATUSES[ status as keyof typeof POST_STATUSES ] ?? 'draft';
