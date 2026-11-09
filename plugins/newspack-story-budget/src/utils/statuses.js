/**
 * WordPress dependencies.
 */
import { check, search } from '@wordpress/icons';

/**
 * How each default story status draws in a `StatusIndicator`.
 *
 * Fact-checking and Approved have no fitting name in the shared vocabulary,
 * so they pass their own glyph; the check circle stays with Published.
 */
const STORY_STATUS_INDICATORS = {
	writing: { status: 'draft' },
	editing: { status: 'pending' },
	factcheck: { icon: search },
	approved: { icon: check },
	published: { status: 'active' },
};

const CUSTOM_STATUS_INDICATOR = { status: 'progress' };

/**
 * The `StatusIndicator` props for a story status.
 *
 * Statuses are filterable through `newspack_story_budget_statuses`, so a slug
 * outside the defaults draws as a stage in progress.
 *
 * @param {string} slug The story status slug.
 * @return {Object} `status` or `icon` props for `StatusIndicator`.
 */
export const getStatusIndicatorProps = slug => STORY_STATUS_INDICATORS[ slug ] || CUSTOM_STATUS_INDICATOR;

/**
 * How each WordPress post status a story can have draws in a `StatusIndicator`.
 */
const WP_STATUS_INDICATORS = {
	publish: { status: 'active' },
	future: { status: 'scheduled' },
	draft: { status: 'draft' },
	pending: { status: 'pending' },
	private: { status: 'private' },
};

/**
 * The `StatusIndicator` props for a WordPress post status.
 *
 * @param {string} slug The post status slug.
 * @return {Object|undefined} `status` props for `StatusIndicator`, or undefined
 *                            for a status without a glyph, which shows as text.
 */
export const getWpStatusIndicatorProps = slug => WP_STATUS_INDICATORS[ slug ];
